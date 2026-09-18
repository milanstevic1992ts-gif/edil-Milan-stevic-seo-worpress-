<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Opportunities {
	private EMS_Local_SEO_Search_Console $search_console;

	public function __construct( EMS_Local_SEO_Search_Console $search_console ) {
		$this->search_console = $search_console;
	}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 50 );
		add_action( 'admin_post_ems_local_seo_export_opportunities', array( $this, 'handle_export' ) );
	}

	public function register_page(): void {
		add_submenu_page(
			'ems-local-seo',
			'Opportunità Google EMS SEO',
			'Opportunità Google',
			'manage_options',
			'ems-local-seo-opportunities',
			array( $this, 'render' )
		);
	}

	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
		}

		check_admin_referer( 'ems_local_seo_export_opportunities' );

		$snapshot = $this->search_console->get_snapshot();
		if ( empty( $snapshot ) ) {
			wp_die( esc_html__( 'Nessuno snapshot Search Console disponibile.', 'ems-local-seo' ) );
		}

		$analysis = $this->analyze( $snapshot );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ems-seo-opportunita-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Impossibile generare il CSV.', 'ems-local-seo' ) );
		}

		fwrite( $output, "ï»¿" );
		fputcsv(
			$output,
			array( 'Priorita EMS', 'Segnale', 'Query', 'Landing page', 'Landing secondaria', 'Click', 'Impression', 'CTR %', 'Posizione', 'Motivo', 'Azione suggerita' ),
			';'
		);

		foreach ( $analysis['items'] as $item ) {
			fputcsv(
				$output,
				array(
					$item['score'],
					$item['label'],
					$item['query'],
					$item['page'],
					$item['secondary_page'],
					$item['clicks'],
					$item['impressions'],
					round( $item['ctr'] * 100, 2 ),
					round( $item['position'], 2 ),
					$item['reason'],
					$item['action'],
				),
				';'
			);
		}

		fclose( $output );
		exit;
	}

	public function analyze( array $snapshot ): array {
		$current_rows  = $this->aggregate_rows( (array) ( $snapshot['current']['rows'] ?? array() ) );
		$previous_rows = $this->aggregate_rows( (array) ( $snapshot['previous']['rows'] ?? array() ) );

		$current_summary  = $this->period_summary( $current_rows );
		$previous_summary = $this->period_summary( $previous_rows );

		$previous_index = array();
		foreach ( $previous_rows as $row ) {
			$previous_index[ $this->row_key( $row ) ] = $row;
		}

		$items = array();

		foreach ( $current_rows as $row ) {
			$key      = $this->row_key( $row );
			$previous = $previous_index[ $key ] ?? null;

			if ( $row['impressions'] >= 20 && $row['position'] >= 5.0 && $row['position'] <= 20.0 ) {
				$items[] = $this->opportunity(
					'quick_win',
					'Quick win',
					$row,
					$previous,
					$this->score_quick_win( $row ),
					'La query è già vicina alla prima pagina o nella parte bassa della prima pagina.',
					'Rafforza la pagina con contenuto utile, prove di lavori reali e link interni pertinenti. Evita riscritture radicali senza verificare il trend.'
				);
			}

			$ctr_threshold = $this->ctr_threshold( $row['position'] );
			if ( $row['impressions'] >= 50 && $row['position'] > 0 && $row['position'] <= 10.0 && $row['ctr'] < $ctr_threshold ) {
				$items[] = $this->opportunity(
					'low_ctr',
					'CTR da migliorare',
					$row,
					$previous,
					$this->score_low_ctr( $row, $ctr_threshold ),
					'La pagina riceve visibilità in alto ma proporzionalmente pochi click.',
					'Controlla intento di ricerca, title e snippet gestiti da The SEO Framework. Migliora la promessa della pagina senza clickbait.'
				);
			}

			if ( is_array( $previous ) && $previous['impressions'] >= 20 ) {
				$click_delta      = $this->delta_pct( $row['clicks'], $previous['clicks'] );
				$impression_delta = $this->delta_pct( $row['impressions'], $previous['impressions'] );
				$position_delta   = $row['position'] - $previous['position'];

				if ( $click_delta <= -30.0 || ( $impression_delta <= -35.0 && $position_delta >= 1.5 ) ) {
					$items[] = $this->opportunity(
						'declining',
						'Calo da verificare',
						$row,
						$previous,
						$this->score_decline( $row, $previous ),
						'Click o impression sono scesi rispetto ai 28 giorni precedenti.',
						'Verifica prima modifiche alla pagina, indicizzazione, SERP e concorrenza. Non consolidare o eliminare contenuti sulla sola base di questo segnale.'
					);
				}

				if ( $row['impressions'] >= 20 && $previous['impressions'] >= 10 && ( $click_delta >= 40.0 || $impression_delta >= 50.0 ) ) {
					$items[] = $this->opportunity(
						'rising',
						'In crescita',
						$row,
						$previous,
						$this->score_growth( $row, $previous ),
						'La query/pagina sta crescendo rispetto al periodo precedente.',
						'Proteggi ciò che sta funzionando: aggiungi link interni e casi reali coerenti, evitando cambi di titolo o struttura non necessari.'
					);
				}
			}
		}

		$items = array_merge( $items, $this->cannibalization_opportunities( $current_rows ) );

		usort(
			$items,
			static function ( array $a, array $b ): int {
				if ( $a['score'] === $b['score'] ) {
					return $b['impressions'] <=> $a['impressions'];
				}

				return $b['score'] <=> $a['score'];
			}
		);

		return array(
			'current_summary'  => $current_summary,
			'previous_summary' => $previous_summary,
			'items'            => array_slice( $items, 0, 150 ),
			'counts'           => $this->counts_by_type( $items ),
		);
	}

	private function aggregate_rows( array $rows ): array {
		$groups = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$query = trim( (string) ( $row['query'] ?? '' ) );
			$page  = trim( (string) ( $row['page'] ?? '' ) );
			if ( '' === $query && '' === $page ) {
				continue;
			}

			$key = mb_strtolower( $query ) . '|' . $page;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'query'          => $query,
					'page'           => $page,
					'clicks'         => 0.0,
					'impressions'    => 0.0,
					'position_total' => 0.0,
				);
			}

			$impressions = max( 0.0, (float) ( $row['impressions'] ?? 0 ) );
			$groups[ $key ]['clicks']         += max( 0.0, (float) ( $row['clicks'] ?? 0 ) );
			$groups[ $key ]['impressions']    += $impressions;
			$groups[ $key ]['position_total'] += max( 0.0, (float) ( $row['position'] ?? 0 ) ) * $impressions;
		}

		$result = array();
		foreach ( $groups as $group ) {
			$impressions = max( 0.0, (float) $group['impressions'] );
			$clicks      = max( 0.0, (float) $group['clicks'] );

			$result[] = array(
				'query'       => $group['query'],
				'page'        => $group['page'],
				'clicks'      => $clicks,
				'impressions' => $impressions,
				'ctr'         => $impressions > 0 ? $clicks / $impressions : 0.0,
				'position'    => $impressions > 0 ? $group['position_total'] / $impressions : 0.0,
			);
		}

		return $result;
	}

	private function period_summary( array $rows ): array {
		$clicks         = 0.0;
		$impressions    = 0.0;
		$position_total = 0.0;

		foreach ( $rows as $row ) {
			$row_impressions = max( 0.0, (float) $row['impressions'] );
			$clicks         += max( 0.0, (float) $row['clicks'] );
			$impressions    += $row_impressions;
			$position_total += max( 0.0, (float) $row['position'] ) * $row_impressions;
		}

		return array(
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => $impressions > 0 ? $clicks / $impressions : 0.0,
			'position'    => $impressions > 0 ? $position_total / $impressions : 0.0,
		);
	}

	private function cannibalization_opportunities( array $rows ): array {
		$queries = array();

		foreach ( $rows as $row ) {
			$query = mb_strtolower( trim( (string) $row['query'] ) );
			if ( '' === $query || $row['impressions'] < 5 ) {
				continue;
			}

			$queries[ $query ][] = $row;
		}

		$items = array();

		foreach ( $queries as $query => $pages ) {
			if ( count( $pages ) < 2 ) {
				continue;
			}

			usort(
				$pages,
				static fn( array $a, array $b ): int => $b['impressions'] <=> $a['impressions']
			);

			$total_impressions = array_sum( array_column( $pages, 'impressions' ) );
			if ( $total_impressions < 30 || $pages[1]['impressions'] < 10 ) {
				continue;
			}

			$second_share = $pages[1]['impressions'] / max( 1.0, $total_impressions );
			if ( $second_share < 0.20 ) {
				continue;
			}

			$top = $pages[0];
			$items[] = array(
				'type'        => 'cannibalization',
				'label'       => 'Possibile cannibalizzazione',
				'query'       => $top['query'],
				'page'        => $top['page'],
				'secondary_page' => $pages[1]['page'],
				'clicks'      => $top['clicks'],
				'impressions' => $total_impressions,
				'ctr'         => $top['ctr'],
				'position'    => $top['position'],
				'previous'    => null,
				'score'       => min( 100, (int) round( 55 + min( 30, $total_impressions / 10 ) + ( $second_share * 25 ) ) ),
				'reason'      => 'La stessa query genera impression significative su almeno due URL.',
				'action'      => 'Confronta intento e Search Console delle due landing page. Differenzia o consolida solo dopo verifica; non applicare redirect automatici.',
				'edit_url'    => $this->edit_url_for_page( $top['page'] ),
			);
		}

		return $items;
	}

	private function opportunity(
		string $type,
		string $label,
		array $row,
		?array $previous,
		int $score,
		string $reason,
		string $action
	): array {
		return array(
			'type'        => $type,
			'label'       => $label,
			'query'       => $row['query'],
			'page'        => $row['page'],
			'secondary_page' => '',
			'clicks'      => $row['clicks'],
			'impressions' => $row['impressions'],
			'ctr'         => $row['ctr'],
			'position'    => $row['position'],
			'previous'    => $previous,
			'score'       => max( 0, min( 100, $score ) ),
			'reason'      => $reason,
			'action'      => $action,
			'edit_url'    => $this->edit_url_for_page( $row['page'] ),
		);
	}

	private function score_quick_win( array $row ): int {
		$visibility = min( 35.0, log10( max( 1.0, $row['impressions'] ) + 1 ) * 14.0 );
		$position   = max( 0.0, 20.0 - $row['position'] ) * 2.0;

		return (int) round( 30.0 + $visibility + min( 35.0, $position ) );
	}

	private function score_low_ctr( array $row, float $threshold ): int {
		$gap        = max( 0.0, $threshold - $row['ctr'] );
		$gap_factor = min( 30.0, ( $gap / max( 0.001, $threshold ) ) * 30.0 );
		$visibility = min( 30.0, log10( max( 1.0, $row['impressions'] ) + 1 ) * 12.0 );

		return (int) round( 40.0 + $gap_factor + $visibility );
	}

	private function score_decline( array $row, array $previous ): int {
		$click_delta      = abs( min( 0.0, $this->delta_pct( $row['clicks'], $previous['clicks'] ) ) );
		$impression_delta = abs( min( 0.0, $this->delta_pct( $row['impressions'], $previous['impressions'] ) ) );
		$impact           = min( 25.0, log10( max( 1.0, $previous['impressions'] ) + 1 ) * 10.0 );

		return (int) round( 35.0 + min( 20.0, $click_delta / 3.0 ) + min( 20.0, $impression_delta / 3.0 ) + $impact );
	}

	private function score_growth( array $row, array $previous ): int {
		$click_delta      = max( 0.0, $this->delta_pct( $row['clicks'], $previous['clicks'] ) );
		$impression_delta = max( 0.0, $this->delta_pct( $row['impressions'], $previous['impressions'] ) );
		$impact           = min( 25.0, log10( max( 1.0, $row['impressions'] ) + 1 ) * 10.0 );

		return (int) round( 30.0 + min( 20.0, $click_delta / 4.0 ) + min( 20.0, $impression_delta / 4.0 ) + $impact );
	}

	private function ctr_threshold( float $position ): float {
		if ( $position <= 3.0 ) {
			return 0.06;
		}
		if ( $position <= 5.0 ) {
			return 0.04;
		}

		return 0.02;
	}

	private function delta_pct( float $current, float $previous ): float {
		if ( $previous <= 0.0 ) {
			return $current > 0.0 ? 100.0 : 0.0;
		}

		return ( ( $current - $previous ) / $previous ) * 100.0;
	}

	private function row_key( array $row ): string {
		return mb_strtolower( trim( (string) $row['query'] ) ) . '|' . trim( (string) $row['page'] );
	}

	private function edit_url_for_page( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return '';
		}

		$edit = get_edit_post_link( $post_id, 'raw' );

		return is_string( $edit ) ? $edit : '';
	}

	private function counts_by_type( array $items ): array {
		$counts = array();

		foreach ( $items as $item ) {
			$type = (string) $item['type'];
			$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
		}

		return $counts;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$snapshot = $this->search_console->get_snapshot();
		$error    = $this->search_console->get_last_error();
		$analysis = ! empty( $snapshot ) ? $this->analyze( $snapshot ) : array();
		?>
		<div class="wrap ems-seo-wrap">
			<div class="ems-seo-hero">
				<div>
					<span class="ems-seo-kicker">SEARCH CONSOLE INTELLIGENCE</span>
					<h1>Opportunità Google</h1>
					<p>Dati reali Search Console tramite Site Kit. Le priorità EMS sono euristiche interne: aiutano a decidere dove guardare, non garantiscono posizioni.</p>
				</div>
				<div class="ems-seo-version">v<?php echo esc_html( EMS_LOCAL_SEO_VERSION ); ?></div>
			</div>

			<?php if ( ! $this->search_console->is_site_kit_active() ) : ?>
				<div class="notice notice-warning inline"><p><strong>Site Kit non rilevato.</strong> Il motore opportunità resta disattivato finché Search Console non è disponibile tramite Site Kit.</p></div>
			<?php elseif ( ! empty( $error ) ) : ?>
				<div class="notice notice-error inline"><p><strong>Ultimo aggiornamento Search Console non riuscito:</strong> <?php echo esc_html( (string) ( $error['message'] ?? 'Errore sconosciuto' ) ); ?></p></div>
			<?php endif; ?>

			<div class="ems-seo-panel">
				<h2>Aggiorna i dati</h2>
				<p>EMS legge due periodi consecutivi di 28 giorni, terminando 3 giorni prima di oggi per ridurre l'effetto dei dati Search Console ancora incompleti.</p>
				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ems_local_seo_refresh_gsc">
						<?php wp_nonce_field( 'ems_local_seo_refresh_gsc' ); ?>
						<?php submit_button( empty( $snapshot ) ? 'Collega i dati Search Console' : 'Aggiorna dati Search Console', 'primary', 'submit', false ); ?>
					</form>
					<?php if ( ! empty( $snapshot ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ems_local_seo_export_opportunities">
							<?php wp_nonce_field( 'ems_local_seo_export_opportunities' ); ?>
							<?php submit_button( 'Esporta opportunità CSV', 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $snapshot['generated_at'] ) ) : ?>
					<p><small>Ultimo snapshot EMS: <?php echo esc_html( (string) $snapshot['generated_at'] ); ?>.</small></p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $analysis ) ) : ?>
				<?php
				$current  = $analysis['current_summary'];
				$previous = $analysis['previous_summary'];
				?>
				<div class="ems-seo-grid">
					<div class="ems-seo-card">
						<span>Click</span>
						<strong><?php echo esc_html( number_format_i18n( $current['clicks'], 0 ) ); ?></strong>
						<small><?php echo esc_html( $this->format_delta( $current['clicks'], $previous['clicks'] ) ); ?> vs periodo precedente</small>
					</div>
					<div class="ems-seo-card">
						<span>Impression</span>
						<strong><?php echo esc_html( number_format_i18n( $current['impressions'], 0 ) ); ?></strong>
						<small><?php echo esc_html( $this->format_delta( $current['impressions'], $previous['impressions'] ) ); ?> vs periodo precedente</small>
					</div>
					<div class="ems-seo-card">
						<span>CTR</span>
						<strong><?php echo esc_html( number_format_i18n( $current['ctr'] * 100, 1 ) ); ?>%</strong>
						<small><?php echo esc_html( $this->format_delta( $current['ctr'], $previous['ctr'] ) ); ?> vs periodo precedente</small>
					</div>
					<div class="ems-seo-card">
						<span>Posizione media ponderata</span>
						<strong><?php echo esc_html( number_format_i18n( $current['position'], 1 ) ); ?></strong>
						<small>ponderata sulle impression</small>
					</div>
				</div>

				<div class="ems-seo-panel">
					<h2>Segnali rilevati</h2>
					<p>
						Quick win: <strong><?php echo esc_html( (string) ( $analysis['counts']['quick_win'] ?? 0 ) ); ?></strong> ·
						CTR: <strong><?php echo esc_html( (string) ( $analysis['counts']['low_ctr'] ?? 0 ) ); ?></strong> ·
						Cali: <strong><?php echo esc_html( (string) ( $analysis['counts']['declining'] ?? 0 ) ); ?></strong> ·
						Crescita: <strong><?php echo esc_html( (string) ( $analysis['counts']['rising'] ?? 0 ) ); ?></strong> ·
						Cannibalizzazione: <strong><?php echo esc_html( (string) ( $analysis['counts']['cannibalization'] ?? 0 ) ); ?></strong>
					</p>
				</div>

				<div class="ems-seo-panel ems-seo-table-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Priorità EMS</th>
								<th>Segnale</th>
								<th>Query</th>
								<th>Landing page</th>
								<th>Dati</th>
								<th>Azione suggerita</th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $analysis['items'] ) ) : ?>
							<tr><td colspan="6">Nessuna opportunità ha superato le soglie euristiche attuali.</td></tr>
						<?php else : ?>
							<?php foreach ( $analysis['items'] as $item ) : ?>
								<tr>
									<td><strong><?php echo esc_html( (string) $item['score'] ); ?>/100</strong></td>
									<td><span class="ems-seo-badge ems-seo-info"><?php echo esc_html( $item['label'] ); ?></span><br><small><?php echo esc_html( $item['reason'] ); ?></small></td>
									<td><?php echo esc_html( $item['query'] ); ?></td>
									<td>
										<a href="<?php echo esc_url( $item['page'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $this->short_url( $item['page'] ) ); ?></a>
										<?php if ( ! empty( $item['secondary_page'] ) ) : ?>
											<br><small>anche: <?php echo esc_html( $this->short_url( $item['secondary_page'] ) ); ?></small>
										<?php endif; ?>
										<?php if ( ! empty( $item['edit_url'] ) ) : ?>
											<br><a href="<?php echo esc_url( $item['edit_url'] ); ?>">Modifica in WordPress</a>
										<?php endif; ?>
									</td>
									<td>
										<?php echo esc_html( number_format_i18n( $item['clicks'], 0 ) ); ?> click ·
										<?php echo esc_html( number_format_i18n( $item['impressions'], 0 ) ); ?> impr.<br>
										CTR <?php echo esc_html( number_format_i18n( $item['ctr'] * 100, 1 ) ); ?>% ·
										pos. <?php echo esc_html( number_format_i18n( $item['position'], 1 ) ); ?>
									</td>
									<td><?php echo esc_html( $item['action'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function format_delta( float $current, float $previous ): string {
		$delta = $this->delta_pct( $current, $previous );
		$sign  = $delta > 0 ? '+' : '';

		return $sign . number_format_i18n( $delta, 1 ) . '%';
	}

	private function short_url( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return $host . ( '' !== $path ? $path : '/' );
	}
}
