<?php

use PHPUnit\Framework\TestCase;

final class LocalEngineTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ems_test_meta'] = array();
	}

	public function test_service_catalog_contains_trieste_priority_services(): void {
		$catalog = EMS_Local_SEO_Local_Engine::service_catalog();

		$this->assertArrayHasKey( 'ristrutturazioni', $catalog );
		$this->assertArrayHasKey( 'bagno', $catalog );
		$this->assertArrayHasKey( 'costi_bagno', $catalog );
		$this->assertArrayHasKey( 'piastrelle', $catalog );
		$this->assertSame( 'P1', $catalog['bagno']['priority'] );
	}

	public function test_manual_service_and_role_override_heuristics(): void {
		$post = new WP_Post(
			array(
				'ID'           => 101,
				'post_type'    => 'page',
				'post_name'    => 'posa-piastrelle-trieste',
				'post_title'   => 'Posa piastrelle a Trieste',
				'post_content' => 'Piastrellatura e posa di pavimenti.',
			)
		);

		$GLOBALS['ems_test_meta'][101] = array(
			'_ems_local_service_key' => 'bagno',
			'_ems_local_role'        => 'service',
		);

		$result = ( new EMS_Local_SEO_Local_Engine() )->classify_post( $post );

		$this->assertSame( 'bagno', $result['service_key'] );
		$this->assertSame( 'service', $result['role'] );
		$this->assertSame( 100, $result['confidence'] );
		$this->assertSame( 'bagno', $result['manual_service'] );
	}

	public function test_cost_bathroom_guide_is_classified_without_historical_data(): void {
		$post = new WP_Post(
			array(
				'ID'           => 102,
				'post_type'    => 'post',
				'post_name'    => 'quanto-costa-rifare-un-bagno-trieste',
				'post_title'   => 'Quanto costa rifare un bagno a Trieste',
				'post_content' => 'Guida al costo bagno, al preventivo bagno e alle lavorazioni che incidono sul prezzo bagno.',
			)
		);

		$result = ( new EMS_Local_SEO_Local_Engine() )->classify_post( $post );

		$this->assertSame( 'costi_bagno', $result['service_key'] );
		$this->assertSame( 'guide', $result['role'] );
		$this->assertGreaterThanOrEqual( 20, $result['confidence'] );
	}

	public function test_low_signal_content_remains_unclassified(): void {
		$post = new WP_Post(
			array(
				'ID'           => 103,
				'post_type'    => 'page',
				'post_name'    => 'pagina-generica',
				'post_title'   => 'Informazioni',
				'post_content' => 'Testo generico senza riferimento ai servizi.',
			)
		);

		$result = ( new EMS_Local_SEO_Local_Engine() )->classify_post( $post );

		$this->assertSame( '', $result['service_key'] );
	}
}
