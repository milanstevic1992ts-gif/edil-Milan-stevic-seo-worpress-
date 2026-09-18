<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'EMS_LOCAL_SEO_VERSION', '1.0.1-test' );

$GLOBALS['ems_test_can_manage'] = true;
$GLOBALS['ems_test_nonce_calls'] = array();

if ( ! function_exists( 'url_to_postid' ) ) {
	function url_to_postid( $url ) {
		return 0;
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $post_id, $context = 'display' ) {
		return '';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string ) {
		return strip_tags( $string );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['ems_test_can_manage'] );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action ) {
		$GLOBALS['ems_test_nonce_calls'][] = $action;
		return 1;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '' ) {
		throw new RuntimeException( (string) $message );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) {
		return $text;
	}
}

if ( ! class_exists( 'EMS_Local_SEO_Search_Console' ) ) {
	class EMS_Local_SEO_Search_Console {
		public function get_snapshot(): array {
			return array();
		}

		public function get_last_error(): array {
			return array();
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-opportunities.php';
require_once dirname( __DIR__ ) . '/includes/class-effective-meta.php';
require_once dirname( __DIR__ ) . '/includes/class-link-health.php';
