<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Setup {
	private EMS_Local_SEO_Compatibility $compatibility;
	private EMS_Local_SEO_Search_Console $search_console;

	private const STEPS = array(
		'attivita'     => 'Attività',
		'zona'         => 'Zona servita',
		'servizi'      => 'Servizi',
		'orari'        => 'Orari',
		'profili'      => 'Profili online',
		'integrazioni' => 'Integrazioni',
		'verifica'     => 'Verifica',
	);

	public function __construct( EMS_Local_SEO_Compatibility $compatibility, EMS_Local_SEO_Search_Console $search_console ) {
		$this->compatibility  = $compatibility;
		$this->search_console = $search_console;
	}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 15 );
		add_action( 'admin_post_ems_local_seo_import', array( $this, 'handle_import' ) );
	}

	public function register_page(): void {
		add_submenu_page(
			'ems-local-seo',
			'Configurazione guidata EMS SEO',
			'Configurazione',
			'manage_options',
			'ems-local-seo-setup',
			array( $this, 'render' )
		);
	}

	public function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
		}

		check_admin_referer( 'ems_local_seo_import' );

		$settings = wp_parse_args(
			(array) get_option( EMS_Local_SEO_Settings::OPTION_KEY, array() ),
			EMS_Local_SEO_Settings::defaults()
		);
		$defaults = EMS_Local_SEO_Settings::defaults();
		$tsf      = get_option( 'autodescription-site-settings', array() );
		$tsf      = is_array( $tsf ) ? $tsf : array();

		$candidates = array(
			'business_name' => sanitize_text_field( (string) ( $tsf['knowledge_name'] ?? '' ) ),
			'logo_url'      => esc_url_raw( (string) ( $tsf['knowledge_logo_url'] ?? '' ) ),
			'facebook_url'  => esc_url_raw( (string) ( $tsf['knowledge_facebook'] ?? '' ) ),
			'instagram_url' => esc_url_raw( (string) ( $tsf['knowledge_instagram'] ?? '' ) ),
		);

		if ( '' === $candidates['logo_url'] ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );
			if ( $logo_id ) {
				$candidates['logo_url'] = esc_url_raw( (string) wp_get_attachment_image_url( $logo_id, 'full' ) );
			}
		}
		if ( '' === $candidates['logo_url'] && has_site_icon() ) {
			$candidates['logo_url'] = esc_url_raw( (string) get_site_icon_url( 512 ) );
		}

		$filled = 0;
		foreach ( $candidates as $key => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}

			$current = trim( (string) ( $settings[ $key ] ?? '' ) );
			$is_default_name = 'business_name' === $key
				&& $current === (string) $defaults['business_name']
				&& $value !== $current;

			if ( '' === $current || $is_default_name ) {
				$settings[ $key ] = $value;
				$filled++;
			}
		}

		update_option( EMS_Local_SEO_Settings::OPTION_KEY, $settings, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'ems-local-seo-setup',
					'step'       => 'attivita',
					'ems_import' => $filled,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function checks(): array {
		$s = wp_parse_args(
			(array) get_option( EMS_Local_SEO_Settings::OPTION_KEY, array() ),
			EMS_Local_SEO_Settings::defaults()
		);

		$lines = static function ( string $value ): array {
			return array_values(
				array_filter(
					array_map(
						'trim',
						(array) preg_split( '/\r\n|\r|\n/', $value )
					)
				)
			);
		};

		$areas    = $lines( (string) $s['service_areas'] );
		$services = $lines( (string) $s['services'] );
		$snapshot = $this->search_console->get_snapshot();
		$audit    = EMS_Local_SEO_Audit::cached_summary();

		return array(
			array(
				'label'  => 'Nome attività',
				'status' => '' !== trim( (string) $s['business_name'] ) ? 'ok' : 'todo',
				'hint'   => 'Usa il nome pubblico reale dell’attività.',
				'step'   => 'attivita',
			),
			array(
				'label'  => 'Telefono pubblico',
				'status' => '' !== trim( (string) $s['phone'] ) ? 'ok' : 'todo',
				'hint'   => 'Lascia vuoto se non vuoi pubblicarlo nello schema.',
				'step'   => 'attivita',
			),
			array(
				'label'  => 'Logo',
				'status' => '' !== trim( (string) $s['logo_url'] ) ? 'ok' : 'warn',
				'hint'   => 'Può essere importato dal tema.',
				'step'   => 'attivita',
			),
			array(
				'label'  => 'Zona servita',
				'status' => count( $areas ) > 0 ? 'ok' : 'todo',
				'hint'   => 'Inserisci solo comuni/località in cui lavori davvero.',
				'step'   => 'zona',
			),
			array(
				'label'  => 'Servizi',
				'status' => count( $services ) >= 3 ? 'ok' : ( count( $services ) > 0 ? 'warn' : 'todo' ),
				'hint'   => 'Elenca i servizi realmente offerti.',
				'step'   => 'servizi',
			),
			array(
				'label'  => 'Orari',
				'status' => ! empty( EMS_Local_SEO_Schema::parse_opening_hours( (string) $s['opening_hours'] ) ) ? 'ok' : 'warn',
				'hint'   => 'Facoltativi. Formato: lun-ven 08:00-12:00, 14:00-18:00.',
				'step'   => 'orari',
			),
			array(
				'label'  => 'Google Business Profile',
				'status' => '' !== trim( (string) $s['google_business_url'] ) ? 'ok' : 'warn',
				'hint'   => 'Aggiungi il link pubblico della scheda quando disponibile.',
				'step'   => 'profili',
			),
			array(
				'label'  => 'Schema senza duplicati',
				'status' => ! empty( $s['enable_schema'] ) && ! ( 'ems' === $s['schema_ownership'] && $this->compatibility->has_external_seo_plugin() ) ? 'ok' : 'warn',
				'hint'   => 'Con un altro plugin SEO, preferisci modalità Automatico.',
				'step'   => 'integrazioni',
			),
			array(
				'label'  => 'Site Kit',
				'status' => $this->search_console->is_site_kit_active() ? 'ok' : 'todo',
				'hint'   => 'Serve per leggere Search Console senza un secondo OAuth.',
				'step'   => 'integrazioni',
			),
			array(
				'label'  => 'Search Console',
				'status' => empty( $snapshot ) ? 'todo' : ( $this->search_console->is_stale( 48 ) ? 'warn' : 'ok' ),
				'hint'   => empty( $snapshot ) ? 'Nessun dato ancora osservato.' : 'Ultimo aggiornamento: ' . (string) ( $snapshot['generated_at'] ?? '—' ),
				'step'   => 'integrazioni',
			),
			array(
				'label'  => 'IndexNow',
				'status' => ! empty( $s['enable_indexnow'] ) ? 'warn' : 'ok',
				'hint'   => ! empty( $s['enable_indexnow'] ) ? 'Esegui un test della chiave prima di affidarti agli invii.' : 'Facoltativo e spento di default.',
				'step'   => 'integrazioni',
			),
			array(
				'label'  => 'Contatti aggregati',
				'status' => ! empty( $s['enable_contacts'] ) ? 'warn' : 'ok',
				'hint'   => ! empty( $s['enable_contacts'] ) ? 'Richiede che il tuo sistema consenso abiliti il filtro EMS.' : 'Spento di default.',
				'step'   => 'integrazioni',
			),
			array(
				'label'  => 'Audit sito',
				'status' => is_numeric( $audit['score'] ?? null ) ? 'ok' : 'warn',
				'hint'   => is_numeric( $audit['score'] ?? null ) ? 'Metrica interna: ' . $audit['score'] . '/100.' : 'Avvia il primo audit.',
				'step'   => 'verifica',
			),
		);
	}

	public function completeness(): int {
		$checks = $this->checks();
		if ( empty( $checks ) ) {
			return 0;
		}

		$points = 0.0;
		foreach ( $checks as $check ) {
			$points += match ( (string) $check['status'] ) {
				'ok'   => 1.0,
				'warn' => 0.5,
				default => 0.0,
			};
		}

		return (int) round( 100 * $points / count( $checks ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'attivita'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::STEPS[ $step ] ) ) {
			$step = 'attivita';
		}

		$s      = wp_parse_args( (array) get_option( EMS_Local_SEO_Settings::OPTION_KEY, array() ), EMS_Local_SEO_Settings::defaults() );
		$checks = $this->checks();
		?>
		<div class="wrap ems-seo-wrap">
			<div class="ems-seo-hero">
				<div>
					<span class="ems-seo-kicker">CONFIGURAZIONE GUIDATA</span>
					<h1>Imposta EMS senza inventare dati</h1>
					<p>Sette passaggi. I campi vuoti restano vuoti finché non esiste un dato reale.</p>
				</div>
				<div class="ems-seo-score"><?php echo esc_html( (string) $this->completeness() ); ?><small>% completezza</small></div>
			</div>

			<nav class="ems-setup-steps" aria-label="Passaggi configurazione">
				<?php foreach ( self::STEPS as $key => $label ) : ?>
					<a class="<?php echo $step === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ems-local-seo-setup', 'step' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ( isset( $_GET['ems_import'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success inline"><p>Import completato: <?php echo esc_html( (string) absint( $_GET['ems_import'] ) ); ?> campi aggiunti senza sovrascrivere dati già inseriti.</p></div>
			<?php endif; ?>

			<?php if ( 'verifica' === $step ) : ?>
				<?php $this->render_checks( $checks ); ?>
			<?php else : ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'ems_local_seo_group' ); ?>
					<div class="ems-seo-panel">
						<?php $this->render_step_fields( $step, $s ); ?>
					</div>
					<?php submit_button( 'Salva questo passaggio' ); ?>
				</form>
			<?php endif; ?>

			<?php if ( 'attivita' === $step ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ems_local_seo_import">
					<?php wp_nonce_field( 'ems_local_seo_import' ); ?>
					<?php submit_button( 'Importa dati disponibili da SEO Framework / tema', 'secondary', 'submit', false ); ?>
				</form>
			<?php elseif ( 'integrazioni' === $step && EMS_Local_SEO_IndexNow::is_enabled() ) : ?>
				<div class="ems-seo-panel">
					<h2>Test IndexNow</h2>
					<p>Verifica che il motore riesca a leggere la chiave pubblica del sito. Un HTTP 200/202 indica ricezione, non indicizzazione garantita.</p>
					<p><code><?php echo esc_html( EMS_Local_SEO_IndexNow::key_url() ); ?></code></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ems_local_seo_test_indexnow">
						<?php wp_nonce_field( 'ems_local_seo_test_indexnow' ); ?>
						<?php submit_button( 'Invia test IndexNow', 'secondary', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_step_fields( string $step, array $s ): void {
		if ( 'attivita' === $step ) {
			echo '<h2>1. Attività</h2><p>Inserisci solo dati pubblici reali.</p>';
			$this->text( 'business_name', 'Nome attività', $s );
			$this->text( 'legal_name', 'Nome legale', $s );
			$this->textarea( 'description', 'Descrizione', $s );
			$this->text( 'phone', 'Telefono pubblico', $s );
			$this->text( 'email', 'Email pubblica', $s, 'email' );
			$this->text( 'logo_url', 'URL logo', $s, 'url' );
			return;
		}

		if ( 'zona' === $step ) {
			echo '<h2>2. Zona servita</h2><p>Non creare località solo per SEO.</p>';
			$this->text( 'street_address', 'Indirizzo pubblico (facoltativo)', $s );
			$this->text( 'locality', 'Città', $s );
			$this->text( 'region', 'Regione', $s );
			$this->text( 'postal_code', 'CAP', $s );
			$this->textarea( 'service_areas', 'Aree servite · una per riga', $s );
			return;
		}

		if ( 'servizi' === $step ) {
			echo '<h2>3. Servizi</h2>';
			$this->textarea( 'services', 'Servizi · uno per riga', $s );
			return;
		}

		if ( 'orari' === $step ) {
			echo '<h2>4. Orari</h2><p>Formato esempio: <code>lun-ven 08:00-12:00, 14:00-18:00</code></p>';
			$this->textarea( 'opening_hours', 'Orari', $s );
			return;
		}

		if ( 'profili' === $step ) {
			echo '<h2>5. Profili online</h2>';
			$this->text( 'google_business_url', 'Google Business Profile', $s, 'url' );
			$this->text( 'facebook_url', 'Facebook', $s, 'url' );
			$this->text( 'instagram_url', 'Instagram', $s, 'url' );
			$this->text( 'tiktok_url', 'TikTok', $s, 'url' );
			return;
		}

		echo '<h2>6. Integrazioni</h2>';
		$this->check( 'enable_schema', 'Schema JSON-LD', $s );
		$this->check( 'enable_breadcrumbs', 'BreadcrumbList', $s );
		$this->check( 'enable_indexnow', 'IndexNow', $s );
		$this->check( 'gsc_auto_refresh', 'Refresh GSC giornaliero dopo autorizzazione manuale', $s );
		$this->check( 'enable_contacts', 'Contatti aggregati · richiede consenso collegato via filtro', $s );
	}

	private function render_checks( array $checks ): void {
		?>
		<div class="ems-seo-panel ems-seo-table-wrap">
			<h2 style="padding:0 20px">7. Verifica finale</h2>
			<table class="widefat striped">
				<thead><tr><th>Stato</th><th>Controllo</th><th>Nota</th><th>Passaggio</th></tr></thead>
				<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<tr>
						<td><span class="ems-seo-badge ems-status-<?php echo esc_attr( $check['status'] ); ?>"><?php echo esc_html( strtoupper( $check['status'] ) ); ?></span></td>
						<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
						<td><?php echo esc_html( $check['hint'] ); ?></td>
						<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ems-local-seo-setup', 'step' => $check['step'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( self::STEPS[ $check['step'] ] ?? $check['step'] ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}

	private function text( string $key, string $label, array $s, string $type = 'text' ): void {
		printf(
			'<label class="ems-seo-field"><span>%1$s</span><input class="regular-text" type="%2$s" name="%3$s[%4$s]" value="%5$s"></label>',
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( EMS_Local_SEO_Settings::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( (string) ( $s[ $key ] ?? '' ) )
		);
	}

	private function textarea( string $key, string $label, array $s ): void {
		printf(
			'<label class="ems-seo-field"><span>%1$s</span><textarea rows="7" name="%2$s[%3$s]">%4$s</textarea></label>',
			esc_html( $label ),
			esc_attr( EMS_Local_SEO_Settings::OPTION_KEY ),
			esc_attr( $key ),
			esc_textarea( (string) ( $s[ $key ] ?? '' ) )
		);
	}

	private function check( string $key, string $label, array $s ): void {
		printf(
			'<label class="ems-seo-check"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
			esc_attr( EMS_Local_SEO_Settings::OPTION_KEY ),
			esc_attr( $key ),
			checked( ! empty( $s[ $key ] ), true, false ),
			esc_html( $label )
		);
	}
}
