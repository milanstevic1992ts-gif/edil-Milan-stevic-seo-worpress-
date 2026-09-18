<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class EMS_Local_SEO_Settings {
    public const OPTION_KEY = 'ems_local_seo_settings';
    private EMS_Local_SEO_Compatibility $compatibility;

    public function __construct( EMS_Local_SEO_Compatibility $compatibility ) {
        $this->compatibility = $compatibility;
    }

    public function hooks(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public static function defaults(): array {
        return array(
            'business_name'         => 'EDIL MILAN STEVIC',
            'business_schema_type'  => 'GeneralContractor',
            'legal_name'            => '',
            'description'           => 'Ristrutturazioni, bagni, piastrellatura, cartongesso, pavimenti e opere murarie a Trieste.',
            'phone'                 => '',
            'email'                 => '',
            'website'               => home_url( '/' ),
            'logo_url'              => '',
            'street_address'        => '',
            'locality'              => 'Trieste',
            'region'                => 'Friuli-Venezia Giulia',
            'postal_code'           => '',
            'country'               => 'IT',
            'latitude'              => '',
            'longitude'             => '',
            'google_business_url'   => '',
            'facebook_url'          => '',
            'instagram_url'         => '',
            'tiktok_url'            => '',
            'service_areas'         => "Trieste",
            'services'              => "Ristrutturazione appartamenti\nRistrutturazione bagni\nPosa piastrelle\nCartongesso\nPavimenti SPC e LVT\nTinteggiatura e rasatura\nOpere murarie",
            'opening_hours'         => '',
            'enable_indexnow'       => 0,
            'gsc_auto_refresh'      => 0,
            'enable_contacts'       => 0,
            'enable_schema'         => 1,
            'schema_ownership'      => 'auto',
            'enable_breadcrumbs'    => 1,
            'delete_data_uninstall' => 0,
        );
    }

    public static function get( string $key, mixed $default = null ): mixed {
        $settings = get_option( self::OPTION_KEY, self::defaults() );
        if ( ! is_array( $settings ) ) {
            $settings = self::defaults();
        }

        return $settings[ $key ] ?? $default;
    }

    public function register_settings(): void {
        register_setting(
            'ems_local_seo_group',
            self::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize' ),
                'default'           => self::defaults(),
            )
        );
    }

    public function sanitize( mixed $input ): array {
        $input    = is_array( $input ) ? $input : array();
        $defaults = self::defaults();
        $stored   = get_option( self::OPTION_KEY, array() );
        $stored   = is_array( $stored ) ? $stored : array();

        // Always start from the currently saved settings so a wizard step cannot
        // erase values belonging to another step.
        $out = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );

        $step        = isset( $input['_ems_setup_step'] ) ? sanitize_key( (string) $input['_ems_setup_step'] ) : '';
        $step_fields = self::setup_step_fields();
        $is_partial  = isset( $step_fields[ $step ] );
        $allowed     = $is_partial ? array_flip( $step_fields[ $step ] ) : array();

        $can_update = static function ( string $field ) use ( $is_partial, $allowed ): bool {
            return ! $is_partial || isset( $allowed[ $field ] );
        };

        $text_fields = array(
            'business_name', 'business_schema_type', 'schema_ownership', 'legal_name', 'phone', 'street_address', 'locality',
            'region', 'postal_code', 'country', 'latitude', 'longitude',
        );
        foreach ( $text_fields as $field ) {
            if ( $can_update( $field ) && array_key_exists( $field, $input ) ) {
                $out[ $field ] = sanitize_text_field( (string) $input[ $field ] );
            }
        }

        if ( $can_update( 'description' ) && array_key_exists( 'description', $input ) ) {
            $out['description'] = sanitize_textarea_field( (string) $input['description'] );
        }
        if ( $can_update( 'email' ) && array_key_exists( 'email', $input ) ) {
            $out['email'] = sanitize_email( (string) $input['email'] );
        }

        $url_fields = array( 'website', 'logo_url', 'google_business_url', 'facebook_url', 'instagram_url', 'tiktok_url' );
        foreach ( $url_fields as $field ) {
            if ( $can_update( $field ) && array_key_exists( $field, $input ) ) {
                $out[ $field ] = esc_url_raw( (string) $input[ $field ] );
            }
        }

        if ( $can_update( 'opening_hours' ) && array_key_exists( 'opening_hours', $input ) ) {
            $lines = preg_split( '/\r\n|\r|\n/', (string) $input['opening_hours'] );
            $lines = array_filter( array_map( 'sanitize_text_field', (array) $lines ) );
            $out['opening_hours'] = implode( "\n", array_values( $lines ) );
        }

        foreach ( array( 'service_areas', 'services' ) as $field ) {
            if ( $can_update( $field ) && array_key_exists( $field, $input ) ) {
                $lines = preg_split( '/\r\n|\r|\n/', (string) $input[ $field ] );
                $lines = array_filter( array_map( 'sanitize_text_field', (array) $lines ) );
                $out[ $field ] = implode( "\n", array_values( array_unique( $lines ) ) );
            }
        }

        $flags = array( 'enable_schema', 'enable_breadcrumbs', 'enable_indexnow', 'gsc_auto_refresh', 'enable_contacts', 'delete_data_uninstall' );
        foreach ( $flags as $flag ) {
            if ( $can_update( $flag ) ) {
                // For a submitted checkbox group, absence means the checkbox was
                // intentionally unchecked. Flags from other wizard steps are untouched.
                $out[ $flag ] = ! empty( $input[ $flag ] ) ? 1 : 0;
            }
        }

        return $out;
    }

    private static function setup_step_fields(): array {
        return array(
            'attivita' => array(
                'business_name',
                'legal_name',
                'description',
                'phone',
                'email',
                'logo_url',
            ),
            'zona' => array(
                'street_address',
                'locality',
                'region',
                'postal_code',
                'service_areas',
            ),
            'servizi' => array(
                'services',
            ),
            'orari' => array(
                'opening_hours',
            ),
            'profili' => array(
                'google_business_url',
                'facebook_url',
                'instagram_url',
                'tiktok_url',
            ),
            'integrazioni' => array(
                'enable_schema',
                'enable_breadcrumbs',
                'enable_indexnow',
                'gsc_auto_refresh',
                'enable_contacts',
            ),
        );
    }

    public function register_menu(): void {
        add_menu_page(
            'EMS Local SEO',
            'EMS SEO',
            'manage_options',
            'ems-local-seo',
            static function (): void {
                if ( isset( EMS_Local_SEO_Plugin::instance()->dashboard ) ) {
                    EMS_Local_SEO_Plugin::instance()->dashboard->render();
                    return;
                }
                EMS_Local_SEO_Plugin::instance()->settings->render_dashboard();
            },
            'dashicons-chart-area',
            58
        );

        add_submenu_page(
            'ems-local-seo',
            'Impostazioni EMS SEO',
            'Impostazioni',
            'manage_options',
            'ems-local-seo-settings',
            array( $this, 'render_settings' )
        );
    }

    public function render_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $detected = $this->compatibility->get_detected();
        $audit    = EMS_Local_SEO_Audit::cached_summary();
        $local    = class_exists( 'EMS_Local_SEO_Local_Engine' ) ? EMS_Local_SEO_Plugin::instance()->local_engine->build() : array();
        $local_state = (array) ( $local['data_state'] ?? array() );
        ?>
        <div class="wrap ems-seo-wrap">
            <div class="ems-seo-hero">
                <div>
                    <span class="ems-seo-kicker">EDIL MILAN STEVIC</span>
                    <h1>EMS Local SEO</h1>
                    <p>SEO locale, dati strutturati e controlli tecnici pensati per il sito aziendale.</p>
                </div>
                <div class="ems-seo-version">v<?php echo esc_html( EMS_LOCAL_SEO_VERSION ); ?></div>
            </div>

            <?php if ( ! empty( $detected ) ) : ?>
                <div class="notice notice-warning inline"><p><strong>Modalità compatibilità attiva.</strong> Rilevato: <?php echo esc_html( implode( ', ', array_values( $detected ) ) ); ?>. EMS SEO non sovrascrive title, meta, canonical e robots gestiti dall'altro plugin.</p></div>
            <?php else : ?>
                <div class="notice notice-success inline"><p><strong>EMS SEO gestisce i metadata.</strong> Non è stato rilevato un altro plugin SEO principale.</p></div>
            <?php endif; ?>

            <div class="ems-seo-grid">
                <div class="ems-seo-card"><span>SEO Health</span><strong><?php echo esc_html( (string) ( $audit['score'] ?? '—' ) ); ?></strong><small>metrica interna diagnostica</small></div>
                <div class="ems-seo-card"><span>Pagine controllate</span><strong><?php echo esc_html( (string) ( $audit['pages'] ?? 0 ) ); ?></strong><small>post e pagine pubblicati</small></div>
                <div class="ems-seo-card"><span>Problemi</span><strong><?php echo esc_html( (string) ( $audit['issues'] ?? 0 ) ); ?></strong><small>elementi da verificare</small></div>
                <div class="ems-seo-card"><span>Schema locale</span><strong><?php echo self::get( 'enable_schema', 1 ) ? 'ON' : 'OFF'; ?></strong><small>LocalBusiness + grafo</small></div>
                <div class="ems-seo-card"><span>Motore locale</span><strong><?php echo esc_html( strtoupper( (string) ( $local_state['level'] ?? 'iniziale' ) ) ); ?></strong><small><?php echo esc_html( (string) ( $local_state['available'] ?? 0 ) ); ?>/<?php echo esc_html( (string) ( $local_state['total'] ?? 5 ) ); ?> fonti disponibili</small></div>
            </div>

            <div class="ems-seo-panel">
                <h2>Stato sviluppo</h2>
                <p><strong>v1.1:</strong> motore locale adattivo · <strong>v1.2:</strong> Centro Azioni e memoria operativa. Le priorità funzionano anche senza Search Console e si arricchiscono man mano che arrivano segnali reali.</p>
                <p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-actions' ) ); ?>">Azioni settimana</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-local-engine' ) ); ?>">Motore locale</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-verification' ) ); ?>">Verifica HTML pubblico</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-link-health' ) ); ?>">Link Health</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-opportunities' ) ); ?>">Opportunità Google</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-audit' ) ); ?>">Audit database</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-settings' ) ); ?>">Configura attività</a></p>
            </div>
        </div>
        <?php
    }

    public function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s = wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), self::defaults() );
        ?>
        <div class="wrap ems-seo-wrap">
            <h1>EMS SEO · Impostazioni attività</h1>
            <p>Questi dati alimentano il grafo semantico LocalBusiness/Organization. Inserire solo informazioni realmente pubbliche e verificabili.</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'ems_local_seo_group' ); ?>
                <div class="ems-seo-panel">
                    <h2>Identità</h2>
                    <?php $this->text_row( 'business_name', 'Nome attività', $s ); ?>
                    <?php $this->schema_type_row( $s ); ?>
                    <?php $this->text_row( 'legal_name', 'Ragione sociale / nome legale', $s ); ?>
                    <?php $this->textarea_row( 'description', 'Descrizione', $s ); ?>
                    <?php $this->text_row( 'phone', 'Telefono', $s ); ?>
                    <?php $this->text_row( 'email', 'Email', $s, 'email' ); ?>
                    <?php $this->text_row( 'website', 'Sito', $s, 'url' ); ?>
                    <?php $this->text_row( 'logo_url', 'URL logo', $s, 'url' ); ?>
                </div>

                <div class="ems-seo-panel">
                    <h2>Sede e territorio</h2>
                    <?php $this->text_row( 'street_address', 'Indirizzo pubblico', $s ); ?>
                    <?php $this->text_row( 'locality', 'Città', $s ); ?>
                    <?php $this->text_row( 'region', 'Regione', $s ); ?>
                    <?php $this->text_row( 'postal_code', 'CAP', $s ); ?>
                    <?php $this->text_row( 'country', 'Paese (ISO)', $s ); ?>
                    <?php $this->text_row( 'latitude', 'Latitudine', $s ); ?>
                    <?php $this->text_row( 'longitude', 'Longitudine', $s ); ?>
                    <?php $this->textarea_row( 'service_areas', 'Aree servite · una per riga', $s ); ?>
                    <?php $this->textarea_row( 'services', 'Servizi · uno per riga', $s ); ?>
                    <?php $this->textarea_row( 'opening_hours', 'Orari · es. lun-ven 08:00-12:00, 14:00-18:00', $s ); ?>
                </div>

                <div class="ems-seo-panel">
                    <h2>Profili ufficiali</h2>
                    <?php $this->text_row( 'google_business_url', 'Google Business Profile', $s, 'url' ); ?>
                    <?php $this->text_row( 'facebook_url', 'Facebook', $s, 'url' ); ?>
                    <?php $this->text_row( 'instagram_url', 'Instagram', $s, 'url' ); ?>
                    <?php $this->text_row( 'tiktok_url', 'TikTok', $s, 'url' ); ?>
                </div>

                <div class="ems-seo-panel">
                    <h2>Funzioni</h2>
                    <?php $this->checkbox_row( 'enable_schema', 'Abilita schema JSON-LD', $s ); ?>
                    <?php $this->schema_ownership_row( $s ); ?>
                    <?php $this->checkbox_row( 'enable_breadcrumbs', 'Abilita BreadcrumbList', $s ); ?>
                    <?php $this->checkbox_row( 'enable_indexnow', 'Abilita IndexNow · Bing e motori aderenti', $s ); ?>
                    <?php $this->checkbox_row( 'gsc_auto_refresh', 'Aggiorna Search Console ogni giorno dopo il primo refresh manuale', $s ); ?>
                    <?php $this->checkbox_row( 'enable_contacts', 'Prepara conteggio contatti · richiede integrazione consenso tramite filtro', $s ); ?>
                    <?php $this->checkbox_row( 'delete_data_uninstall', 'Elimina impostazioni quando il plugin viene disinstallato', $s ); ?>
                </div>
                <?php submit_button( 'Salva impostazioni' ); ?>
            </form>
        </div>
        <?php
    }

    private function schema_ownership_row( array $s ): void {
        $modes = array(
            'auto' => 'Automatico · integra The SEO Framework; evita output duplicato con altri plugin SEO',
            'ems'  => 'Forza EMS SEO · usa solo se lo schema dell’altro plugin è disattivato',
            'off'  => 'Disattivato',
        );
        echo '<label class="ems-seo-field"><span>Proprietario schema</span><select name="' . esc_attr( self::OPTION_KEY ) . '[schema_ownership]">';
        foreach ( $modes as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $s['schema_ownership'] ?? 'auto' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
    }

    private function schema_type_row( array $s ): void {
        $types = array(
            'GeneralContractor' => 'GeneralContractor · impresa edile / ristrutturazioni',
            'HomeAndConstructionBusiness' => 'HomeAndConstructionBusiness · generico edilizia/casa',
            'HousePainter' => 'HousePainter · pitturazione',
            'Organization' => 'Organization · usa questo se non vuoi pubblicare una sede fisica',
        );
        echo '<label class="ems-seo-field"><span>Tipo Schema.org</span><select name="' . esc_attr( self::OPTION_KEY ) . '[business_schema_type]">';
        foreach ( $types as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $s['business_schema_type'] ?? 'GeneralContractor' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';
    }

    private function text_row( string $key, string $label, array $s, string $type = 'text' ): void {
        printf(
            '<label class="ems-seo-field"><span>%1$s</span><input class="regular-text" type="%2$s" name="%3$s[%4$s]" value="%5$s"></label>',
            esc_html( $label ), esc_attr( $type ), esc_attr( self::OPTION_KEY ), esc_attr( $key ), esc_attr( (string) ( $s[ $key ] ?? '' ) )
        );
    }

    private function textarea_row( string $key, string $label, array $s ): void {
        printf(
            '<label class="ems-seo-field"><span>%1$s</span><textarea class="large-text" rows="5" name="%2$s[%3$s]">%4$s</textarea></label>',
            esc_html( $label ), esc_attr( self::OPTION_KEY ), esc_attr( $key ), esc_textarea( (string) ( $s[ $key ] ?? '' ) )
        );
    }

    private function checkbox_row( string $key, string $label, array $s ): void {
        printf(
            '<label class="ems-seo-check"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> <span>%4$s</span></label>',
            esc_attr( self::OPTION_KEY ), esc_attr( $key ), checked( ! empty( $s[ $key ] ), true, false ), esc_html( $label )
        );
    }
}
