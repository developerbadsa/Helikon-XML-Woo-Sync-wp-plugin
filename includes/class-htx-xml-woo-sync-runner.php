<?php
/**
 * Sync orchestration and cron handling.
 *
 * @package Helikon_XML_Woo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTX_XML_Woo_Sync_Runner {
	const LOCK_TTL_SECONDS   = 15 * MINUTE_IN_SECONDS;
	const MAX_BATCH_RUNTIME  = 20;
	const CLEANUP_BATCH_SIZE = 25;
	const SAFETY_RETRY_DELAY = 5 * MINUTE_IN_SECONDS;

	/**
	 * State instance.
	 *
	 * @var HTX_XML_Woo_Sync_State
	 */
	private $state;

	/**
	 * Feed instance.
	 *
	 * @var HTX_XML_Woo_Sync_Feed
	 */
	private $feed;

	/**
	 * Product sync instance.
	 *
	 * @var HTX_XML_Woo_Sync_Products
	 */
	private $products;

	/**
	 * Constructor.
	 *
	 * @param HTX_XML_Woo_Sync_State    $state    State service.
	 * @param HTX_XML_Woo_Sync_Feed     $feed     Feed service.
	 * @param HTX_XML_Woo_Sync_Products $products Product service.
	 */
	public function __construct( $state, $feed, $products ) {
		$this->state    = $state;
		$this->feed     = $feed;
		$this->products = $products;
	}

	/**
	 * Start a queued sync.
	 *
	 * @param string $trigger Trigger label.
	 * @return array|WP_Error
	 */
	public function start_sync( $trigger ) {
		if ( ! HTX_XML_Woo_Sync_Plugin::instance()->is_woocommerce_ready() ) {
			return new WP_Error( 'htx_woo_missing', __( 'WooCommerce is not active, so the sync cannot run.', 'helikon-xml-woo-sync' ) );
		}

		$settings = $this->state->get_settings();
		if ( empty( $settings['xml_url'] ) ) {
			return new WP_Error( 'htx_missing_xml_url', __( 'Set the XML URL before running a sync.', 'helikon-xml-woo-sync' ) );
		}

		$state = $this->state->get_state();
		$lock  = $this->state->get_lock();
		if ( ! empty( $state['is_running'] ) && ! $this->state->is_lock_stale( $lock ) ) {
			return new WP_Error( 'htx_sync_running', __( 'A sync is already running. Wait for it to finish or clear the lock if it is stuck.', 'helikon-xml-woo-sync' ) );
		}

		$token       = wp_generate_uuid4();
		$lock_result = $this->state->claim_lock( $token, self::LOCK_TTL_SECONDS );
		if ( is_wp_error( $lock_result ) ) {
			return $lock_result;
		}

		$this->cleanup_feed_file( $state['feed_path'] );

		$this->state->update_state(
			array(
				'last_sync_time' => $state['last_sync_time'],
				'last_status'    => 'queued',
				'last_message'   => __( 'The sync was queued and will run in batches.', 'helikon-xml-woo-sync' ),
				'last_error'     => '',
				'summary'        => $this->state->get_empty_summary(),
				'log'            => $state['log'],
				'is_running'     => true,
				'phase'          => 'queued',
				'current_run'    => $token,
				'trigger'        => sanitize_key( $trigger ),
				'started_at'     => current_time( 'mysql' ),
				'feed_path'      => '',
				'checkpoint'     => 0,
				'total_products' => 0,
			)
		);

		$this->state->add_log(
			sprintf(
				/* translators: %s: trigger name */
				__( 'Sync queued via %s.', 'helikon-xml-woo-sync' ),
				$trigger
			)
		);

		$this->schedule_batch( $token, 1 );

		return array(
			'token' => $token,
		);
	}

	/**
	 * Resume or start a scheduled sync.
	 *
	 * @return void
	 */
	public function maybe_start_scheduled_sync() {
		$state = $this->state->get_state();
		if ( ! empty( $state['is_running'] ) && ! empty( $state['current_run'] ) ) {
			$lock = $this->state->get_lock();
			if ( empty( $lock ) || $this->state->is_lock_stale( $lock ) ) {
				$this->state->add_log( __( 'Scheduled sync resumed an unfinished run.', 'helikon-xml-woo-sync' ) );
				$this->schedule_batch( $state['current_run'], 1 );
			}
			return;
		}

		$result = $this->start_sync( 'scheduled' );
		if ( is_wp_error( $result ) ) {
			$this->state->add_log( $result->get_error_message(), 'warning' );
		}
	}

	/**
	 * Process one sync batch.
	 *
	 * @param string $token Sync token.
	 * @return void
	 */
	public function process_batch( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return;
		}

		$state = $this->state->get_state();
		if ( empty( $state['is_running'] ) || $token !== (string) $state['current_run'] ) {
			return;
		}

		if ( ! HTX_XML_Woo_Sync_Plugin::instance()->is_woocommerce_ready() ) {
			$this->fail_run( $token, __( 'WooCommerce is not active, so the sync was stopped safely.', 'helikon-xml-woo-sync' ) );
			return;
		}

		$lock_result = $this->state->refresh_lock( $token, self::LOCK_TTL_SECONDS );
		if ( is_wp_error( $lock_result ) ) {
			return;
		}

		$this->schedule_batch( $token, self::SAFETY_RETRY_DELAY );

		try {
			$settings = $this->state->get_settings();
			$state    = $this->state->get_state();

			if ( empty( $state['feed_path'] ) ) {
				$this->state->patch_state(
					array(
						'phase'        => 'preparing',
						'last_message' => __( 'Downloading and validating the XML feed.', 'helikon-xml-woo-sync' ),
					)
				);

				$prepared_feed = $this->feed->download_and_validate_feed( $settings, $token );
				if ( is_wp_error( $prepared_feed ) ) {
					$this->fail_run( $token, $prepared_feed->get_error_message() );
					return;
				}

				$state = $this->state->patch_state(
					array(
						'phase'          => 'processing',
						'feed_path'      => $prepared_feed['path'],
						'total_products' => (int) $prepared_feed['total_products'],
						'last_message'   => sprintf(
							/* translators: %d: product count */
							__( 'Feed validated. %d product rows are queued for syncing.', 'helikon-xml-woo-sync' ),
							(int) $prepared_feed['total_products']
						),
					)
				);

				$this->state->add_log(
					sprintf(
						/* translators: %d: product count */
						__( 'Feed validated with %d Product nodes.', 'helikon-xml-woo-sync' ),
						(int) $prepared_feed['total_products']
					)
				);
			}

			$state = $this->state->get_state();
			if ( 'finalizing' === $state['phase'] ) {
				$this->process_finalization_batch( $token, $settings, $state );
				return;
			}

			$batch = $this->feed->read_batch(
				$state['feed_path'],
				(int) $state['checkpoint'],
				(int) $settings['batch_size'],
				$settings,
				self::MAX_BATCH_RUNTIME
			);

			if ( is_wp_error( $batch ) ) {
				$this->fail_run( $token, $batch->get_error_message() );
				return;
			}

			$summary       = $state['summary'];
			$product_stats = $this->products->sync_groups( $batch['groups'], $settings, $token );

			$summary['processed']          += (int) $batch['processed_products'];
			$summary['created']            += (int) $product_stats['created'];
			$summary['updated']            += (int) $product_stats['updated'];
			$summary['skipped']            += (int) $batch['skipped'] + (int) $product_stats['skipped'];
			$summary['failed']             += (int) $batch['failed'] + (int) $product_stats['failed'];
			$summary['parents_created']    += (int) $product_stats['parents_created'];
			$summary['parents_updated']    += (int) $product_stats['parents_updated'];
			$summary['variations_created'] += (int) $product_stats['variations_created'];
			$summary['variations_updated'] += (int) $product_stats['variations_updated'];

			$this->state->patch_state(
				array(
					'summary'      => $summary,
					'checkpoint'   => (int) $batch['next_offset'],
					'phase'        => $batch['complete'] ? 'finalizing' : 'processing',
					'last_message' => sprintf(
						/* translators: 1: processed count, 2: total count */
						__( 'Processed %1$d of %2$d feed rows.', 'helikon-xml-woo-sync' ),
						(int) $batch['next_offset'],
						max( (int) $state['total_products'], (int) $batch['next_offset'] )
					),
				)
			);

			if ( $batch['complete'] ) {
				if ( 'ignore' === $settings['missing_action'] ) {
					$this->complete_run( $token, __( 'Sync completed successfully.', 'helikon-xml-woo-sync' ) );
					return;
				}

				$this->state->add_log( __( 'Feed rows are complete. Finalizing missing-item handling.', 'helikon-xml-woo-sync' ) );
			}

			$this->schedule_batch( $token, 10 );
		} catch ( Exception $exception ) {
			$this->fail_run( $token, $exception->getMessage() );
		}
	}

	/**
	 * Reset the run state after a manual clear.
	 *
	 * @return void
	 */
	public function clear_lock_and_reset() {
		$state = $this->state->get_state();
		$this->cleanup_feed_file( $state['feed_path'] );
		$this->clear_scheduled_batch( $state['current_run'] );
		$this->state->clear_lock();

		$this->state->patch_state(
			array(
				'is_running'     => false,
				'phase'          => 'idle',
				'current_run'    => '',
				'trigger'        => '',
				'started_at'     => '',
				'feed_path'      => '',
				'checkpoint'     => 0,
				'total_products' => 0,
				'last_status'    => 'warning',
				'last_message'   => __( 'The sync lock was cleared manually.', 'helikon-xml-woo-sync' ),
			)
		);

		$this->state->add_log( __( 'The sync lock was cleared manually by an administrator.', 'helikon-xml-woo-sync' ), 'warning' );
	}

	/**
	 * Handle final missing-item cleanup batches.
	 *
	 * @param string $token    Sync token.
	 * @param array  $settings Plugin settings.
	 * @param array  $state    Current runtime state.
	 * @return void
	 */
	private function process_finalization_batch( $token, $settings, $state ) {
		$cleanup = $this->products->cleanup_missing_variations( $token, $settings['missing_action'], self::CLEANUP_BATCH_SIZE );
		if ( is_wp_error( $cleanup ) ) {
			$this->fail_run( $token, $cleanup->get_error_message() );
			return;
		}

		$summary                     = $state['summary'];
		$summary['missing_adjusted'] += (int) $cleanup['count'];
		$this->state->patch_state(
			array(
				'summary'      => $summary,
				'last_message' => __( 'Finalizing missing-item handling.', 'helikon-xml-woo-sync' ),
			)
		);

		if ( ! empty( $cleanup['complete'] ) ) {
			$this->complete_run( $token, __( 'Sync completed successfully.', 'helikon-xml-woo-sync' ) );
			return;
		}

		$this->schedule_batch( $token, 10 );
	}

	/**
	 * Mark the current run as complete.
	 *
	 * @param string $token   Sync token.
	 * @param string $message Final message.
	 * @return void
	 */
	private function complete_run( $token, $message ) {
		$state = $this->state->get_state();
		$this->cleanup_feed_file( $state['feed_path'] );
		$this->clear_scheduled_batch( $token );
		$this->state->release_lock( $token );

		$this->state->patch_state(
			array(
				'last_sync_time' => current_time( 'mysql' ),
				'last_status'    => 'success',
				'last_message'   => $message,
				'last_error'     => '',
				'is_running'     => false,
				'phase'          => 'idle',
				'current_run'    => '',
				'trigger'        => '',
				'started_at'     => '',
				'feed_path'      => '',
				'checkpoint'     => 0,
				'total_products' => 0,
			)
		);

		$this->state->add_log( $message );
	}

	/**
	 * Mark the current run as failed.
	 *
	 * @param string $token   Sync token.
	 * @param string $message Failure message.
	 * @return void
	 */
	private function fail_run( $token, $message ) {
		$state = $this->state->get_state();
		$this->cleanup_feed_file( $state['feed_path'] );
		$this->clear_scheduled_batch( $token );
		$this->state->release_lock( $token );

		$this->state->patch_state(
			array(
				'last_sync_time' => current_time( 'mysql' ),
				'last_status'    => 'error',
				'last_message'   => $message,
				'last_error'     => $message,
				'is_running'     => false,
				'phase'          => 'idle',
				'current_run'    => '',
				'trigger'        => '',
				'started_at'     => '',
				'feed_path'      => '',
				'checkpoint'     => 0,
				'total_products' => 0,
			)
		);

		$this->state->add_log( $message, 'error' );
	}

	/**
	 * Schedule the next batch and avoid duplicates.
	 *
	 * @param string $token Sync token.
	 * @param int    $delay Delay in seconds.
	 * @return void
	 */
	private function schedule_batch( $token, $delay ) {
		$this->clear_scheduled_batch( $token );
		wp_schedule_single_event( time() + max( 1, absint( $delay ) ), HTX_XML_Woo_Sync_Plugin::CRON_BATCH_HOOK, array( $token ) );
	}

	/**
	 * Clear any pending batch events for the token.
	 *
	 * @param string $token Sync token.
	 * @return void
	 */
	private function clear_scheduled_batch( $token ) {
		if ( '' === (string) $token ) {
			return;
		}

		wp_clear_scheduled_hook( HTX_XML_Woo_Sync_Plugin::CRON_BATCH_HOOK, array( $token ) );
	}

	/**
	 * Remove a downloaded feed file.
	 *
	 * @param string $feed_path File path.
	 * @return void
	 */
	private function cleanup_feed_file( $feed_path ) {
		$feed_path = (string) $feed_path;
		if ( '' !== $feed_path && file_exists( $feed_path ) ) {
			wp_delete_file( $feed_path );
		}
	}
}
