<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_IndexNow {
	public const KEY_OPTION = 'ems_local_seo_indexnow_key';
	public const LOG_OPTION = 'ems_local_seo_indexnow_log';
	public const CRON_HOOK  = 'ems_local_seo_indexnow_ping';

	private const ENDPOINT = 'https://api.indexnow.org/indexnow';

	public function hooks(): void {
		add_action( 'init', array( $this, 'maybe_serve_key_file' ), 0 );
		add_action( 'wp_after_insert_post', array( $this, 'on_after_insert' ), 20, 4 );
		add_action( self::CRON_HOOK, array( $this, 'ping' ) );
		add_action( 'admin_post_ems_local_seo_test_indexnow', array( $this, 'handle_test' ) );
	}

	public static function is_enabled(): bool {
		return (bool) EMS_Local_SEO_Settings::get( 'enable_indexnow', 0 );
	}

	public static function get_key(): string {
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key ) ) {
			return $key;
		}

		$key = wp_generate_password( 32, false, false );
		update_option( self::KEY_OPTION, $key, false );

		return $key;
	}

	public static function key_url(): string {
		return home_url( '/' . rawurlencode( self::get_key() ) . '.txt' );
	}

	public static function get_log(): array {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	public function maybe_serve_key_file(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$want = '/' . self::get_key() . '.txt';
		if ( $path !== $want ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=300' );
		echo esc_html( self::get_key() );
		exit;
	}

	public function on_after_insert( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ): void {
		if ( ! self::is_enabled() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, get_post_types( array( 'public' => true ), 'names' ), true ) ) {
			return;
		}

		$was_public = $post_before instanceof WP_Post && 'publish' === $post_before->post_status;
		$is_public  = 'publish' === $post->post_status;

		if ( ! $is_public && ! $was_public ) {
			return;
		}

		$url = get_permalink( $post_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}

		if ( $is_public && $this->is_noindex( $post_id ) ) {
			return;
		}

		$this->queue( $url );
	}

	private function is_noindex( int $post_id ): bool {
		if ( (bool) get_post_meta( $post_id, '_ems_seo_noindex', true ) ) {
			return true;
		}

		return false;
	}

	public function queue( string $url ): void {
		$url = esc_url_raw( $url );
		if ( '' === $url || ! str_starts_with( $url, home_url( '/' ) ) ) {
			return;
		}

		wp_schedule_single_event( time() + 30, self::CRON_HOOK, array( $url ) );
	}

	public function ping( string $url ): array {
		if ( ! self::is_enabled() ) {
			return array( 'status' => 0, 'message' => 'IndexNow disattivato.' );
		}

		$url = esc_url_raw( $url );
		if ( '' === $url || ! str_starts_with( $url, home_url( '/' ) ) ) {
			return array( 'status' => 0, 'message' => 'URL non valido per questo sito.' );
		}

		$key = self::get_key();
		$response = wp_remote_get(
			add_query_arg(
				array(
					'url'         => $url,
					'key'         => $key,
					'keyLocation' => self::key_url(),
				),
				self::ENDPOINT
			),
			array(
				'timeout'    => 10,
				'user-agent' => 'EMS-Local-SEO/' . EMS_LOCAL_SEO_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'status'  => 0,
				'message' => $response->get_error_message(),
			);
			$this->log( $url, $result );
			return $result;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$result = array(
			'status'  => $status,
			'message' => self::describe_status( $status ),
		);
		$this->log( $url, $result );

		return $result;
	}

	public static function describe_status( int $status ): string {
		return match ( $status ) {
			200 => 'URL ricevuto.',
			202 => 'URL ricevuto; verifica chiave in corso.',
			400 => 'Formato richiesta non valido.',
			403 => 'Chiave IndexNow non valida o non verificabile.',
			422 => 'URL/host/chiave non coerenti.',
			429 => 'Troppe richieste: riprovare più tardi.',
			default => $status > 0 ? 'Risposta HTTP ' . $status . '.' : 'Nessuna risposta valida.',
		};
	}

	private function log( string $url, array $result ): void {
		$log = self::get_log();
		array_unshift(
			$log,
			array(
				'created_at' => current_time( 'mysql' ),
				'url'        => $url,
				'status'     => (int) ( $result['status'] ?? 0 ),
				'message'    => sanitize_text_field( (string) ( $result['message'] ?? '' ) ),
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, 30 ), false );
	}

	public function handle_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
		}

		check_admin_referer( 'ems_local_seo_test_indexnow' );

		$result = $this->ping( home_url( '/' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'ems-local-seo-settings',
					'ems_index' => (int) ( $result['status'] ?? 0 ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
