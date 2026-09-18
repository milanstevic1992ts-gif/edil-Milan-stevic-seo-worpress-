<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Effective_Meta {
	private const MAX_BODY_BYTES = 524288;

	public function read_url( string $url ): array {
		$url = esc_url_raw( $url );

		if ( ! $this->is_internal_url( $url ) ) {
			return $this->error_result( $url, 'URL esterno o non valido.' );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 6,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BODY_BYTES,
				'headers'             => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
				'user-agent'          => 'EMS-Local-SEO/' . EMS_LOCAL_SEO_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error_result( $url, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return array_merge(
				$this->empty_result( $url ),
				array(
					'status' => $status,
					'error'  => 'Risposta HTML vuota.',
				)
			);
		}

		$parsed = $this->parse_html( $body );

		return array_merge(
			$parsed,
			array(
				'url'        => $url,
				'status'     => $status,
				'error'      => '',
				'fetched_at' => current_time( 'mysql' ),
				'source'     => 'public_html',
				'truncated'  => strlen( $body ) >= self::MAX_BODY_BYTES,
			)
		);
	}

	public function read_post( WP_Post|int $post ): array {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return $this->error_result( '', 'Contenuto WordPress non trovato.' );
		}

		$url = get_permalink( $post );
		if ( ! is_string( $url ) || '' === $url ) {
			return $this->error_result( '', 'Permalink non disponibile.' );
		}

		$result          = $this->read_url( $url );
		$result['post_id'] = $post->ID;
		$result['saved'] = $this->saved_meta( $post );

		return $result;
	}

	public function saved_meta( WP_Post|int $post ): array {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		return array(
			'ems_title'       => trim( (string) get_post_meta( $post->ID, '_ems_seo_title', true ) ),
			'ems_description' => trim( (string) get_post_meta( $post->ID, '_ems_seo_description', true ) ),
			'ems_canonical'   => trim( (string) get_post_meta( $post->ID, '_ems_seo_canonical', true ) ),
			'ems_noindex'     => (bool) get_post_meta( $post->ID, '_ems_seo_noindex', true ),
			'ems_nofollow'    => (bool) get_post_meta( $post->ID, '_ems_seo_nofollow', true ),
			'wp_title'        => get_the_title( $post ),
			'permalink'       => get_permalink( $post ),
		);
	}

	private function parse_html( string $html ): array {
		if ( class_exists( 'DOMDocument' ) ) {
			$dom = $this->parse_with_dom( $html );
			if ( ! empty( $dom ) ) {
				return $dom;
			}
		}

		return $this->parse_with_regex( $html );
	}

	private function parse_with_dom( string $html ): array {
		$document = new DOMDocument();

		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return array();
		}

		$xpath = new DOMXPath( $document );

		$titles       = $this->node_texts( $xpath->query( '//title' ) );
		$descriptions = $this->attribute_values(
			$xpath->query( '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]' ),
			'content'
		);
		$robots = $this->attribute_values(
			$xpath->query( '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]' ),
			'content'
		);
		$canonicals = $this->attribute_values(
			$xpath->query( '//link[contains(concat(" ", normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")), " "), " canonical ")]' ),
			'href'
		);

		return $this->normalize_parsed( $titles, $descriptions, $canonicals, $robots );
	}

	private function parse_with_regex( string $html ): array {
		$titles = array();
		if ( preg_match_all( '/<title\b[^>]*>(.*?)<\/title>/is', $html, $matches ) ) {
			foreach ( $matches[1] as $value ) {
				$titles[] = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		$descriptions = $this->regex_meta_values( $html, 'description' );
		$robots       = $this->regex_meta_values( $html, 'robots' );
		$canonicals   = array();

		if ( preg_match_all( '/<link\b[^>]*>/is', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$rel  = $this->regex_attribute( $tag, 'rel' );
				$href = $this->regex_attribute( $tag, 'href' );
				if ( '' !== $href && preg_match( '/(?:^|\s)canonical(?:\s|$)/i', $rel ) ) {
					$canonicals[] = $href;
				}
			}
		}

		return $this->normalize_parsed( $titles, $descriptions, $canonicals, $robots );
	}

	private function regex_meta_values( string $html, string $name ): array {
		$values = array();

		if ( ! preg_match_all( '/<meta\b[^>]*>/is', $html, $tags ) ) {
			return $values;
		}

		foreach ( $tags[0] as $tag ) {
			$tag_name = mb_strtolower( $this->regex_attribute( $tag, 'name' ) );
			if ( $name !== $tag_name ) {
				continue;
			}

			$content = $this->regex_attribute( $tag, 'content' );
			if ( '' !== $content ) {
				$values[] = $content;
			}
		}

		return $values;
	}

	private function regex_attribute( string $tag, string $attribute ): string {
		$pattern = '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*(["\'])(.*?)\1/is';

		if ( preg_match( $pattern, $tag, $match ) ) {
			return trim( html_entity_decode( (string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		return '';
	}

	private function node_texts( DOMNodeList|false $nodes ): array {
		$values = array();

		if ( false === $nodes ) {
			return $values;
		}

		foreach ( $nodes as $node ) {
			$value = trim( (string) $node->textContent );
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}

		return $values;
	}

	private function attribute_values( DOMNodeList|false $nodes, string $attribute ): array {
		$values = array();

		if ( false === $nodes ) {
			return $values;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$value = trim( (string) $node->getAttribute( $attribute ) );
			if ( '' !== $value ) {
				$values[] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return $values;
	}

	private function normalize_parsed( array $titles, array $descriptions, array $canonicals, array $robots ): array {
		$titles       = $this->clean_values( $titles );
		$descriptions = $this->clean_values( $descriptions );
		$canonicals   = $this->clean_values( $canonicals );
		$robots       = $this->clean_values( $robots );

		return array(
			'title'           => $titles[0] ?? '',
			'description'     => $descriptions[0] ?? '',
			'canonical'       => $canonicals[0] ?? '',
			'robots'          => $robots[0] ?? '',
			'title_count'     => count( $titles ),
			'description_count' => count( $descriptions ),
			'canonical_count' => count( $canonicals ),
			'robots_count'    => count( $robots ),
			'all_titles'      => $titles,
			'all_descriptions'=> $descriptions,
			'all_canonicals'  => $canonicals,
			'all_robots'      => $robots,
			'noindex'         => $this->robots_has( $robots, 'noindex' ),
			'nofollow'        => $this->robots_has( $robots, 'nofollow' ),
		);
	}

	private function clean_values( array $values ): array {
		$clean = array();

		foreach ( $values as $value ) {
			$value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
			if ( '' !== $value ) {
				$clean[] = $value;
			}
		}

		return array_values( $clean );
	}

	private function robots_has( array $robots, string $directive ): bool {
		foreach ( $robots as $value ) {
			$parts = preg_split( '/[\s,]+/', mb_strtolower( $value ) );
			if ( in_array( $directive, (array) $parts, true ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_internal_url( string $url ): bool {
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$home_host = mb_strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$url_host  = mb_strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return '' !== $home_host && $home_host === $url_host;
	}

	private function empty_result( string $url ): array {
		return array(
			'url'               => $url,
			'status'            => 0,
			'error'             => '',
			'fetched_at'        => '',
			'source'            => 'public_html',
			'truncated'         => false,
			'title'             => '',
			'description'       => '',
			'canonical'         => '',
			'robots'            => '',
			'title_count'       => 0,
			'description_count' => 0,
			'canonical_count'   => 0,
			'robots_count'      => 0,
			'all_titles'        => array(),
			'all_descriptions'  => array(),
			'all_canonicals'    => array(),
			'all_robots'        => array(),
			'noindex'           => false,
			'nofollow'          => false,
		);
	}

	private function error_result( string $url, string $message ): array {
		$result          = $this->empty_result( $url );
		$result['error'] = $message;

		return $result;
	}
}
