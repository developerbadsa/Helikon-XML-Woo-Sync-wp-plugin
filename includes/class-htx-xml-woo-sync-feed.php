<?php
/**
 * Feed download and parsing helpers.
 *
 * @package Helikon_XML_Woo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTX_XML_Woo_Sync_Feed {
	/**
	 * State instance.
	 *
	 * @var HTX_XML_Woo_Sync_State
	 */
	private $state;

	/**
	 * Constructor.
	 *
	 * @param HTX_XML_Woo_Sync_State $state State service.
	 */
	public function __construct( $state ) {
		$this->state = $state;
	}

	/**
	 * Test the configured connection.
	 *
	 * @param array $settings Plugin settings.
	 * @return array|WP_Error
	 */
	public function test_connection( $settings ) {
		$request_url = trim( (string) $settings['xml_url'] );
		if ( '' === $request_url || ! wp_http_validate_url( $request_url ) ) {
			return new WP_Error( 'htx_test_url', __( 'Enter a valid XML URL before testing the connection.', 'helikon-xml-woo-sync' ) );
		}

		$response = wp_safe_remote_get(
			$request_url,
			array(
				'timeout'             => 25,
				'headers'             => $this->get_request_headers( $settings ),
				'user-agent'          => 'Helikon XML Woo Sync/' . HTX_XML_WOO_SYNC_VERSION,
				'limit_response_size' => 4096,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'htx_test_request', $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return $this->build_http_status_error( 'htx_test_status', $status_code, $response, $settings );
		}

		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		if ( '' === $body ) {
			return new WP_Error( 'htx_test_empty', __( 'The server responded, but the XML body was empty.', 'helikon-xml-woo-sync' ) );
		}

		return array(
			'message' => __( 'Connection succeeded and the server returned XML content.', 'helikon-xml-woo-sync' ),
		);
	}

	/**
	 * Download the remote feed and validate it before syncing.
	 *
	 * @param array  $settings Plugin settings.
	 * @param string $token    Sync token.
	 * @return array|WP_Error
	 */
	public function download_and_validate_feed( $settings, $token ) {
		$request_url = trim( (string) $settings['xml_url'] );
		if ( '' === $request_url || ! wp_http_validate_url( $request_url ) ) {
			return new WP_Error( 'htx_feed_url', __( 'The XML URL is missing or invalid.', 'helikon-xml-woo-sync' ) );
		}

		$runtime_dir = $this->state->get_runtime_dir();
		if ( is_wp_error( $runtime_dir ) ) {
			return $runtime_dir;
		}

		$feed_path = trailingslashit( $runtime_dir ) . 'feed-' . sanitize_file_name( $token ) . '.xml';
		if ( file_exists( $feed_path ) ) {
			wp_delete_file( $feed_path );
		}

		$response = wp_safe_remote_get(
			$request_url,
			array(
				'timeout'    => 60,
				'headers'    => $this->get_request_headers( $settings ),
				'user-agent' => 'Helikon XML Woo Sync/' . HTX_XML_WOO_SYNC_VERSION,
				'stream'     => true,
				'filename'   => $feed_path,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'htx_feed_request', $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			wp_delete_file( $feed_path );
			return $this->build_http_status_error( 'htx_feed_status', $status_code, $response, $settings );
		}

		if ( ! file_exists( $feed_path ) || 0 === filesize( $feed_path ) ) {
			wp_delete_file( $feed_path );
			return new WP_Error( 'htx_feed_empty', __( 'The downloaded XML file is empty.', 'helikon-xml-woo-sync' ) );
		}

		$validation = $this->validate_feed_file( $feed_path );
		if ( is_wp_error( $validation ) ) {
			wp_delete_file( $feed_path );
			return $validation;
		}

		return array(
			'path'           => $feed_path,
			'total_products' => (int) $validation['total_products'],
		);
	}

	/**
	 * Read one product batch from a validated local XML file.
	 *
	 * @param string $feed_path     Local feed file path.
	 * @param int    $offset        Product node offset.
	 * @param int    $limit         Batch size.
	 * @param array  $settings      Plugin settings.
	 * @param int    $runtime_limit Maximum runtime in seconds.
	 * @return array|WP_Error
	 */
	public function read_batch( $feed_path, $offset, $limit, $settings, $runtime_limit ) {
		if ( ! class_exists( 'XMLReader' ) ) {
			return new WP_Error( 'htx_xmlreader_missing', __( 'PHP XMLReader is required for batched feed processing.', 'helikon-xml-woo-sync' ) );
		}

		$reader = new XMLReader();
		$opened = $reader->open( $feed_path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA );
		if ( ! $opened ) {
			return new WP_Error( 'htx_feed_open', __( 'The local XML file could not be opened for processing.', 'helikon-xml-woo-sync' ) );
		}

		$start_time  = microtime( true );
		$offset      = max( 0, absint( $offset ) );
		$limit       = max( 1, absint( $limit ) );
		$current     = 0;
		$records     = array();
		$skip_count  = 0;
		$fail_count  = 0;
		$reached_end = true;

		libxml_use_internal_errors( true );

		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType || 'Product' !== $reader->localName ) {
				continue;
			}

			if ( $current < $offset ) {
				++$current;
				continue;
			}

			if ( count( $records ) >= $limit ) {
				$reached_end = false;
				break;
			}

			if ( microtime( true ) - $start_time >= $runtime_limit && ! empty( $records ) ) {
				$reached_end = false;
				break;
			}

			$record = $this->parse_product_xml( $reader->readOuterXML(), $settings );
			++$current;

			if ( is_wp_error( $record ) ) {
				if ( 'htx_missing_sku' === $record->get_error_code() ) {
					++$skip_count;
					$this->state->add_log( $record->get_error_message(), 'warning' );
				} else {
					++$fail_count;
					$this->state->add_log( $record->get_error_message(), 'error' );
				}

				continue;
			}

			$records[] = $record;
		}

		$errors = libxml_get_errors();
		libxml_clear_errors();
		$reader->close();

		if ( ! empty( $errors ) ) {
			$messages = array();
			foreach ( array_slice( $errors, 0, 3 ) as $error ) {
				$messages[] = trim( $error->message );
			}
			$this->state->add_log(
				__( 'XML parser warnings during batch read: ', 'helikon-xml-woo-sync' ) . implode( ' | ', $messages ),
				'warning'
			);
		}

		return array(
			'groups'             => $this->group_records( $records ),
			'next_offset'        => $current,
			'complete'           => $reached_end,
			'processed_products' => count( $records ) + $skip_count + $fail_count,
			'skipped'            => $skip_count,
			'failed'             => $fail_count,
		);
	}

	/**
	 * Validate the XML file without touching products.
	 *
	 * @param string $feed_path Local file path.
	 * @return array|WP_Error
	 */
	private function validate_feed_file( $feed_path ) {
		if ( ! class_exists( 'XMLReader' ) ) {
			return new WP_Error( 'htx_xmlreader_missing', __( 'PHP XMLReader is required for feed validation.', 'helikon-xml-woo-sync' ) );
		}

		$reader = new XMLReader();
		$opened = $reader->open( $feed_path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA );
		if ( ! $opened ) {
			return new WP_Error( 'htx_validate_open', __( 'The downloaded XML file could not be opened.', 'helikon-xml-woo-sync' ) );
		}

		$product_count = 0;
		libxml_use_internal_errors( true );

		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT === $reader->nodeType && 'Product' === $reader->localName ) {
				++$product_count;
			}
		}

		$errors = libxml_get_errors();
		libxml_clear_errors();
		$reader->close();

		if ( ! empty( $errors ) ) {
			$messages = array();
			foreach ( array_slice( $errors, 0, 3 ) as $error ) {
				$messages[] = trim( $error->message );
			}
			return new WP_Error( 'htx_invalid_xml', __( 'Invalid XML. The site was left untouched.', 'helikon-xml-woo-sync' ) . ' ' . implode( ' | ', $messages ) );
		}

		if ( 0 === $product_count ) {
			return new WP_Error( 'htx_no_products', __( 'No Product nodes were found in the XML feed.', 'helikon-xml-woo-sync' ) );
		}

		return array(
			'total_products' => $product_count,
		);
	}

	/**
	 * Parse one Product XML node.
	 *
	 * @param string $product_xml Product XML.
	 * @param array  $settings    Plugin settings.
	 * @return array|WP_Error
	 */
	public function parse_product_xml( $product_xml, $settings ) {
		$product_xml = trim( (string) $product_xml );
		if ( '' === $product_xml ) {
			return new WP_Error( 'htx_empty_product_node', __( 'Skipped an empty Product node.', 'helikon-xml-woo-sync' ) );
		}

		libxml_use_internal_errors( true );
		$product = simplexml_load_string( $product_xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		if ( false === $product ) {
			$messages = array();
			foreach ( array_slice( libxml_get_errors(), 0, 3 ) as $error ) {
				$messages[] = trim( $error->message );
			}
			libxml_clear_errors();
			return new WP_Error( 'htx_product_xml', __( 'Skipped a Product node because it could not be parsed.', 'helikon-xml-woo-sync' ) . ' ' . implode( ' | ', $messages ) );
		}

		$data = $this->xml_to_array( $product );
		$sku  = $this->get_mapped_scalar( $data, $settings, 'sku_path', 'SKU' );
		if ( '' === $sku ) {
			return new WP_Error( 'htx_missing_sku', __( 'Skipped a feed row because the SKU field is missing.', 'helikon-xml-woo-sync' ) );
		}

		$group = $this->build_group_context( $data, $settings, $sku );

		return array(
			'group_key'         => $group['group_key'],
			'group_external'    => $group['group_external'],
			'sku_base'          => $group['sku_base'],
			'title'             => $group['title'],
			'description'       => $group['description'],
			'brand'             => $group['brand'],
			'manufacturer'      => $group['manufacturer'],
			'product_line'      => $group['product_line'],
			'fabric_name'       => $group['fabric_name'],
			'fabric_composition'=> $group['fabric_composition'],
			'main_photo'        => $group['main_photo'],
			'additional_photos' => $group['additional_photos'],
			'item'              => array(
				'sku'           => $sku,
				'erp_id'        => $this->get_mapped_scalar( $data, $settings, 'erp_id_path', 'erpID' ),
				'ean'           => $this->normalize_scalar( $this->array_get( $data, 'aEAN' ) ),
				'hs_code'       => $this->normalize_scalar( $this->array_get( $data, 'KodPCN' ) ),
				'attributes'    => $this->extract_variant_attributes( $data, $settings ),
				'regular_price' => $this->extract_price( $data, $settings['price_path'], array( 'price', 'Price', 'grossPrice', 'GrossPrice', 'retailPrice', 'RetailPrice' ) ),
				'sale_price'    => $this->extract_price( $data, $settings['sale_price_path'], array( 'salePrice', 'SalePrice', 'promoPrice', 'PromoPrice' ) ),
				'stock_qty'     => $this->extract_numeric( $data, $settings['stock_qty_path'], array( 'stock', 'Stock', 'stockQty', 'StockQty', 'quantity', 'Quantity', 'qty', 'Qty' ) ),
				'stock_status'  => $this->extract_stock_status( $data, $settings ),
				'weight'        => $this->get_mapped_scalar( $data, $settings, 'weight_path', 'NetWeight' ),
				'weight_unit'   => $this->get_mapped_scalar( $data, $settings, 'weight_unit_path', 'WeightUnit' ),
				'main_photo'    => $group['main_photo'],
			),
		);
	}

	/**
	 * Group normalized records for one batch.
	 *
	 * @param array $records Normalized items.
	 * @return array
	 */
	private function group_records( $records ) {
		$groups = array();

		foreach ( $records as $record ) {
			$group_key = $record['group_key'];

			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array(
					'group_key'         => $record['group_key'],
					'group_external'    => $record['group_external'],
					'sku_base'          => $record['sku_base'],
					'title'             => $record['title'],
					'description'       => $record['description'],
					'brand'             => $record['brand'],
					'manufacturer'      => $record['manufacturer'],
					'product_line'      => $record['product_line'],
					'fabric_name'       => $record['fabric_name'],
					'fabric_composition'=> $record['fabric_composition'],
					'main_photo'        => $record['main_photo'],
					'additional_photos' => $record['additional_photos'],
					'items'             => array(),
					'attribute_union'   => array(),
				);
			}

			$groups[ $group_key ]['items'][] = $record['item'];
			foreach ( $record['item']['attributes'] as $label => $value ) {
				if ( '' === $value ) {
					continue;
				}
				if ( ! isset( $groups[ $group_key ]['attribute_union'][ $label ] ) ) {
					$groups[ $group_key ]['attribute_union'][ $label ] = array();
				}
				$groups[ $group_key ]['attribute_union'][ $label ][] = $value;
			}
		}

		foreach ( $groups as $group_key => $group ) {
			foreach ( $group['attribute_union'] as $label => $values ) {
				$groups[ $group_key ]['attribute_union'][ $label ] = array_values(
					array_unique(
						array_filter(
							array_map( 'strval', $values )
						)
					)
				);
			}
		}

		return $groups;
	}

	/**
	 * Build group context for a single row.
	 *
	 * @param array  $data     Normalized row data.
	 * @param array  $settings Plugin settings.
	 * @param string $sku      Product SKU.
	 * @return array
	 */
	private function build_group_context( $data, $settings, $sku ) {
		$title              = $this->get_mapped_scalar( $data, $settings, 'title_path', 'productName' );
		$brand              = $this->get_mapped_scalar( $data, $settings, 'brand_path', 'Brand' );
		$manufacturer       = $this->get_mapped_scalar( $data, $settings, 'manufacturer_path', 'Manufacturer' );
		$description        = $this->normalize_description( $this->get_mapped_scalar( $data, $settings, 'description_path', 'catalogDescription' ) );
		$product_line       = $this->normalize_scalar( $this->array_get( $data, 'productLine' ) );
		$fabric_name        = $this->normalize_scalar( $this->array_get( $data, 'Fabric.Name' ) );
		$fabric_composition = $this->normalize_scalar( $this->array_get( $data, 'Fabric.Composition' ) );
		$main_photo         = $this->get_mapped_scalar( $data, $settings, 'main_photo_path', 'Multimedia.mainPhoto' );
		$additional         = $this->get_mapped_array( $data, $settings, 'gallery_photo_path', 'Multimedia.additionalPhotos.photo' );
		$sku_base           = $this->derive_sku_base( $sku );
		$field_value        = $this->resolve_group_field_value( $data, $settings );

		switch ( $settings['grouping_mode'] ) {
			case 'field_only':
				$group_external = $field_value;
				break;
			case 'sku_base':
				$group_external = $sku_base;
				break;
			case 'name_and_sku_base':
				$group_external = trim( $title . '|' . $sku_base, '|' );
				break;
			case 'field_then_sku_base':
			default:
				$group_external = $field_value ? $field_value : $sku_base;
				break;
		}

		if ( '' === $group_external ) {
			$group_external = $sku;
		}

		return array(
			'group_key'         => md5( strtolower( $group_external ) ),
			'group_external'    => $group_external,
			'sku_base'          => $sku_base,
			'title'             => $title ? $title : $sku_base,
			'description'       => $description,
			'brand'             => $brand,
			'manufacturer'      => $manufacturer,
			'product_line'      => $product_line,
			'fabric_name'       => $fabric_name,
			'fabric_composition'=> $fabric_composition,
			'main_photo'        => $main_photo,
			'additional_photos' => $additional,
		);
	}

	/**
	 * Read a configured scalar path with a fallback.
	 *
	 * @param array  $data          Row data.
	 * @param array  $settings      Plugin settings.
	 * @param string $setting_key   Settings array key.
	 * @param string $fallback_path Fallback dot path.
	 * @return string
	 */
	private function get_mapped_scalar( $data, $settings, $setting_key, $fallback_path ) {
		$path = $this->get_setting_path( $settings, $setting_key, $fallback_path );
		return $this->normalize_scalar( $this->array_get( $data, $path ) );
	}

	/**
	 * Read a configured array path with a fallback.
	 *
	 * @param array  $data          Row data.
	 * @param array  $settings      Plugin settings.
	 * @param string $setting_key   Settings array key.
	 * @param string $fallback_path Fallback dot path.
	 * @return array
	 */
	private function get_mapped_array( $data, $settings, $setting_key, $fallback_path ) {
		$path = $this->get_setting_path( $settings, $setting_key, $fallback_path );
		return $this->normalize_to_array( $this->array_get( $data, $path ) );
	}

	/**
	 * Get a saved mapping path or a fallback.
	 *
	 * @param array  $settings      Plugin settings.
	 * @param string $setting_key   Settings array key.
	 * @param string $fallback_path Fallback path.
	 * @return string
	 */
	private function get_setting_path( $settings, $setting_key, $fallback_path ) {
		if ( ! empty( $settings[ $setting_key ] ) ) {
			return (string) $settings[ $setting_key ];
		}

		return (string) $fallback_path;
	}

	/**
	 * Resolve the grouping field value for a feed row.
	 *
	 * When no explicit grouping path is configured, try common group ID keys so
	 * existing installs can still link variants under the same parent.
	 *
	 * @param array $data     Row data.
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private function resolve_group_field_value( $data, $settings ) {
		$paths = array();

		if ( ! empty( $settings['grouping_path'] ) ) {
			$paths[] = (string) $settings['grouping_path'];
		}

		foreach ( array( 'groupId', 'groupID', 'GroupId', 'GroupID' ) as $fallback_path ) {
			if ( ! in_array( $fallback_path, $paths, true ) ) {
				$paths[] = $fallback_path;
			}
		}

		foreach ( $paths as $path ) {
			$value = $this->normalize_scalar( $this->array_get( $data, $path ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Extract configurable variation attributes.
	 *
	 * @param array $data     Row data.
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	private function extract_variant_attributes( $data, $settings ) {
		$attributes = array();
		$map_lines  = preg_split( '/\r\n|\r|\n/', (string) $settings['variant_attribute_map'] );

		foreach ( $map_lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}

			list( $path, $label ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' === $path || '' === $label ) {
				continue;
			}

			$value = $this->normalize_scalar( $this->array_get( $data, $path ) );
			if ( '' !== $value ) {
				$attributes[ $label ] = $value;
			}
		}

		return $attributes;
	}

	/**
	 * Extract a price from the feed.
	 *
	 * @param array  $data           Row data.
	 * @param string $custom_path    Custom path.
	 * @param array  $fallback_paths Fallback paths.
	 * @return string
	 */
	private function extract_price( $data, $custom_path, $fallback_paths ) {
		if ( $custom_path ) {
			$value = $this->normalize_scalar( $this->array_get( $data, $custom_path ) );
			if ( '' !== $value ) {
				return $this->normalize_price( $value );
			}
		}

		foreach ( $fallback_paths as $path ) {
			$value = $this->normalize_scalar( $this->array_get( $data, $path ) );
			if ( '' !== $value ) {
				return $this->normalize_price( $value );
			}
		}

		return '';
	}

	/**
	 * Extract a numeric field from the feed.
	 *
	 * @param array  $data           Row data.
	 * @param string $custom_path    Custom path.
	 * @param array  $fallback_paths Fallback paths.
	 * @return string
	 */
	private function extract_numeric( $data, $custom_path, $fallback_paths ) {
		if ( $custom_path ) {
			$value = $this->normalize_scalar( $this->array_get( $data, $custom_path ) );
			if ( '' !== $value ) {
				return $this->normalize_number( $value );
			}
		}

		foreach ( $fallback_paths as $path ) {
			$value = $this->normalize_scalar( $this->array_get( $data, $path ) );
			if ( '' !== $value ) {
				return $this->normalize_number( $value );
			}
		}

		return '';
	}

	/**
	 * Extract stock status from the feed.
	 *
	 * @param array $data     Row data.
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private function extract_stock_status( $data, $settings ) {
		$raw = '';
		if ( ! empty( $settings['stock_status_path'] ) ) {
			$raw = $this->normalize_scalar( $this->array_get( $data, $settings['stock_status_path'] ) );
		}

		if ( '' === $raw ) {
			foreach ( array( 'stockStatus', 'StockStatus', 'availability', 'Availability', 'available', 'Available' ) as $path ) {
				$raw = $this->normalize_scalar( $this->array_get( $data, $path ) );
				if ( '' !== $raw ) {
					break;
				}
			}
		}

		$raw = strtolower( trim( $raw ) );
		if ( '' !== $raw ) {
			if ( in_array( $raw, array( 'instock', 'in_stock', 'available', 'yes', '1', 'true' ), true ) ) {
				return 'instock';
			}

			if ( in_array( $raw, array( 'outofstock', 'out_of_stock', 'unavailable', 'no', '0', 'false' ), true ) ) {
				return 'outofstock';
			}
		}

		$qty = $this->extract_numeric( $data, $settings['stock_qty_path'], array( 'stock', 'Stock', 'stockQty', 'StockQty', 'quantity', 'Quantity', 'qty', 'Qty' ) );
		if ( '' !== $qty ) {
			return (float) $qty > 0 ? 'instock' : 'outofstock';
		}

		return '';
	}

	/**
	 * Create request headers.
	 *
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	private function get_request_headers( $settings ) {
		$headers = array();

		if ( '' !== (string) $settings['username'] || '' !== (string) $settings['password'] ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $settings['username'] . ':' . $settings['password'] );
		}

		return $headers;
	}

	/**
	 * Build a more actionable HTTP status error.
	 *
	 * @param string $error_code  Local error code.
	 * @param int    $status_code HTTP status code.
	 * @param array  $response    HTTP response array.
	 * @param array  $settings    Plugin settings.
	 * @return WP_Error
	 */
	private function build_http_status_error( $error_code, $status_code, $response, $settings ) {
		$message = sprintf(
			/* translators: %d: HTTP status code */
			__( 'The feed request returned HTTP %d.', 'helikon-xml-woo-sync' ),
			$status_code
		);

		if ( 401 === (int) $status_code ) {
			$auth_header       = (string) wp_remote_retrieve_header( $response, 'www-authenticate' );
			$has_saved_username = '' !== (string) $settings['username'];
			$has_saved_password = '' !== (string) $settings['password'];
			$uses_basic_auth   = false !== stripos( $auth_header, 'basic' );

			if ( $uses_basic_auth && ! $has_saved_username && ! $has_saved_password ) {
				$message = __( 'The feed returned HTTP 401 Unauthorized and the server is requesting HTTP Basic Auth. Save the XML URL, username, and password first, then test again.', 'helikon-xml-woo-sync' );
			} elseif ( $uses_basic_auth ) {
				$message = __( 'The feed returned HTTP 401 Unauthorized. The remote server rejected the saved Basic Auth username/password. If you just changed the fields below, click Save Settings first and then test again.', 'helikon-xml-woo-sync' );
			}
		}

		return new WP_Error( $error_code, $message );
	}

	/**
	 * Convert XML to a nested array.
	 *
	 * @param mixed $node XML node.
	 * @return mixed
	 */
	private function xml_to_array( $node ) {
		if ( ! $node instanceof SimpleXMLElement ) {
			return $node;
		}

		$children = $node->children();
		if ( 0 === count( $children ) ) {
			return trim( (string) $node );
		}

		$result = array();
		foreach ( $children as $child ) {
			$name  = $child->getName();
			$value = $this->xml_to_array( $child );

			if ( array_key_exists( $name, $result ) ) {
				if ( ! is_array( $result[ $name ] ) || ! array_key_exists( 0, $result[ $name ] ) ) {
					$result[ $name ] = array( $result[ $name ] );
				}
				$result[ $name ][] = $value;
			} else {
				$result[ $name ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Read a dot-separated path from a nested array.
	 *
	 * @param mixed  $data Array data.
	 * @param string $path Dot path.
	 * @return mixed|null
	 */
	private function array_get( $data, $path ) {
		if ( '' === (string) $path ) {
			return null;
		}

		$segments = explode( '.', (string) $path );
		$current  = $data;

		foreach ( $segments as $segment ) {
			if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
				$current = $current[ $segment ];
				continue;
			}

			return null;
		}

		return $current;
	}

	/**
	 * Normalize a scalar value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function normalize_scalar( $value ) {
		if ( is_array( $value ) ) {
			if ( array_key_exists( 0, $value ) ) {
				$first = reset( $value );
				return is_scalar( $first ) ? $this->repair_text( trim( (string) $first ) ) : '';
			}

			return '';
		}

		return is_scalar( $value ) ? $this->repair_text( trim( (string) $value ) ) : '';
	}

	/**
	 * Normalize an incoming value to an array of unique strings.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	private function normalize_to_array( $value ) {
		if ( null === $value ) {
			return array();
		}

		if ( is_array( $value ) ) {
			$items = array();
			foreach ( $value as $item ) {
				if ( is_array( $item ) ) {
					continue;
				}

				$item = $this->repair_text( trim( (string) $item ) );
				if ( '' !== $item ) {
					$items[] = $item;
				}
			}

			return array_values( array_unique( $items ) );
		}

		$value = $this->repair_text( trim( (string) $value ) );
		return '' === $value ? array() : array( $value );
	}

	/**
	 * Normalize a description.
	 *
	 * @param string $value Raw description.
	 * @return string
	 */
	private function normalize_description( $value ) {
		$value = str_replace( '[more]', '', (string) $value );
		return $this->repair_text( trim( $value ) );
	}

	/**
	 * Repair common mojibake sequences returned by the supplier feed.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	private function repair_text( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		return str_replace(
			array( 'Â®', 'Â©', 'Â°', 'Â·', 'â„¢', 'â€™', 'â€˜', 'â€œ', 'â€', 'â€“', 'â€”', 'â€¢' ),
			array( '®', '©', '°', '·', '™', "'", "'", '"', '"', '-', '-', '•' ),
			$value
		);
	}

	/**
	 * Normalize price.
	 *
	 * @param string $value Raw price.
	 * @return string
	 */
	private function normalize_price( $value ) {
		$number = $this->normalize_number( $value );
		if ( '' === $number ) {
			return '';
		}

		if ( function_exists( 'wc_format_decimal' ) && function_exists( 'wc_get_price_decimals' ) ) {
			return wc_format_decimal( $number, wc_get_price_decimals() );
		}

		return $number;
	}

	/**
	 * Normalize a number.
	 *
	 * @param string $value Raw number.
	 * @return string
	 */
	private function normalize_number( $value ) {
		$value = str_replace( array( ' ', "\xc2\xa0" ), '', (string) $value );
		$value = str_replace( ',', '.', $value );
		$value = preg_replace( '/[^0-9.\-]/', '', $value );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}

		return (string) $value;
	}

	/**
	 * Derive a stable base SKU for grouping.
	 *
	 * @param string $sku SKU value.
	 * @return string
	 */
	private function derive_sku_base( $sku ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			return '';
		}

		if ( preg_match( '/^(.+)-[^-]+$/', $sku, $matches ) ) {
			return $matches[1];
		}

		return $sku;
	}
}
