<?php

use PHPUnit\Framework\TestCase;

final class SearchConsoleShapeTest extends TestCase {
	public function test_empty_list_is_valid_empty_report(): void {
		$adapter = new EMS_Local_SEO_Search_Console();
		$method  = new ReflectionMethod( EMS_Local_SEO_Search_Console::class, 'extract_rows' );
		$method->setAccessible( true );

		$result = $method->invoke( $adapter, array() );

		$this->assertSame( array(), $result );
	}

	public function test_rows_wrapper_is_accepted(): void {
		$adapter = new EMS_Local_SEO_Search_Console();
		$method  = new ReflectionMethod( EMS_Local_SEO_Search_Console::class, 'extract_rows' );
		$method->setAccessible( true );

		$rows = array(
			array(
				'keys' => array( 'bagno trieste', 'https://example.test/bagno/' ),
				'clicks' => 2,
				'impressions' => 30,
			),
		);

		$result = $method->invoke( $adapter, array( 'rows' => $rows ) );

		$this->assertSame( $rows, $result );
	}

	public function test_unexpected_associative_payload_returns_error(): void {
		$adapter = new EMS_Local_SEO_Search_Console();
		$method  = new ReflectionMethod( EMS_Local_SEO_Search_Console::class, 'extract_rows' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$adapter,
			array(
				'changedShape' => array( 'unexpected' => true ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ems_gsc_unexpected_shape', $result->get_error_code() );
	}
}
