<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Action_Center {
	public const TRANSIENT_KEY = 'ems_local_seo_action_center_v1';

	private EMS_Local_SEO_Local_Engine $local_engine;
	private EMS_Local_SEO_Verification $verification;
	private EMS_Local_SEO_Link_Health $link_health;
	private EMS_Local_SEO_Search_Console $search_console;
	private EMS_Local_SEO_Opportunities $opportunities;
	private EMS_Local_SEO_Links $links;
	private EMS_Local_SEO_Change_Journal $journal;
	private EMS_Local_SEO_Conversion_Signals $conversion_signals;

	public function __construct(
		EMS_Local_SEO_Local_Engine $local_engine,
		EMS_Local_SEO_Verification $verification,
		EMS_Local_SEO_Link_Health $link_health,
		EMS_Local_SEO_Search_Console $search_console,
		EMS_Local_SEO_Opportunities $opportunities,
		EMS_Local_SEO_Links $links,
		EMS_Local_SEO_Change_Journal $journal,
		EMS_Local_SEO_Conversion_Signals $conversion_signals
	) {
		$this->local_engine   = $local_engine;
		$this->verification   = $verification;
		$this->link_health    = $link_health;
		$this->search_console = $search_console;
		$this->opportunities  = $opportunities;
		$this->links          = $links;
		$this->journal        = $journal;
		$this->conversion_signals = $conversion_signals;
	}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 12 );
		add_action( 'admin_post_ems_local_seo_rebuild_actions', array( $this, 'handle_rebuild' ) );
		add_action( 'save_post', array( $this, 'invalidate' ), 50 );
		add_action( 'ems_local_seo_observed_change', array( $this, 'invalidate' ), 50 );
	}

	public function register_page(): void {
		add_submenu_page(
			'ems-local-seo',
			'Azioni della settimana EMS SEO',
			'Azioni settimana',
			'manage_options',
			'ems-local-seo-actions',
			array( $this, 'render' )
		);
	}

	public function invalidate(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	public function handle_rebuild(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
		}

		check_admin_referer( 'ems_local_seo_rebuild_actions' );
		delete_transient( self::TRANSIENT_KEY );
		$this->get_actions( true );

		wp_safe_redirect( admin_url( 'admin.php?page=ems-local-seo-actions&ems_actions=done' ) );
		exit;
	}

	public function get_actions( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$local       = $this->local_engine->build();
		$verification= $this->verification->get_state();
		$link_health = $this->link_health->get_state();
		$link_result = $this->links->get_suggestions();
		$snapshot    = $this->search_console->get_snapshot();
		$gsc         = ! empty( $snapshot ) ? $this->opportunities->analyze( $snapshot ) : array();
		$commercial  = $this->conversion_signals->summary( 28 );

		$context = array(
			'local'        => $local,
			'verification' => $verification,
			'link_health'  => $link_health,
			'links'        => $link_result,
			'gsc'          => $gsc,
			'urls'         => array(
				'verification' => admin_url( 'admin.php?page=ems-local-seo-verification' ),
				'link_health'  => admin_url( 'admin.php?page=ems-local-seo-link-health' ),
				'local_engine' => admin_url( 'admin.php?page=ems-local-seo-local-engine' ),
				'links'        => admin_url( 'admin.php?page=ems-local-seo-links' ),
				'settings'     => admin_url( 'admin.php?page=ems-local-seo-settings' ),
				'case_studies' => admin_url( 'admin.php?page=ems-local-seo-case-studies' ),
				'gsc'          => admin_url( 'admin.php?page=ems-local-seo-opportunities' ),
			),
		);

		$compiled = $this->compile( $context );
		$result   = array(
			'generated_at' => current_time( 'mysql' ),
			'data_level'   => (string) ( $local['data_state']['level'] ?? 'iniziale' ),
			'data_sources' => (int) ( $local['data_state']['available'] ?? 0 ) + ( ! empty( $commercial['available'] ) ? 1 : 0 ),
			'total_sources'=> (int) ( $local['data_state']['total'] ?? 5 ) + 1,
			'commercial'   => $commercial,
			'top'          => array_slice( $compiled, 0, 5 ),
			'all_count'    => count( $compiled ),
			'journal'      => $this->journal->recent( 12 ),
		);

		set_transient( self::TRANSIENT_KEY, $result, HOUR_IN_SECONDS );

		return $result;
	}

	public function compile( array $context ): array {
		$actions = array();
		$urls    = (array) ( $context['urls'] ?? array() );

		$actions = array_merge(
			$actions,
			$this->verification_actions( (array) ( $context['verification'] ?? array() ), $urls ),
			$this->link_health_actions( (array) ( $context['link_health'] ?? array() ), $urls ),
			$this->entity_actions( (array) ( $context['local']['business_entity'] ?? array() ), $urls ),
			$this->service_actions( (array) ( $context['local']['services'] ?? array() ), $urls ),
			$this->internal_link_actions( (array) ( $context['links'] ?? array() ), $urls ),
			$this->gsc_actions( (array) ( $context['gsc'] ?? array() ), $urls )
		);

		$actions = $this->deduplicate( $actions );

		usort(
			$actions,
			static function ( array $a, array $b ): int {
				if ( (int) $a['priority'] !== (int) $b['priority'] ) {
					return (int) $a['priority'] <=> (int) $b['priority'];
				}

				return (int) $b['score'] <=> (int) $a['score'];
			}
		);

		return $actions;
	}

	private function verification_actions( array $state, array $urls ): array {
		$url    = (string) ( $urls['verification'] ?? '' );
		$status = (string) ( $state['status'] ?? 'idle' );
		$total  = max( 0, (int) ( $state['total'] ?? 0 ) );
		$cursor = max( 0, (int) ( $state['cursor'] ?? 0 ) );

		if ( empty( $state ) || 'complete' !== $status ) {
			return array(
				$this->action(
					'verification-incomplete',
					1,
					99,
					'HTML pubblico',
					'Completa la verifica dell’HTML pubblico',
					'EMS non ha ancora una lettura completa dei metadata e dei link realmente renderizzati.',
					$total > 0 ? "Copertura osservata: {$cursor}/{$total} URL." : 'Scansione non ancora completata.',
					'Avvia o continua la verifica a lotti. Non modificare metadata sulla sola base del database.',
					$url,
					'media'
				),
			);
		}

		if ( ! empty( $state['stale'] ) ) {
			return array(
				$this->action(
					'verification-stale',
					1,
					96,
					'HTML pubblico',
					'Rifai la verifica HTML dopo le modifiche',
					'Il sito è cambiato dopo l’ultima scansione completa.',
					'Lo stato EMS è marcato come potenzialmente non aggiornato.',
					'Ricomincia la verifica pubblica prima di prendere decisioni tecniche.',
					$url,
					'alta'
				),
			);
		}

		$issues = array_values( (array) ( $state['issues'] ?? array() ) );
		foreach ( $issues as $issue ) {
			if ( ! is_array( $issue ) || 'warning' !== (string) ( $issue['severity'] ?? '' ) ) {
				continue;
			}

			return array(
				$this->action(
					'verification-warning-' . sanitize_key( (string) ( $issue['code'] ?? 'issue' ) ),
					1,
					94,
					'HTML pubblico',
					'Controlla un’anomalia nei metadata pubblici',
					(string) ( $issue['message'] ?? 'È presente una segnalazione nell’HTML pubblico.' ),
					'Fonte: scansione HTML effettiva.',
					'Apri la pagina indicata e verifica prima di correggere.',
					(string) ( $issue['edit_url'] ?? $url ),
					'alta'
				),
			);
		}

		return array();
	}

	private function link_health_actions( array $state, array $urls ): array {
		$url     = (string) ( $urls['link_health'] ?? '' );
		$status  = (string) ( $state['status'] ?? 'idle' );
		$summary = (array) ( $state['summary'] ?? array() );

		if ( empty( $state ) || 'complete' !== $status ) {
			return array(
				$this->action(
					'link-health-incomplete',
					1,
					90,
					'Link Health',
					'Completa il controllo dei link interni',
					'Non abbiamo ancora copertura completa degli URL interni rilevati.',
					'Una scansione incompleta non viene interpretata come “nessun errore”.',
					'Continua Link Health fino al 100% di copertura.',
					$url,
					'media'
				),
			);
		}

		if ( ! empty( $state['stale'] ) ) {
			return array(
				$this->action(
					'link-health-stale',
					1,
					88,
					'Link Health',
					'Ricalcola Link Health dopo le modifiche',
					'I contenuti sono cambiati dopo la scansione.',
					'Stato Link Health: risultati potenzialmente non aggiornati.',
					'Ricomincia la scansione prima di intervenire sui redirect.',
					$url,
					'alta'
				),
			);
		}

		$broken = (int) ( $summary['broken'] ?? 0 );
		$errors = (int) ( $summary['error'] ?? 0 );
		if ( $broken > 0 || $errors > 0 ) {
			return array(
				$this->action(
					'link-health-broken',
					1,
					95,
					'Link Health',
					'Correggi i link interni non raggiungibili',
					"Link rotti osservati: {$broken}; errori rete: {$errors}.",
					'Fonte: verifica HTTP degli URL interni rilevati.',
					'Apri Link Health, verifica l’origine e correggi il link alla fonte. Non creare redirect automatici senza controllo.',
					$url,
					'alta'
				),
			);
		}

		return array();
	}

	private function entity_actions( array $entity, array $urls ): array {
		$missing = array_values( (array) ( $entity['missing_required'] ?? array() ) );
		$warnings= array_values( (array) ( $entity['warnings'] ?? array() ) );

		if ( ! empty( $missing ) ) {
			return array(
				$this->action(
					'business-entity-missing',
					1,
					93,
					'Entità aziendale',
					'Completa i dati aziendali essenziali',
					'Mancano campi necessari alla scheda entità EMS: ' . implode( ', ', $missing ) . '.',
					'EMS non compilerà automaticamente i dati mancanti.',
					'Inserisci soltanto informazioni pubbliche e verificabili nelle impostazioni.',
					(string) ( $urls['settings'] ?? '' ),
					'alta'
				),
			);
		}

		if ( ! empty( $warnings ) ) {
			return array(
				$this->action(
					'business-entity-warning',
					1,
					87,
					'Entità aziendale',
					'Verifica la coerenza dell’entità aziendale',
					implode( ' ', array_map( 'strval', $warnings ) ),
					'Fonte: confronto tra impostazioni EMS e sito corrente.',
					'Controlla le impostazioni senza inventare indirizzi, recapiti o aree servite.',
					(string) ( $urls['settings'] ?? '' ),
					'alta'
				),
			);
		}

		return array();
	}

	private function service_actions( array $services, array $urls ): array {
		$actions = array();

		foreach ( $services as $key => $service ) {
			if ( ! is_array( $service ) ) {
				continue;
			}

			$priority_label = (string) ( $service['priority'] ?? 'P2' );
			$priority       = 'P1' === $priority_label ? 1 : 2;
			$label          = (string) ( $service['label'] ?? $key );
			$coverage       = (string) ( $service['coverage'] ?? 'missing' );
			$primary        = $service['primary_candidate'] ?? null;
			$warnings       = array_values( (array) ( $service['warnings'] ?? array() ) );
			$roles          = (array) ( $service['roles'] ?? array() );

			if ( ! empty( $warnings ) ) {
				$actions[] = $this->action(
					'service-warning-' . sanitize_key( (string) $key ),
					$priority,
					'P1' === $priority_label ? 91 : 79,
					'Architettura locale',
					'Chiarisci la pagina principale per ' . $label,
					implode( ' ', array_map( 'strval', $warnings ) ),
					'Fonte: Motore Locale EMS; è un segnale di architettura, non una diagnosi Google.',
					'Confronta intento e ruolo delle pagine. Scegli una pagina principale solo se il contenuto lo giustifica.',
					is_array( $primary ) ? (string) ( $primary['edit_url'] ?? '' ) : (string) ( $urls['local_engine'] ?? '' ),
					'media',
					(string) $key,
					$label
				);
				continue;
			}

			if ( 'missing' === $coverage ) {
				$actions[] = $this->action(
					'service-missing-' . sanitize_key( (string) $key ),
					$priority,
					'P1' === $priority_label ? 89 : 70,
					'Architettura locale',
					'Definisci la pagina di riferimento per ' . $label,
					'EMS non trova ancora una pagina sufficientemente chiara per questo servizio.',
					'Fonte: classificazione dei contenuti WordPress disponibili; dati storici non richiesti.',
					'Valuta se esiste già una pagina adatta. Se esiste, classificane il ruolo; crea una nuova pagina solo se manca davvero un contenuto utile.',
					(string) ( $urls['local_engine'] ?? '' ),
					'media',
					(string) $key,
					$label
				);
				continue;
			}

			if ( 'fragmented' === $coverage ) {
				$actions[] = $this->action(
					'service-fragmented-' . sanitize_key( (string) $key ),
					$priority,
					'P1' === $priority_label ? 84 : 68,
					'Architettura locale',
					'Riorganizza i contenuti su ' . $label,
					'Il tema è presente, ma non emerge una struttura principale/supporti sufficientemente chiara.',
					'Fonte: Motore Locale EMS.',
					'Identifica una pagina di riferimento e lascia guide, quiz e lavori reali come supporto.',
					is_array( $primary ) ? (string) ( $primary['edit_url'] ?? '' ) : (string) ( $urls['local_engine'] ?? '' ),
					'media',
					(string) $key,
					$label
				);
				continue;
			}

			if ( 'P1' === $priority_label && is_array( $primary ) && 0 === (int) ( $roles['case_study'] ?? 0 ) ) {
				$actions[] = $this->action(
					'service-case-study-' . sanitize_key( (string) $key ),
					2,
					73,
					'Lavori reali',
					'Aggiungi una prova di lavoro reale per ' . $label,
					'La pagina servizio esiste ma EMS non vede ancora un case study associato.',
					'Fonte: contenuti marcati come lavori reali.',
					'Quando hai fotografie e dati verificabili, collega un lavoro realmente eseguito. Non usare immagini generate come prova di cantiere.',
					(string) ( $urls['case_studies'] ?? '' ),
					'media',
					(string) $key,
					$label
				);
			}
		}

		return $actions;
	}

	private function internal_link_actions( array $links, array $urls ): array {
		$items = array_values( (array) ( $links['items'] ?? array() ) );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || (int) ( $item['priority'] ?? 9 ) > 2 ) {
				continue;
			}

			return array(
				$this->action(
					'internal-link-' . (int) ( $item['source_id'] ?? 0 ) . '-' . (int) ( $item['target_id'] ?? 0 ),
					2,
					70,
					'Link interni',
					'Aggiungi un collegamento contestuale utile',
					(string) ( $item['reason'] ?? 'EMS ha trovato un percorso interno coerente con l’architettura del servizio.' ),
					'Anchor suggerita: ' . (string) ( $item['anchor'] ?? '—' ) . '. ' . (string) ( $item['insertion_hint'] ?? '' ),
					'Apri la pagina sorgente e inserisci il link solo se migliora davvero il percorso del lettore.',
					(string) ( $item['edit_url'] ?? $urls['links'] ?? '' ),
					'media',
					(string) ( $item['service_key'] ?? '' ),
					(string) ( $item['service_label'] ?? '' )
				),
			);
		}

		return array();
	}

	private function gsc_actions( array $analysis, array $urls ): array {
		$items   = array_values( (array) ( $analysis['items'] ?? array() ) );
		$actions = array();

		$type_scores = array(
			'declining'           => array( 1, 92, 'Calo Search Console da verificare' ),
			'query_overlap'       => array( 1, 82, 'Query distribuita su più URL' ),
			'quick_win'           => array( 2, 80, 'Visibilità già presente da rafforzare' ),
			'low_ctr'             => array( 2, 78, 'Snippet con CTR da verificare' ),
			'rising'              => array( 3, 58, 'Pagina in crescita da proteggere' ),
			'missing_from_sample' => array( 3, 45, 'Riga assente dal campione corrente' ),
			'new_in_sample'       => array( 3, 45, 'Riga nuova nel campione corrente' ),
		);

		foreach ( array_slice( $items, 0, 12 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type = (string) ( $item['type'] ?? '' );
			if ( ! isset( $type_scores[ $type ] ) ) {
				continue;
			}

			[ $priority, $base_score, $title ] = $type_scores[ $type ];
			$confidence = (string) ( $item['confidence'] ?? 'media' );
			if ( 'bassa' === $confidence ) {
				$base_score -= 10;
			}

			$query = (string) ( $item['query'] ?? '' );
			$actions[] = $this->action(
				'gsc-' . sanitize_key( $type ) . '-' . substr( md5( $query . '|' . (string) ( $item['page'] ?? '' ) ), 0, 10 ),
				$priority,
				max( 0, min( 100, $base_score ) ),
				'Search Console',
				$title . ( '' !== $query ? ': ' . $query : '' ),
				(string) ( $item['reason'] ?? '' ),
				'Fonte: righe query→pagina restituite da Search Console. Affidabilità: ' . $confidence . '.',
				(string) ( $item['action'] ?? 'Verifica il dato prima di modificare la pagina.' ),
				(string) ( $item['edit_url'] ?? $urls['gsc'] ?? '' ),
				$confidence
			);

			if ( count( $actions ) >= 3 ) {
				break;
			}
		}

		return $actions;
	}

	private function action(
		string $id,
		int $priority,
		int $score,
		string $source,
		string $title,
		string $reason,
		string $evidence,
		string $instruction,
		string $url,
		string $confidence,
		string $service_key = '',
		string $service_label = ''
	): array {
		return array(
			'id'            => sanitize_key( $id ),
			'priority'      => max( 1, min( 3, $priority ) ),
			'score'         => max( 0, min( 100, $score ) ),
			'source'        => $source,
			'title'         => $title,
			'reason'        => $reason,
			'evidence'      => $evidence,
			'instruction'   => $instruction,
			'url'           => $url,
			'confidence'    => in_array( $confidence, array( 'alta', 'media', 'bassa' ), true ) ? $confidence : 'media',
			'service_key'   => $service_key,
			'service_label' => $service_label,
		);
	}

	private function deduplicate( array $actions ): array {
		$by_id      = array();
		$by_service = array();
		$out        = array();

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || empty( $action['id'] ) ) {
				continue;
			}

			$id = (string) $action['id'];
			if ( isset( $by_id[ $id ] ) ) {
				continue;
			}

			$service_key = (string) ( $action['service_key'] ?? '' );
			if ( '' !== $service_key && isset( $by_service[ $service_key ] ) ) {
				$existing_index = $by_service[ $service_key ];
				$existing       = $out[ $existing_index ];

				$is_better = (int) $action['priority'] < (int) $existing['priority']
					|| ( (int) $action['priority'] === (int) $existing['priority'] && (int) $action['score'] > (int) $existing['score'] );

				if ( $is_better ) {
					$out[ $existing_index ] = $action;
					$by_id[ $id ] = true;
				}

				continue;
			}

			$by_id[ $id ] = true;
			$out[] = $action;
			if ( '' !== $service_key ) {
				$by_service[ $service_key ] = array_key_last( $out );
			}
		}

		return array_values( $out );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = $this->get_actions();
		?>
		<div class="wrap ems-seo-wrap">
			<div class="ems-seo-hero">
				<div>
					<span class="ems-seo-kicker">DECISIONI OPERATIVE</span>
					<h1>Le 5 azioni della settimana</h1>
					<p>Priorità diagnostiche costruite soltanto sui segnali disponibili. Non sono previsioni di ranking e non inventano metriche mancanti.</p>
				</div>
				<div class="ems-seo-score"><?php echo esc_html( (string) count( $result['top'] ) ); ?><small>azioni · dati <?php echo esc_html( $result['data_level'] ); ?></small></div>
			</div>

			<div class="ems-seo-panel">
				<p><strong>Fonti disponibili:</strong> <?php echo esc_html( (string) $result['data_sources'] ); ?>/<?php echo esc_html( (string) $result['total_sources'] ); ?>. <strong>Segnali commerciali:</strong> <?php echo ! empty( $result['commercial']['available'] ) ? esc_html( (string) array_sum( (array) $result['commercial']['events'] ) ) . ' osservati' : 'non ancora osservati'; ?>. <strong>Azioni candidate:</strong> <?php echo esc_html( (string) $result['all_count'] ); ?>. L’assenza di una fonte significa “non osservato”, non zero.</p>
				<?php if ( ! empty( $result['commercial']['available'] ) ) : ?>
					<p><small>
						<?php
						$labels = EMS_Local_SEO_Conversion_Signals::allowed_events();
						$parts = array();
						foreach ( (array) $result['commercial']['events'] as $event => $count ) {
							if ( (int) $count > 0 ) {
								$parts[] = ( $labels[ $event ] ?? $event ) . ': ' . (int) $count;
							}
						}
						echo esc_html( implode( ' · ', $parts ) );
						?>
					</small></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ems_local_seo_rebuild_actions">
					<?php wp_nonce_field( 'ems_local_seo_rebuild_actions' ); ?>
					<?php submit_button( 'Ricalcola priorità', 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<div class="ems-seo-grid">
				<?php if ( empty( $result['top'] ) ) : ?>
					<div class="ems-seo-panel"><p>Nessuna azione sufficientemente motivata con i dati disponibili.</p></div>
				<?php else : ?>
					<?php foreach ( $result['top'] as $index => $item ) : ?>
						<div class="ems-seo-panel">
							<span class="ems-seo-kicker">#<?php echo esc_html( (string) ( $index + 1 ) ); ?> · P<?php echo esc_html( (string) $item['priority'] ); ?> · <?php echo esc_html( $item['source'] ); ?></span>
							<h2><?php echo esc_html( $item['title'] ); ?></h2>
							<p><?php echo esc_html( $item['reason'] ); ?></p>
							<p><small><strong>Perché:</strong> <?php echo esc_html( $item['evidence'] ); ?></small></p>
							<p><strong>Cosa fare:</strong> <?php echo esc_html( $item['instruction'] ); ?></p>
							<p><small>Affidabilità del segnale: <strong><?php echo esc_html( $item['confidence'] ); ?></strong> · priorità EMS <?php echo esc_html( (string) $item['score'] ); ?>/100, metrica interna.</small></p>
							<?php if ( ! empty( $item['url'] ) ) : ?>
								<p><a class="button button-primary" href="<?php echo esc_url( $item['url'] ); ?>">Apri punto di intervento</a></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div class="ems-seo-panel ems-seo-table-wrap">
				<h2 style="padding:0 20px">Diario recente</h2>
				<p style="padding:0 20px">Solo metadata operativi: nessun testo delle pagine o dato personale.</p>
				<table class="widefat striped">
					<thead><tr><th>Data</th><th>Evento</th><th>Fonte</th><th>ID contenuto</th><th>Servizio</th></tr></thead>
					<tbody>
					<?php if ( empty( $result['journal'] ) ) : ?>
						<tr><td colspan="5">Il diario si popolerà man mano che il sito viene modificato e analizzato.</td></tr>
					<?php else : ?>
						<?php foreach ( $result['journal'] as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $entry['timestamp'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['type'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['source'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['post_id'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['service_key'] ?? '' ) ); ?></td>
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
