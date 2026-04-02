<?php
/**
 * State and settings storage.
 *
 * @package Helikon_XML_Woo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTX_XML_Woo_Sync_State {
	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	public function get_default_settings() {
		return array(
			'xml_url'               => 'https://media.helikon-tex.com/partners/products-photos/xml/CustomerData_en.xml',
			'username'              => '',
			'password'              => '',
			'media_base_url'        => '',
			'sku_path'              => 'SKU',
			'title_path'            => 'productName',
			'description_path'      => 'catalogDescription',
			'brand_path'            => 'Brand',
			'manufacturer_path'     => 'Manufacturer',
			'main_photo_path'       => 'Multimedia.mainPhoto',
			'gallery_photo_path'    => 'Multimedia.additionalPhotos.photo',
			'erp_id_path'           => 'erpID',
			'weight_path'           => 'NetWeight',
			'weight_unit_path'      => 'WeightUnit',
			'grouping_mode'         => 'field_then_sku_base',
			'grouping_path'         => 'groupId',
			'variant_attribute_map' => "size.tagEU=Size\nsize.tagUSA=US Size\ncolor.name=Color",
			'price_path'            => '',
			'sale_price_path'       => '',
			'stock_qty_path'        => '',
			'stock_status_path'     => '',
			'test_sync_limit'       => 20,
			'schedule'              => 'daily',
			'batch_size'            => 20,
			'missing_action'        => 'ignore',
		);
	}

	/**
	 * Get saved settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		return wp_parse_args(
			get_option( HTX_XML_Woo_Sync_Plugin::OPTION_KEY, array() ),
			$this->get_default_settings()
		);
	}

	/**
	 * Sanitize plugin settings.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$defaults = $this->get_default_settings();
		$output   = array(
			'xml_url'               => isset( $input['xml_url'] ) ? esc_url_raw( trim( (string) $input['xml_url'] ) ) : $defaults['xml_url'],
			'username'              => isset( $input['username'] ) ? trim( wp_unslash( (string) $input['username'] ) ) : '',
			// Preserve the exact password for Basic Auth. Escaping happens on output.
			'password'              => isset( $input['password'] ) ? wp_unslash( (string) $input['password'] ) : '',
			'media_base_url'        => isset( $input['media_base_url'] ) ? esc_url_raw( trim( (string) $input['media_base_url'] ) ) : '',
			'sku_path'              => isset( $input['sku_path'] ) ? sanitize_text_field( (string) $input['sku_path'] ) : $defaults['sku_path'],
			'title_path'            => isset( $input['title_path'] ) ? sanitize_text_field( (string) $input['title_path'] ) : $defaults['title_path'],
			'description_path'      => isset( $input['description_path'] ) ? sanitize_text_field( (string) $input['description_path'] ) : $defaults['description_path'],
			'brand_path'            => isset( $input['brand_path'] ) ? sanitize_text_field( (string) $input['brand_path'] ) : $defaults['brand_path'],
			'manufacturer_path'     => isset( $input['manufacturer_path'] ) ? sanitize_text_field( (string) $input['manufacturer_path'] ) : $defaults['manufacturer_path'],
			'main_photo_path'       => isset( $input['main_photo_path'] ) ? sanitize_text_field( (string) $input['main_photo_path'] ) : $defaults['main_photo_path'],
			'gallery_photo_path'    => isset( $input['gallery_photo_path'] ) ? sanitize_text_field( (string) $input['gallery_photo_path'] ) : $defaults['gallery_photo_path'],
			'erp_id_path'           => isset( $input['erp_id_path'] ) ? sanitize_text_field( (string) $input['erp_id_path'] ) : $defaults['erp_id_path'],
			'weight_path'           => isset( $input['weight_path'] ) ? sanitize_text_field( (string) $input['weight_path'] ) : $defaults['weight_path'],
			'weight_unit_path'      => isset( $input['weight_unit_path'] ) ? sanitize_text_field( (string) $input['weight_unit_path'] ) : $defaults['weight_unit_path'],
			'grouping_mode'         => isset( $input['grouping_mode'] ) ? sanitize_key( (string) $input['grouping_mode'] ) : $defaults['grouping_mode'],
			'grouping_path'         => isset( $input['grouping_path'] ) ? sanitize_text_field( (string) $input['grouping_path'] ) : $defaults['grouping_path'],
			'variant_attribute_map' => isset( $input['variant_attribute_map'] ) ? sanitize_textarea_field( (string) $input['variant_attribute_map'] ) : $defaults['variant_attribute_map'],
			'price_path'            => isset( $input['price_path'] ) ? sanitize_text_field( (string) $input['price_path'] ) : '',
			'sale_price_path'       => isset( $input['sale_price_path'] ) ? sanitize_text_field( (string) $input['sale_price_path'] ) : '',
			'stock_qty_path'        => isset( $input['stock_qty_path'] ) ? sanitize_text_field( (string) $input['stock_qty_path'] ) : '',
			'stock_status_path'     => isset( $input['stock_status_path'] ) ? sanitize_text_field( (string) $input['stock_status_path'] ) : '',
			'test_sync_limit'       => isset( $input['test_sync_limit'] ) ? absint( $input['test_sync_limit'] ) : $defaults['test_sync_limit'],
			'schedule'              => isset( $input['schedule'] ) ? sanitize_key( (string) $input['schedule'] ) : $defaults['schedule'],
			'batch_size'            => isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : $defaults['batch_size'],
			'missing_action'        => isset( $input['missing_action'] ) ? sanitize_key( (string) $input['missing_action'] ) : $defaults['missing_action'],
		);

		if ( ! in_array( $output['grouping_mode'], array( 'field_then_sku_base', 'field_only', 'sku_base', 'name_and_sku_base' ), true ) ) {
			$output['grouping_mode'] = $defaults['grouping_mode'];
		}

		if ( ! in_array( $output['schedule'], array( 'manual', 'every_30_minutes', 'hourly', 'twicedaily', 'daily' ), true ) ) {
			$output['schedule'] = $defaults['schedule'];
		}

		if ( ! in_array( $output['missing_action'], array( 'ignore', 'outofstock', 'draft' ), true ) ) {
			$output['missing_action'] = $defaults['missing_action'];
		}

		$output['batch_size'] = max( 1, min( 100, $output['batch_size'] ) );
		$output['test_sync_limit'] = max( 1, min( 500, $output['test_sync_limit'] ) );

		return $output;
	}

	/**
	 * Get runtime state.
	 *
	 * @return array
	 */
	public function get_state() {
		return wp_parse_args(
			get_option( HTX_XML_Woo_Sync_Plugin::STATE_KEY, array() ),
			$this->get_default_state()
		);
	}

	/**
	 * Get default runtime state.
	 *
	 * @return array
	 */
	public function get_default_state() {
		return array(
			'last_sync_time' => '',
			'last_status'    => 'idle',
			'last_message'   => '',
			'last_error'     => '',
			'summary'        => $this->get_empty_summary(),
			'log'            => array(),
			'is_running'     => false,
			'phase'          => 'idle',
			'current_run'    => '',
			'trigger'        => '',
			'started_at'     => '',
			'feed_path'      => '',
			'checkpoint'     => 0,
			'is_test_run'    => false,
			'max_products'   => 0,
			'total_products' => 0,
		);
	}

	/**
	 * Get an empty summary block.
	 *
	 * @return array
	 */
	public function get_empty_summary() {
		return array(
			'processed'          => 0,
			'created'            => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'failed'             => 0,
			'parents_created'    => 0,
			'parents_updated'    => 0,
			'variations_created' => 0,
			'variations_updated' => 0,
			'missing_adjusted'   => 0,
		);
	}

	/**
	 * Persist runtime state.
	 *
	 * @param array $state State.
	 * @return void
	 */
	public function update_state( $state ) {
		update_option( HTX_XML_Woo_Sync_Plugin::STATE_KEY, $state, false );
	}

	/**
	 * Merge a partial state update.
	 *
	 * @param array $changes Partial state.
	 * @return array
	 */
	public function patch_state( $changes ) {
		$state = $this->get_state();
		foreach ( $changes as $key => $value ) {
			$state[ $key ] = $value;
		}
		$this->update_state( $state );

		return $state;
	}

	/**
	 * Add a rolling log entry.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level.
	 * @return void
	 */
	public function add_log( $message, $level = 'info' ) {
		$state          = $this->get_state();
		$state['log']   = array_values( (array) $state['log'] );
		$state['log'][] = sprintf(
			'[%1$s] %2$s %3$s',
			strtoupper( sanitize_key( $level ) ),
			gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			wp_strip_all_tags( (string) $message )
		);
		$state['log'] = array_slice( $state['log'], -200 );
		$this->update_state( $state );
	}

	/**
	 * Get runtime directory path.
	 *
	 * @return string|WP_Error
	 */
	public function get_runtime_dir() {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'htx_upload_dir', $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'helikon-xml-woo-sync';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'htx_runtime_dir', __( 'Could not create the sync runtime directory in uploads.', 'helikon-xml-woo-sync' ) );
		}

		return $dir;
	}

	/**
	 * Get lock data.
	 *
	 * @return array
	 */
	public function get_lock() {
		$lock = get_option( HTX_XML_Woo_Sync_Plugin::LOCK_KEY, array() );
		return is_array( $lock ) ? $lock : array();
	}

	/**
	 * Determine whether a lock is stale.
	 *
	 * @param array $lock Lock data.
	 * @return bool
	 */
	public function is_lock_stale( $lock ) {
		if ( empty( $lock['expires_at'] ) ) {
			return true;
		}

		return (int) $lock['expires_at'] < time();
	}

	/**
	 * Claim the sync lock.
	 *
	 * @param string $token Lock owner token.
	 * @param int    $ttl   Lock TTL in seconds.
	 * @return true|WP_Error
	 */
	public function claim_lock( $token, $ttl ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return new WP_Error( 'htx_lock_token', __( 'A lock token is required.', 'helikon-xml-woo-sync' ) );
		}

		$data = array(
			'token'      => $token,
			'started_at' => time(),
			'expires_at' => time() + absint( $ttl ),
		);

		$current = $this->get_lock();
		if ( empty( $current ) ) {
			if ( add_option( HTX_XML_Woo_Sync_Plugin::LOCK_KEY, $data, '', false ) ) {
				return true;
			}

			$current = $this->get_lock();
		}

		if ( ! empty( $current['token'] ) && $token === $current['token'] ) {
			update_option( HTX_XML_Woo_Sync_Plugin::LOCK_KEY, $data, false );
			return true;
		}

		if ( $this->is_lock_stale( $current ) ) {
			update_option( HTX_XML_Woo_Sync_Plugin::LOCK_KEY, $data, false );
			return true;
		}

		return new WP_Error( 'htx_sync_locked', __( 'A sync is already running.', 'helikon-xml-woo-sync' ) );
	}

	/**
	 * Refresh an owned lock.
	 *
	 * @param string $token Lock owner token.
	 * @param int    $ttl   Lock TTL in seconds.
	 * @return true|WP_Error
	 */
	public function refresh_lock( $token, $ttl ) {
		return $this->claim_lock( $token, $ttl );
	}

	/**
	 * Release the lock when owned by the given token.
	 *
	 * @param string $token Lock owner token.
	 * @return void
	 */
	public function release_lock( $token ) {
		$lock = $this->get_lock();
		if ( empty( $lock ) ) {
			return;
		}

		if ( empty( $lock['token'] ) || $lock['token'] === $token ) {
			delete_option( HTX_XML_Woo_Sync_Plugin::LOCK_KEY );
		}
	}

	/**
	 * Force clear the lock.
	 *
	 * @return void
	 */
	public function clear_lock() {
		delete_option( HTX_XML_Woo_Sync_Plugin::LOCK_KEY );
	}

	/**
	 * Clear the stored log entries and runtime error text.
	 *
	 * @return void
	 */
	public function clear_logs() {
		$state = $this->get_state();

		$state['log']        = array();
		$state['last_error'] = '';

		if ( empty( $state['is_running'] ) ) {
			$state['last_status']  = 'idle';
			$state['last_message'] = __( 'Recent log entries were cleared.', 'helikon-xml-woo-sync' );
		}

		$this->update_state( $state );
	}
}
