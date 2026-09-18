<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridge consent-aware tra CTA/moduli del sito e lo store aggregato v1.2.
 *
 * Nessun dato personale viene salvato: solo tipo evento, giorno, pagina,
 * servizio e fonte aggregata.
 */
final class EMS_Local_SEO_Contacts {
	public const TYPES = array(
		'whatsapp'     => 'WhatsApp',
		'phone'        => 'Telefono',
		'email'        => 'Email',
		'form_success' => 'Modulo inviato',
	);

	public const SOURCES = array(
		'google'       => 'Google organico',
		'annunci'      => 'Annunci',
		'altri_motori' => 'Altri motori',
		'social'       => 'Social',
		'altri_siti'   => 'Altri siti',
		'diretto'      => 'Diretto',
		'sconosciuta'  => 'Non rilevata',
	);

	private EMS_Local_SEO_Conversion_Signals $signals;

	public function __construct( EMS_Local_SEO_Conversion_Signals $signals ) {
		$this->signals = $signals;
	}

	public static function is_enabled(): bool {
		return (bool) EMS_Local_SEO_Settings::get( 'enable_contacts', 0 );
	}

	public static function tracking_allowed(): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		/**
		 * Un CMP/theme può restituire true solo dopo consenso analytics.
		 * Default false: nessun tracking frontend senza integrazione esplicita.
		 */
		return (bool) apply_filters( 'ems_local_seo_contacts_tracking_allowed', false );
	}

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker' ) );

		add_action( 'grunion_after_message_sent', array( $this, 'on_form_sent' ) );
		add_action( 'wpcf7_mail_sent', array( $this, 'on_form_sent' ) );
		add_action( 'wpforms_process_complete', array( $this, 'on_form_sent' ) );
		add_action( 'gform_after_submission', array( $this, 'on_form_sent' ) );
		add_action( 'elementor_pro/forms/new_record', array( $this, 'on_form_sent' ) );
	}

	public function enqueue_tracker(): void {
		if ( ! self::tracking_allowed() || is_admin() ) {
			return;
		}

		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return;
		}

		$post_id = is_singular() ? (int) get_queried_object_id() : 0;
		$service = $post_id > 0 ? sanitize_key( (string) get_post_meta( $post_id, '_ems_local_service_key', true ) ) : '';

		wp_register_script( 'ems-seo-contacts', false, array(), EMS_LOCAL_SEO_VERSION, true );
		wp_enqueue_script( 'ems-seo-contacts' );

		wp_add_inline_script(
			'ems-seo-contacts',
			'window.emsSeoContacts=' . wp_json_encode(
				array(
					'url'     => rest_url( 'ems-local-seo/v1/contact-event' ),
					'postId'  => $post_id,
					'service' => $service,
				)
			) . ';' . self::tracker_js()
		);
	}

	public static function tracker_js(): string {
		return <<<'JS'
(function(){
var c=window.emsSeoContacts;if(!c||!window.JSON)return;
function source(){
 var q=new URLSearchParams(location.search),r=document.referrer||'',h='',m=(q.get('utm_medium')||'').toLowerCase(),u=(q.get('utm_source')||'').toLowerCase(),f='diretto';
 try{h=r?new URL(r).hostname.toLowerCase():''}catch(e){}
 if(q.get('gclid')||q.get('gbraid')||q.get('wbraid')||q.get('msclkid')||/^(cpc|ppc|paid|paidsearch|paid_social)$/.test(m))f='annunci';
 else if(/(^|.)google./.test(h))f='google';
 else if(/(^|.)(bing|duckduckgo|yahoo|ecosia|qwant|yandex)./.test(h))f='altri_motori';
 else if(/(facebook|instagram|tiktok|linkedin|pinterest|whatsapp).|(^|.)t.co$|(^|.)lnkd.in$/.test(h)||/^(facebook|instagram|tiktok|social|ig|fb)/.test(u))f='social';
 else if(h&&h!==location.hostname)f='altri_siti';
 else if(h===location.hostname)f='sconosciuta';
 return f;
}
var F=source(),last={};
function type(a){
 var d=(a.getAttribute('data-ems-contact')||'').toLowerCase();if(d)return d;
 var h=(a.getAttribute('href')||'').trim().toLowerCase();
 if(h.indexOf('tel:')===0)return'phone';
 if(h.indexOf('mailto:')===0)return'email';
 if(h.indexOf('whatsapp:')===0||/^(https?:)?//(wa.me|api.whatsapp.com|web.whatsapp.com|wa.link)//.test(h))return'whatsapp';
 return'';
}
function send(t){
 if(!/^(whatsapp|phone|email)$/.test(t))return;
 var now=Date.now(),k=t+'|'+location.pathname;if(last[k]&&now-last[k]<30000)return;last[k]=now;
 var body=JSON.stringify({event:t,source:F,post_id:c.postId||0,service_key:c.service||''});
 if(navigator.sendBeacon){navigator.sendBeacon(c.url,new Blob([body],{type:'text/plain'}));}
 else if(window.fetch){fetch(c.url,{method:'POST',body:body,keepalive:true,credentials:'omit',headers:{'Content-Type':'text/plain'}});}
}
document.addEventListener('click',function(e){
 var a=e.target&&e.target.closest?e.target.closest('a[href],[data-ems-contact]'):null;
 if(!a)return;var t=type(a);if(t)send(t);
},true);
document.addEventListener('submit',function(e){
 var f=e.target;if(!f||f.tagName!=='FORM')return;
 [['ems_analytics_ok','1'],['ems_source',F],['ems_post_id',String(c.postId||0)],['ems_service_key',c.service||'']].forEach(function(p){
  var i=f.querySelector('input[name="'+p[0]+'"]');if(!i){i=document.createElement('input');i.type='hidden';i.name=p[0];f.appendChild(i)}i.value=p[1];
 });
},true);
})();
JS;
	}

	public function register_route(): void {
		register_rest_route(
			'ems-local-seo/v1',
			'/contact-event',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_event' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle_event( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( null, 204 );

		if ( ! self::tracking_allowed() || $this->is_bot() || ! $this->same_origin_request( $request ) ) {
			return $response;
		}

		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$event = sanitize_key( (string) ( $data['event'] ?? '' ) );
		if ( ! isset( self::TYPES[ $event ] ) || 'form_success' === $event ) {
			return $response;
		}

		$this->signals->record(
			$event,
			array(
				'post_id'     => max( 0, (int) ( $data['post_id'] ?? 0 ) ),
				'service_key' => sanitize_key( (string) ( $data['service_key'] ?? '' ) ),
				'source'      => $this->normalize_source( (string) ( $data['source'] ?? '' ) ),
			)
		);

		return $response;
	}

	public function on_form_sent(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( empty( $_POST['ems_analytics_ok'] ) || '1' !== (string) wp_unslash( $_POST['ems_analytics_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$this->signals->record(
			'form_success',
			array(
				'post_id'     => isset( $_POST['ems_post_id'] ) ? max( 0, (int) $_POST['ems_post_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'service_key' => isset( $_POST['ems_service_key'] ) ? sanitize_key( (string) wp_unslash( $_POST['ems_service_key'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
				'source'      => isset( $_POST['ems_source'] ) ? $this->normalize_source( (string) wp_unslash( $_POST['ems_source'] ) ) : 'sconosciuta', // phpcs:ignore WordPress.Security.NonceVerification
			)
		);
	}

	public function summary( int $days = 28 ): array {
		return $this->signals->summary( $days );
	}

	private function normalize_source( string $source ): string {
		$source = sanitize_key( $source );
		return isset( self::SOURCES[ $source ] ) ? $source : 'sconosciuta';
	}

	private function same_origin_request( WP_REST_Request $request ): bool {
		$home_host = mb_strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( '' === $home_host ) {
			return false;
		}

		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin ) {
			return mb_strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) === $home_host;
		}

		$referer = (string) $request->get_header( 'referer' );
		if ( '' !== $referer ) {
			return mb_strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) ) === $home_host;
		}

		return false;
	}

	private function is_bot(): bool {
		$ua = mb_strtolower( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		if ( '' === $ua ) {
			return false;
		}

		return (bool) preg_match( '/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|headless|lighthouse|pagespeed/', $ua );
	}
}
