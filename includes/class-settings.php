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
            'description'           => 'Ristrutturazioni, bagni, piastrellatura, cartongesso, pavimenti e opere murarie a Trieste e provincia.',
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
            'service_areas'         => "Trieste\nMuggia\nOpicina\nDuino-Aurisina\nSan Dorligo della Valle",
            'services'              => "Ristrutturazione appartamenti\nRistrutturazione bagni\nPosa piastrelle\nCartongesso\nPavimenti SPC e LVT\nTinteggiatura e rasatura\nOpere murarie",
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
        $input = is_array( $input ) ? $input : array();
        $out   = self::defaults();

        $text_fields = array(
            'business_name', 'business_schema_type', 'schema_ownership', 'legal_name', 'phone', 'street_address', 'locality',
            'region', 'postal_code', 'country', 'latitude', 'longitude',
        );
        foreach ( $text_fields as $field ) {
            if ( array_key_exists( $field, $input ) ) {
                $out[ $field ] = sanitize_text_field( (string) $input[ $field ] );
            }
        }

        if ( isset( $input['description'] ) ) {
            $out['description'] = sanitize_textarea_field( (string) $input['description'] );
        }
        if ( isset( $input['email'] ) ) {
            $out['email'] = sanitize_email( (string) $input['email'] );
        }

        $url_fields = array( 'website', 'logo_url', 'google_business_url', 'facebook_url', 'instagram_url', 'tiktok_url' );
        foreach ( $url_fields as $field ) {
            if ( isset( $input[ $field ] ) ) {
                $out[ $field ] = esc_url_raw( (string) $input[ $field ] );
            }
        }

        foreach ( array( 'service_areas', 'services' ) as $field ) {
            if ( isset( $input[ $field ] ) ) {
                $lines = preg_split( '/\r\n|\r|\n/', (string) $input[ $field ] );
                $lines = array_filter( array_map( 'sanitize_text_field', (array) $lines ) );
                $out[ $field ] = implode( "\n", array_values( array_unique( $lines ) ) );
            }
        }

        foreach ( array( 'enable_schema', 'enable_breadcrumbs', 'delete_data_uninstall' ) as $flag ) {
            $out[ $flag ] = ! empty( $input[ $flag ] ) ? 1 : 0;
        }

        return $out;
    }

    public function register_menu(): void {
        add_menu_page(
            'EMS Local SEO',
            'EMS SEO',
            'manage_options',
            'ems-local-seo',
            array( $this, 'render_dashboard' ),
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
            </div>

            <div class="ems-seo-panel">
                <h2>Stato sviluppo</h2>
                <p><strong>v0.1 Core:</strong> completato · <strong>v0.2 Metadata:</strong> completato · <strong>v0.3 Entity locale:</strong> completato · <strong>v0.4 Schema graph:</strong> completato · <strong>v0.5 Audit:</strong> completato · <strong>v0.6 Link interni:</strong> completato · <strong>v0.8 Content Map:</strong> completato · <strong>v0.9 Search Console:</strong> completato · <strong>v1.0:</strong> release candidate.</p>
                <p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-opportunities' ) ); ?>">Opportunità Google</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-audit' ) ); ?>">Apri audit</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-link-health' ) ); ?>">Link Health</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-case-studies' ) ); ?>">Lavori reali</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ems-local-seo-settings' ) ); ?>">Configura attività</a></p>
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
