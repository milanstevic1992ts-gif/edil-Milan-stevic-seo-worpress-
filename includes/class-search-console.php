<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Search_Console {
	public const SNAPSHOT_OPTION = 'ems_local_seo_gsc_snapshot_v1';
	public const ERROR_OPTION    = 'ems_local_seo_gsc_last_error_v1';
	public const HISTORY_OPTION  = 'ems_local_seo_gsc_history_v1';
	public const ROUTE           = '/google-site-kit/v1/modules/search-console/data/searchanalytics';

	private const RANGE_DAYS = 28;
	private const DATA_LAG_DAYS = 3;
	private const MAX_ROWS = 2500;

	public function hooks(): void {
		add_action( 'admin_post_ems_local_seo_refresh_gsc', array( $this, 'handle_refresh' ) );
	}

	public function handle_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
		}

		check_admin_referer( 'ems_local_seo_refresh_gsc' );

		$result = $this->refresh();
		$status = is_wp_error( $result ) ? 'error' : 'ok';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'ems-local-seo-opportunities',
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

		$snapshot = array(
			'generated_at' => current_time( 'mysql' ),
			'generated_gmt' => gmdate( 'c' ),
			'source'       => 'site-kit-search-console',
			'route'        => self::ROUTE,
			'range_days'   => self::RANGE_DAYS,
			'data_lag_days'=> self::DATA_LAG_DAYS,
			'ranges'       => $ranges,
			'current'      => array(
				'rows' => $current,
			),
			'previous'     => array(
				'rows' => $previous,
			),
		);

		update_option( self::SNAPSHOT_OPTION, $snapshot, false );
		$this->store_history_entry( $snapshot );
		delete_option( self::ERROR_OPTION );

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

		return $this->normalize_rows( $rows, $dimensions );
	}

	private function extract_rows( mixed $data ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}

		if ( isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
			return $data['rows'];
		}

		if ( isset( $data['data']['rows'] ) && is_array( $data['data']['rows'] ) ) {
			return $data['data']['rows'];
		}

		if ( array_is_list( $data ) ) {
			return $data;
		}

		return array();
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

			if ( '' === (string) ( $item['query'] ?? '' ) && '' === (string) ( $item['page'] ?? '' ) ) {
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
				'current'      => $this->compact_summary( (array) ( $snapshot['current']['rows'] ?? array() ) ),
				'previous'     => $this->compact_summary( (array) ( $snapshot['previous']['rows'] ?? array() ) ),
			)
		);

		update_option( self::HISTORY_OPTION, array_slice( $history, 0, 12 ), false );
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
