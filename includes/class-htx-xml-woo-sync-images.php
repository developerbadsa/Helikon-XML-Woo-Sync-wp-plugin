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
			$this->attachment_cache[ $image_url ] = $existing_id;
			return $existing_id;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $image_url, $post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error(
				'htx_image_import_failed',
				sprintf(
					/* translators: %s: image URL */
					__( 'Image import failed for %s.', 'helikon-xml-woo-sync' ),
					$image_url
				),
				$attachment_id
			);
		}

		update_post_meta( $attachment_id, HTX_XML_Woo_Sync_Plugin::META_IMAGE_SOURCE, esc_url_raw( $image_url ) );
		$this->attachment_cache[ $image_url ] = (int) $attachment_id;

		return (int) $attachment_id;
	}

	/**
	 * Merge gallery images without duplicating existing source URLs.
	 *
	 * @param array $existing_ids Existing attachment IDs.
	 * @param array $image_paths  Raw image paths.
	 * @param int   $post_id      Parent post ID.
	 * @param array $settings     Plugin settings.
	 * @return array
	 */
	public function merge_gallery_ids( $existing_ids, $image_paths, $post_id, $settings ) {
		$gallery_ids = array_values( array_unique( array_map( 'absint', (array) $existing_ids ) ) );
		$known_urls  = array();
		$image_paths = array_values( array_unique( array_filter( array_map( 'strval', (array) $image_paths ) ) ) );

		foreach ( $gallery_ids as $attachment_id ) {
			$source_url = $this->get_attachment_source_url( $attachment_id );
			if ( $source_url ) {
				$known_urls[] = $source_url;
			}
		}

		foreach ( $image_paths as $image_path ) {
			$image_url = $this->resolve_image_url( $image_path, $settings );
			if ( is_wp_error( $image_url ) ) {
				$this->state->add_log( $image_url->get_error_message(), 'warning' );
				continue;
			}

			if ( in_array( $image_url, $known_urls, true ) ) {
				continue;
			}

			$attachment_id = $this->import_image( $image_path, $post_id, $settings );
			if ( is_wp_error( $attachment_id ) ) {
				$this->state->add_log( $attachment_id->get_error_message(), 'warning' );
				continue;
			}

			$gallery_ids[] = (int) $attachment_id;
			$known_urls[]  = $image_url;
		}

		return array_values( array_unique( array_map( 'absint', $gallery_ids ) ) );
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
			)
		);

		return ! empty( $existing ) ? (int) $existing[0] : 0;
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
