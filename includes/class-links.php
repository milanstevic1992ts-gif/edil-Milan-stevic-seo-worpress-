<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Links {
	public const TRANSIENT_KEY = 'ems_local_seo_link_suggestions_v2';

	private EMS_Local_SEO_Local_Engine $local_engine;

	public function __construct( EMS_Local_SEO_Local_Engine $local_engine ) {
		$this->local_engine = $local_engine;
	}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 30 );
		add_action( 'admin_post_ems_local_seo_rebuild_links', array( $this, 'handle_rebuild' ) );
		add_action( 'save_post', array( $this, 'invalidate_cache' ), 20, 1 );
	}

	public function register_page(): void {
		add_submenu_page(
			'ems-local-seo',
			'Link interni EMS SEO',
			'Link interni',
			'manage_options',
			'ems-local-seo-links',
			array( $this, 'render' )
		);
	}

	public function invalidate_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	public function handle_rebuild(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
		}

		check_admin_referer( 'ems_local_seo_rebuild_links' );
		delete_transient( self::TRANSIENT_KEY );
		$this->get_suggestions( true );

		wp_safe_redirect( admin_url( 'admin.php?page=ems-local-seo-links&ems_links=done' ) );
		exit;
	}

	public function get_suggestions( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$architecture = $this->local_engine->build();
		$posts        = $this->load_posts();
		$documents    = array();

		foreach ( $posts as $post ) {
			$documents[ $post->ID ] = array(
				'post'   => $post,
				'tokens' => $this->document_tokens( $post ),
				'links'  => $this->linked_post_ids( $post->post_content ),
			);
		}

		$suggestions = $this->architecture_suggestions( $architecture, $documents );
		$suggestions = $this->merge_unique_suggestions(
			$suggestions,
			$this->semantic_fallback_suggestions( $documents )
		);

		usort(
			$suggestions,
			static function ( array $a, array $b ): int {
				if ( $a['priority'] === $b['priority'] ) {
					return $b['score'] <=> $a['score'];
				}

				return $a['priority'] <=> $b['priority'];
			}
		);

		$result = array(
			'generated_at' => current_time( 'mysql' ),
			'count'        => count( $suggestions ),
			'data_level'   => (string) ( $architecture['data_state']['level'] ?? 'iniziale' ),
			'items'        => array_slice( $suggestions, 0, 150 ),
		);

		set_transient( self::TRANSIENT_KEY, $result, 6 * HOUR_IN_SECONDS );

		return $result;
	}

	private function load_posts(): array {
		return get_posts(
			array(
				'post_type'      => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	}

	private function architecture_suggestions( array $architecture, array $documents ): array {
		$items = array();

		foreach ( (array) ( $architecture['services'] ?? array() ) as $service ) {
			$primary = $service['primary_candidate'] ?? null;
			if ( ! is_array( $primary ) || empty( $primary['id'] ) ) {
				continue;
			}

			$primary_id = (int) $primary['id'];

			foreach ( (array) ( $service['items'] ?? array() ) as $item ) {
				$source_id = (int) ( $item['id'] ?? 0 );
				if ( ! $source_id || $source_id === $primary_id || ! isset( $documents[ $source_id ], $documents[ $primary_id ] ) ) {
					continue;
				}

				if ( ! in_array( $primary_id, $documents[ $source_id ]['links'], true ) ) {
					$items[] = $this->make_suggestion(
						$documents[ $source_id ]['post'],
						$documents[ $primary_id ]['post'],
						$item,
						$primary,
						1,
						min( 100, 70 + (int) round( $primary['confidence'] * 0.25 ) ),
						'architecture',
						'Il contenuto di supporto appartiene allo stesso servizio ma non collega ancora la pagina principale candidata.'
					);
				}
			}

			$support = array_values(
				array_filter(
					(array) ( $service['items'] ?? array() ),
					static fn( array $item ): bool => (int) ( $item['id'] ?? 0 ) !== $primary_id
						&& in_array( (string) ( $item['role'] ?? '' ), array( 'guide', 'case_study', 'quiz' ), true )
				)
			);

			usort(
				$support,
				static fn( array $a, array $b ): int => $b['confidence'] <=> $a['confidence']
			);

			foreach ( array_slice( $support, 0, 4 ) as $target ) {
				$target_id = (int) $target['id'];
				if ( ! isset( $documents[ $target_id ], $documents[ $primary_id ] ) ) {
					continue;
				}
				if ( in_array( $target_id, $documents[ $primary_id ]['links'], true ) ) {
					continue;
				}

				$items[] = $this->make_suggestion(
					$documents[ $primary_id ]['post'],
					$documents[ $target_id ]['post'],
					$primary,
					$target,
					2,
					min( 95, 58 + (int) round( $target['confidence'] * 0.22 ) ),
					'architecture',
					'La pagina servizio può rafforzare fiducia e utilità collegando un contenuto di supporto reale e pertinente.'
				);
			}
		}

		return $items;
	}

	private function semantic_fallback_suggestions( array $documents ): array {
		$suggestions = array();

		foreach ( $documents as $source_id => $source ) {
			if ( count( $source['tokens'] ) < 2 ) {
				continue;
			}

			$candidates = array();
			foreach ( $documents as $target_id => $target ) {
				if ( $source_id === $target_id || in_array( $target_id, $source['links'], true ) ) {
					continue;
				}

				$score = $this->similarity_score( $source['tokens'], $target['tokens'] );
				if ( $score < 24 ) {
					continue;
				}

				$candidates[] = array(
					'target_id' => $target_id,
					'score'     => $score,
				);
			}

			usort(
				$candidates,
				static fn( array $a, array $b ): int => $b['score'] <=> $a['score']
			);

			foreach ( array_slice( $candidates, 0, 2 ) as $candidate ) {
				$target_id = (int) $candidate['target_id'];
				$source_class = $this->local_engine->classify_post( $source['post'] );
				$target_class = $this->local_engine->classify_post( $documents[ $target_id ]['post'] );

				$suggestions[] = $this->make_suggestion(
					$source['post'],
					$documents[ $target_id ]['post'],
					$source_class,
					$target_class,
					3,
					(int) $candidate['score'],
					'semantic',
					$this->reason( $source['tokens'], $documents[ $target_id ]['tokens'] )
				);
			}
		}

		return $suggestions;
	}

	private function make_suggestion(
		WP_Post $source,
		WP_Post $target,
		array $source_class,
		array $target_class,
		int $priority,
		int $score,
		string $kind,
		string $reason
	): array {
		return array(
			'source_id'      => $source->ID,
			'source_title'   => get_the_title( $source ),
			'source_url'     => get_permalink( $source ),
			'target_id'      => $target->ID,
			'target_title'   => get_the_title( $target ),
			'target_url'     => get_permalink( $target ),
			'edit_url'       => get_edit_post_link( $source->ID, 'raw' ),
			'priority'       => $priority,
			'score'          => max( 0, min( 100, $score ) ),
			'kind'           => $kind,
			'reason'         => $reason,
			'service_key'    => (string) ( $target_class['service_key'] ?? '' ),
			'service_label'  => (string) ( $target_class['service_label'] ?? '' ),
			'target_role'    => (string) ( $target_class['role'] ?? '' ),
			'anchor'         => $this->suggest_anchor( $target, $target_class ),
			'insertion_hint' => $this->insertion_hint( $source, $target, $target_class ),
		);
	}

	private function merge_unique_suggestions( array $primary, array $secondary ): array {
		$seen = array();
		$out  = array();

		foreach ( array_merge( $primary, $secondary ) as $item ) {
			$key = (int) $item['source_id'] . '>' . (int) $item['target_id'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$out[] = $item;
		}

		return $out;
	}

	private function suggest_anchor( WP_Post $target, array $class ): string {
		$service = trim( (string) ( $class['service_label'] ?? '' ) );
		$role    = (string) ( $class['role'] ?? '' );

		if ( 'service' === $role && '' !== $service ) {
			$base = preg_replace( '/\s*\/.*$/u', '', $service );
			$base = trim( (string) $base );

			return '' !== $base ? $base . ' a Trieste' : get_the_title( $target );
		}

		$title = trim( (string) get_the_title( $target ) );
		if ( mb_strlen( $title ) <= 70 ) {
			return $title;
		}

		return mb_substr( $title, 0, 67 ) . '…';
	}

	private function insertion_hint( WP_Post $source, WP_Post $target, array $target_class ): string {
		$content = (string) $source->post_content;
		if ( '' === trim( $content ) ) {
			return 'Inserire solo dove il collegamento è utile al lettore.';
		}

		$parts = preg_split( '/<\/p>|(?:\r?\n){2,}/i', $content );
		$needles = array_values(
			array_filter(
				array_unique(
					array_merge(
						$this->simple_tokens( get_the_title( $target ) ),
						$this->simple_tokens( (string) ( $target_class['service_label'] ?? '' ) )
					)
				)
			)
		);

		$best = '';
		$best_score = 0;

		foreach ( (array) $parts as $part ) {
			$plain = trim( wp_strip_all_tags( strip_shortcodes( (string) $part ) ) );
			if ( mb_strlen( $plain ) < 40 ) {
				continue;
			}

			$normalized = remove_accents( mb_strtolower( $plain ) );
			$score = 0;
			foreach ( $needles as $needle ) {
				if ( str_contains( $normalized, $needle ) ) {
					$score++;
				}
			}

			if ( $score > $best_score ) {
				$best_score = $score;
				$best = $plain;
			}
		}

		if ( '' === $best ) {
			return 'Nessun punto forte rilevato automaticamente: inserire solo in un paragrafo realmente pertinente.';
		}

		$best = preg_replace( '/\s+/u', ' ', $best );
		if ( mb_strlen( $best ) > 180 ) {
			$best = mb_substr( $best, 0, 177 ) . '…';
		}

		return 'Vicino a: “' . $best . '”';
	}

	private function simple_tokens( string $text ): array {
		$text = remove_accents( mb_strtolower( wp_strip_all_tags( $text ) ) );
		$raw = preg_split( '/[^a-z0-9]+/u', $text );

		$stop = array_flip( array( 'trieste','edil','milan','stevic','della','delle','degli','dello','alla','alle','allo','con','per','una','uno','del','dei','dal','come' ) );
		$out = array();

		foreach ( (array) $raw as $token ) {
			if ( mb_strlen( $token ) < 4 || isset( $stop[ $token ] ) || is_numeric( $token ) ) {
				continue;
			}
			$out[] = $token;
		}

		return array_values( array_unique( $out ) );
	}

	private function document_tokens( WP_Post $post ): array {
		$parts = array(
			get_the_title( $post ),
			(string) get_post_meta( $post->ID, '_ems_seo_primary_query', true ),
			(string) get_post_meta( $post->ID, '_ems_seo_service_name', true ),
			(string) get_post_meta( $post->ID, '_ems_case_study_service', true ),
			wp_strip_all_tags( strip_shortcodes( $post->post_content ) ),
		);

		$text = mb_strtolower( implode( ' ', $parts ) );
		$text = remove_accents( $text );
		$text = preg_replace( '/[^a-z0-9]+/u', ' ', $text );
		$raw  = preg_split( '/\s+/u', (string) $text );

		$stopwords = array_flip(
			array(
				'anche','che','chi','con','come','dalla','dalle','degli','della','delle','dello','nella','nelle','sulla','sulle','alla',
				'alle','allo','questo','questa','questi','queste','nostro','nostra','tuo','tua','trieste','edil','milan','stevic','servizio',
				'servizi','casa','dopo','prima','quando','dove','quale','sono','puo',
			)
		);

		$weights = array();
		foreach ( (array) $raw as $token ) {
			$token = trim( (string) $token );
			if ( mb_strlen( $token ) < 4 || isset( $stopwords[ $token ] ) || is_numeric( $token ) ) {
				continue;
			}
			$weights[ $token ] = min( 5, ( $weights[ $token ] ?? 0 ) + 1 );
		}

		foreach ( $this->simple_tokens(
			get_the_title( $post ) . ' ' .
			(string) get_post_meta( $post->ID, '_ems_seo_primary_query', true ) . ' ' .
			(string) get_post_meta( $post->ID, '_ems_seo_service_name', true )
		) as $token ) {
			$weights[ $token ] = min( 8, ( $weights[ $token ] ?? 0 ) + 3 );
		}

		arsort( $weights );

		return array_slice( $weights, 0, 80, true );
	}

	private function linked_post_ids( string $html ): array {
		$ids = array();

		if ( preg_match_all( '/href=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$href = html_entity_decode( (string) $href, ENT_QUOTES | ENT_HTML5 );

				if ( str_starts_with( $href, '/' ) ) {
					$href = home_url( $href );
				}

				if ( ! str_starts_with( $href, home_url() ) ) {
					continue;
				}

				$id = url_to_postid( $href );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function similarity_score( array $source, array $target ): int {
		$common = array_intersect_key( $source, $target );
		if ( empty( $common ) ) {
			return 0;
		}

		$weighted = 0;
		foreach ( $common as $token => $source_weight ) {
			$weighted += min( (int) $source_weight, (int) $target[ $token ] );
		}

		$denominator = max( 1, min( array_sum( $source ), array_sum( $target ) ) );

		return (int) round( 100 * $weighted / $denominator );
	}

	private function reason( array $source, array $target ): string {
		$common = array_keys( array_intersect_key( $source, $target ) );

		return empty( $common )
			? 'Affinità semantica da verificare.'
			: 'Tema comune: ' . implode( ', ', array_slice( $common, 0, 4 ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = $this->get_suggestions();
		?>
		<div class="wrap ems-seo-wrap">
			<div class="ems-seo-hero">
				<div>
					<span class="ems-seo-kicker">ARCHITETTURA DEL SITO</span>
					<h1>Suggerimenti link interni</h1>
					<p>Prima struttura servizio→supporti, poi affinità semantica. EMS propone anchor e punto di inserimento ma non modifica automaticamente i contenuti.</p>
				</div>
				<div class="ems-seo-score"><?php echo esc_html( (string) $result['count'] ); ?><small>idee · dati <?php echo esc_html( $result['data_level'] ); ?></small></div>
			</div>

			<p>Ultima analisi: <strong><?php echo esc_html( (string) $result['generated_at'] ); ?></strong>. Un suggerimento è utile solo se migliora davvero il percorso del lettore.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ems_local_seo_rebuild_links">
				<?php wp_nonce_field( 'ems_local_seo_rebuild_links' ); ?>
				<?php submit_button( 'Ricalcola suggerimenti', 'secondary', 'submit', false ); ?>
			</form>

			<div class="ems-seo-panel ems-seo-table-wrap">
				<table class="widefat striped">
					<thead><tr><th>Priorità</th><th>Da</th><th>Verso</th><th>Anchor suggerita</th><th>Dove</th><th>Motivo</th><th></th></tr></thead>
					<tbody>
					<?php if ( empty( $result['items'] ) ) : ?>
						<tr><td colspan="7">Nessun suggerimento sufficientemente utile rilevato.</td></tr>
					<?php else : ?>
						<?php foreach ( $result['items'] as $item ) : ?>
							<tr>
								<td><strong>P<?php echo esc_html( (string) $item['priority'] ); ?></strong><br><small><?php echo esc_html( $item['kind'] ); ?> · <?php echo esc_html( (string) $item['score'] ); ?>%</small></td>
								<td><a href="<?php echo esc_url( $item['source_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['source_title'] ); ?></a></td>
								<td><a href="<?php echo esc_url( $item['target_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['target_title'] ); ?></a></td>
								<td><code><?php echo esc_html( $item['anchor'] ); ?></code></td>
								<td><small><?php echo esc_html( $item['insertion_hint'] ); ?></small></td>
								<td><?php echo esc_html( $item['reason'] ); ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( $item['edit_url'] ); ?>">Apri sorgente</a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
