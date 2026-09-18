<?php

use PHPUnit\Framework\TestCase;

final class ConversionSignalsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ems_test_options'] = array();
	}

	public function test_no_observation_is_not_reported_as_zero_contacts(): void {
		$signals = new EMS_Local_SEO_Conversion_Signals();
		$summary = $signals->summary( 28 );

		$this->assertFalse( $summary['available'] );
		$this->assertSame( array(), $summary['events'] );
		$this->assertStringContainsString( 'non significa zero', strtolower( $summary['note'] ) );
	}

	public function test_records_only_aggregate_dimensions(): void {
		$signals = new EMS_Local_SEO_Conversion_Signals();

		$ok = $signals->record(
			'whatsapp',
			array(
				'service_key' => 'bagno',
				'post_id' => 77,
				'source' => 'service_page',
				'email' => 'do-not-store@example.com',
				'phone' => '+39 123',
				'message' => 'private text',
				'ip' => '127.0.0.1',
			)
		);

		$this->assertTrue( $ok );

		$data = $signals->get_data();
		$this->assertArrayHasKey( '2026-09-18', $data );
		$this->assertSame( 1, $data['2026-09-18']['events']['whatsapp'] );
		$this->assertSame( 1, $data['2026-09-18']['services']['bagno']['whatsapp'] );
		$this->assertSame( 1, $data['2026-09-18']['pages']['77']['whatsapp'] );

		$serialized = json_encode( $data );
		$this->assertStringNotContainsString( 'do-not-store', $serialized );
		$this->assertStringNotContainsString( 'private text', $serialized );
		$this->assertStringNotContainsString( '127.0.0.1', $serialized );
	}

	public function test_rejects_unknown_event_types(): void {
		$signals = new EMS_Local_SEO_Conversion_Signals();

		$this->assertFalse(
			$signals->record(
				'arbitrary_personal_event',
				array( 'service_key' => 'bagno' )
			)
		);
		$this->assertSame( array(), $signals->get_data() );
	}

	public function test_summary_aggregates_observed_events_without_inventing_missing_types(): void {
		$signals = new EMS_Local_SEO_Conversion_Signals();
		$signals->record( 'whatsapp', array( 'service_key' => 'bagno', 'source' => 'service_page' ) );
		$signals->record( 'quiz', array( 'service_key' => 'bagno', 'source' => 'quiz' ) );

		$summary = $signals->summary( 28 );

		$this->assertTrue( $summary['available'] );
		$this->assertSame( 1, $summary['events']['whatsapp'] );
		$this->assertSame( 1, $summary['events']['quiz'] );
		$this->assertArrayNotHasKey( 'phone', $summary['events'] );
	}
}
