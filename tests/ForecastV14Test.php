<?php

use PHPUnit\Framework\TestCase;

final class ForecastV14Test extends TestCase {
	public function test_projection_refuses_too_little_history(): void {
		$result = EMS_Local_SEO_Forecast::project(
			array(
				array( 'week' => '2026-09-01', 'clicks' => 10 ),
				array( 'week' => '2026-09-08', 'clicks' => 12 ),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'bassa', $result['confidence'] );
	}

	public function test_projection_returns_four_bounded_scenario_points(): void {
		$weeks = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$weeks[] = array(
				'week'        => ( new DateTimeImmutable( '2026-06-01' ) )->modify( '+' . $i . ' weeks' )->format( 'Y-m-d' ),
				'clicks'      => 20 + $i * 2,
				'impressions' => 300 + $i * 10,
				'ctr'         => 0.08,
				'position'    => 8.0 - $i * 0.2,
			);
		}

		$result = EMS_Local_SEO_Forecast::project( $weeks, 'clicks', 4, 10 );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 4, $result['points'] );
		foreach ( $result['points'] as $point ) {
			$this->assertGreaterThanOrEqual( 0, $point['low'] );
			$this->assertGreaterThanOrEqual( $point['low'], $point['value'] );
			$this->assertGreaterThanOrEqual( $point['value'], $point['high'] );
		}
		$this->assertStringContainsString( 'non è una previsione', $result['note'] );
	}

	public function test_potential_is_a_scenario_not_a_promise(): void {
		$result = EMS_Local_SEO_Forecast::potential(
			array(
				array(
					'query' => 'ristrutturazione bagno trieste',
					'clicks' => 4,
					'impressions' => 200,
					'ctr' => 0.02,
					'position' => 8.0,
				),
			),
			3.0,
			10
		);

		$this->assertCount( 1, $result['items'] );
		$this->assertGreaterThan( 0, $result['total'] );
		$this->assertStringContainsString( 'Scenario teorico', $result['note'] );
	}
}
