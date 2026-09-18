<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Conversion_Signals {
	public const OPTION_KEY = 'ems_local_seo_conversion_signals_v1';
	private const MAX_DAYS = 180;

	public function hooks(): void {
		add_action( 'ems_local_seo_conversion_event', array( $this, 'observe' ), 10, 2 );
	}

	public static function allowed_events(): array {
		return array(
			'whatsapp'         => 'WhatsApp',
			'phone'            => 'Chiamata',
			'quiz'             => 'Quiz completato',
			'form_success'     => 'Modulo inviato',
			'qualified_contact'=> 'Contatto qualificato',
		);
	}

	public function observe( string $event, array $context = array() ): void {
		$this->record( $event, $context );
	}

	public function record( string $event, array $context = array() ): bool {
		$event = sanitize_key( $event );
		if ( ! isset( self::allowed_events()[ $event ] ) ) {
			return false;
		}

		$service_key = sanitize_key( (string) ( $context['service_key'] ?? '' ) );
		$post_id     = max( 0, (int) ( $context['post_id'] ?? 0 ) );
		$source      = sanitize_key( (string) ( $context['source'] ?? 'site' ) );
		$day         = current_time( 'Y-m-d' );

		$data = $this->get_data();
		if ( ! isset( $data[ $day ] ) || ! is_array( $data[ $day ] ) ) {
			$data[ $day ] = array(
				'events'   => array(),
				'services' => array(),
				'sources'  => array(),
				'pages'    => array(),
			);
		}

		$data[ $day ]['events'][ $event ] = (int) ( $data[ $day ]['events'][ $event ] ?? 0 ) + 1;

		if ( '' !== $service_key ) {
			if ( ! isset( $data[ $day ]['services'][ $service_key ] ) ) {
				$data[ $day ]['services'][ $service_key ] = array();
			}
			$data[ $day ]['services'][ $service_key ][ $event ] = (int) ( $data[ $day ]['services'][ $service_key ][ $event ] ?? 0 ) + 1;
		}

		if ( '' !== $source ) {
			$data[ $day ]['sources'][ $source ] = (int) ( $data[ $day ]['sources'][ $source ] ?? 0 ) + 1;
		}

		if ( $post_id > 0 ) {
			$page_key = (string) $post_id;
			if ( ! isset( $data[ $day ]['pages'][ $page_key ] ) ) {
				$data[ $day ]['pages'][ $page_key ] = array();
			}
			$data[ $day ]['pages'][ $page_key ][ $event ] = (int) ( $data[ $day ]['pages'][ $page_key ][ $event ] ?? 0 ) + 1;
		}

		ksort( $data );
		if ( count( $data ) > self::MAX_DAYS ) {
			$data = array_slice( $data, -self::MAX_DAYS, null, true );
		}

		update_option( self::OPTION_KEY, $data, false );
		do_action(
			'ems_local_seo_observed_change',
			'conversion_signal_observed',
			array(
				'post_id'     => $post_id,
				'service_key' => $service_key,
				'source'      => 'conversion_signal',
			)
		);

		return true;
	}

	public function get_data(): array {
		$data = get_option( self::OPTION_KEY, array() );

		return is_array( $data ) ? $data : array();
	}

	public function summary( int $days = 28 ): array {
		$days = max( 1, min( self::MAX_DAYS, $days ) );
		$data = $this->get_data();

		if ( empty( $data ) ) {
			return array(
				'available' => false,
				'period_days' => $days,
				'events' => array(),
				'services' => array(),
				'sources' => array(),
				'note' => 'Nessun segnale commerciale è stato ancora osservato. Questo non significa zero contatti.',
			);
		}

		$days_keys = array_keys( $data );
		$days_keys = array_slice( $days_keys, -$days );

		$events   = array();
		$services = array();
		$sources  = array();

		foreach ( $days_keys as $day ) {
			$row = (array) ( $data[ $day ] ?? array() );

			foreach ( (array) ( $row['events'] ?? array() ) as $event => $count ) {
				$events[ $event ] = (int) ( $events[ $event ] ?? 0 ) + (int) $count;
			}

			foreach ( (array) ( $row['services'] ?? array() ) as $service => $service_events ) {
				if ( ! isset( $services[ $service ] ) ) {
					$services[ $service ] = array();
				}
				foreach ( (array) $service_events as $event => $count ) {
					$services[ $service ][ $event ] = (int) ( $services[ $service ][ $event ] ?? 0 ) + (int) $count;
				}
			}

			foreach ( (array) ( $row['sources'] ?? array() ) as $source => $count ) {
				$sources[ $source ] = (int) ( $sources[ $source ] ?? 0 ) + (int) $count;
			}
		}

		return array(
			'available'   => true,
			'period_days' => $days,
			'events'      => $events,
			'services'    => $services,
			'sources'     => $sources,
			'note'        => 'Conteggi aggregati osservati; nessun IP, email, telefono, messaggio o identificatore cliente viene salvato.',
		);
	}
}
