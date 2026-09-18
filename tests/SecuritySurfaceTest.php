<?php

use PHPUnit\Framework\TestCase;

final class SecuritySurfaceTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ems_test_can_manage'] = true;
		$GLOBALS['ems_test_nonce_calls'] = array();
	}

	public function test_link_health_guard_rejects_user_without_manage_options(): void {
		$GLOBALS['ems_test_can_manage'] = false;

		$instance = new EMS_Local_SEO_Link_Health();
		$method   = new ReflectionMethod( EMS_Local_SEO_Link_Health::class, 'guard_action' );
		$method->setAccessible( true );

		$this->expectException( RuntimeException::class );

		try {
			$method->invoke( $instance, 'ems_test_nonce' );
		} finally {
			$this->assertSame( array(), $GLOBALS['ems_test_nonce_calls'] );
		}
	}

	public function test_link_health_guard_checks_nonce_for_admin(): void {
		$GLOBALS['ems_test_can_manage'] = true;

		$instance = new EMS_Local_SEO_Link_Health();
		$method   = new ReflectionMethod( EMS_Local_SEO_Link_Health::class, 'guard_action' );
		$method->setAccessible( true );

		$method->invoke( $instance, 'ems_test_nonce' );

		$this->assertSame( array( 'ems_test_nonce' ), $GLOBALS['ems_test_nonce_calls'] );
	}
}
