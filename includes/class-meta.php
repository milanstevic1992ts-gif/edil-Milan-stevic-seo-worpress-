<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class EMS_Local_SEO_Meta {
    private EMS_Local_SEO_Compatibility $compatibility;

    private const KEYS = array(
        '_ems_seo_title'         => 'string',
        '_ems_seo_description'   => 'string',
        '_ems_seo_canonical'     => 'string',
        '_ems_seo_noindex'       => 'boolean',
        '_ems_seo_nofollow'      => 'boolean',
        '_ems_seo_primary_query' => 'string',
        '_ems_seo_schema_type'   => 'string',
        '_ems_seo_service_name'  => 'string',
    );

    public function __construct( EMS_Local_SEO_Compatibility $compatibility ) {
        $this->compatibility = $compatibility;
    }

    public function hooks(): void {
        add_action( 'init', array( $this, 'register_meta' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_meta_box' ) );

        add_filter( 'pre_get_document_title', array( $this, 'filter_title' ), 90 );
        add_action( 'wp_head', array( $this, 'output_description' ), 1 );
        add_filter( 'get_canonical_url', array( $this, 'filter_canonical' ), 10, 2 );
        add_filter( 'wp_robots', array( $this, 'filter_robots' ), 20 );
    }

    public function register_meta(): void {
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        foreach ( $post_types as $post_type ) {
            foreach ( self::KEYS as $key => $type ) {
                register_post_meta(
                    $post_type,
                    $key,
                    array(
                        'type'              => $type,
                        'single'            => true,
                        'show_in_rest'      => true,
                        'sanitize_callback' => $this->sanitize_callback_for( $key ),
                        'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
                            return $post_id > 0 && current_user_can( 'edit_post', $post_id );
                        },
                    )
                );
            }
        }
    }

    private function sanitize_callback_for( string $key ): callable {
        if ( '_ems_seo_canonical' === $key ) {
            return 'esc_url_raw';
        }
        if ( in_array( $key, array( '_ems_seo_noindex', '_ems_seo_nofollow' ), true ) ) {
            return 'rest_sanitize_boolean';
        }
        if ( '_ems_seo_description' === $key ) {
            return 'sanitize_textarea_field';
        }

        return 'sanitize_text_field';
    }

    public function add_meta_box(): void {
        foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
            add_meta_box(
                'ems-local-seo-meta',
                'EMS SEO',
                array( $this, 'render_meta_box' ),
                $post_type,
                'normal',
                'high'
            );
        }
    }

    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'ems_local_seo_save_meta', 'ems_local_seo_nonce' );
        $title       = (string) get_post_meta( $post->ID, '_ems_seo_title', true );
        $description = (string) get_post_meta( $post->ID, '_ems_seo_description', true );
        $canonical   = (string) get_post_meta( $post->ID, '_ems_seo_canonical', true );
        $query       = (string) get_post_meta( $post->ID, '_ems_seo_primary_query', true );
        $schema      = (string) get_post_meta( $post->ID, '_ems_seo_schema_type', true );
        $service     = (string) get_post_meta( $post->ID, '_ems_seo_service_name', true );
        $noindex     = (bool) get_post_meta( $post->ID, '_ems_seo_noindex', true );
        $nofollow    = (bool) get_post_meta( $post->ID, '_ems_seo_nofollow', true );
        ?>
        <div class="ems-seo-metabox">
            <?php if ( $this->compatibility->has_external_seo_plugin() ) : ?>
                <div class="notice notice-warning inline"><p>È attiva la modalità compatibilità: i campi vengono salvati, ma EMS SEO non emette title/meta/canonical/robots finché è attivo un altro plugin SEO principale.</p></div>
            <?php endif; ?>
            <label class="ems-seo-field"><span>Query principale</span><input type="text" class="widefat" name="ems_seo_primary_query" value="<?php echo esc_attr( $query ); ?>" placeholder="es. ristrutturazione bagno Trieste"></label>
            <label class="ems-seo-field"><span>Titolo Google</span><input type="text" class="widefat" name="ems_seo_title" value="<?php echo esc_attr( $title ); ?>" maxlength="180"><small>Se vuoto, WordPress usa il titolo normale.</small></label>
            <label class="ems-seo-field"><span>Meta description</span><textarea class="widefat" rows="3" name="ems_seo_description" maxlength="320"><?php echo esc_textarea( $description ); ?></textarea></label>
            <label class="ems-seo-field"><span>Canonical personalizzata</span><input type="url" class="widefat" name="ems_seo_canonical" value="<?php echo esc_attr( $canonical ); ?>" placeholder="Lascia vuoto per canonical automatica"></label>
            <div class="ems-seo-two-col">
                <label class="ems-seo-field"><span>Tipo schema</span>
                    <select name="ems_seo_schema_type">
                        <?php foreach ( array( 'auto' => 'Automatico', 'service' => 'Servizio', 'article' => 'Articolo', 'webpage' => 'Pagina', 'none' => 'Nessuno specifico' ) as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $schema ?: 'auto', $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="ems-seo-field"><span>Nome servizio</span><input type="text" name="ems_seo_service_name" value="<?php echo esc_attr( $service ); ?>" placeholder="es. Ristrutturazione bagno"></label>
            </div>
            <div class="ems-seo-inline-checks">
                <label><input type="checkbox" name="ems_seo_noindex" value="1" <?php checked( $noindex ); ?>> noindex</label>
                <label><input type="checkbox" name="ems_seo_nofollow" value="1" <?php checked( $nofollow ); ?>> nofollow</label>
            </div>
        </div>
        <?php
    }

    public function save_meta_box( int $post_id ): void {
        if ( ! isset( $_POST['ems_local_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ems_local_seo_nonce'] ) ), 'ems_local_seo_save_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $map = array(
            '_ems_seo_title'         => array( 'field' => 'ems_seo_title', 'sanitize' => 'sanitize_text_field' ),
            '_ems_seo_description'   => array( 'field' => 'ems_seo_description', 'sanitize' => 'sanitize_textarea_field' ),
            '_ems_seo_canonical'     => array( 'field' => 'ems_seo_canonical', 'sanitize' => 'esc_url_raw' ),
            '_ems_seo_primary_query' => array( 'field' => 'ems_seo_primary_query', 'sanitize' => 'sanitize_text_field' ),
            '_ems_seo_schema_type'   => array( 'field' => 'ems_seo_schema_type', 'sanitize' => 'sanitize_text_field' ),
            '_ems_seo_service_name'  => array( 'field' => 'ems_seo_service_name', 'sanitize' => 'sanitize_text_field' ),
        );

        foreach ( $map as $meta_key => $spec ) {
            $raw   = isset( $_POST[ $spec['field'] ] ) ? wp_unslash( $_POST[ $spec['field'] ] ) : '';
            $value = call_user_func( $spec['sanitize'], $raw );
            if ( '' === $value ) {
                delete_post_meta( $post_id, $meta_key );
            } else {
                update_post_meta( $post_id, $meta_key, $value );
            }
        }

        update_post_meta( $post_id, '_ems_seo_noindex', ! empty( $_POST['ems_seo_noindex'] ) ? 1 : 0 );
        update_post_meta( $post_id, '_ems_seo_nofollow', ! empty( $_POST['ems_seo_nofollow'] ) ? 1 : 0 );
        delete_transient( EMS_Local_SEO_Audit::TRANSIENT_KEY );
    }

    public function filter_title( string $title ): string {
        if ( ! $this->compatibility->owns_frontend_meta() || ! is_singular() ) {
            return $title;
        }

        $custom = trim( (string) get_post_meta( get_queried_object_id(), '_ems_seo_title', true ) );
        return '' !== $custom ? $custom : $title;
    }

    public function output_description(): void {
        if ( ! $this->compatibility->owns_frontend_meta() || ! is_singular() ) {
            return;
        }

        $description = trim( (string) get_post_meta( get_queried_object_id(), '_ems_seo_description', true ) );
        if ( '' === $description ) {
            return;
        }

        echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
    }

    public function filter_canonical( string $canonical, WP_Post $post ): string {
        if ( ! $this->compatibility->owns_frontend_meta() ) {
            return $canonical;
        }

        $custom = trim( (string) get_post_meta( $post->ID, '_ems_seo_canonical', true ) );
        return '' !== $custom ? esc_url_raw( $custom ) : $canonical;
    }

    public function filter_robots( array $robots ): array {
        if ( ! $this->compatibility->owns_frontend_meta() || ! is_singular() ) {
            return $robots;
        }

        $post_id = get_queried_object_id();
        if ( (bool) get_post_meta( $post_id, '_ems_seo_noindex', true ) ) {
            unset( $robots['index'] );
            $robots['noindex'] = true;
        }
        if ( (bool) get_post_meta( $post_id, '_ems_seo_nofollow', true ) ) {
            unset( $robots['follow'] );
            $robots['nofollow'] = true;
        }

        return $robots;
    }
}
