<?php
/**
 * Supplemental SKU-based price list importer.
 *
 * @package Helikon_XML_Woo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTX_XML_Woo_Sync_Price_Importer {
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
	 * Import a supplemental CSV/XLSX price list.
	 *
	 * @param array  $file Uploaded file array.
	 * @param string $mode Import mode.
	 * @return array|WP_Error
	 */
	public function import_file( $file, $mode ) {
		if ( ! HTX_XML_Woo_Sync_Plugin::instance()->is_woocommerce_ready() ) {
			return new WP_Error( 'htx_woo_missing', __( 'WooCommerce is not active, so the price list import cannot run.', 'helikon-xml-woo-sync' ) );
		}

		$state = $this->state->get_state();
		$lock  = $this->state->get_lock();
		if ( ! empty( $state['is_running'] ) && ! $this->state->is_lock_stale( $lock ) ) {
			return new WP_Error( 'htx_price_sync_running', __( 'A sync is currently running. Wait for it to finish before importing a supplemental price list.', 'helikon-xml-woo-sync' ) );
		}

		if ( empty( $file ) || ! is_array( $file ) ) {
			return new WP_Error( 'htx_price_file_missing', __( 'Upload a CSV or XLSX price list before starting the import.', 'helikon-xml-woo-sync' ) );
		}

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'htx_price_file_upload', $this->get_upload_error_message( (int) $file['error'] ) );
		}

		if ( empty( $file['tmp_name'] ) || ! file_exists( $file['tmp_name'] ) ) {
			return new WP_Error( 'htx_price_file_tmp', __( 'The uploaded price list could not be read from the temporary upload location.', 'helikon-xml-woo-sync' ) );
		}

		$mode      = $this->sanitize_import_mode( $mode );
		$file_name = ! empty( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : 'price-list';
		$extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		$file_size = isset( $file['size'] ) ? absint( $file['size'] ) : 0;

		if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
			return new WP_Error( 'htx_price_file_type', __( 'Only CSV and XLSX price lists are supported.', 'helikon-xml-woo-sync' ) );
		}

		if ( $file_size < 1 ) {
			return new WP_Error( 'htx_price_file_empty', __( 'The uploaded price list file is empty.', 'helikon-xml-woo-sync' ) );
		}

		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file_name );
			$allowed  = array( 'csv', 'xlsx' );
			$detected = isset( $filetype['ext'] ) ? strtolower( (string) $filetype['ext'] ) : '';

			if ( '' !== $detected && ! in_array( $detected, $allowed, true ) ) {
				return new WP_Error( 'htx_price_file_detected_type', __( 'The uploaded file does not look like a valid CSV or XLSX spreadsheet.', 'helikon-xml-woo-sync' ) );
			}
		}

		$rows = 'xlsx' === $extension
			? $this->parse_xlsx_file( $file['tmp_name'] )
			: $this->parse_csv_file( $file['tmp_name'] );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$prepared = $this->prepare_rows( $rows );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		} elseif ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		return $this->apply_rows( $prepared, $mode, $file_name, $extension );
	}

	/**
	 * Sanitize the selected import mode.
	 *
	 * @param string $mode Raw mode.
	 * @return string
	 */
	public function sanitize_import_mode( $mode ) {
		$mode = sanitize_key( (string) $mode );

		if ( ! in_array( $mode, array( 'sync_prices', 'fill_missing', 'overwrite_all' ), true ) ) {
			return 'sync_prices';
		}

		return $mode;
	}

	/**
	 * Parse a CSV price list.
	 *
	 * @param string $path Local file path.
	 * @return array|WP_Error
	 */
	private function parse_csv_file( $path ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'htx_price_csv_open', __( 'The uploaded CSV price list could not be opened.', 'helikon-xml-woo-sync' ) );
		}

		$first_line = fgets( $handle );
		if ( false === $first_line ) {
			fclose( $handle );
			return new WP_Error( 'htx_price_csv_empty', __( 'The uploaded CSV price list is empty.', 'helikon-xml-woo-sync' ) );
		}

		rewind( $handle );
		$delimiter = $this->detect_csv_delimiter( $first_line );
		$headers   = fgetcsv( $handle, 0, $delimiter, '"', '\\' );
		if ( empty( $headers ) || ! is_array( $headers ) ) {
			fclose( $handle );
			return new WP_Error( 'htx_price_csv_headers', __( 'The CSV header row could not be read.', 'helikon-xml-woo-sync' ) );
		}

		$headers = $this->normalize_headers( $headers );
		if ( ! in_array( 'sku', $headers, true ) ) {
			fclose( $handle );
			return new WP_Error( 'htx_price_csv_sku_header', __( 'The uploaded CSV price list must contain a SKU column.', 'helikon-xml-woo-sync' ) );
		}

		$rows    = array();

		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			if ( empty( array_filter( $row, array( $this, 'row_value_has_content' ) ) ) ) {
				continue;
			}

			$row_data = array();
			foreach ( $headers as $index => $header_key ) {
				if ( '' === $header_key ) {
					continue;
				}

				$row_data[ $header_key ] = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
			}

			$rows[] = $row_data;
		}

		fclose( $handle );

		if ( empty( $rows ) ) {
			return new WP_Error( 'htx_price_csv_rows', __( 'The uploaded CSV price list does not contain any data rows.', 'helikon-xml-woo-sync' ) );
		}

		return $rows;
	}

	/**
	 * Parse an XLSX price list from the first worksheet.
	 *
	 * @param string $path Local file path.
	 * @return array|WP_Error
	 */
	private function parse_xlsx_file( $path ) {
		if ( class_exists( 'ZipArchive' ) ) {
			return $this->parse_xlsx_with_zip_archive( $path );
		}

		if ( defined( 'ABSPATH' ) && ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'unzip_file' ) || ! function_exists( 'trailingslashit' ) ) {
			return new WP_Error( 'htx_price_xlsx_zip', __( 'XLSX import requires either PHP ZipArchive or the WordPress unzip utilities.', 'helikon-xml-woo-sync' ) );
		}

		return $this->parse_xlsx_with_wordpress_unzip( $path );
	}

	/**
	 * Parse XLSX content via ZipArchive.
	 *
	 * @param string $path Local file path.
	 * @return array|WP_Error
	 */
	private function parse_xlsx_with_zip_archive( $path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'htx_price_xlsx_open', __( 'The uploaded XLSX price list could not be opened.', 'helikon-xml-woo-sync' ) );
		}

		$shared_strings = $this->read_xlsx_shared_strings( $zip );
		if ( is_wp_error( $shared_strings ) ) {
			$zip->close();
			return $shared_strings;
		}

		$sheet_path = $this->get_xlsx_first_sheet_path( $zip );
		if ( is_wp_error( $sheet_path ) ) {
			$zip->close();
			return $sheet_path;
		}

		$sheet_xml = $zip->getFromName( $sheet_path );
		$zip->close();

		if ( false === $sheet_xml || '' === $sheet_xml ) {
			return new WP_Error( 'htx_price_xlsx_sheet', __( 'The first worksheet in the XLSX price list could not be read.', 'helikon-xml-woo-sync' ) );
		}

		return $this->parse_xlsx_sheet_xml( $sheet_xml, $shared_strings );
	}

	/**
	 * Parse XLSX content via WordPress unzip utilities when ZipArchive is unavailable.
	 *
	 * @param string $path Local file path.
	 * @return array|WP_Error
	 */
	private function parse_xlsx_with_wordpress_unzip( $path ) {
		$runtime_dir = method_exists( $this->state, 'get_runtime_dir' ) ? $this->state->get_runtime_dir() : '';
		if ( is_wp_error( $runtime_dir ) ) {
			return $runtime_dir;
		}

		if ( '' === $runtime_dir ) {
			return new WP_Error( 'htx_price_xlsx_runtime', __( 'The runtime directory for XLSX extraction is not available.', 'helikon-xml-woo-sync' ) );
		}

		$temp_dir = trailingslashit( $runtime_dir ) . 'xlsx-import-' . uniqid( '', true );
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'htx_price_xlsx_temp_dir', __( 'A temporary directory for XLSX extraction could not be created.', 'helikon-xml-woo-sync' ) );
		}

		$unzipped = unzip_file( $path, $temp_dir );
		if ( is_wp_error( $unzipped ) ) {
			$this->delete_temp_directory( $temp_dir );
			return new WP_Error( 'htx_price_xlsx_unzip', $unzipped->get_error_message() );
		}

		$shared_strings = $this->read_extracted_xlsx_shared_strings( $temp_dir );
		if ( is_wp_error( $shared_strings ) ) {
			$this->delete_temp_directory( $temp_dir );
			return $shared_strings;
		}

		$sheet_path = $this->get_extracted_xlsx_first_sheet_path( $temp_dir );
		if ( is_wp_error( $sheet_path ) ) {
			$this->delete_temp_directory( $temp_dir );
			return $sheet_path;
		}

		$sheet_xml = file_get_contents( $sheet_path );
		$this->delete_temp_directory( $temp_dir );

		if ( false === $sheet_xml || '' === $sheet_xml ) {
			return new WP_Error( 'htx_price_xlsx_sheet', __( 'The first worksheet in the XLSX price list could not be read.', 'helikon-xml-woo-sync' ) );
		}

		return $this->parse_xlsx_sheet_xml( $sheet_xml, $shared_strings );
	}

	/**
	 * Parse worksheet XML into row arrays.
	 *
	 * @param string $sheet_xml       Worksheet XML content.
	 * @param array  $shared_strings  Shared strings table.
	 * @return array|WP_Error
	 */
	private function parse_xlsx_sheet_xml( $sheet_xml, $shared_strings ) {
		$previous = libxml_use_internal_errors( true );
		$sheet    = simplexml_load_string( $sheet_xml );
		if ( false === $sheet ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return new WP_Error( 'htx_price_xlsx_xml', __( 'The XLSX worksheet XML could not be parsed.', 'helikon-xml-woo-sync' ) );
		}

		$namespace_uri = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$sheet_data    = $sheet->children( $namespace_uri )->sheetData;
		$rows          = array();
		$headers       = array();

		if ( ! isset( $sheet_data->row ) ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return new WP_Error( 'htx_price_xlsx_rows', __( 'The XLSX price list does not contain any worksheet rows.', 'helikon-xml-woo-sync' ) );
		}

		$is_header = true;

		foreach ( $sheet_data->row as $row ) {
			$cells = array();

			foreach ( $row->children( $namespace_uri )->c as $cell ) {
				$reference = (string) $cell['r'];
				$column    = preg_replace( '/\d+/', '', $reference );
				if ( '' === $column ) {
					continue;
				}

				$column_index           = $this->xlsx_column_to_index( $column );
				$cells[ $column_index ] = $this->read_xlsx_cell_value( $cell, $shared_strings, $namespace_uri );
			}

			ksort( $cells );

			if ( $is_header ) {
				$headers = $this->normalize_headers( $cells );
				if ( ! in_array( 'sku', $headers, true ) ) {
					libxml_clear_errors();
					libxml_use_internal_errors( $previous );
					return new WP_Error( 'htx_price_xlsx_sku_header', __( 'The uploaded XLSX price list must contain a SKU column.', 'helikon-xml-woo-sync' ) );
				}
				$is_header = false;
				continue;
			}

			if ( empty( array_filter( $cells, array( $this, 'row_value_has_content' ) ) ) ) {
				continue;
			}

			$row_data = array();
			foreach ( $headers as $index => $header_key ) {
				if ( '' === $header_key ) {
					continue;
				}

				$row_data[ $header_key ] = isset( $cells[ $index ] ) ? trim( (string) $cells[ $index ] ) : '';
			}

			$rows[] = $row_data;
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( empty( $rows ) ) {
			return new WP_Error( 'htx_price_xlsx_data', __( 'The uploaded XLSX price list does not contain any data rows.', 'helikon-xml-woo-sync' ) );
		}

		return $rows;
	}

	/**
	 * Prepare parsed rows for SKU matching.
	 *
	 * @param array $rows Parsed raw rows.
	 * @return array|WP_Error
	 */
	private function prepare_rows( $rows ) {
		$rows_by_sku = array();
		$duplicates  = 0;
		$empty_skus  = 0;
		$row_total   = 0;

		foreach ( $rows as $row ) {
			++$row_total;

			$sku = $this->normalize_sku( $this->array_get_first( $row, array( 'sku' ) ) );
			if ( '' === $sku ) {
				++$empty_skus;
				continue;
			}

			if ( isset( $rows_by_sku[ $sku ] ) ) {
				++$duplicates;
			}

			$rows_by_sku[ $sku ] = array(
				'sku'               => $sku,
				'regular_price'     => $this->normalize_decimal( $this->array_get_first( $row, array( 'productregularprice', 'regularprice', 'price' ) ), true ),
				'regular_currency'  => strtoupper( $this->normalize_text( $this->array_get_first( $row, array( 'productregularcurrency', 'currency' ) ) ) ),
				'discount_price'    => $this->normalize_decimal( $this->array_get_first( $row, array( 'discountprice', 'saleprice' ) ), true ),
				'discount_currency' => strtoupper( $this->normalize_text( $this->array_get_first( $row, array( 'discountcurrency', 'salecurrency' ) ) ) ),
				'msrp_price'        => $this->normalize_decimal( $this->array_get_first( $row, array( 'productmsrpprice', 'msrpprice' ) ), true ),
				'msrp_currency'     => strtoupper( $this->normalize_text( $this->array_get_first( $row, array( 'productmsrpcurrency', 'msrpcurrency' ) ) ) ),
				'ean'               => $this->normalize_text( $this->array_get_first( $row, array( 'ean13', 'ean', 'aean' ) ) ),
				'hs_code'           => $this->normalize_text( $this->array_get_first( $row, array( 'cn', 'kodpcn', 'hscode' ) ) ),
				'weight'            => $this->normalize_decimal( $this->array_get_first( $row, array( 'productweight', 'weight', 'netweight' ) ) ),
				'weight_unit'       => $this->normalize_text( $this->array_get_first( $row, array( 'productweightunit', 'weightunit' ) ) ),
			);
		}

		if ( empty( $rows_by_sku ) ) {
			return new WP_Error( 'htx_price_sku_missing', __( 'The uploaded file does not contain any usable SKU rows. Make sure there is a SKU column.', 'helikon-xml-woo-sync' ) );
		}

		return array(
			'rows'       => $rows_by_sku,
			'row_total'  => $row_total,
			'unique'     => count( $rows_by_sku ),
			'duplicates' => $duplicates,
			'empty_skus' => $empty_skus,
		);
	}

	/**
	 * Apply prepared rows to WooCommerce products matched by SKU.
	 *
	 * @param array  $prepared  Prepared row payload.
	 * @param string $mode      Import mode.
	 * @param string $file_name Source file name.
	 * @param string $extension File extension.
	 * @return array|WP_Error
	 */
	private function apply_rows( $prepared, $mode, $file_name, $extension ) {
		$sku_to_product = $this->find_product_ids_by_skus( array_keys( $prepared['rows'] ) );
		$parent_ids     = array();
		$summary        = array(
			'file_name'   => $file_name,
			'file_type'   => $extension,
			'mode'        => $mode,
			'row_total'   => (int) $prepared['row_total'],
			'unique'      => (int) $prepared['unique'],
			'duplicates'  => (int) $prepared['duplicates'],
			'empty_skus'  => (int) $prepared['empty_skus'],
			'matched'     => 0,
			'updated'     => 0,
			'unchanged'   => 0,
			'unmatched'   => 0,
			'failed'      => 0,
		);

		foreach ( $prepared['rows'] as $sku => $row ) {
			if ( empty( $sku_to_product[ $sku ] ) ) {
				++$summary['unmatched'];
				continue;
			}

			++$summary['matched'];

			try {
				$product = wc_get_product( (int) $sku_to_product[ $sku ] );
				if ( ! $product instanceof WC_Product ) {
					++$summary['failed'];
					continue;
				}

				$result = $this->apply_row_to_product( $product, $row, $mode );
				if ( empty( $result['changed'] ) ) {
					++$summary['unchanged'];
					continue;
				}

				++$summary['updated'];

				if ( $product instanceof WC_Product_Variation ) {
					$parent_ids[] = $product->get_parent_id();
				}
			} catch ( \Throwable $exception ) {
				++$summary['failed'];
				$this->state->add_log(
					sprintf(
						/* translators: 1: SKU, 2: error message */
						__( 'Supplemental price import failed for SKU %1$s: %2$s', 'helikon-xml-woo-sync' ),
						$sku,
						$exception->getMessage()
					),
					'error'
				);
			}
		}

		foreach ( array_unique( array_filter( array_map( 'absint', $parent_ids ) ) ) as $parent_id ) {
			if ( method_exists( 'WC_Product_Variable', 'sync' ) ) {
				WC_Product_Variable::sync( $parent_id );
			}
			wc_delete_product_transients( $parent_id );
		}

		$this->log_import_summary( $summary );

		return $summary;
	}

	/**
	 * Apply a single supplemental row to a WooCommerce product.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $row     Normalized row data.
	 * @param string     $mode    Import mode.
	 * @return array
	 */
	private function apply_row_to_product( $product, $row, $mode ) {
		$changed      = false;
		$meta_updates = array();
		$product_id   = (int) $product->get_id();

		$regular_price = $row['regular_price'];
		$sale_price    = $row['discount_price'];

		if ( '' === $regular_price && '' !== $sale_price ) {
			$regular_price = $sale_price;
			$sale_price    = '';
		}

		$regular_price_current = (string) $product->get_regular_price( 'edit' );
		$sale_price_current    = (string) $product->get_sale_price( 'edit' );
		$target_sale_price     = '';

		if ( '' !== $regular_price ) {
			if ( $this->should_update_price_related_value( $regular_price_current, $mode ) && $regular_price_current !== $regular_price ) {
				$product->set_regular_price( $regular_price );
				$regular_price_current = $regular_price;
				$changed               = true;
			}

			if ( '' !== $sale_price && $this->is_effective_sale_price( $regular_price_current ? $regular_price_current : $regular_price, $sale_price ) ) {
				$target_sale_price = $sale_price;
			}

			if ( 'fill_missing' === $mode ) {
				if ( '' !== $target_sale_price && '' === $sale_price_current ) {
					$product->set_sale_price( $target_sale_price );
					$sale_price_current = $target_sale_price;
					$changed            = true;
				}

				$active_price = '' !== $sale_price_current ? $sale_price_current : $regular_price_current;
				if ( '' !== $active_price && (string) $product->get_price( 'edit' ) !== $active_price ) {
					$product->set_price( $active_price );
					$changed = true;
				}
			} else {
				if ( $sale_price_current !== $target_sale_price ) {
					$product->set_sale_price( $target_sale_price );
					$sale_price_current = $target_sale_price;
					$changed            = true;
				}

				$active_price = '' !== $target_sale_price ? $target_sale_price : $regular_price_current;
				if ( '' !== $active_price && (string) $product->get_price( 'edit' ) !== $active_price ) {
					$product->set_price( $active_price );
					$changed = true;
				}
			}
		}

		$changed = $this->queue_meta_if_needed( $meta_updates, $product_id, HTX_XML_Woo_Sync_Plugin::META_PRICE_CURRENCY, $row['regular_currency'], $mode, true ) || $changed;
		$changed = $this->queue_meta_if_needed( $meta_updates, $product_id, HTX_XML_Woo_Sync_Plugin::META_SALE_CURRENCY, $row['discount_currency'], $mode, true ) || $changed;
		$changed = $this->queue_meta_if_needed( $meta_updates, $product_id, HTX_XML_Woo_Sync_Plugin::META_MSRP_PRICE, $row['msrp_price'], $mode, true ) || $changed;
		$changed = $this->queue_meta_if_needed( $meta_updates, $product_id, HTX_XML_Woo_Sync_Plugin::META_MSRP_CURRENCY, $row['msrp_currency'], $mode, true ) || $changed;
		$changed = $this->queue_meta_if_needed( $meta_updates, $product_id, '_htx_hs_code', $row['hs_code'], $mode ) || $changed;
		$changed = $this->queue_meta_if_needed( $meta_updates, $product_id, '_htx_weight_unit', $row['weight_unit'], $mode ) || $changed;
		$changed = $this->queue_meta_if_needed( $meta_updates, $product_id, '_htx_ean', $row['ean'], $mode ) || $changed;

		if ( '' !== $row['weight'] && $this->should_update_meta_value( (string) $product->get_weight( 'edit' ), $mode ) && (string) $product->get_weight( 'edit' ) !== $row['weight'] ) {
			$product->set_weight( $row['weight'] );
			$changed = true;
		}

		if ( '' !== $row['ean'] && $this->product_supports_unique_id( $product ) ) {
			$current_unique_id = $this->get_product_unique_id( $product );
			if ( $this->should_update_meta_value( $current_unique_id, $mode ) && $current_unique_id !== $row['ean'] ) {
				$product->set_global_unique_id( $row['ean'] );
				$changed = true;
			}
		}

		if ( $changed ) {
			$product->save();
		}

		foreach ( $meta_updates as $meta_key => $meta_value ) {
			update_post_meta( $product_id, $meta_key, $meta_value );
		}

		return array(
			'changed' => $changed,
		);
	}

	/**
	 * Queue a meta update when the chosen mode allows it.
	 *
	 * @param array  $meta_updates     Queued meta updates.
	 * @param int    $object_id        Object ID.
	 * @param string $meta_key         Meta key.
	 * @param string $value            Target value.
	 * @param string $mode             Import mode.
	 * @param bool   $is_price_related Whether this is a price-related field.
	 * @return bool
	 */
	private function queue_meta_if_needed( &$meta_updates, $object_id, $meta_key, $value, $mode, $is_price_related = false ) {
		$value   = $this->normalize_text( $value );
		$current = $this->normalize_text( get_post_meta( $object_id, $meta_key, true ) );

		if ( '' === $value || ! $this->should_update_value( $current, $mode, $is_price_related ) || $current === $value ) {
			return false;
		}

		$meta_updates[ $meta_key ] = $value;
		return true;
	}

	/**
	 * Determine whether the current value should be replaced.
	 *
	 * @param string $current          Current value.
	 * @param string $mode             Import mode.
	 * @param bool   $is_price_related Whether the field is price-related.
	 * @return bool
	 */
	private function should_update_value( $current, $mode, $is_price_related = false ) {
		$current = $this->normalize_text( $current );

		if ( 'overwrite_all' === $mode ) {
			return true;
		}

		if ( $is_price_related && 'sync_prices' === $mode ) {
			return true;
		}

		return '' === $current;
	}

	/**
	 * Determine whether a price-related property should be updated.
	 *
	 * @param string $current Current value.
	 * @param string $mode    Import mode.
	 * @return bool
	 */
	private function should_update_price_related_value( $current, $mode ) {
		return $this->should_update_value( $current, $mode, true );
	}

	/**
	 * Determine whether a non-price metadata value should be updated.
	 *
	 * @param string $current Current value.
	 * @param string $mode    Import mode.
	 * @return bool
	 */
	private function should_update_meta_value( $current, $mode ) {
		return $this->should_update_value( $current, $mode, false );
	}

	/**
	 * Check whether a discount price is a real sale price.
	 *
	 * @param string $regular_price Regular price.
	 * @param string $sale_price    Sale price.
	 * @return bool
	 */
	private function is_effective_sale_price( $regular_price, $sale_price ) {
		if ( '' === $regular_price || '' === $sale_price ) {
			return false;
		}

		return (float) $sale_price < (float) $regular_price;
	}

	/**
	 * Find WooCommerce objects by SKU in chunks.
	 *
	 * @param array $skus SKU list.
	 * @return array
	 */
	private function find_product_ids_by_skus( $skus ) {
		global $wpdb;

		$matches = array();
		$skus    = array_values( array_unique( array_filter( array_map( array( $this, 'normalize_sku' ), (array) $skus ) ) ) );

		foreach ( array_chunk( $skus, 250 ) as $sku_chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $sku_chunk ), '%s' ) );
			$query        = $wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_sku'
					AND pm.meta_value IN ($placeholders)
					AND p.post_type IN ('product', 'product_variation')
					AND p.post_status <> 'trash'",
				$sku_chunk
			);
			$results      = $wpdb->get_results( $query, ARRAY_A );

			foreach ( (array) $results as $result ) {
				$sku = isset( $result['meta_value'] ) ? $this->normalize_sku( $result['meta_value'] ) : '';
				if ( '' !== $sku && ! isset( $matches[ $sku ] ) ) {
					$matches[ $sku ] = (int) $result['post_id'];
				}
			}
		}

		return $matches;
	}

	/**
	 * Log a concise import summary.
	 *
	 * @param array $summary Import summary.
	 * @return void
	 */
	private function log_import_summary( $summary ) {
		$this->state->add_log(
			sprintf(
				/* translators: 1: file name, 2: updated count, 3: matched count, 4: unmatched count, 5: failed count */
				__( 'Supplemental price list "%1$s" imported. Updated %2$d matched SKUs, matched %3$d total, left %4$d unmatched, and hit %5$d failures.', 'helikon-xml-woo-sync' ),
				$summary['file_name'],
				(int) $summary['updated'],
				(int) $summary['matched'],
				(int) $summary['unmatched'],
				(int) $summary['failed']
			)
		);

		if ( ! empty( $summary['duplicates'] ) ) {
			$this->state->add_log(
				sprintf(
					/* translators: %d: duplicate row count */
					__( 'The uploaded price list contained %d duplicate SKUs. The last row for each duplicate SKU was used.', 'helikon-xml-woo-sync' ),
					(int) $summary['duplicates']
				),
				'warning'
			);
		}

		if ( ! empty( $summary['empty_skus'] ) ) {
			$this->state->add_log(
				sprintf(
					/* translators: %d: row count */
					__( '%d supplemental price rows were skipped because the SKU column was empty.', 'helikon-xml-woo-sync' ),
					(int) $summary['empty_skus']
				),
				'warning'
			);
		}
	}

	/**
	 * Normalize an array of headers.
	 *
	 * @param array $headers Raw headers.
	 * @return array
	 */
	private function normalize_headers( $headers ) {
		$normalized = array();

		foreach ( (array) $headers as $header ) {
			$normalized[] = $this->normalize_header_key( $header );
		}

		return $normalized;
	}

	/**
	 * Normalize a header key.
	 *
	 * @param string $header Raw header.
	 * @return string
	 */
	private function normalize_header_key( $header ) {
		$header = trim( (string) $header );
		$header = preg_replace( '/^\xEF\xBB\xBF/', '', $header );
		$header = strtolower( $header );
		$header = preg_replace( '/[^a-z0-9]+/', '', $header );

		return (string) $header;
	}

	/**
	 * Normalize free text.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalize_text( $value ) {
		return trim( (string) $value );
	}

	/**
	 * Normalize a SKU key.
	 *
	 * @param string $sku Raw SKU.
	 * @return string
	 */
	private function normalize_sku( $sku ) {
		return strtoupper( trim( (string) $sku ) );
	}

	/**
	 * Normalize a decimal number.
	 *
	 * @param string $value Raw number.
	 * @return string
	 */
	private function normalize_decimal( $value, $format_for_price = false ) {
		$value = str_replace( array( ' ', "\xc2\xa0" ), '', (string) $value );
		$value = str_replace( ',', '.', $value );
		$value = preg_replace( '/[^0-9.\-]/', '', $value );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}

		if ( $format_for_price && function_exists( 'wc_format_decimal' ) && function_exists( 'wc_get_price_decimals' ) ) {
			return wc_format_decimal( $value, wc_get_price_decimals() );
		}

		return (string) $value;
	}

	/**
	 * Get the first available value from a row.
	 *
	 * @param array $row        Row data.
	 * @param array $candidates Candidate keys.
	 * @return string
	 */
	private function array_get_first( $row, $candidates ) {
		foreach ( (array) $candidates as $candidate ) {
			if ( isset( $row[ $candidate ] ) && '' !== trim( (string) $row[ $candidate ] ) ) {
				return (string) $row[ $candidate ];
			}
		}

		return '';
	}

	/**
	 * Detect the most likely CSV delimiter from the first line.
	 *
	 * @param string $first_line First CSV line.
	 * @return string
	 */
	private function detect_csv_delimiter( $first_line ) {
		$candidates = array( ';', ',', "\t" );
		$best_match = ';';
		$best_count = 0;

		foreach ( $candidates as $candidate ) {
			$count = count( str_getcsv( (string) $first_line, $candidate, '"', '\\' ) );
			if ( $count > $best_count ) {
				$best_count = $count;
				$best_match = $candidate;
			}
		}

		return $best_match;
	}

	/**
	 * Determine whether a row value should count as content.
	 *
	 * @param mixed $value Row value.
	 * @return bool
	 */
	private function row_value_has_content( $value ) {
		return '' !== trim( (string) $value );
	}

	/**
	 * Read extracted XLSX shared strings from a directory.
	 *
	 * @param string $directory Extracted XLSX directory.
	 * @return array|WP_Error
	 */
	private function read_extracted_xlsx_shared_strings( $directory ) {
		$shared_strings_path = trailingslashit( $directory ) . 'xl/sharedStrings.xml';
		if ( ! file_exists( $shared_strings_path ) ) {
			return array();
		}

		$shared_strings_xml = file_get_contents( $shared_strings_path );
		if ( false === $shared_strings_xml ) {
			return new WP_Error( 'htx_price_xlsx_shared', __( 'The extracted XLSX shared string table could not be read.', 'helikon-xml-woo-sync' ) );
		}

		return $this->parse_shared_strings_xml( $shared_strings_xml );
	}

	/**
	 * Find the first extracted worksheet path.
	 *
	 * @param string $directory Extracted XLSX directory.
	 * @return string|WP_Error
	 */
	private function get_extracted_xlsx_first_sheet_path( $directory ) {
		$workbook_path = trailingslashit( $directory ) . 'xl/workbook.xml';
		$rels_path     = trailingslashit( $directory ) . 'xl/_rels/workbook.xml.rels';

		if ( ! file_exists( $workbook_path ) || ! file_exists( $rels_path ) ) {
			return new WP_Error( 'htx_price_xlsx_workbook', __( 'The extracted XLSX workbook metadata could not be read.', 'helikon-xml-woo-sync' ) );
		}

		$workbook_xml = file_get_contents( $workbook_path );
		$rels_xml     = file_get_contents( $rels_path );
		if ( false === $workbook_xml || false === $rels_xml ) {
			return new WP_Error( 'htx_price_xlsx_workbook', __( 'The extracted XLSX workbook metadata could not be read.', 'helikon-xml-woo-sync' ) );
		}

		$sheet_path = $this->resolve_first_sheet_path_from_xml( $workbook_xml, $rels_xml );
		if ( is_wp_error( $sheet_path ) ) {
			return $sheet_path;
		}

		$absolute_sheet_path = trailingslashit( $directory ) . ltrim( $sheet_path, '/' );
		if ( ! file_exists( $absolute_sheet_path ) ) {
			return new WP_Error( 'htx_price_xlsx_sheet', __( 'The extracted XLSX worksheet file could not be found.', 'helikon-xml-woo-sync' ) );
		}

		return $absolute_sheet_path;
	}

	/**
	 * Recursively delete a temporary directory.
	 *
	 * @param string $directory Directory path.
	 * @return void
	 */
	private function delete_temp_directory( $directory ) {
		$directory = (string) $directory;
		if ( '' === $directory || ! is_dir( $directory ) ) {
			return;
		}

		$items = scandir( $directory );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = trailingslashit( $directory ) . $item;
			if ( is_dir( $path ) ) {
				$this->delete_temp_directory( $path );
				continue;
			}

			@unlink( $path );
		}

		@rmdir( $directory );
	}

	/**
	 * Read the shared strings table from an XLSX file.
	 *
	 * @param ZipArchive $zip Open zip instance.
	 * @return array|WP_Error
	 */
	private function read_xlsx_shared_strings( $zip ) {
		$shared_strings_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $shared_strings_xml || '' === $shared_strings_xml ) {
			return array();
		}

		return $this->parse_shared_strings_xml( $shared_strings_xml );
	}

	/**
	 * Find the first worksheet path in an XLSX file.
	 *
	 * @param ZipArchive $zip Open zip instance.
	 * @return string|WP_Error
	 */
	private function get_xlsx_first_sheet_path( $zip ) {
		$workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
		$rels_xml     = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );

		if ( false === $workbook_xml || false === $rels_xml ) {
			return new WP_Error( 'htx_price_xlsx_workbook', __( 'The XLSX workbook metadata could not be read.', 'helikon-xml-woo-sync' ) );
		}

		return $this->resolve_first_sheet_path_from_xml( $workbook_xml, $rels_xml );
	}

	/**
	 * Parse the shared strings XML for an XLSX document.
	 *
	 * @param string $shared_strings_xml Shared strings XML.
	 * @return array|WP_Error
	 */
	private function parse_shared_strings_xml( $shared_strings_xml ) {
		$previous = libxml_use_internal_errors( true );
		$shared_strings = simplexml_load_string( $shared_strings_xml );
		if ( false === $shared_strings ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return new WP_Error( 'htx_price_xlsx_shared', __( 'The XLSX shared string table could not be parsed.', 'helikon-xml-woo-sync' ) );
		}

		$namespace_uri = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$values        = array();

		foreach ( $shared_strings->children( $namespace_uri )->si as $string_item ) {
			$text = '';

			if ( isset( $string_item->children( $namespace_uri )->t ) ) {
				$text = (string) $string_item->children( $namespace_uri )->t;
			} elseif ( isset( $string_item->children( $namespace_uri )->r ) ) {
				foreach ( $string_item->children( $namespace_uri )->r as $run ) {
					$text .= (string) $run->children( $namespace_uri )->t;
				}
			}

			$values[] = $text;
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $values;
	}

	/**
	 * Resolve the first worksheet path from workbook XML strings.
	 *
	 * @param string $workbook_xml Workbook XML content.
	 * @param string $rels_xml     Workbook relationships XML.
	 * @return string|WP_Error
	 */
	private function resolve_first_sheet_path_from_xml( $workbook_xml, $rels_xml ) {
		$previous = libxml_use_internal_errors( true );
		$workbook = simplexml_load_string( $workbook_xml );
		$rels     = simplexml_load_string( $rels_xml );
		if ( false === $workbook || false === $rels ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return new WP_Error( 'htx_price_xlsx_workbook_xml', __( 'The XLSX workbook metadata could not be parsed.', 'helikon-xml-woo-sync' ) );
		}

		$workbook_namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$relationships      = array();

		foreach ( $rels->children( 'http://schemas.openxmlformats.org/package/2006/relationships' )->Relationship as $relationship ) {
			$attributes                                  = $relationship->attributes();
			$relationships[ (string) $attributes['Id'] ] = 'xl/' . ltrim( (string) $attributes['Target'], '/' );
		}

		$children = $workbook->children( $workbook_namespace );
		if ( ! isset( $children->sheets->sheet[0] ) ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return new WP_Error( 'htx_price_xlsx_sheet_missing', __( 'The XLSX price list does not contain a worksheet.', 'helikon-xml-woo-sync' ) );
		}

		$sheet_attributes = $children->sheets->sheet[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', true );
		$relationship_id  = isset( $sheet_attributes['id'] ) ? (string) $sheet_attributes['id'] : '';

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( '' === $relationship_id || empty( $relationships[ $relationship_id ] ) ) {
			return new WP_Error( 'htx_price_xlsx_sheet_rel', __( 'The first XLSX worksheet relationship could not be resolved.', 'helikon-xml-woo-sync' ) );
		}

		return $relationships[ $relationship_id ];
	}

	/**
	 * Read a worksheet cell value from XLSX XML.
	 *
	 * @param SimpleXMLElement $cell           Cell node.
	 * @param array            $shared_strings Shared strings table.
	 * @param string           $namespace_uri  Worksheet namespace.
	 * @return string
	 */
	private function read_xlsx_cell_value( $cell, $shared_strings, $namespace_uri ) {
		$type     = (string) $cell['t'];
		$children = $cell->children( $namespace_uri );

		if ( 'inlineStr' === $type && isset( $children->is->t ) ) {
			return (string) $children->is->t;
		}

		if ( ! isset( $children->v ) ) {
			return '';
		}

		$value = (string) $children->v;
		if ( 's' === $type ) {
			$index = absint( $value );
			return isset( $shared_strings[ $index ] ) ? (string) $shared_strings[ $index ] : '';
		}

		return $value;
	}

	/**
	 * Convert an XLSX column label to a zero-based index.
	 *
	 * @param string $column Column label.
	 * @return int
	 */
	private function xlsx_column_to_index( $column ) {
		$column = strtoupper( (string) $column );
		$index  = 0;

		for ( $i = 0, $length = strlen( $column ); $i < $length; $i++ ) {
			$index = ( $index * 26 ) + ( ord( $column[ $i ] ) - 64 );
		}

		return max( 0, $index - 1 );
	}

	/**
	 * Check whether the product supports a WooCommerce global unique ID.
	 *
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	private function product_supports_unique_id( $product ) {
		return method_exists( $product, 'set_global_unique_id' ) && method_exists( $product, 'get_global_unique_id' );
	}

	/**
	 * Read the current global unique ID safely.
	 *
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	private function get_product_unique_id( $product ) {
		if ( ! $this->product_supports_unique_id( $product ) ) {
			return '';
		}

		try {
			return (string) $product->get_global_unique_id( 'edit' );
		} catch ( \Throwable $exception ) {
			return '';
		}
	}

	/**
	 * Get a user-friendly upload error message.
	 *
	 * @param int $error_code PHP upload error code.
	 * @return string
	 */
	private function get_upload_error_message( $error_code ) {
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The uploaded price list is larger than the server upload limit.', 'helikon-xml-woo-sync' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The uploaded price list was only partially received. Please try again.', 'helikon-xml-woo-sync' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Choose a CSV or XLSX price list before starting the import.', 'helikon-xml-woo-sync' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'The server temporary upload directory is missing.', 'helikon-xml-woo-sync' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the uploaded price list to disk.', 'helikon-xml-woo-sync' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'A server extension stopped the uploaded price list.', 'helikon-xml-woo-sync' );
			default:
				return __( 'The uploaded price list could not be processed.', 'helikon-xml-woo-sync' );
		}
	}
}
