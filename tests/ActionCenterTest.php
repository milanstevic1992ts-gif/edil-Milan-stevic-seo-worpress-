<?php

use PHPUnit\Framework\TestCase;

final class ActionCenterTest extends TestCase {
	private EMS_Local_SEO_Action_Center $center;

	protected function setUp(): void {
		$reflection = new ReflectionClass( EMS_Local_SEO_Action_Center::class );
		$this->center = $reflection->newInstanceWithoutConstructor();
	}

	public function test_cold_start_still_produces_technical_actions_without_gsc(): void {
		$actions = $this->center->compile(
			array(
				'local' => array(
					'services' => array(),
					'business_entity' => array(),
				),
				'verification' => array(),
				'link_health' => array(),
				'links' => array(),
				'gsc' => array(),
				'urls' => array(),
			)
		);

		$ids = array_column( $actions, 'id' );

		$this->assertContains( 'verification-incomplete', $ids );
		$this->assertContains( 'link-health-incomplete', $ids );
		$this->assertNotContains( 'gsc', array_column( $actions, 'source' ) );
	}

	public function test_missing_p1_service_becomes_action_without_historical_metrics(): void {
		$actions = $this->center->compile(
			array(
				'local' => array(
					'business_entity' => array(),
					'services' => array(
						'bagno' => array(
							'priority' => 'P1',
							'label' => 'Ristrutturazione bagno',
							'coverage' => 'missing',
							'primary_candidate' => null,
							'warnings' => array(),
							'roles' => array(),
						),
					),
				),
				'verification' => array( 'status' => 'complete', 'stale' => false, 'issues' => array() ),
				'link_health' => array( 'status' => 'complete', 'stale' => false, 'summary' => array() ),
				'links' => array(),
				'gsc' => array(),
				'urls' => array(),
			)
		);

		$matches = array_values(
			array_filter(
				$actions,
				static fn( array $item ): bool => 'bagno' === ( $item['service_key'] ?? '' )
			)
		);

		$this->assertCount( 1, $matches );
		$this->assertSame( 1, $matches[0]['priority'] );
		$this->assertStringContainsString( 'Ristrutturazione bagno', $matches[0]['title'] );
	}

	public function test_gsc_query_overlap_is_not_labeled_cannibalization(): void {
		$actions = $this->center->compile(
			array(
				'local' => array( 'services' => array(), 'business_entity' => array() ),
				'verification' => array( 'status' => 'complete', 'stale' => false, 'issues' => array() ),
				'link_health' => array( 'status' => 'complete', 'stale' => false, 'summary' => array() ),
				'links' => array(),
				'gsc' => array(
					'items' => array(
						array(
							'type' => 'query_overlap',
							'query' => 'ristrutturazione bagno trieste',
							'page' => 'https://example.test/a/',
							'reason' => 'La stessa query genera impression su due URL.',
							'action' => 'Confronta gli intenti.',
							'confidence' => 'media',
							'edit_url' => '',
						),
					),
				),
				'urls' => array(),
			)
		);

		$text = strtolower( implode( ' ', array_column( $actions, 'title' ) ) . ' ' . implode( ' ', array_column( $actions, 'reason' ) ) );

		$this->assertStringContainsString( 'più url', $text );
		$this->assertStringNotContainsString( 'cannibal', $text );
	}

	public function test_only_highest_priority_action_survives_per_service(): void {
		$actions = $this->center->compile(
			array(
				'local' => array(
					'business_entity' => array(),
					'services' => array(
						'piastrelle' => array(
							'priority' => 'P1',
							'label' => 'Posa piastrelle',
							'coverage' => 'basic',
							'primary_candidate' => array( 'edit_url' => '' ),
							'warnings' => array( 'Più pagine principali da verificare.' ),
							'roles' => array( 'case_study' => 0 ),
						),
					),
				),
				'verification' => array( 'status' => 'complete', 'stale' => false, 'issues' => array() ),
				'link_health' => array( 'status' => 'complete', 'stale' => false, 'summary' => array() ),
				'links' => array(
					'items' => array(
						array(
							'priority' => 1,
							'source_id' => 1,
							'target_id' => 2,
							'service_key' => 'piastrelle',
							'service_label' => 'Posa piastrelle',
							'reason' => 'Link utile',
							'anchor' => 'posa piastrelle a Trieste',
							'insertion_hint' => 'Paragrafo pertinente',
							'edit_url' => '',
						),
					),
				),
				'gsc' => array(),
				'urls' => array(),
			)
		);

		$service_actions = array_values(
			array_filter(
				$actions,
				static fn( array $item ): bool => 'piastrelle' === ( $item['service_key'] ?? '' )
			)
		);

		$this->assertCount( 1, $service_actions );
		$this->assertStringContainsString( 'Chiarisci', $service_actions[0]['title'] );
	}
}
