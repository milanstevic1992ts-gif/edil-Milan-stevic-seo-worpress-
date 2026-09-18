<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class EMS_Local_SEO_Audit {
    public const TRANSIENT_KEY = 'ems_local_seo_audit_v1';
    private EMS_Local_SEO_Compatibility $compatibility;

    public function __construct( EMS_Local_SEO_Compatibility $compatibility ) {
        $this->compatibility = $compatibility;
    }

    public function hooks(): void {
        add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
        add_action( 'admin_post_ems_local_seo_run_audit', array( $this, 'handle_run_audit' ) );
    }

    public function register_page(): void {
        add_submenu_page(
            'ems-local-seo',
            'Audit EMS SEO',
            'Audit',
            'manage_options',
            'ems-local-seo-audit',
            array( $this, 'render' )
        );
    }

    public static function cached_summary(): array {
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( is_array( $cached ) ) {
            return array(
                'score'  => $cached['score'] ?? '—',
                'pages'  => $cached['pages'] ?? 0,
                'issues' => $cached['issues_count'] ?? 0,
            );
        }

        return array( 'score' => '—', 'pages' => 0, 'issues' => 0 );
    }

    public function handle_run_audit(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
        }
        check_admin_referer( 'ems_local_seo_run_audit' );

        $result = $this->run();
        set_transient( self::TRANSIENT_KEY, $result, 6 * HOUR_IN_SECONDS );

        wp_safe_redirect( admin_url( 'admin.php?page=ems-local-seo-audit&ems_audit=done' ) );
        exit;
    }

    public function run(): array {
        $posts = get_posts(
            array(
                'post_type'      => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            )
        );

        $issues       = array();
        $titles       = array();
        $descriptions = array();
        $queries      = array();
        $inbound      = array_fill_keys( wp_list_pluck( $posts, 'ID' ), 0 );

        foreach ( $posts as $post ) {
            $seo_title       = trim( (string) get_post_meta( $post->ID, '_ems_seo_title', true ) );
            $effective_title = '' !== $seo_title ? $seo_title : get_the_title( $post );
            $desc            = trim( (string) get_post_meta( $post->ID, '_ems_seo_description', true ) );
            $noindex         = (bool) get_post_meta( $post->ID, '_ems_seo_noindex', true );
            $primary_query    = trim( (string) get_post_meta( $post->ID, '_ems_seo_primary_query', true ) );

            if ( '' === $desc ) {
                $issues[] = $this->issue( $post, 'warning', 'Meta description mancante' );
            } elseif ( mb_strlen( $desc ) < 70 ) {
                $issues[] = $this->issue( $post, 'info', 'Meta description molto corta (' . mb_strlen( $desc ) . ' caratteri)' );
            } elseif ( mb_strlen( $desc ) > 170 ) {
                $issues[] = $this->issue( $post, 'info', 'Meta description lunga (' . mb_strlen( $desc ) . ' caratteri)' );
            }

            if ( mb_strlen( $effective_title ) > 70 ) {
                $issues[] = $this->issue( $post, 'info', 'Titolo SEO lungo (' . mb_strlen( $effective_title ) . ' caratteri)' );
            }

            if ( $noindex ) {
                $issues[] = $this->issue( $post, 'warning', 'Pagina pubblicata impostata noindex: verificare che sia intenzionale' );
            }

            $title_key = mb_strtolower( wp_strip_all_tags( $effective_title ) );
            if ( '' !== $title_key ) {
                $titles[ $title_key ][] = $post;
            }
            $desc_key = mb_strtolower( wp_strip_all_tags( $desc ) );
            if ( '' !== $desc_key ) {
                $descriptions[ $desc_key ][] = $post;
            }

            $query_key = mb_strtolower( remove_accents( wp_strip_all_tags( $primary_query ) ) );
            if ( '' !== $query_key ) {
                $queries[ $query_key ][] = $post;
            }

            $thumb_id = get_post_thumbnail_id( $post );
            if ( $thumb_id ) {
                $alt = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
                if ( '' === $alt ) {
                    $issues[] = $this->issue( $post, 'info', 'Immagine in evidenza senza testo ALT' );
                }
            }

            $missing_content_alt = $this->count_images_missing_alt( $post->post_content );
            if ( $missing_content_alt > 0 ) {
                $issues[] = $this->issue( $post, 'info', $missing_content_alt . ' immagine/i nel contenuto senza ALT utile' );
            }

            $this->count_internal_links( $post->post_content, $inbound );
        }

        foreach ( $titles as $group ) {
            if ( count( $group ) > 1 ) {
                foreach ( $group as $post ) {
                    $issues[] = $this->issue( $post, 'warning', 'Titolo SEO duplicato con un altro contenuto' );
                }
            }
        }

        foreach ( $descriptions as $group ) {
            if ( count( $group ) > 1 ) {
                foreach ( $group as $post ) {
                    $issues[] = $this->issue( $post, 'warning', 'Meta description duplicata' );
                }
            }
        }

        foreach ( $queries as $query => $group ) {
            if ( count( $group ) > 1 ) {
                foreach ( $group as $post ) {
                    $issues[] = $this->issue( $post, 'info', 'Query principale assegnata a più contenuti: “' . $query . '”. Verificare se gli intenti sono realmente sovrapposti.' );
                }
            }
        }

        $business_type = (string) EMS_Local_SEO_Settings::get( 'business_schema_type', 'GeneralContractor' );
        $street        = trim( (string) EMS_Local_SEO_Settings::get( 'street_address', '' ) );
        if ( 'Organization' !== $business_type && '' === $street ) {
            $issues[] = $this->global_issue(
                'warning',
                'Schema locale senza indirizzo pubblico: per LocalBusiness Google richiede un indirizzo fisico. Inseriscilo solo se reale e pubblico; altrimenti valuta Organization.',
                admin_url( 'admin.php?page=ems-local-seo-settings' )
            );
        }

        $orphan_coverage = $this->count_navigation_inbound( $inbound );

        $front_id = (int) get_option( 'page_on_front' );
        foreach ( $posts as $post ) {
            if ( $post->ID === $front_id ) {
                continue;
            }
            if ( isset( $inbound[ $post->ID ] ) && 0 === $inbound[ $post->ID ] ) {
                $issues[] = $this->issue( $post, 'warning', 'Possibile pagina orfana: nessun link interno rilevato nel contenuto pubblicato' );
            }
        }

        $max_penalty = max( 1, count( $posts ) * 4 );
        $penalty     = 0;
        foreach ( $issues as $issue ) {
            $penalty += 'warning' === $issue['severity'] ? 2 : 1;
        }
        $score = max( 0, min( 100, (int) round( 100 - ( 100 * min( $penalty, $max_penalty ) / $max_penalty ) ) ) );

        return array(
            'generated_at' => current_time( 'mysql' ),
            'score'        => $score,
            'pages'        => count( $posts ),
            'issues_count'    => count( $issues ),
            'issues'          => $issues,
            'orphan_coverage' => $orphan_coverage,
        );
    }

    private function count_navigation_inbound( array &$inbound ): array {
        $coverage = array(
            'post_content'     => true,
            'classic_menus'    => false,
            'block_navigation' => false,
        );

        if ( post_type_exists( 'wp_navigation' ) ) {
            $navigation_posts = get_posts(
                array(
                    'post_type'      => 'wp_navigation',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                )
            );

            foreach ( $navigation_posts as $navigation_post ) {
                $this->count_internal_links( (string) $navigation_post->post_content, $inbound );
            }

            $coverage['block_navigation'] = true;
        }

        if ( function_exists( 'wp_get_nav_menus' ) && function_exists( 'wp_get_nav_menu_items' ) ) {
            foreach ( (array) wp_get_nav_menus() as $menu ) {
                foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
                    $href = isset( $item->url ) ? (string) $item->url : '';
                    $this->count_single_internal_link( $href, $inbound );
                }
            }

            $coverage['classic_menus'] = true;
        }

        return $coverage;
    }

    private function count_images_missing_alt( string $html ): int {
        if ( '' === trim( $html ) ) {
            return 0;
        }

        $missing = 0;
        if ( preg_match_all( '/<img\\b[^>]*>/i', $html, $matches ) ) {
            foreach ( $matches[0] as $tag ) {
                if ( ! preg_match( '/\\balt\\s*=\\s*(["\\\'])\\s*[^"\\\']+\\s*\\1/i', (string) $tag ) ) {
                    $missing++;
                }
            }
        }

        return $missing;
    }

    private function count_internal_links( string $html, array &$inbound ): void {
        if ( '' === trim( $html ) ) {
            return;
        }

        if ( preg_match_all( '/href=["\']([^"\']+)["\']/i', $html, $matches ) ) {
            foreach ( $matches[1] as $href ) {
                $href = html_entity_decode( (string) $href, ENT_QUOTES | ENT_HTML5 );
                if ( ! str_starts_with( $href, home_url() ) && ! str_starts_with( $href, '/' ) ) {
                    continue;
                }
                $this->count_single_internal_link( $href, $inbound );
            }
        }
    }

    private function count_single_internal_link( string $href, array &$inbound ): void {
        $href = html_entity_decode( trim( $href ), ENT_QUOTES | ENT_HTML5 );

        if ( '' === $href ) {
            return;
        }

        if ( ! str_starts_with( $href, home_url() ) && ! str_starts_with( $href, '/' ) ) {
            return;
        }

        $absolute = str_starts_with( $href, '/' ) ? home_url( $href ) : $href;
        $target   = url_to_postid( $absolute );

        if ( $target && array_key_exists( $target, $inbound ) ) {
            $inbound[ $target ]++;
        }
    }

    private function issue( WP_Post $post, string $severity, string $message ): array {
        return array(
            'post_id'  => $post->ID,
            'title'    => get_the_title( $post ),
            'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
            'url'      => get_permalink( $post ),
            'severity' => $severity,
            'message'  => $message,
        );
    }

    private function global_issue( string $severity, string $message, string $edit_url = '' ): array {
        return array(
            'post_id'  => 0,
            'title'    => 'Configurazione sito',
            'edit_url' => $edit_url,
            'url'      => home_url( '/' ),
            'severity' => $severity,
            'message'  => $message,
        );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $result = get_transient( self::TRANSIENT_KEY );
        if ( ! is_array( $result ) ) {
            $result = $this->run();
            set_transient( self::TRANSIENT_KEY, $result, 6 * HOUR_IN_SECONDS );
        }
        ?>
        <div class="wrap ems-seo-wrap">
            <div class="ems-seo-hero"><div><span class="ems-seo-kicker">ANALISI LOCALE</span><h1>SEO Audit</h1><p>Segnala problemi tecnici e strutturali. Il punteggio è interno e non è un punteggio Google.</p></div><div class="ems-seo-score"><?php echo esc_html( (string) $result['score'] ); ?><small>/100</small></div></div>
            <p>Ultimo controllo: <strong><?php echo esc_html( (string) $result['generated_at'] ); ?></strong> · <?php echo esc_html( (string) $result['pages'] ); ?> contenuti · <?php echo esc_html( (string) $result['issues_count'] ); ?> segnalazioni.</p>
            <?php if ( ! empty( $result['orphan_coverage'] ) ) : ?>
                <p><small>Copertura orphan: contenuto sì · menu classici <?php echo ! empty( $result['orphan_coverage']['classic_menus'] ) ? 'sì' : 'non disponibili'; ?> · navigazione a blocchi <?php echo ! empty( $result['orphan_coverage']['block_navigation'] ) ? 'sì' : 'non rilevata'; ?>.</small></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ems_local_seo_run_audit">
                <?php wp_nonce_field( 'ems_local_seo_run_audit' ); ?>
                <?php submit_button( 'Esegui audit ora', 'secondary', 'submit', false ); ?>
            </form>
            <div class="ems-seo-panel ems-seo-table-wrap">
                <table class="widefat striped">
                    <thead><tr><th>Priorità</th><th>Pagina</th><th>Problema</th><th></th></tr></thead>
                    <tbody>
                    <?php if ( empty( $result['issues'] ) ) : ?>
                        <tr><td colspan="4">Nessun problema rilevato dai controlli attuali.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $result['issues'] as $issue ) : ?>
                            <tr>
                                <td><span class="ems-seo-badge ems-seo-<?php echo esc_attr( $issue['severity'] ); ?>"><?php echo esc_html( strtoupper( $issue['severity'] ) ); ?></span></td>
                                <td><a href="<?php echo esc_url( $issue['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $issue['title'] ?: '(senza titolo)' ); ?></a></td>
                                <td><?php echo esc_html( $issue['message'] ); ?></td>
                                <td><a class="button button-small" href="<?php echo esc_url( $issue['edit_url'] ); ?>">Modifica</a></td>
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
