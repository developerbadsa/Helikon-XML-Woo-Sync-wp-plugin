<?php
/**
 * Image import helpers.
 *
 * @package Helikon_XML_Woo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTX_XML_Woo_Sync_Images {
	const META_IMAGE_ETAG           = '_htx_source_image_etag';
	const META_IMAGE_LAST_MODIFIED  = '_htx_source_image_last_modified';
	const META_IMAGE_CONTENT_LENGTH = '_htx_source_image_content_length';
	const META_IMAGE_REMOTE_HASH    = '_htx_source_image_remote_hash';
	const REMOTE_TIMEOUT            = 60;

	/**
	 * State instance.
	 *
	 * @var HTX_XML_Woo_Sync_State
	 */
	private $state;

	/**
	 * Attachment cache.
	 *
	 * @var array
	 */
	private $attachment_cache = array();

	/**
	 * Constructor.
	 *
	 * @param HTX_XML_Woo_Sync_State $state State service.
	 */
	public function __construct( $state ) {
		$this->state = $state;
	}

	/**
	 * Import a remote image or return an existing attachment.
	 *
	 * @param string $image_path Image path from feed.
	 * @param int    $post_id    Parent post ID.
	 * @param array  $settings   Plugin settings.
	 * @return int|WP_Error
	 */
	public function import_image( $image_path, $post_id, $settings ) {
		$image_url = $this->resolve_image_url( $image_path, $settings );
		if ( is_wp_error( $image_url ) ) {
			return $image_url;
		}

		if ( isset( $this->attachment_cache[ $image_url ] ) ) {
			return (int) $this->attachment_cache[ $image_url ];
		}

		$existing_id = $this->find_existing_attachment_id( $image_url );
		if ( $existing_id ) {
			$attachment_id = $this->maybe_refresh_attachment( $existing_id, $image_url, $post_id, $settings );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			$this->attachment_cache[ $image_url ] = (int) $attachment_id;
			return (int) $attachment_id;
		}

		$attachment_id = $this->create_attachment_from_remote_image( $image_url, $post_id, $settings );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$this->attachment_cache[ $image_url ] = (int) $attachment_id;

		return (int) $attachment_id;
	}

	/**
	 * Sync gallery images from the feed while preserving manual attachments.
	 *
	 * @param array $existing_ids Existing attachment IDs.
	 * @param array $image_paths  Raw image paths.
	 * @param int   $post_id      Parent post ID.
	 * @param array $settings     Plugin settings.
	 * @return array
	 */
	public function sync_gallery_ids( $existing_ids, $image_paths, $post_id, $settings ) {
		$existing_ids = array_values( array_unique( array_map( 'absint', (array) $existing_ids ) ) );
		$image_paths = array_values( array_unique( array_filter( array_map( 'strval', (array) $image_paths ) ) ) );
		$managed_ids = array();
		$manual_ids  = array();

		foreach ( $existing_ids as $attachment_id ) {
			if ( $this->is_managed_attachment( $attachment_id ) ) {
				$managed_ids[] = $attachment_id;
				continue;
			}

			$manual_ids[] = $attachment_id;
		}

		$gallery_ids = array();

		foreach ( $image_paths as $image_path ) {
			$attachment_id = $this->import_image( $image_path, $post_id, $settings );
			if ( is_wp_error( $attachment_id ) ) {
				$this->state->add_log( $attachment_id->get_error_message(), 'warning' );
				continue;
			}

			$gallery_ids[] = (int) $attachment_id;
		}

		$stale_managed_ids = array_diff( $managed_ids, $gallery_ids );
		foreach ( $stale_managed_ids as $attachment_id ) {
			unset( $this->attachment_cache[ $this->get_attachment_source_url( $attachment_id ) ] );
		}

		return array_values( array_unique( array_map( 'absint', array_merge( $gallery_ids, $manual_ids ) ) ) );
	}

	/**
	 * Determine whether an attachment was created by this sync.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_managed_attachment( $attachment_id ) {
		return '' !== $this->get_attachment_source_url( $attachment_id );
	}

	/**
	 * Resolve an incoming image path to an absolute URL.
	 *
	 * @param string $image_path Image path.
	 * @param array  $settings   Plugin settings.
	 * @return string|WP_Error
	 */
	public function resolve_image_url( $image_path, $settings ) {
		$image_path = trim( (string) $image_path );
		if ( '' === $image_path ) {
			return new WP_Error( 'htx_image_empty', __( 'The image path is empty.', 'helikon-xml-woo-sync' ) );
		}

		if ( preg_match( '#^https?://#i', $image_path ) ) {
			$image_url = $image_path;
		} else {
			$base_url = trim( (string) $settings['media_base_url'] );
			if ( '' === $base_url ) {
				$base_url = $this->derive_media_base_url( $settings['xml_url'] );
			}

			if ( '' === $base_url ) {
				return new WP_Error( 'htx_image_base_url', __( 'The media base URL could not be determined.', 'helikon-xml-woo-sync' ) );
			}

			$image_url = trailingslashit( $base_url ) . ltrim( $image_path, '/' );
		}

		$image_url = esc_url_raw( $image_url );
		if ( ! wp_http_validate_url( $image_url ) ) {
			return new WP_Error(
				'htx_image_invalid_url',
				sprintf(
					/* translators: %s: image URL */
					__( 'Skipped an invalid image URL: %s', 'helikon-xml-woo-sync' ),
					$image_url
				)
			);
		}

		return $image_url;
	}

	/**
	 * Derive the media base URL from the XML URL.
	 *
	 * @param string $xml_url XML feed URL.
	 * @return string
	 */
	private function derive_media_base_url( $xml_url ) {
		$xml_url = trim( (string) $xml_url );
		if ( '' === $xml_url ) {
			return '';
		}

		$parts = wp_parse_url( $xml_url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return '';
		}

		$path = preg_replace( '#/xml/[^/]+$#', '/', $parts['path'] );
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $parts['scheme'] . '://' . $parts['host'] . $port . $path;
	}

	/**
	 * Find an existing attachment by source URL.
	 *
	 * @param string $image_url Source URL.
	 * @return int
	 */
	private function find_existing_attachment_id( $image_url ) {
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => 1,
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'meta_key'       => HTX_XML_Woo_Sync_Plugin::META_IMAGE_SOURCE,
				'meta_value'     => $image_url,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		return ! empty( $existing ) ? (int) $existing[0] : 0;
	}

	/**
	 * Refresh an existing attachment when the remote image changed.
	 *
	 * @param int    $attachment_id Existing attachment ID.
	 * @param string $image_url     Absolute image URL.
	 * @param int    $post_id       Parent post ID.
	 * @param array  $settings      Plugin settings.
	 * @return int|WP_Error
	 */
	private function maybe_refresh_attachment( $attachment_id, $image_url, $post_id, $settings ) {
		$attachment_id     = absint( $attachment_id );
		$remote_signature  = $this->get_remote_image_signature( $image_url, $settings );
		$stored_signature  = $this->get_attachment_signature( $attachment_id );

		if ( $this->signatures_match( $stored_signature, $remote_signature ) ) {
			$this->persist_attachment_signature( $attachment_id, $image_url, $remote_signature );
			return $attachment_id;
		}

		$download = $this->download_image_to_temp( $image_url, $settings );
		if ( is_wp_error( $download ) ) {
			return $download;
		}

		$download_hash = $download['hash'];
		$current_hash  = $this->get_local_attachment_hash( $attachment_id );
		$stored_hash   = (string) get_post_meta( $attachment_id, self::META_IMAGE_REMOTE_HASH, true );

		if ( '' !== $download_hash && ( $download_hash === $stored_hash || $download_hash === $current_hash ) ) {
			$this->cleanup_temp_file( $download['tmp_name'] );
			$this->persist_attachment_signature( $attachment_id, $image_url, $download['signature'] );
			$this->persist_attachment_hash( $attachment_id, $download_hash );
			return $attachment_id;
		}

		return $this->create_attachment_from_download( $download, $post_id, $image_url );
	}

	/**
	 * Create a fresh attachment from a remote image URL.
	 *
	 * @param string $image_url Absolute image URL.
	 * @param int    $post_id   Parent post ID.
	 * @param array  $settings  Plugin settings.
	 * @return int|WP_Error
	 */
	private function create_attachment_from_remote_image( $image_url, $post_id, $settings ) {
		$download = $this->download_image_to_temp( $image_url, $settings );
		if ( is_wp_error( $download ) ) {
			return $download;
		}

		return $this->create_attachment_from_download( $download, $post_id, $image_url );
	}

	/**
	 * Turn a downloaded temp file into an attachment.
	 *
	 * @param array  $download  Download metadata.
	 * @param int    $post_id   Parent post ID.
	 * @param string $image_url Absolute image URL.
	 * @return int|WP_Error
	 */
	private function create_attachment_from_download( $download, $post_id, $image_url ) {
		$this->load_media_dependencies();

		$file_array = array(
			'name'     => $download['filename'],
			'type'     => $download['mime_type'],
			'tmp_name' => $download['tmp_name'],
			'error'    => 0,
			'size'     => file_exists( $download['tmp_name'] ) ? filesize( $download['tmp_name'] ) : 0,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id, null );
		if ( is_wp_error( $attachment_id ) ) {
			$this->cleanup_temp_file( $download['tmp_name'] );
			return new WP_Error(
				'htx_image_import_failed',
				sprintf(
					/* translators: 1: image URL, 2: error message */
					__( 'Image import failed for %1$s: %2$s', 'helikon-xml-woo-sync' ),
					$image_url,
					$attachment_id->get_error_message()
				),
				$attachment_id
			);
		}

		$this->persist_attachment_signature( $attachment_id, $image_url, $download['signature'] );
		$this->persist_attachment_hash( $attachment_id, $download['hash'] );

		return (int) $attachment_id;
	}

	/**
	 * Download a remote image to a temp file.
	 *
	 * @param string $image_url Absolute image URL.
	 * @param array  $settings  Plugin settings.
	 * @return array|WP_Error
	 */
	private function download_image_to_temp( $image_url, $settings ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temp_file = wp_tempnam( $image_url );
		if ( ! $temp_file ) {
			return new WP_Error( 'htx_image_temp_file', __( 'A temporary file could not be created for the image download.', 'helikon-xml-woo-sync' ) );
		}

		$response = wp_safe_remote_get(
			$image_url,
			array(
				'timeout'    => self::REMOTE_TIMEOUT,
				'headers'    => $this->get_request_headers_for_url( $image_url, $settings ),
				'user-agent' => 'Helikon XML Woo Sync/' . HTX_XML_WOO_SYNC_VERSION,
				'stream'     => true,
				'filename'   => $temp_file,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->cleanup_temp_file( $temp_file );
			return new WP_Error(
				'htx_image_download_failed',
				sprintf(
					/* translators: 1: image URL, 2: error message */
					__( 'Image download failed for %1$s: %2$s', 'helikon-xml-woo-sync' ),
					$image_url,
					$response->get_error_message()
				)
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$this->cleanup_temp_file( $temp_file );
			return new WP_Error(
				'htx_image_download_status',
				sprintf(
					/* translators: 1: image URL, 2: HTTP status code */
					__( 'Image download failed for %1$s (HTTP %2$d).', 'helikon-xml-woo-sync' ),
					$image_url,
					$status_code
				)
			);
		}

		if ( ! file_exists( $temp_file ) || 0 === filesize( $temp_file ) ) {
			$this->cleanup_temp_file( $temp_file );
			return new WP_Error(
				'htx_image_download_empty',
				sprintf(
					/* translators: %s: image URL */
					__( 'The downloaded image was empty for %s.', 'helikon-xml-woo-sync' ),
					$image_url
				)
			);
		}

		$mime_type = $this->normalize_mime_type( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$file_hash = md5_file( $temp_file );

		return array(
			'tmp_name'  => $temp_file,
			'filename'  => $this->build_download_filename( $image_url, $mime_type ),
			'mime_type' => $mime_type,
			'signature' => $this->extract_signature_from_response( $response ),
			'hash'      => $file_hash ? $file_hash : '',
		);
	}

	/**
	 * Get a lightweight remote image signature.
	 *
	 * @param string $image_url Absolute image URL.
	 * @param array  $settings  Plugin settings.
	 * @return array
	 */
	private function get_remote_image_signature( $image_url, $settings ) {
		$response = wp_safe_remote_head(
			$image_url,
			array(
				'timeout'    => 20,
				'headers'    => $this->get_request_headers_for_url( $image_url, $settings ),
				'user-agent' => 'Helikon XML Woo Sync/' . HTX_XML_WOO_SYNC_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		return $this->extract_signature_from_response( $response );
	}

	/**
	 * Extract remote signature headers.
	 *
	 * @param array $response HTTP response.
	 * @return array
	 */
	private function extract_signature_from_response( $response ) {
		return array(
			'etag'           => trim( (string) wp_remote_retrieve_header( $response, 'etag' ) ),
			'last_modified'  => trim( (string) wp_remote_retrieve_header( $response, 'last-modified' ) ),
			'content_length' => trim( (string) wp_remote_retrieve_header( $response, 'content-length' ) ),
		);
	}

	/**
	 * Read the stored signature metadata for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function get_attachment_signature( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		return array(
			'etag'           => (string) get_post_meta( $attachment_id, self::META_IMAGE_ETAG, true ),
			'last_modified'  => (string) get_post_meta( $attachment_id, self::META_IMAGE_LAST_MODIFIED, true ),
			'content_length' => (string) get_post_meta( $attachment_id, self::META_IMAGE_CONTENT_LENGTH, true ),
		);
	}

	/**
	 * Compare stored and remote signatures.
	 *
	 * @param array $stored_signature Stored signature values.
	 * @param array $remote_signature Remote signature values.
	 * @return bool
	 */
	private function signatures_match( $stored_signature, $remote_signature ) {
		$has_strong_match = false;

		foreach ( array( 'etag', 'last_modified' ) as $key ) {
			if ( empty( $stored_signature[ $key ] ) || empty( $remote_signature[ $key ] ) ) {
				continue;
			}

			if ( (string) $stored_signature[ $key ] !== (string) $remote_signature[ $key ] ) {
				return false;
			}

			$has_strong_match = true;
		}

		if ( ! $has_strong_match ) {
			return false;
		}

		if ( ! empty( $stored_signature['content_length'] ) && ! empty( $remote_signature['content_length'] ) ) {
			return (string) $stored_signature['content_length'] === (string) $remote_signature['content_length'];
		}

		return true;
	}

	/**
	 * Store source and signature metadata for an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $image_url     Absolute image URL.
	 * @param array  $signature     Signature payload.
	 * @return void
	 */
	private function persist_attachment_signature( $attachment_id, $image_url, $signature ) {
		update_post_meta( $attachment_id, HTX_XML_Woo_Sync_Plugin::META_IMAGE_SOURCE, esc_url_raw( $image_url ) );
		$this->update_meta_value( $attachment_id, self::META_IMAGE_ETAG, isset( $signature['etag'] ) ? $signature['etag'] : '' );
		$this->update_meta_value( $attachment_id, self::META_IMAGE_LAST_MODIFIED, isset( $signature['last_modified'] ) ? $signature['last_modified'] : '' );
		$this->update_meta_value( $attachment_id, self::META_IMAGE_CONTENT_LENGTH, isset( $signature['content_length'] ) ? $signature['content_length'] : '' );
	}

	/**
	 * Store a downloaded file hash for later refresh checks.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $hash          File hash.
	 * @return void
	 */
	private function persist_attachment_hash( $attachment_id, $hash ) {
		$this->update_meta_value( $attachment_id, self::META_IMAGE_REMOTE_HASH, $hash );
	}

	/**
	 * Update an attachment meta value or remove it when empty.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $meta_key      Meta key.
	 * @param string $value         Meta value.
	 * @return void
	 */
	private function update_meta_value( $attachment_id, $meta_key, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			delete_post_meta( $attachment_id, $meta_key );
			return;
		}

		update_post_meta( $attachment_id, $meta_key, $value );
	}

	/**
	 * Get the current local file hash for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function get_local_attachment_hash( $attachment_id ) {
		$file_path = get_attached_file( absint( $attachment_id ) );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return '';
		}

		$hash = md5_file( $file_path );
		return $hash ? $hash : '';
	}

	/**
	 * Build a safe filename for the downloaded image.
	 *
	 * @param string $image_url  Absolute image URL.
	 * @param string $mime_type  MIME type from the response.
	 * @return string
	 */
	private function build_download_filename( $image_url, $mime_type ) {
		$path     = (string) wp_parse_url( $image_url, PHP_URL_PATH );
		$filename = sanitize_file_name( wp_basename( $path ) );

		if ( '' !== $filename && false !== strpos( $filename, '.' ) ) {
			return $filename;
		}

		return 'htx-image-' . md5( $image_url ) . $this->mime_type_to_extension( $mime_type );
	}

	/**
	 * Normalize the content type header to a MIME type.
	 *
	 * @param string $content_type Raw content type header.
	 * @return string
	 */
	private function normalize_mime_type( $content_type ) {
		$content_type = trim( strtolower( (string) $content_type ) );
		if ( '' === $content_type ) {
			return '';
		}

		$parts = explode( ';', $content_type, 2 );
		return trim( $parts[0] );
	}

	/**
	 * Convert a MIME type into a common file extension.
	 *
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	private function mime_type_to_extension( $mime_type ) {
		$map = array(
			'image/jpeg'    => '.jpg',
			'image/jpg'     => '.jpg',
			'image/png'     => '.png',
			'image/gif'     => '.gif',
			'image/webp'    => '.webp',
			'image/bmp'     => '.bmp',
			'image/svg+xml' => '.svg',
		);

		return isset( $map[ $mime_type ] ) ? $map[ $mime_type ] : '.jpg';
	}

	/**
	 * Load the WordPress media dependencies when needed.
	 *
	 * @return void
	 */
	private function load_media_dependencies() {
		if ( function_exists( 'media_handle_sideload' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Build request headers for image URLs.
	 *
	 * @param string $image_url Absolute image URL.
	 * @param array  $settings  Plugin settings.
	 * @return array
	 */
	private function get_request_headers_for_url( $image_url, $settings ) {
		$headers = array();

		if ( '' === (string) $settings['username'] && '' === (string) $settings['password'] ) {
			return $headers;
		}

		if ( ! $this->should_send_basic_auth( $image_url, $settings ) ) {
			return $headers;
		}

		$headers['Authorization'] = 'Basic ' . base64_encode( $settings['username'] . ':' . $settings['password'] );

		return $headers;
	}

	/**
	 * Decide whether the saved Basic Auth credentials should be sent to this image URL.
	 *
	 * @param string $image_url Absolute image URL.
	 * @param array  $settings  Plugin settings.
	 * @return bool
	 */
	private function should_send_basic_auth( $image_url, $settings ) {
		$image_host = $this->normalize_host_for_compare( $image_url );
		if ( '' === $image_host ) {
			return false;
		}

		foreach ( array( $settings['xml_url'], $settings['media_base_url'] ) as $candidate_url ) {
			if ( $image_host === $this->normalize_host_for_compare( $candidate_url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize host and port for URL comparisons.
	 *
	 * @param string $url URL value.
	 * @return string
	 */
	private function normalize_host_for_compare( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $host . $port;
	}

	/**
	 * Delete a temp file if it still exists.
	 *
	 * @param string $temp_file Temp file path.
	 * @return void
	 */
	private function cleanup_temp_file( $temp_file ) {
		$temp_file = (string) $temp_file;
		if ( '' !== $temp_file && file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}
	}

	/**
	 * Get the saved source URL for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function get_attachment_source_url( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return '';
		}

		return (string) get_post_meta( $attachment_id, HTX_XML_Woo_Sync_Plugin::META_IMAGE_SOURCE, true );
	}
}
