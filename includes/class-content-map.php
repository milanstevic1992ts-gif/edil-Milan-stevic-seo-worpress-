<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class EMS_Local_SEO_Content_Map {
    public const TRANSIENT_KEY = 'ems_local_seo_content_map_v1';

    public function hooks(): void {
        add_action( 'admin_menu', array( $this, 'register_page' ), 40 );
        add_action( 'admin_post_ems_local_seo_rebuild_content_map', array( $this, 'handle_rebuild' ) );
        add_action( 'save_post', array( $this, 'invalidate_cache' ) );
    }

    public function register_page(): void {
        add_submenu_page(
            'ems-local-seo',
            'Content Map EMS SEO',
            'Content Map',
            'manage_options',
            'ems-local-seo-content-map',
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

        check_admin_referer( 'ems_local_seo_rebuild_content_map' );
        delete_transient( self::TRANSIENT_KEY );
        $this->build( true );

        wp_safe_redirect( admin_url( 'admin.php?page=ems-local-seo-content-map&ems_map=done' ) );
        exit;
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

        $topics = apply_filters( 'ems_local_seo_content_topics', $this->default_topics() );
        $map    = array();

        foreach ( $topics as $topic_key => $topic ) {
            $map[ $topic_key ] = array(
                'label'    => $topic['label'],
                'keywords' => $topic['keywords'],
                'items'    => array(),
            );
        }

        $documents = array();
        foreach ( $posts as $post ) {
            $searchable = $this->normalize(
                implode(
                    ' ',
                    array(
                        get_the_title( $post ),
                        (string) get_post_meta( $post->ID, '_ems_seo_primary_query', true ),
                        (string) get_post_meta( $post->ID, '_ems_seo_service_name', true ),
                        wp_strip_all_tags( strip_shortcodes( $post->post_excerpt . ' ' . $post->post_content ) ),
                    )
                )
            );

            $documents[ $post->ID ] = array(
                'post'   => $post,
                'title'  => get_the_title( $post ),
                'slug'   => $post->post_name,
                'tokens' => $this->title_tokens( get_the_title( $post ) ),
            );

            foreach ( $topics as $topic_key => $topic ) {
                $score = 0;
                foreach ( $topic['keywords'] as $keyword ) {
                    $needle = $this->normalize( $keyword );
                    if ( '' !== $needle && str_contains( $searchable, $needle ) ) {
                        $score++;
                    }
                }

                if ( $score > 0 ) {
                    $map[ $topic_key ]['items'][] = array(
                        'id'        => $post->ID,
                        'title'     => get_the_title( $post ),
                        'url'       => get_permalink( $post ),
                        'edit_url'  => get_edit_post_link( $post->ID, 'raw' ),
                        'post_type' => $post->post_type,
                        'score'     => $score,
                    );
                }
            }
        }

        foreach ( $map as &$topic ) {
            usort(
                $topic['items'],
                static function ( array $a, array $b ): int {
                    if ( $a['score'] === $b['score'] ) {
                        return strcmp( $a['title'], $b['title'] );
                    }
                    return $b['score'] <=> $a['score'];
                }
            );
            $topic['count'] = count( $topic['items'] );
        }
        unset( $topic );

        $overlaps = $this->find_overlaps( $documents );

        $result = array(
            'generated_at' => current_time( 'mysql' ),
            'total'        => count( $posts ),
            'topics'       => $map,
            'overlaps'     => $overlaps,
        );

        set_transient( self::TRANSIENT_KEY, $result, 12 * HOUR_IN_SECONDS );
        return $result;
    }

    private function find_overlaps( array $documents ): array {
        $overlaps = array();
        $ids      = array_keys( $documents );
        $count    = count( $ids );

        for ( $i = 0; $i < $count; $i++ ) {
            $a = $documents[ $ids[ $i ] ];

            if ( $this->suspicious_slug( $a['slug'] ) ) {
                $overlaps[] = array(
                    'severity' => 'warning',
                    'kind'     => 'slug',
                    'a'        => $this->document_ref( $a ),
                    'b'        => null,
                    'score'    => 100,
                    'reason'   => 'Slug da verificare: sembra una copia/variante generata automaticamente.',
                );
            }

            for ( $j = $i + 1; $j < $count; $j++ ) {
                $b = $documents[ $ids[ $j ] ];
                $similarity = $this->jaccard( $a['tokens'], $b['tokens'] );

                if ( $similarity < 0.66 ) {
                    continue;
                }

                $overlaps[] = array(
                    'severity' => $similarity >= 0.84 ? 'warning' : 'info',
                    'kind'     => 'title',
                    'a'        => $this->document_ref( $a ),
                    'b'        => $this->document_ref( $b ),
                    'score'    => (int) round( $similarity * 100 ),
                    'reason'   => 'Titoli semanticamente molto simili: verificare intento, canonical, consolidamento o differenziazione.',
                );
            }
        }

        usort(
            $overlaps,
            static fn( array $a, array $b ): int => $b['score'] <=> $a['score']
        );

        return array_slice( $overlaps, 0, 150 );
    }

    private function document_ref( array $doc ): array {
        $post = $doc['post'];

        return array(
            'id'       => $post->ID,
            'title'    => $doc['title'],
            'slug'     => $doc['slug'],
            'url'      => get_permalink( $post ),
            'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
        );
    }

    private function suspicious_slug( string $slug ): bool {
        return 1 === preg_match( '/(?:-2|-3|-4|-copy|-copia)$/i', $slug )
            || 1 === preg_match( '/^\d+(?:-\d+)?$/', $slug );
    }

    private function jaccard( array $a, array $b ): float {
        if ( empty( $a ) || empty( $b ) ) {
            return 0.0;
        }

        $intersection = array_intersect( $a, $b );
        $union        = array_unique( array_merge( $a, $b ) );

        return count( $union ) > 0 ? count( $intersection ) / count( $union ) : 0.0;
    }

    private function title_tokens( string $title ): array {
        $text = $this->normalize( $title );
        $raw  = preg_split( '/\s+/', $text );

        $stopwords = array_flip(
            array(
                'trieste','edil','milan','stevic','servizio','servizi','come','cosa','quale','quando','perche','della','delle','degli',
                'dello','nella','nelle','sulla','sulle','alla','alle','allo','con','per','una','uno','del','dei','dal','dalla','che',
                'casa','tuo','tua','nostro','nostra','davvero',
            )
        );

        $tokens = array();
        foreach ( (array) $raw as $token ) {
            if ( mb_strlen( $token ) < 4 || isset( $stopwords[ $token ] ) || is_numeric( $token ) ) {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values( array_unique( $tokens ) );
    }

    private function normalize( string $text ): string {
        $text = remove_accents( mb_strtolower( wp_strip_all_tags( $text ) ) );
        $text = preg_replace( '/[^a-z0-9]+/u', ' ', $text );
        return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
    }

    private function default_topics(): array {
        return array(
            'ristrutturazioni' => array(
                'label'    => 'Ristrutturazioni',
                'keywords' => array( 'ristrutturazione', 'ristrutturare', 'appartamento', 'preventivo', 'progettazione', 'computo metrico' ),
            ),
            'bagni' => array(
                'label'    => 'Bagni e docce',
                'keywords' => array( 'bagno', 'doccia', 'vasca', 'sanitari', 'filo pavimento' ),
            ),
            'piastrelle' => array(
                'label'    => 'Piastrelle e terrazzi',
                'keywords' => array( 'piastrelle', 'piastrellatura', 'fughe', 'terrazzo', 'posa su posa' ),
            ),
            'pavimenti' => array(
                'label'    => 'Pavimenti SPC / LVT',
                'keywords' => array( 'spc', 'lvt', 'laminato', 'pavimento', 'autolivellante' ),
            ),
            'cartongesso' => array(
                'label'    => 'Cartongesso',
                'keywords' => array( 'cartongesso', 'controsoffitto', 'veletta' ),
            ),
            'pittura' => array(
                'label'    => 'Pittura e rasatura',
                'keywords' => array( 'pittura', 'pitturazione', 'tinteggiatura', 'rasatura', 'colore pareti', 'stuccatura' ),
            ),
            'muratura' => array(
                'label'    => 'Muratura e ripristini',
                'keywords' => array( 'muratura', 'opere murarie', 'massetto', 'crepe', 'ripristino', 'tracce' ),
            ),
            'muffa' => array(
                'label'    => 'Muffa, umidità e infiltrazioni',
                'keywords' => array( 'muffa', 'umidita', 'infiltrazioni', 'condensa' ),
            ),
        );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $result = $this->build();
        ?>
        <div class="wrap ems-seo-wrap">
            <div class="ems-seo-hero">
                <div>
                    <span class="ems-seo-kicker">TOPICAL LOCAL SEO</span>
                    <h1>Content Map</h1>
                    <p>Mappa la copertura dei servizi e segnala contenuti che potrebbero competere tra loro. Nessuna pagina viene modificata automaticamente.</p>
                </div>
                <div class="ems-seo-score"><?php echo esc_html( (string) $result['total'] ); ?><small>contenuti</small></div>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ems_local_seo_rebuild_content_map">
                <?php wp_nonce_field( 'ems_local_seo_rebuild_content_map' ); ?>
                <?php submit_button( 'Ricalcola mappa', 'secondary', 'submit', false ); ?>
            </form>

            <div class="ems-seo-grid">
                <?php foreach ( $result['topics'] as $topic ) : ?>
                    <div class="ems-seo-card">
                        <span><?php echo esc_html( $topic['label'] ); ?></span>
                        <strong><?php echo esc_html( (string) $topic['count'] ); ?></strong>
                        <small>contenuti correlati</small>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ems-seo-panel">
                <h2>Copertura per argomento</h2>
                <?php foreach ( $result['topics'] as $topic ) : ?>
                    <details style="margin:10px 0">
                        <summary><strong><?php echo esc_html( $topic['label'] ); ?></strong> · <?php echo esc_html( (string) $topic['count'] ); ?> contenuti</summary>
                        <ul>
                            <?php foreach ( array_slice( $topic['items'], 0, 15 ) as $item ) : ?>
                                <li><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['title'] ); ?></a> <small>(<?php echo esc_html( $item['post_type'] ); ?>)</small></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
            </div>

            <div class="ems-seo-panel ems-seo-table-wrap">
                <h2 style="padding:0 20px">Possibili sovrapposizioni</h2>
                <table class="widefat striped">
                    <thead><tr><th>Tipo</th><th>Contenuto A</th><th>Contenuto B</th><th>Somiglianza</th><th>Nota</th></tr></thead>
                    <tbody>
                    <?php if ( empty( $result['overlaps'] ) ) : ?>
                        <tr><td colspan="5">Nessuna sovrapposizione evidente rilevata.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $result['overlaps'] as $overlap ) : ?>
                            <tr>
                                <td><span class="ems-seo-badge ems-seo-<?php echo esc_attr( $overlap['severity'] ); ?>"><?php echo esc_html( strtoupper( $overlap['kind'] ) ); ?></span></td>
                                <td><a href="<?php echo esc_url( $overlap['a']['edit_url'] ); ?>"><?php echo esc_html( $overlap['a']['title'] ?: $overlap['a']['slug'] ); ?></a><br><small><?php echo esc_html( $overlap['a']['slug'] ); ?></small></td>
                                <td>
                                    <?php if ( ! empty( $overlap['b'] ) ) : ?>
                                        <a href="<?php echo esc_url( $overlap['b']['edit_url'] ); ?>"><?php echo esc_html( $overlap['b']['title'] ?: $overlap['b']['slug'] ); ?></a><br><small><?php echo esc_html( $overlap['b']['slug'] ); ?></small>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( (string) $overlap['score'] ); ?>%</td>
                                <td><?php echo esc_html( $overlap['reason'] ); ?></td>
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
