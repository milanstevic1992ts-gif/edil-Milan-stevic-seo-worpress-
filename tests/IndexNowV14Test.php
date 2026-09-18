<?php

use PHPUnit\Framework\TestCase;

final class IndexNowV14Test extends TestCase {
	public function test_indexnow_status_messages_are_conservative(): void {
		$this->assertStringContainsString( 'ricevuto', strtolower( EMS_Local_SEO_IndexNow::describe_status( 200 ) ) );
		$this->assertStringContainsString( 'verifica', strtolower( EMS_Local_SEO_IndexNow::describe_status( 202 ) ) );
		$this->assertStringContainsString( 'troppe', strtolower( EMS_Local_SEO_IndexNow::describe_status( 429 ) ) );
	}
}
