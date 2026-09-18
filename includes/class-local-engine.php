<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Local_Engine {
	public const TRANSIENT_KEY = 'ems_local_seo_local_engine_v1';
	public const HISTORY_OPTION = 'ems_local_seo_local_engine_history_v1';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 38 );
		add_action( 'admin_init', array( $this, 'maybe_observe' ), 40 );
		add_action( 'admin_post_ems_local_seo_rebuild_local_engine', array( $this, 'handle_rebuild' ) );
		add_action( 'save_post', array( $this, 'invalidate_cache' ), 20, 1 );
	}

	public function register_page(): void {
		add_submenu_page(
			'ems-local-seo',
			'Motore locale EMS SEO',
			'Motore locale',
			'manage_options',
			'ems-local-seo-local-engine',
			array( $this, 'render' )
		);
	}

	public function invalidate_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	public function maybe_observe(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return;
		}

		$this->build( true );
	}

	public function handle_rebuild(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
		}

		check_admin_referer( 'ems_local_seo_rebuild_local_engine' );
		delete_transient( self::TRANSIENT_KEY );
		$this->build( true );

		wp_safe_redirect( admin_url( 'admin.php?page=ems-local-seo-local-engine&ems_local=done' ) );
		exit;
	}

	public static function service_catalog(): array {
		return array(
			'ristrutturazioni' => array(
				'label'    => 'Impresa edile / Ristrutturazioni',
				'priority' => 'P1',
				'intents'  => array(
					'impresa edile trieste',
					'ristrutturazioni trieste',
					'ristrutturazione appartamento trieste',
					'servizi edili trieste',
				),
				'keywords' => array( 'ristrutturazione', 'ristrutturazioni', 'impresa edile', 'appartamento', 'servizi edili', 'ristrutturare' ),
			),
			'bagno' => array(
				'label'    => 'Ristrutturazione bagno',
				'priority' => 'P1',
				'intents'  => array(
					'ristrutturazione bagno trieste',
					'rifare bagno trieste',
					'ristrutturare bagno trieste',
				),
				'keywords' => array( 'bagno', 'ristrutturazione bagno', 'rifare bagno', 'doccia', 'vasca', 'sanitari' ),
			),
			'costi_bagno' => array(
				'label'    => 'Costi ristrutturazione bagno',
				'priority' => 'P1',
				'intents'  => array(
					'quanto costa rifare un bagno trieste',
					'costo ristrutturazione bagno trieste',
				),
				'keywords' => array( 'quanto costa', 'costo bagno', 'costi bagno', 'preventivo bagno', 'prezzo bagno' ),
			),
			'piastrelle' => array(
				'label'    => 'Posa piastrelle',
				'priority' => 'P1',
				'intents'  => array(
					'piastrellista trieste',
					'posa piastrelle trieste',
					'piastrellatura trieste',
				),
				'keywords' => array( 'piastrelle', 'piastrellista', 'piastrellatura', 'posa piastrelle', 'posa su posa', 'fughe', 'terrazzo' ),
			),
			'cartongesso' => array(
				'label'    => 'Cartongesso / Controsoffitti',
				'priority' => 'P2',
				'intents'  => array(
					'cartongesso trieste',
					'controsoffitto cartongesso trieste',
				),
				'keywords' => array( 'cartongesso', 'controsoffitto', 'veletta', 'velette' ),
			),
			'spc_lvt' => array(
				'label'    => 'Pavimenti SPC / LVT',
				'priority' => 'P2',
				'intents'  => array(
					'posa spc trieste',
					'pavimenti lvt trieste',
					'posa pavimento vinilico trieste',
				),
				'keywords' => array( 'spc', 'lvt', 'laminato', 'pavimento vinilico', 'pavimenti', 'autolivellante' ),
			),
			'pittura' => array(
				'label'    => 'Pittura / Rasatura',
				'priority' => 'P2',
				'intents'  => array(
					'imbianchino trieste',
					'pittura interni trieste',
					'rasatura muri trieste',
				),
				'keywords' => array( 'pittura', 'pitturazione', 'tinteggiatura', 'imbianchino', 'rasatura', 'stuccatura' ),
			),
			'preventivi' => array(
				'label'    => 'Preventivi / Progettazione',
				'priority' => 'P2',
				'intents'  => array(
					'preventivo ristrutturazione trieste',
					'progettazione ristrutturazione trieste',
					'computo metrico ristrutturazione trieste',
				),
				'keywords' => array( 'preventivo', 'progettazione', 'computo metrico', 'richiedi preventivo', 'costi ristrutturazione' ),
			),
		);
	}

	public static function role_catalog(): array {
		return array(
			'service'    => 'Pagina servizio principale',
			'guide'      => 'Guida / articolo di supporto',
			'quiz'       => 'Quiz / simulatore / richiesta',
			'case_study' => 'Lavoro reale / case study',
			'company'    => 'Azienda / fiducia / contatti',
			'other'      => 'Altro contenuto',
		);
	}

	public function build( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$catalog = self::service_catalog();
		$services = array();

		foreach ( $catalog as $key => $definition ) {
			$services[ $key ] = array(
				'key'               => $key,
				'label'             => $definition['label'],
				'priority'          => $definition['priority'],
				'intents'           => $definition['intents'],
				'items'             => array(),
				'roles'             => array_fill_keys( array_keys( self::role_catalog() ), 0 ),
				'primary_candidate' => null,
				'manual_primary'    => null,
				'coverage'          => 'missing',
				'warnings'          => array(),
			);
		}

		$unclassified = array();
		$manual_count = 0;

		foreach ( $posts as $post ) {
			$class = $this->classify_post( $post );

			if ( ! empty( $class['manual_service'] ) || ! empty( $class['manual_role'] ) ) {
				$manual_count++;
			}

			if ( '' === $class['service_key'] || ! isset( $services[ $class['service_key'] ] ) ) {
				$unclassified[] = $class;
				continue;
			}

			$key = $class['service_key'];
			$services[ $key ]['items'][] = $class;
			$role = $class['role'];
			if ( isset( $services[ $key ]['roles'][ $role ] ) ) {
				$services[ $key ]['roles'][ $role ]++;
			}

			if ( 'service' === $role ) {
				if ( ! empty( $class['manual_service'] ) && ! empty( $class['manual_role'] ) ) {
					if ( null === $services[ $key ]['manual_primary'] || $class['confidence'] > $services[ $key ]['manual_primary']['confidence'] ) {
						$services[ $key ]['manual_primary'] = $class;
					}
				}

				if ( null === $services[ $key ]['primary_candidate'] || $class['confidence'] > $services[ $key ]['primary_candidate']['confidence'] ) {
					$services[ $key ]['primary_candidate'] = $class;
				}
			}
		}

		foreach ( $services as &$service ) {
			if ( null !== $service['manual_primary'] ) {
				$service['primary_candidate'] = $service['manual_primary'];
			}

			if ( null === $service['primary_candidate'] && ! empty( $service['items'] ) ) {
				$candidates = $service['items'];
				usort(
					$candidates,
					static fn( array $a, array $b ): int => $b['confidence'] <=> $a['confidence']
				);
				$service['primary_candidate'] = $candidates[0];
			}

			$service['coverage'] = $this->coverage_state( $service );
			$service['support_count'] = max( 0, count( $service['items'] ) - ( null !== $service['primary_candidate'] ? 1 : 0 ) );

			if ( (int) ( $service['roles']['service'] ?? 0 ) > 1 ) {
				$service['warnings'][] = 'Più pagine risultano “servizio principale”: verificare intento e scegliere una pagina di riferimento.';
			}
			if ( null === $service['primary_candidate'] && ! empty( $service['items'] ) ) {
				$service['warnings'][] = 'Esistono contenuti sul tema ma non emerge ancora una pagina principale affidabile.';
			}
		}
		unset( $service );

		$data_state = $this->data_state( count( $posts ), $manual_count );
		$result = array(
			'generated_at'  => current_time( 'mysql' ),
			'total'         => count( $posts ),
			'services'      => $services,
			'unclassified'  => $unclassified,
			'data_state'    => $data_state,
			'service_areas' => $this->service_areas(),
		);

		set_transient( self::TRANSIENT_KEY, $result, 6 * HOUR_IN_SECONDS );
		$this->store_history( $result );

		return $result;
	}

	public function classify_post( WP_Post $post ): array {
		$manual_service = trim( (string) get_post_meta( $post->ID, '_ems_local_service_key', true ) );
		$manual_role    = trim( (string) get_post_meta( $post->ID, '_ems_local_role', true ) );
		$catalog        = self::service_catalog();
		$roles          = self::role_catalog();

		if ( ! isset( $catalog[ $manual_service ] ) ) {
			$manual_service = '';
		}
		if ( ! isset( $roles[ $manual_role ] ) ) {
			$manual_role = '';
		}

		$title       = (string) get_the_title( $post );
		$slug        = (string) $post->post_name;
		$query       = trim( (string) get_post_meta( $post->ID, '_ems_seo_primary_query', true ) );
		$service     = trim( (string) get_post_meta( $post->ID, '_ems_seo_service_name', true ) );
		$case_service= trim( (string) get_post_meta( $post->ID, '_ems_case_study_service', true ) );
		$is_case     = (bool) get_post_meta( $post->ID, '_ems_case_study_enabled', true );
		$schema      = trim( (string) get_post_meta( $post->ID, '_ems_seo_schema_type', true ) );
		$content     = wp_strip_all_tags( strip_shortcodes( (string) $post->post_excerpt . ' ' . (string) $post->post_content ) );

		$service_scores = array();
		$evidence       = array();

		if ( '' !== $manual_service ) {
			$service_scores[ $manual_service ] = 100;
			$evidence[] = 'servizio manuale';
		}

		foreach ( $catalog as $key => $definition ) {
			$score = $service_scores[ $key ] ?? 0;

			$score += $this->text_score( $query, $definition['keywords'], 18 );
			$score += $this->text_score( $service, $definition['keywords'], 16 );
			$score += $this->text_score( $case_service, $definition['keywords'], 16 );
			$score += $this->text_score( $title, $definition['keywords'], 12 );
			$score += $this->text_score( $slug, $definition['keywords'], 10 );
			$score += $this->text_score( $content, $definition['keywords'], 2, 24 );

			$service_scores[ $key ] = min( 100, $score );
		}

		arsort( $service_scores );
		$service_key = (string) array_key_first( $service_scores );
		$confidence  = (int) ( $service_scores[ $service_key ] ?? 0 );

		if ( $confidence < 16 ) {
			$service_key = '';
		}

		if ( '' === $manual_role ) {
			$role = $this->detect_role( $post, $schema, $is_case );
		} else {
			$role = $manual_role;
			$evidence[] = 'ruolo manuale';
		}

		if ( '' !== $query ) {
			$evidence[] = 'query assegnata';
		}
		if ( '' !== $service ) {
			$evidence[] = 'nome servizio';
		}
		if ( $is_case ) {
			$evidence[] = 'lavoro reale';
		}
		if ( 'service' === $schema ) {
			$evidence[] = 'schema servizio';
		}

		if ( '' !== $manual_service ) {
			$confidence = 100;
		} elseif ( '' !== $service_key ) {
			$confidence = max( 20, min( 95, $confidence ) );
		}

		return array(
			'id'             => $post->ID,
			'title'          => $title,
			'slug'           => $slug,
			'url'            => get_permalink( $post ),
			'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
			'post_type'      => $post->post_type,
			'service_key'    => $service_key,
			'service_label'  => '' !== $service_key && isset( $catalog[ $service_key ] ) ? $catalog[ $service_key ]['label'] : '',
			'role'           => $role,
			'role_label'     => $roles[ $role ] ?? $role,
			'confidence'     => $confidence,
			'manual_service' => $manual_service,
			'manual_role'    => $manual_role,
			'evidence'       => array_values( array_unique( $evidence ) ),
		);
	}

	private function detect_role( WP_Post $post, string $schema, bool $is_case ): string {
		if ( $is_case ) {
			return 'case_study';
		}

		$haystack = $this->normalize( get_the_title( $post ) . ' ' . $post->post_name );
		if ( str_contains( $haystack, 'quiz' ) || str_contains( $haystack, 'simul' ) || str_contains( $haystack, 'richiedi preventivo' ) ) {
			return 'quiz';
		}

		if ( preg_match( '/\b(chi siamo|contatti|azienda|come lavoriamo|recensioni)\b/u', $haystack ) ) {
			return 'company';
		}

		if ( 'service' === $schema || 'page' === $post->post_type && preg_match( '/\b(servizio|ristrutturazione|piastrell|cartongesso|paviment|pittura|rasatura|preventiv|progettaz)\b/u', $haystack ) ) {
			return 'service';
		}

		if ( 'post' === $post->post_type || preg_match( '/\b(guida|quanto costa|perche|come|errori|consigli|costi)\b/u', $haystack ) ) {
			return 'guide';
		}

		return 'other';
	}

	private function text_score( string $text, array $keywords, int $weight, int $cap = 40 ): int {
		$text = $this->normalize( $text );
		if ( '' === $text ) {
			return 0;
		}

		$score = 0;
		foreach ( $keywords as $keyword ) {
			$needle = $this->normalize( (string) $keyword );
			if ( '' !== $needle && str_contains( $text, $needle ) ) {
				$score += $weight;
			}
		}

		return min( $cap, $score );
	}

	private function normalize( string $text ): string {
		$text = remove_accents( mb_strtolower( wp_strip_all_tags( $text ) ) );
		$text = preg_replace( '/[^a-z0-9]+/u', ' ', $text );

		return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	private function coverage_state( array $service ): string {
		if ( empty( $service['items'] ) ) {
			return 'missing';
		}

		$has_service = (int) ( $service['roles']['service'] ?? 0 ) > 0 || null !== $service['primary_candidate'];
		$has_support = (int) ( $service['roles']['guide'] ?? 0 ) > 0 || (int) ( $service['roles']['case_study'] ?? 0 ) > 0;
		$has_case    = (int) ( $service['roles']['case_study'] ?? 0 ) > 0;

		if ( $has_service && $has_support && $has_case ) {
			return 'strong';
		}
		if ( $has_service && $has_support ) {
			return 'developing';
		}
		if ( $has_service ) {
			return 'basic';
		}

		return 'fragmented';
	}

	private function data_state( int $post_count, int $manual_count ): array {
		$verification = get_option( EMS_Local_SEO_Verification::STATE_OPTION, array() );
		$gsc_snapshot = get_option( EMS_Local_SEO_Search_Console::SNAPSHOT_OPTION, array() );
		$gsc_history  = get_option( EMS_Local_SEO_Search_Console::HISTORY_OPTION, array() );

		$case_count = count(
			get_posts(
				array(
					'post_type'      => array( 'post', 'page' ),
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_ems_case_study_enabled',
					'meta_value'     => '1',
				)
			)
		);

		$html_complete = is_array( $verification )
			&& 'complete' === ( $verification['status'] ?? '' )
			&& empty( $verification['stale'] )
			&& (int) ( $verification['cursor'] ?? 0 ) === (int) ( $verification['total'] ?? -1 );

		$signals = array(
			'wordpress_content' => array(
				'available' => $post_count > 0,
				'label'     => 'Contenuti WordPress',
				'value'     => $post_count,
			),
			'manual_mapping' => array(
				'available' => $manual_count > 0,
				'label'     => 'Classificazioni manuali',
				'value'     => $manual_count,
			),
			'public_html' => array(
				'available' => $html_complete,
				'label'     => 'HTML pubblico completo',
				'value'     => $html_complete ? (int) ( $verification['total'] ?? 0 ) : 0,
			),
			'gsc' => array(
				'available' => ! empty( $gsc_snapshot ),
				'label'     => 'Search Console',
				'value'     => is_array( $gsc_history ) ? count( $gsc_history ) : 0,
			),
			'case_studies' => array(
				'available' => $case_count > 0,
				'label'     => 'Lavori reali',
				'value'     => $case_count,
			),
		);

		$available = count(
			array_filter(
				$signals,
				static fn( array $signal ): bool => ! empty( $signal['available'] )
			)
		);

		$level = 'iniziale';
		if ( $available >= 4 ) {
			$level = 'informato';
		} elseif ( $available >= 2 ) {
			$level = 'in apprendimento';
		}

		return array(
			'level'       => $level,
			'signals'     => $signals,
			'available'   => $available,
			'total'       => count( $signals ),
			'description' => 'EMS usa solo segnali realmente disponibili. L’assenza di un segnale non viene convertita in zero.',
		);
	}

	private function service_areas(): array {
		$lines = preg_split( '/\r\n|\r|\n/', (string) EMS_Local_SEO_Settings::get( 'service_areas', 'Trieste' ) );
		$lines = array_values( array_unique( array_filter( array_map( 'trim', (array) $lines ) ) ) );

		return $lines;
	}

	private function store_history( array $result ): void {
		$history = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$fingerprint = array();
		foreach ( $result['services'] as $key => $service ) {
			$fingerprint[ $key ] = array(
				'coverage' => $service['coverage'],
				'items'    => count( $service['items'] ),
				'primary'  => (int) ( $service['primary_candidate']['id'] ?? 0 ),
			);
		}

		$entry = array(
			'generated_at' => (string) $result['generated_at'],
			'data_level'   => (string) $result['data_state']['level'],
			'signals'      => (int) $result['data_state']['available'],
			'services'     => $fingerprint,
		);

		$latest = $history[0] ?? array();
		if ( ! empty( $latest ) && wp_json_encode( $latest['services'] ?? array() ) === wp_json_encode( $entry['services'] ) && ( $latest['data_level'] ?? '' ) === $entry['data_level'] ) {
			return;
		}

		array_unshift( $history, $entry );
		update_option( self::HISTORY_OPTION, array_slice( $history, 0, 24 ), false );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = $this->build();
		$state  = $result['data_state'];
		?>
		<div class="wrap ems-seo-wrap">
			<div class="ems-seo-hero">
				<div>
					<span class="ems-seo-kicker">ARCHITETTURA LOCALE</span>
					<h1>Motore locale adattivo</h1>
					<p>Organizza il sito per servizi e intenti di Trieste. Funziona anche con pochi dati e aumenta l’affidabilità man mano che arrivano verifiche, Search Console e lavori reali.</p>
				</div>
				<div class="ems-seo-score"><?php echo esc_html( strtoupper( $state['level'] ) ); ?><small><?php echo esc_html( $state['available'] . '/' . $state['total'] ); ?> fonti disponibili</small></div>
			</div>

			<div class="ems-seo-panel">
				<p><strong>Stato dati:</strong> <?php echo esc_html( $state['description'] ); ?></p>
				<div class="ems-seo-grid">
					<?php foreach ( $state['signals'] as $signal ) : ?>
						<div class="ems-seo-card">
							<span><?php echo esc_html( $signal['label'] ); ?></span>
							<strong><?php echo ! empty( $signal['available'] ) ? 'SÌ' : '—'; ?></strong>
							<small><?php echo esc_html( (string) $signal['value'] ); ?> osservazioni</small>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ems_local_seo_rebuild_local_engine">
				<?php wp_nonce_field( 'ems_local_seo_rebuild_local_engine' ); ?>
				<?php submit_button( 'Ricalcola architettura', 'secondary', 'submit', false ); ?>
			</form>

			<div class="ems-seo-panel ems-seo-table-wrap">
				<table class="widefat striped">
					<thead><tr><th>Priorità</th><th>Servizio</th><th>Pagina principale candidata</th><th>Supporto</th><th>Copertura</th><th>Affidabilità classificazione</th><th>Nota</th></tr></thead>
					<tbody>
					<?php foreach ( $result['services'] as $service ) : ?>
						<?php $primary = $service['primary_candidate']; ?>
						<tr>
							<td><strong><?php echo esc_html( $service['priority'] ); ?></strong></td>
							<td>
								<strong><?php echo esc_html( $service['label'] ); ?></strong><br>
								<small><?php echo esc_html( implode( ' · ', array_slice( $service['intents'], 0, 2 ) ) ); ?></small>
							</td>
							<td>
								<?php if ( $primary ) : ?>
									<a href="<?php echo esc_url( $primary['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $primary['title'] ?: $primary['slug'] ); ?></a>
									<br><small><?php echo esc_html( $primary['role_label'] ); ?></small>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( (string) $service['support_count'] ); ?> contenuti<br>
								<small><?php echo esc_html( (string) $service['roles']['guide'] ); ?> guide · <?php echo esc_html( (string) $service['roles']['case_study'] ); ?> lavori · <?php echo esc_html( (string) $service['roles']['quiz'] ); ?> quiz</small>
							</td>
							<td><?php echo esc_html( $service['coverage'] ); ?></td>
							<td><?php echo $primary ? esc_html( (string) $primary['confidence'] . '%' ) : '—'; ?><br><small>metrica interna, non ranking Google</small></td>
							<td>
								<?php if ( ! empty( $service['warnings'] ) ) : ?>
									<?php echo esc_html( implode( ' ', $service['warnings'] ) ); ?>
								<?php else : ?>
									<small>Area servita: <?php echo esc_html( implode( ', ', $result['service_areas'] ) ); ?></small>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( ! empty( $result['unclassified'] ) ) : ?>
				<div class="ems-seo-panel">
					<h2>Contenuti ancora non classificati</h2>
					<p><?php echo esc_html( (string) count( $result['unclassified'] ) ); ?> contenuti non hanno ancora abbastanza segnali. Restano fuori dalle decisioni forti invece di essere forzati in una categoria sbagliata.</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
