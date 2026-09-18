<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Search_Console {
	public const SNAPSHOT_OPTION = 'ems_local_seo_gsc_snapshot_v1';
	public const ERROR_OPTION    = 'ems_local_seo_gsc_last_error_v1';
	public const HISTORY_OPTION  = 'ems_local_seo_gsc_history_v1';
	public const USER_OPTION     = 'ems_local_seo_gsc_user';
	public const CRON_HOOK       = 'ems_local_seo_gsc_daily';
	public const ROUTE           = '/google-site-kit/v1/modules/search-console/data/searchanalytics';

	private const RANGE_DAYS    = 28;
	private const DATA_LAG_DAYS = 3;
	private const MAX_ROWS      = 2500;
	private const DAILY_DAYS    = 182;

	public function hooks(): void {
		add_action( 'admin_post_ems_local_seo_refresh_gsc', array( $this, 'handle_refresh' ) );
		add_action( self::CRON_HOOK, array( $this, 'cron_refresh' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	public function maybe_schedule(): void {
		$enabled = (bool) EMS_Local_SEO_Settings::get( 'gsc_auto_refresh', 0 );
		$user_id = (int) get_option( self::USER_OPTION, 0 );
		$next    = wp_next_scheduled( self::CRON_HOOK );

		if ( $enabled && $user_id > 0 && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		} elseif ( ( ! $enabled || $user_id <= 0 ) && $next ) {
			wp_unschedule_hook( self::CRON_HOOK );
		}
	}

	public function cron_refresh(): void {
		$user_id = (int) get_option( self::USER_OPTION, 0 );
		if ( $user_id <= 0 || ! user_can( $user_id, 'manage_options' ) ) {
			return;
		}

		$previous_user = get_current_user_id();
		wp_set_current_user( $user_id );

		$result = $this->refresh();
		if ( is_wp_error( $result ) ) {
			$this->store_error( $result );
		}

		wp_set_current_user( $previous_user );
	}

	public function is_stale( int $max_age_hours = 36 ): bool {
		$snapshot = $this->get_snapshot();
		if ( empty( $snapshot['generated_gmt'] ) ) {
			return true;
		}

		$timestamp = strtotime( (string) $snapshot['generated_gmt'] );
		if ( false === $timestamp ) {
			return true;
		}

		return ( time() - $timestamp ) > max( 1, $max_age_hours ) * HOUR_IN_SECONDS;
	}

	public function handle_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
		}

		check_admin_referer( 'ems_local_seo_refresh_gsc' );

		update_option( self::USER_OPTION, get_current_user_id(), false );

		$result = $this->refresh();
		$status = is_wp_error( $result ) ? 'error' : 'ok';

		$allowed_pages = array(
			'ems-local-seo',
			'ems-local-seo-opportunities',
			'ems-local-seo-settings',
			'ems-local-seo-dashboard',
		);
		$back = isset( $_REQUEST['ems_back'] ) ? sanitize_key( wp_unslash( $_REQUEST['ems_back'] ) ) : 'ems-local-seo-opportunities';
		if ( ! in_array( $back, $allowed_pages, true ) ) {
			$back = 'ems-local-seo-opportunities';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => $back,
					'ems_gsc' => $status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function is_site_kit_active(): bool {
		if ( defined( 'GOOGLESITEKIT_VERSION' ) || class_exists( '\Google\Site_Kit\Plugin' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'google-site-kit/google-site-kit.php' );
	}

	public function get_snapshot(): array {
		$snapshot = get_option( self::SNAPSHOT_OPTION, array() );

		return is_array( $snapshot ) ? $snapshot : array();
	}

	public function get_last_error(): array {
		$error = get_option( self::ERROR_OPTION, array() );

		return is_array( $error ) ? $error : array();
	}

	public function refresh(): array|WP_Error {
		if ( ! $this->is_site_kit_active() ) {
			return $this->store_error(
				new WP_Error(
					'ems_site_kit_missing',
					'Site Kit by Google non risulta attivo.'
				)
			);
		}

		$ranges = $this->date_ranges();

		$current = $this->fetch_report(
			$ranges['current']['start'],
			$ranges['current']['end'],
			array( 'query', 'page' )
		);
		if ( is_wp_error( $current ) ) {
			return $this->store_error( $current );
		}

		$previous = $this->fetch_report(
			$ranges['previous']['start'],
			$ranges['previous']['end'],
			array( 'query', 'page' )
		);
		if ( is_wp_error( $previous ) ) {
			return $this->store_error( $previous );
		}

		$daily_end = $ranges['current']['end'];
		try {
			$daily_start = ( new DateTimeImmutable( $daily_end ) )
				->modify( '-' . ( self::DAILY_DAYS - 1 ) . ' days' )
				->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			$daily_start = $ranges['previous']['start'];
		}

		$daily = $this->fetch_report( $daily_start, $daily_end, array( 'date' ) );
		if ( is_wp_error( $daily ) ) {
			return $this->store_error( $daily );
		}

		$daily_rows = (array) ( $daily['rows'] ?? array() );
		usort(
			$daily_rows,
			static fn( array $a, array $b ): int => strcmp( (string) ( $a['date'] ?? '' ), (string) ( $b['date'] ?? '' ) )
		);
		$daily['rows'] = $daily_rows;

		$snapshot = array(
			'generated_at'  => current_time( 'mysql' ),
			'generated_gmt' => gmdate( 'c' ),
			'source'        => 'site-kit-search-console',
			'route'         => self::ROUTE,
			'range_days'    => self::RANGE_DAYS,
			'data_lag_days' => self::DATA_LAG_DAYS,
			'ranges'        => $ranges,
			'current'       => $current,
			'previous'      => $previous,
			'daily'         => $daily,
		);

		update_option( self::SNAPSHOT_OPTION, $snapshot, false );
		$this->store_history_entry( $snapshot );
		delete_option( self::ERROR_OPTION );
		do_action( 'ems_local_seo_observed_change', 'gsc_refreshed', array( 'source' => 'search_console' ) );

		return $snapshot;
	}

	public function fetch_report( string $start_date, string $end_date, array $dimensions ): array|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'ems_gsc_forbidden', 'Utente non autorizzato.' );
		}

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params(
			array(
				'startDate'  => $start_date,
				'endDate'    => $end_date,
				'dimensions' => array_values( $dimensions ),
				'limit'      => self::MAX_ROWS,
			)
		);

		$response = rest_do_request( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) $response->get_status();
		$data   = $response->get_data();

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] )
				? (string) $data['message']
				: 'Site Kit non ha restituito dati Search Console utilizzabili.';

			return new WP_Error(
				'ems_gsc_http_' . $status,
				$message,
				array( 'status' => $status )
			);
		}

		$rows = $this->extract_rows( $data );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$normalized = $this->normalize_rows( $rows, $dimensions );

		return array(
			'rows' => $normalized,
			'meta' => array(
				'dimensions'         => array_values( $dimensions ),
				'raw_rows'           => count( $rows ),
				'normalized_rows'    => count( $normalized ),
				'requested_limit'    => self::MAX_ROWS,
				'possibly_truncated' => count( $rows ) >= self::MAX_ROWS,
				'complete_claimed'   => false,
			),
		);
	}

	private function extract_rows( mixed $data ): array|WP_Error {
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'ems_gsc_unexpected_shape',
				'Risposta Search Console inattesa: il payload non è un array.'
			);
		}

		if ( array() === $data ) {
			return array();
		}

		if ( isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
			return $data['rows'];
		}

		if ( isset( $data['data']['rows'] ) && is_array( $data['data']['rows'] ) ) {
			return $data['data']['rows'];
		}

		if ( $this->is_list_array( $data ) ) {
			return $data;
		}

		return new WP_Error(
			'ems_gsc_unexpected_shape',
			'Risposta Search Console inattesa: EMS non riconosce la struttura restituita da Site Kit.',
			array(
				'keys' => array_slice( array_keys( $data ), 0, 20 ),
			)
		);
	}

	private function is_list_array( array $value ): bool {
		$expected = 0;

		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected ) {
				return false;
			}
			$expected++;
		}

		return true;
	}

	private function normalize_rows( array $rows, array $dimensions ): array {
		$normalized = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$keys = isset( $row['keys'] ) && is_array( $row['keys'] ) ? array_values( $row['keys'] ) : array();

			$item = array(
				'clicks'      => isset( $row['clicks'] ) ? (float) $row['clicks'] : 0.0,
				'impressions' => isset( $row['impressions'] ) ? (float) $row['impressions'] : 0.0,
				'ctr'         => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0.0,
				'position'    => isset( $row['position'] ) ? (float) $row['position'] : 0.0,
			);

			foreach ( $dimensions as $index => $dimension ) {
				$item[ $dimension ] = isset( $keys[ $index ] ) ? (string) $keys[ $index ] : '';
			}

			$has_dimension = false;
			foreach ( $dimensions as $dimension ) {
				if ( '' !== (string) ( $item[ $dimension ] ?? '' ) ) {
					$has_dimension = true;
					break;
				}
			}
			if ( ! $has_dimension ) {
				continue;
			}

			$normalized[] = $item;
		}

		return $normalized;
	}

	private function date_ranges(): array {
		$end = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$end = $end->modify( '-' . self::DATA_LAG_DAYS . ' days' );

		$current_end   = $end;
		$current_start = $current_end->modify( '-' . ( self::RANGE_DAYS - 1 ) . ' days' );
		$previous_end  = $current_start->modify( '-1 day' );
		$previous_start= $previous_end->modify( '-' . ( self::RANGE_DAYS - 1 ) . ' days' );

		return array(
			'current' => array(
				'start' => $current_start->format( 'Y-m-d' ),
				'end'   => $current_end->format( 'Y-m-d' ),
			),
			'previous' => array(
				'start' => $previous_start->format( 'Y-m-d' ),
				'end'   => $previous_end->format( 'Y-m-d' ),
			),
		);
	}

	public function get_history(): array {
		$history = get_option( self::HISTORY_OPTION, array() );

		return is_array( $history ) ? $history : array();
	}

	private function store_history_entry( array $snapshot ): void {
		$history = $this->get_history();

		array_unshift(
			$history,
			array(
				'generated_at' => (string) ( $snapshot['generated_at'] ?? current_time( 'mysql' ) ),
				'ranges'       => (array) ( $snapshot['ranges'] ?? array() ),
				'current'      => array_merge(
					$this->compact_summary( (array) ( $snapshot['current']['rows'] ?? array() ) ),
					array( 'meta' => (array) ( $snapshot['current']['meta'] ?? array() ) )
				),
				'previous'     => array_merge(
					$this->compact_summary( (array) ( $snapshot['previous']['rows'] ?? array() ) ),
					array( 'meta' => (array) ( $snapshot['previous']['meta'] ?? array() ) )
				),
			)
		);

		update_option( self::HISTORY_OPTION, array_slice( $history, 0, 26 ), false );
	}

	private function compact_summary( array $rows ): array {
		$clicks         = 0.0;
		$impressions    = 0.0;
		$position_total = 0.0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row_impressions = max( 0.0, (float) ( $row['impressions'] ?? 0 ) );
			$clicks         += max( 0.0, (float) ( $row['clicks'] ?? 0 ) );
			$impressions    += $row_impressions;
			$position_total += max( 0.0, (float) ( $row['position'] ?? 0 ) ) * $row_impressions;
		}

		return array(
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => $impressions > 0 ? $clicks / $impressions : 0.0,
			'position'    => $impressions > 0 ? $position_total / $impressions : 0.0,
			'rows'        => count( $rows ),
		);
	}

	private function store_error( WP_Error $error ): WP_Error {
		update_option(
			self::ERROR_OPTION,
			array(
				'code'       => $error->get_error_code(),
				'message'    => $error->get_error_message(),
				'data'       => $error->get_error_data(),
				'created_at' => current_time( 'mysql' ),
			),
			false
		);

		return $error;
	}
}
