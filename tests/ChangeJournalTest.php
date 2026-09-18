<?php

use PHPUnit\Framework\TestCase;

final class ChangeJournalTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ems_test_options'] = array();
	}

	public function test_journal_entry_keeps_only_safe_metadata(): void {
		$journal = new EMS_Local_SEO_Change_Journal();

		$entry = $journal->normalize_entry(
			'post_updated',
			array(
				'post_id' => 42,
				'service_key' => 'bagno',
				'source' => 'wordpress',
				'email' => 'should-not-be-stored@example.com',
				'content' => 'secret page text',
				'phone' => '+39 000',
			)
		);

		$this->assertSame(
			array( 'timestamp', 'type', 'post_id', 'service_key', 'source' ),
			array_keys( $entry )
		);
		$this->assertSame( 42, $entry['post_id'] );
		$this->assertSame( 'bagno', $entry['service_key'] );
	}

	public function test_unknown_event_type_is_rejected(): void {
		$journal = new EMS_Local_SEO_Change_Journal();
		$entry = $journal->normalize_entry( 'send_personal_data_somewhere', array() );

		$this->assertSame( '', $entry['type'] );
	}

	public function test_journal_is_bounded_to_200_entries(): void {
		$journal = new EMS_Local_SEO_Change_Journal();

		for ( $i = 1; $i <= 205; $i++ ) {
			$journal->record(
				'post_updated',
				array(
					'post_id' => $i,
					'service_key' => 'bagno',
					'source' => 'wordpress',
				)
			);
		}

		$this->assertCount( 200, $journal->get_entries() );
		$this->assertSame( 205, $journal->get_entries()[0]['post_id'] );
	}
}
