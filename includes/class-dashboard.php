<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Dashboard {
	private EMS_Local_SEO_Search_Console $search_console;
	private EMS_Local_SEO_Opportunities $opportunities;
	private EMS_Local_SEO_Setup $setup;
	private EMS_Local_SEO_Action_Center $action_center;
	private EMS_Local_SEO_Contacts $contacts;

	public function __construct(
		EMS_Local_SEO_Search_Console $search_console,
		EMS_Local_SEO_Opportunities $opportunities,
		EMS_Local_SEO_Setup $setup,
		EMS_Local_SEO_Action_Center $action_center,
		EMS_Local_SEO_Contacts $contacts
	) {
		$this->search_console = $search_console;
		$this->opportunities = $opportunities;
		$this->setup          = $setup;
		$this->action_center  = $action_center;
		$this->contacts       = $contacts;
	}

	public function hooks(): void {}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$period = isset( $_GET['period'] ) ? absint( $_GET['period'] ) : 28; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $period, array( 28, 90 ), true ) ) {
			$period = 28;
		}

		$snapshot = $this->search_console->get_snapshot();
		$error    = $this->search_console->get_last_error();
		$actions  = $this->action_center->get_actions();

		$this->render_header( $snapshot, $period );

		if ( ! empty( $error ) ) {
			echo '<div class="notice notice-error inline"><p><strong>Search Console:</strong> ' . esc_html( (string) ( $error['message'] ?? 'Errore non specificato.' ) ) . '</p></div>';
		}

		$this->render_setup_status();

		if ( empty( $snapshot ) ) {
			$this->render_empty( $actions );
			return;
		}

		$daily = (array) ( $snapshot['daily']['rows'] ?? array() );
		$weeks = EMS_Local_SEO_Forecast::weekly( $daily );
		$compare = EMS_Local_SEO_Forecast::compare_periods( $daily, $period );
		$forecast = EMS_Local_SEO_Forecast::project( $weeks, 'clicks', 4, 12 );
		$current_rows = (array) ( $snapshot['current']['rows'] ?? array() );
		$previous_rows= (array) ( $snapshot['previous']['rows'] ?? array() );
		$potential = EMS_Local_SEO_Forecast::potential( $current_rows, 3.0, 8 );
		$analysis  = $this->opportunities->analyze( $snapshot );
		$contacts  = $this->contacts->summary( $period );

		$this->render_kpis( $compare );
		$this->render_trend( $weeks, $forecast, $potential );
		$this->render_actions( $actions, $analysis );
		$this->render_contacts( $contacts );
		$this->render_tables( $current_rows, $previous_rows );
		$this->render_health( $snapshot );
	}

	private function render_header( array $snapshot, int $period ): void {
		$stale = ! empty( $snapshot ) && $this->search_console->is_stale( 48 );
		?>
		<div class="wrap ems-seo-wrap ems-dashboard">
			<div class="ems-seo-hero">
				<div>
					<span class="ems-seo-kicker">RISULTATI SU GOOGLE</span>
					<h1>EMS Local SEO</h1>
					<p>Andamento osservato, priorità operative e segnali commerciali aggregati. Nessuna metrica è una garanzia di ranking.</p>
				</div>
				<div class="ems-seo-version">v<?php echo esc_html( EMS_LOCAL_SEO_VERSION ); ?></div>
			</div>

			<div class="ems-dashboard-toolbar">
				<div>
					<a class="button <?php echo 28 === $period ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ems-local-seo', 'period' => 28 ), admin_url( 'admin.php' ) ) ); ?>">28 giorni</a>
					<a class="button <?php echo 90 === $period ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ems-local-seo', 'period' => 90 ), admin_url( 'admin.php' ) ) ); ?>">90 giorni</a>
				</div>
				<div>
					<?php if ( ! empty( $snapshot['generated_at'] ) ) : ?>
						<span class="ems-dashboard-meta">Dati: <?php echo esc_html( (string) $snapshot['generated_at'] ); ?><?php echo $stale ? ' · da aggiornare' : ''; ?></span>
					<?php endif; ?>
					<form class="ems-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ems_local_seo_refresh_gsc">
						<input type="hidden" name="ems_back" value="ems-local-seo">
						<?php wp_nonce_field( 'ems_local_seo_refresh_gsc' ); ?>
						<button class="button" type="submit">Aggiorna Search Console</button>
					</form>
				</div>
			</div>
		<?php
	}

	private function render_setup_status(): void {
		$complete = $this->setup->completeness();
		if ( $complete >= 90 ) {
			return;
		}
		?>
		<div class="ems-seo-panel ems-setup-callout">
			<div>
				<strong>Configurazione EMS: <?php echo esc_html( (string) $complete ); ?>%</strong>
				<p>Completa solo i dati realmente disponibili; i campi mancanti possono restare vuoti.</p>
			</div>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-setup' ) ); ?>">Continua configurazione</a>
		</div>
		<?php
	}

	private function render_empty( array $actions ): void {
		?>
		<div class="ems-seo-panel">
			<h2>Nessun dato Search Console ancora osservato</h2>
			<p>La dashboard non mostra zero inventati. Collega Site Kit e scarica i primi dati quando disponibili.</p>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-setup&step=integrazioni' ) ); ?>">Apri integrazioni</a></p>
		</div>
		<?php $this->render_actions( $actions, array() ); ?>
		</div>
		<?php
	}

	private function render_kpis( array $compare ): void {
		if ( empty( $compare ) ) {
			return;
		}

		$now  = (array) $compare['current'];
		$prev = (array) $compare['previous'];

		$kpis = array(
			array( 'Click', $this->num( (float) $now['clicks'] ), EMS_Local_SEO_Forecast::delta_pct( (float) $now['clicks'], (float) $prev['clicks'] ) ),
			array( 'Impression', $this->num( (float) $now['impressions'] ), EMS_Local_SEO_Forecast::delta_pct( (float) $now['impressions'], (float) $prev['impressions'] ) ),
			array( 'CTR medio', number_format_i18n( (float) $now['ctr'] * 100, 1 ) . '%', null ),
			array( 'Posizione media', number_format_i18n( (float) $now['position'], 1 ), null ),
		);
		?>
		<div class="ems-seo-grid ems-kpi-grid">
			<?php foreach ( $kpis as $kpi ) : ?>
				<div class="ems-seo-card">
					<span><?php echo esc_html( $kpi[0] ); ?></span>
					<strong><?php echo esc_html( $kpi[1] ); ?></strong>
					<small>
						<?php
						if ( null === $kpi[2] ) {
							echo 'dato osservato';
						} else {
							echo esc_html( $this->signed_pct( (float) $kpi[2] ) . ' vs periodo precedente' );
						}
						?>
					</small>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_trend( array $weeks, array $forecast, array $potential ): void {
		$recent = array_slice( $weeks, -12 );
		$max = 0.0;
		foreach ( $recent as $week ) {
			$max = max( $max, (float) ( $week['clicks'] ?? 0 ) );
		}
		foreach ( (array) ( $forecast['points'] ?? array() ) as $point ) {
			$max = max( $max, (float) ( $point['high'] ?? 0 ) );
		}
		$max = max( 1.0, $max );
		?>
		<section class="ems-dashboard-split">
			<div class="ems-seo-panel">
				<div class="ems-section-head">
					<div>
						<span class="ems-seo-kicker">TREND</span>
						<h2>Click settimanali</h2>
					</div>
					<?php if ( ! empty( $forecast['ok'] ) ) : ?>
						<span class="ems-seo-badge ems-seo-info">scenario <?php echo esc_html( $forecast['confidence'] ); ?></span>
					<?php endif; ?>
				</div>
				<div class="ems-week-bars" role="img" aria-label="Click settimanali osservati">
					<?php foreach ( $recent as $week ) : ?>
						<div class="ems-week-bar">
							<div style="height:<?php echo esc_attr( (string) max( 4, round( 100 * (float) $week['clicks'] / $max ) ) ); ?>%"></div>
							<small><?php echo esc_html( wp_date( 'j M', strtotime( (string) $week['week'] ) ) ); ?></small>
							<strong><?php echo esc_html( $this->num( (float) $week['clicks'] ) ); ?></strong>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( ! empty( $forecast['ok'] ) ) : ?>
					<p class="description"><strong>Prossime 4 settimane, scenario lineare:</strong> <?php echo esc_html( $this->num( (float) $forecast['total'] ) ); ?> click complessivi centrali. <?php echo esc_html( (string) $forecast['note'] ); ?></p>
				<?php endif; ?>
			</div>

			<div class="ems-seo-panel">
				<span class="ems-seo-kicker">MARGINE TEORICO</span>
				<h2>Query già vicine</h2>
				<?php if ( empty( $potential['items'] ) ) : ?>
					<p>Nessuna query con dati sufficienti per lo scenario posizione 3.</p>
				<?php else : ?>
					<p class="ems-big-number">+<?php echo esc_html( $this->num( (float) $potential['total'] ) ); ?></p>
					<p>click teorici nel periodo se le query selezionate raggiungessero la posizione 3 mantenendo le impression osservate.</p>
					<ol class="ems-mini-list">
						<?php foreach ( array_slice( $potential['items'], 0, 5 ) as $item ) : ?>
							<li><strong><?php echo esc_html( (string) ( $item['query'] ?? 'query' ) ); ?></strong> · pos. <?php echo esc_html( number_format_i18n( (float) ( $item['position'] ?? 0 ), 1 ) ); ?></li>
						<?php endforeach; ?>
					</ol>
					<p class="description"><?php echo esc_html( (string) $potential['note'] ); ?></p>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	private function render_actions( array $actions, array $analysis ): void {
		$top = array_values( (array) ( $actions['top'] ?? array() ) );
		?>
		<div class="ems-seo-panel">
			<div class="ems-section-head">
				<div>
					<span class="ems-seo-kicker">PRIORITÀ</span>
					<h2>Le 5 azioni della settimana</h2>
				</div>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-actions' ) ); ?>">Centro Azioni</a>
			</div>
			<?php if ( empty( $top ) ) : ?>
				<p>Nessuna azione sufficientemente motivata con i segnali attuali.</p>
			<?php else : ?>
				<div class="ems-action-list">
					<?php foreach ( $top as $i => $item ) : ?>
						<article class="ems-action-item">
							<span class="ems-action-rank"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
							<div>
								<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
								<p><?php echo esc_html( (string) $item['instruction'] ); ?></p>
								<small><?php echo esc_html( (string) $item['source'] ); ?> · affidabilità <?php echo esc_html( (string) $item['confidence'] ); ?></small>
							</div>
							<?php if ( ! empty( $item['url'] ) ) : ?><a class="button button-small" href="<?php echo esc_url( $item['url'] ); ?>">Apri</a><?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $analysis['items'] ) ) : ?>
				<p class="description">Search Console aggiunge segnali solo quando presenti nel campione; righe assenti non vengono trasformate in zero.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_contacts( array $contacts ): void {
		?>
		<div class="ems-seo-panel">
			<div class="ems-section-head">
				<div>
					<span class="ems-seo-kicker">CONTATTI</span>
					<h2>Segnali commerciali osservati</h2>
				</div>
			</div>
			<?php if ( empty( $contacts['available'] ) ) : ?>
				<p>Non ci sono ancora segnali commerciali osservati. Questo non significa zero contatti.</p>
				<p class="description">Il tracker frontend resta spento finché non viene abilitato e collegato al consenso tramite <code>ems_local_seo_contacts_tracking_allowed</code>.</p>
			<?php else : ?>
				<div class="ems-contact-chips">
					<?php $labels = EMS_Local_SEO_Conversion_Signals::allowed_events(); ?>
					<?php foreach ( (array) $contacts['events'] as $event => $count ) : ?>
						<?php if ( (int) $count <= 0 ) { continue; } ?>
						<span><strong><?php echo esc_html( (string) $count ); ?></strong> <?php echo esc_html( $labels[ $event ] ?? $event ); ?></span>
					<?php endforeach; ?>
				</div>
				<p class="description"><?php echo esc_html( (string) $contacts['note'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_tables( array $current, array $previous ): void {
		$queries = EMS_Local_SEO_Forecast::group_by( $current, 'query' );
		$pages   = EMS_Local_SEO_Forecast::group_by( $current, 'page' );
		?>
		<section class="ems-dashboard-split">
			<?php $this->render_rank_table( 'Query principali', $queries, 'query' ); ?>
			<?php $this->render_rank_table( 'Pagine principali', $pages, 'page' ); ?>
		</section>
		<?php
	}

	private function render_rank_table( string $title, array $rows, string $dimension ): void {
		?>
		<div class="ems-seo-panel ems-seo-table-wrap">
			<h2 style="padding:0 20px"><?php echo esc_html( $title ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html( 'query' === $dimension ? 'Query' : 'Pagina' ); ?></th><th>Click</th><th>Impression</th><th>CTR</th><th>Pos.</th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $rows, 0, 10 ) as $row ) : ?>
					<tr>
						<td><?php echo esc_html( 'page' === $dimension ? $this->short_url( (string) $row[ $dimension ] ) : (string) $row[ $dimension ] ); ?></td>
						<td><?php echo esc_html( $this->num( (float) $row['clicks'] ) ); ?></td>
						<td><?php echo esc_html( $this->num( (float) $row['impressions'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $row['ctr'] * 100, 1 ) . '%' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $row['position'], 1 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $rows ) ) : ?><tr><td colspan="5">Nessun dato osservato.</td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_health( array $snapshot ): void {
		$audit = EMS_Local_SEO_Audit::cached_summary();
		$local = EMS_Local_SEO_Plugin::instance()->local_engine->build();
		$verification = EMS_Local_SEO_Plugin::instance()->verification->get_state();
		$link_health  = EMS_Local_SEO_Plugin::instance()->link_health->get_state();
		?>
		<div class="ems-seo-grid">
			<div class="ems-seo-card"><span>SEO Health</span><strong><?php echo esc_html( (string) ( $audit['score'] ?? '—' ) ); ?></strong><small>metrica interna</small></div>
			<div class="ems-seo-card"><span>Dati locali</span><strong><?php echo esc_html( strtoupper( (string) ( $local['data_state']['level'] ?? 'iniziale' ) ) ); ?></strong><small><?php echo esc_html( (string) ( $local['data_state']['available'] ?? 0 ) ); ?>/<?php echo esc_html( (string) ( $local['data_state']['total'] ?? 5 ) ); ?> fonti</small></div>
			<div class="ems-seo-card"><span>HTML pubblico</span><strong><?php echo 'complete' === ( $verification['status'] ?? '' ) ? 'OK' : 'DA FARE'; ?></strong><small>verifica metadata renderizzati</small></div>
			<div class="ems-seo-card"><span>Link Health</span><strong><?php echo 'complete' === ( $link_health['status'] ?? '' ) ? 'OK' : 'DA FARE'; ?></strong><small>copertura link interni</small></div>
		</div>
		</div>
		<?php
	}

	private function num( float $value ): string {
		return number_format_i18n( $value, $value >= 100 ? 0 : 1 );
	}

	private function signed_pct( float $value ): string {
		return ( $value > 0 ? '+' : '' ) . number_format_i18n( $value * 100, 1 ) . '%';
	}

	private function short_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' !== $path ? $path : $url;
	}
}
