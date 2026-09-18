<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class EMS_Local_SEO_Links {
    public const TRANSIENT_KEY = 'ems_local_seo_link_suggestions_v1';

    public function hooks(): void {
        add_action( 'admin_menu', array( $this, 'register_page' ), 30 );
        add_action( 'admin_post_ems_local_seo_rebuild_links', array( $this, 'handle_rebuild' ) );
        add_action( 'save_post', array( $this, 'invalidate_cache' ) );
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

        $posts = get_posts(
            array(
                'post_type'      => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            )
        );

        $documents = array();
        foreach ( $posts as $post ) {
            $documents[ $post->ID ] = array(
                'post'   => $post,
                'tokens' => $this->document_tokens( $post ),
                'links'  => $this->linked_post_ids( $post->post_content ),
            );
        }

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
                if ( $score < 18 ) {
                    continue;
                }

                $candidates[] = array(
                    'target_id'    => $target_id,
                    'target_title' => get_the_title( $target['post'] ),
                    'target_url'   => get_permalink( $target['post'] ),
                    'edit_url'     => get_edit_post_link( $source_id, 'raw' ),
                    'score'        => $score,
                    'reason'       => $this->reason( $source['tokens'], $target['tokens'] ),
                );
            }

            usort(
                $candidates,
                static fn( array $a, array $b ): int => $b['score'] <=> $a['score']
            );

            foreach ( array_slice( $candidates, 0, 3 ) as $candidate ) {
                $suggestions[] = array_merge(
                    array(
                        'source_id'    => $source_id,
                        'source_title' => get_the_title( $source['post'] ),
                        'source_url'   => get_permalink( $source['post'] ),
                    ),
                    $candidate
                );
            }
        }

        usort(
            $suggestions,
            static fn( array $a, array $b ): int => $b['score'] <=> $a['score']
        );

        $result = array(
            'generated_at' => current_time( 'mysql' ),
            'count'        => count( $suggestions ),
            'items'        => array_slice( $suggestions, 0, 100 ),
        );

        set_transient( self::TRANSIENT_KEY, $result, 12 * HOUR_IN_SECONDS );
        return $result;
    }

    private function document_tokens( WP_Post $post ): array {
        $parts = array(
            get_the_title( $post ),
            (string) get_post_meta( $post->ID, '_ems_seo_primary_query', true ),
            (string) get_post_meta( $post->ID, '_ems_seo_service_name', true ),
            wp_strip_all_tags( strip_shortcodes( $post->post_content ) ),
        );

        $text = mb_strtolower( implode( ' ', $parts ) );
        $text = remove_accents( $text );
        $text = preg_replace( '/[^a-z0-9àèéìòù]+/u', ' ', $text );
        $raw  = preg_split( '/\s+/u', (string) $text );

        $stopwords = array_flip(
            array(
                'a','ad','al','alla','alle','allo','anche','che','chi','con','come','da','dal','dalla','dalle','dei','del','della','delle',
                'di','e','ed','gli','ha','i','il','in','la','le','lo','ma','nel','nella','nelle','non','o','per','piu','quale','sono','su',
                'sul','sulla','tra','un','una','uno','dei','degli','dell','dello','dopo','prima','puo','si','se','come','quando','dove',
                'questo','questa','questi','queste','nostro','nostra','tuo','tua','trieste','edil','milan','stevic',
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

        $priority = $this->tokenize_priority(
            implode(
                ' ',
                array(
                    get_the_title( $post ),
                    (string) get_post_meta( $post->ID, '_ems_seo_primary_query', true ),
                    (string) get_post_meta( $post->ID, '_ems_seo_service_name', true ),
                )
            ),
            $stopwords
        );

        foreach ( $priority as $token ) {
            $weights[ $token ] = min( 8, ( $weights[ $token ] ?? 0 ) + 3 );
        }

        arsort( $weights );
        return array_slice( $weights, 0, 80, true );
    }

    private function tokenize_priority( string $text, array $stopwords ): array {
        $text = remove_accents( mb_strtolower( $text ) );
        $text = preg_replace( '/[^a-z0-9]+/u', ' ', $text );
        $raw  = preg_split( '/\s+/u', (string) $text );

        return array_values(
            array_unique(
                array_filter(
                    (array) $raw,
                    static fn( string $token ): bool => mb_strlen( $token ) >= 4 && ! isset( $stopwords[ $token ] ) && ! is_numeric( $token )
                )
            )
        );
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
        if ( empty( $common ) ) {
            return '';
        }

        return 'Tema comune: ' . implode( ', ', array_slice( $common, 0, 4 ) );
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
                    <p>Individua collegamenti semanticamente utili che non risultano già presenti. EMS non modifica automaticamente le pagine.</p>
                </div>
                <div class="ems-seo-score"><?php echo esc_html( (string) $result['count'] ); ?><small>idee</small></div>
            </div>

            <p>Ultima analisi: <strong><?php echo esc_html( (string) $result['generated_at'] ); ?></strong>. I suggerimenti sono euristici: vanno inseriti solo quando aiutano davvero il lettore.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ems_local_seo_rebuild_links">
                <?php wp_nonce_field( 'ems_local_seo_rebuild_links' ); ?>
                <?php submit_button( 'Ricalcola suggerimenti', 'secondary', 'submit', false ); ?>
            </form>

            <div class="ems-seo-panel ems-seo-table-wrap">
                <table class="widefat striped">
                    <thead><tr><th>Da</th><th>Verso</th><th>Affinità</th><th>Motivo</th><th></th></tr></thead>
                    <tbody>
                    <?php if ( empty( $result['items'] ) ) : ?>
                        <tr><td colspan="5">Nessun suggerimento sufficientemente rilevante rilevato.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $result['items'] as $item ) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url( $item['source_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['source_title'] ); ?></a></td>
                                <td><a href="<?php echo esc_url( $item['target_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['target_title'] ); ?></a></td>
                                <td><strong><?php echo esc_html( (string) $item['score'] ); ?>%</strong></td>
                                <td><?php echo esc_html( $item['reason'] ); ?></td>
                                <td><a class="button button-small" href="<?php echo esc_url( $item['edit_url'] ); ?>">Apri pagina sorgente</a></td>
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
