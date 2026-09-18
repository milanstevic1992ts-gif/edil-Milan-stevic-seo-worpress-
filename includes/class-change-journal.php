<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Change_Journal {
	public const OPTION_KEY = 'ems_local_seo_change_journal_v1';
	private const MAX_ENTRIES = 200;

	public function hooks(): void {
		add_action( 'save_post', array( $this, 'observe_post_save' ), 35, 2 );
		add_action( 'ems_local_seo_observed_change', array( $this, 'observe_change' ), 10, 2 );
	}

	public function observe_post_save( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$this->record(
			'post_updated',
			array(
				'post_id'     => $post_id,
				'service_key' => (string) get_post_meta( $post_id, '_ems_local_service_key', true ),
				'source'      => 'wordpress',
			)
		);
	}

	public function observe_change( string $type, array $context = array() ): void {
		$this->record( $type, $context );
	}

	public function record( string $type, array $context = array() ): void {
		$entry = $this->normalize_entry( $type, $context );

		if ( '' === $entry['type'] ) {
			return;
		}

		$journal = $this->get_entries();
		$latest  = $journal[0] ?? array();

		if ( $this->same_event( $latest, $entry ) ) {
			return;
		}

		array_unshift( $journal, $entry );
		update_option( self::OPTION_KEY, array_slice( $journal, 0, self::MAX_ENTRIES ), false );
	}

	public function get_entries(): array {
		$entries = get_option( self::OPTION_KEY, array() );

		return is_array( $entries ) ? array_values( $entries ) : array();
	}

	public function recent( int $limit = 20 ): array {
		$limit = max( 1, min( 100, $limit ) );

		return array_slice( $this->get_entries(), 0, $limit );
	}

	public function normalize_entry( string $type, array $context = array() ): array {
		$allowed_types = array(
			'post_updated',
			'case_study_updated',
			'gsc_refreshed',
			'html_verification_completed',
			'link_health_completed',
			'local_engine_rebuilt',
			'conversion_signal_observed',
			'manual_note',
		);

		$type = sanitize_key( $type );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = '';
		}

		$post_id = max( 0, (int) ( $context['post_id'] ?? 0 ) );
		$service_key = sanitize_key( (string) ( $context['service_key'] ?? '' ) );
		$source = sanitize_key( (string) ( $context['source'] ?? $this->source_for_type( $type ) ) );

		return array(
			'timestamp'   => current_time( 'mysql' ),
			'type'        => $type,
			'post_id'     => $post_id,
			'service_key' => $service_key,
			'source'      => $source,
		);
	}

	private function source_for_type( string $type ): string {
		$map = array(
			'post_updated'                 => 'wordpress',
			'case_study_updated'           => 'case_study',
			'gsc_refreshed'                => 'search_console',
			'html_verification_completed'  => 'public_html',
			'link_health_completed'        => 'link_health',
			'local_engine_rebuilt'         => 'local_engine',
			'conversion_signal_observed'   => 'conversion_signal',
			'manual_note'                  => 'manual',
		);

		return $map[ $type ] ?? '';
	}

	private function same_event( array $a, array $b ): bool {
		if ( empty( $a ) ) {
			return false;
		}

		return (string) ( $a['type'] ?? '' ) === (string) $b['type']
			&& (int) ( $a['post_id'] ?? 0 ) === (int) $b['post_id']
			&& (string) ( $a['service_key'] ?? '' ) === (string) $b['service_key']
			&& (string) ( $a['source'] ?? '' ) === (string) $b['source'];
	}
}
