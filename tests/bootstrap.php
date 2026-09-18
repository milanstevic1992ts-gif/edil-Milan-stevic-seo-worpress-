<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'EMS_LOCAL_SEO_VERSION', '1.0.1-test' );

$GLOBALS['ems_test_can_manage'] = true;
$GLOBALS['ems_test_nonce_calls'] = array();
$GLOBALS['ems_test_meta'] = array();
$GLOBALS['ems_test_options'] = array();

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'page';
		public string $post_name = '';
		public string $post_excerpt = '';
		public string $post_content = '';
		public string $post_title = '';

		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return '2026-09-18 09:30:00';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['ems_test_options'] ) ? $GLOBALS['ems_test_options'][ $key ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['ems_test_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		return $GLOBALS['ems_test_meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		return $post instanceof WP_Post ? $post->post_title : '';
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0 ) {
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		return 'https://triesteincostruzione.com/test-' . $id . '/';
	}
}

if ( ! function_exists( 'strip_shortcodes' ) ) {
	function strip_shortcodes( $content ) {
		return $content;
	}
}

if ( ! function_exists( 'remove_accents' ) ) {
	function remove_accents( $text ) {
		$map = array( 'à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','À'=>'A','È'=>'E','É'=>'E','Ì'=>'I','Ò'=>'O','Ù'=>'U' );
		return strtr( $text, $map );
	}
}

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

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = (string) $code;
			$this->message = (string) $message;
			$this->data = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function __construct( $method = 'GET', $route = '' ) {}
		public function set_query_params( $params ) {}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-search-console.php';
require_once dirname( __DIR__ ) . '/includes/class-opportunities.php';
require_once dirname( __DIR__ ) . '/includes/class-effective-meta.php';
require_once dirname( __DIR__ ) . '/includes/class-link-health.php';
require_once dirname( __DIR__ ) . '/includes/class-local-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-change-journal.php';
require_once dirname( __DIR__ ) . '/includes/class-action-center.php';
