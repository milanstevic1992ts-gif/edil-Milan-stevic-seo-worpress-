<?php

use PHPUnit\Framework\TestCase;

final class OpportunitiesTest extends TestCase {
	private EMS_Local_SEO_Opportunities $analyzer;

	protected function setUp(): void {
		$this->analyzer = new EMS_Local_SEO_Opportunities( new EMS_Local_SEO_Search_Console() );
	}

	public function test_missing_current_row_is_not_classified_as_decline(): void {
		$snapshot = array(
			'current' => array(
				'rows' => array(),
				'meta' => array( 'possibly_truncated' => false ),
			),
			'previous' => array(
				'rows' => array(
					array(
						'query' => 'ristrutturazione bagno trieste',
						'page' => 'https://triesteincostruzione.com/ristrutturazione-bagno/',
						'clicks' => 8,
						'impressions' => 120,
						'ctr' => 0.066,
						'position' => 7.2,
					),
				),
				'meta' => array( 'possibly_truncated' => false ),
			),
		);

		$result = $this->analyzer->analyze( $snapshot );

		$this->assertSame( 0, $result['counts']['declining'] ?? 0 );
		$this->assertSame( 1, $result['counts']['missing_from_sample'] ?? 0 );
		$this->assertSame( 'media', $this->firstItemOfType( $result['items'], 'missing_from_sample' )['confidence'] );
	}

	public function test_truncated_report_lowers_confidence_for_missing_row(): void {
		$snapshot = array(
			'current' => array(
				'rows' => array(),
				'meta' => array( 'possibly_truncated' => true ),
			),
			'previous' => array(
				'rows' => array(
					array(
						'query' => 'piastrellista trieste',
						'page' => 'https://triesteincostruzione.com/servizio-di-piastrellatura/',
						'clicks' => 5,
						'impressions' => 90,
						'ctr' => 0.055,
						'position' => 8.0,
					),
				),
				'meta' => array( 'possibly_truncated' => false ),
			),
		);

		$result = $this->analyzer->analyze( $snapshot );
		$item   = $this->firstItemOfType( $result['items'], 'missing_from_sample' );

		$this->assertTrue( $result['data_quality']['possibly_truncated'] );
		$this->assertSame( 'bassa', $item['confidence'] );
	}

	public function test_same_row_in_both_periods_can_be_classified_as_decline(): void {
		$snapshot = array(
			'current' => array(
				'rows' => array(
					array(
						'query' => 'cartongesso trieste',
						'page' => 'https://triesteincostruzione.com/cartongesso-2/',
						'clicks' => 3,
						'impressions' => 50,
						'ctr' => 0.06,
						'position' => 10.0,
					),
				),
				'meta' => array( 'possibly_truncated' => false ),
			),
			'previous' => array(
				'rows' => array(
					array(
						'query' => 'cartongesso trieste',
						'page' => 'https://triesteincostruzione.com/cartongesso-2/',
						'clicks' => 10,
						'impressions' => 110,
						'ctr' => 0.09,
						'position' => 7.0,
					),
				),
				'meta' => array( 'possibly_truncated' => false ),
			),
		);

		$result = $this->analyzer->analyze( $snapshot );

		$this->assertGreaterThanOrEqual( 1, $result['counts']['declining'] ?? 0 );
	}

	public function test_new_row_is_described_as_new_in_sample_not_new_query(): void {
		$snapshot = array(
			'current' => array(
				'rows' => array(
					array(
						'query' => 'posa spc trieste',
						'page' => 'https://triesteincostruzione.com/posa-spc-e-lvt-a-trieste-per-appartamenti-e-ambienti-interni/',
						'clicks' => 4,
						'impressions' => 60,
						'ctr' => 0.066,
						'position' => 8.5,
					),
				),
				'meta' => array( 'possibly_truncated' => false ),
			),
			'previous' => array(
				'rows' => array(),
				'meta' => array( 'possibly_truncated' => false ),
			),
		);

		$result = $this->analyzer->analyze( $snapshot );
		$item   = $this->firstItemOfType( $result['items'], 'new_in_sample' );

		$this->assertStringContainsString( 'campione', mb_strtolower( $item['label'] ) );
		$this->assertSame( 'bassa', $item['confidence'] );
	}

	private function firstItemOfType( array $items, string $type ): array {
		foreach ( $items as $item ) {
			if ( ( $item['type'] ?? '' ) === $type ) {
				return $item;
			}
		}

		$this->fail( 'Item type not found: ' . $type );
	}
}
