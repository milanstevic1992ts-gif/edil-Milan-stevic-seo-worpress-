<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Link_Health {
	public const TRANSIENT_KEY = 'ems_local_seo_link_health_v1';
	private const MAX_URLS = 80;

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 45 );
		add_action( 'admin_post_ems_local_seo_run_link_health', array( $this, 'handle_run' ) );
		add_action( 'save_post', array( $this, 'invalidate_cache' ) );
	}

	public function register_page(): void {
		add_submenu_page(
			'ems-local-seo',
			'Link Health EMS SEO',
			'Link Health',
			'manage_options',
			'ems-local-seo-link-health',
			array( $this, 'render' )
		);
	}

	public function invalidate_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	public function handle_run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
		}

		check_admin_referer( 'ems_local_seo_run_link_health' );

		$result = $this->run();
		set_transient( self::TRANSIENT_KEY, $result, 12 * HOUR_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ems-local-seo-link-health',
					'ems_links'   => 'done',
				),
				admin_url( 'admin.php' )
			)
		);
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

		$targets = array();
		foreach ( $posts as $post ) {
			foreach ( $this->extract_internal_links( $post->post_content ) as $url ) {
				if ( ! isset( $targets[ $url ] ) ) {
					$targets[ $url ] = array(
						'url'     => $url,
						'sources' => array(),
					);
				}

				$targets[ $url ]['sources'][ $post->ID ] = array(
					'id'       => $post->ID,
					'title'    => get_the_title( $post ),
					'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
				);
			}
		}

		$total_found = count( $targets );
		$targets     = array_slice( $targets, 0, self::MAX_URLS, true );
		$checked     = array();

		foreach ( $targets as $url => $target ) {
			$status = $this->check_url( $url );
			$checked[] = array(
				'url'       => $url,
				'status'    => $status['status'],
				'location'  => $status['location'],
				'error'     => $status['error'],
				'sources'   => array_values( $target['sources'] ),
			);
		}

		usort(
			$checked,
			static function ( array $a, array $b ): int {
				$rank = static function ( array $row ): int {
					if ( ! empty( $row['error'] ) ) {
						return 0;
					}
					if ( $row['status'] >= 400 ) {
						return 1;
					}
					if ( $row['status'] >= 300 ) {
						return 2;
					}
					return 3;
				};

				$ra = $rank( $a );
				$rb = $rank( $b );

				return $ra === $rb ? $a['status'] <=> $b['status'] : $ra <=> $rb;
			}
		);

		$summary = array(
			'ok'       => 0,
			'redirect' => 0,
			'broken'   => 0,
			'error'    => 0,
		);

		foreach ( $checked as $row ) {
			if ( ! empty( $row['error'] ) ) {
				$summary['error']++;
			} elseif ( $row['status'] >= 400 ) {
				$summary['broken']++;
			} elseif ( $row['status'] >= 300 ) {
				$summary['redirect']++;
			} else {
				$summary['ok']++;
			}
		}

		return array(
			'generated_at' => current_time( 'mysql' ),
			'total_found'  => $total_found,
			'checked'      => count( $checked ),
			'truncated'    => $total_found > self::MAX_URLS,
			'summary'      => $summary,
			'items'        => $checked,
		);
	}

	private function extract_internal_links( string $html ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$home_host = mb_strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$urls      = array();

		if ( ! preg_match_all( '/href=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			return array();
		}

		foreach ( $matches[1] as $href ) {
			$href = html_entity_decode( trim( (string) $href ), ENT_QUOTES | ENT_HTML5 );

			if ( '' === $href || str_starts_with( $href, '#' ) || str_starts_with( $href, 'mailto:' ) || str_starts_with( $href, 'tel:' ) || str_starts_with( $href, 'javascript:' ) ) {
				continue;
			}

			if ( str_starts_with( $href, '/' ) ) {
				$href = home_url( $href );
			}

			if ( ! wp_http_validate_url( $href ) ) {
				continue;
			}

			$host = mb_strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
			if ( '' === $host || $host !== $home_host ) {
				continue;
			}

			$fragmentless = strtok( $href, '#' );
			if ( false === $fragmentless ) {
				continue;
			}

			if ( str_contains( $fragmentless, '/wp-admin/' ) || str_contains( $fragmentless, '/wp-login.php' ) ) {
				continue;
			}

			$urls[] = esc_url_raw( $fragmentless );
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	private function check_url( string $url ): array {
		$args = array(
			'timeout'     => 3,
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => 'EMS-Local-SEO/' . EMS_LOCAL_SEO_VERSION . '; ' . home_url( '/' ),
		);

		$response = wp_safe_remote_head( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'   => 0,
				'location' => '',
				'error'    => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 405 === $status || 501 === $status ) {
			$response = wp_safe_remote_get(
				$url,
				array_merge(
					$args,
					array( 'limit_response_size' => 1024 )
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'status'   => 0,
					'location' => '',
					'error'    => $response->get_error_message(),
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
		}

		return array(
			'status'   => $status,
			'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
			'error'    => '',
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = get_transient( self::TRANSIENT_KEY );
		?>
		<div class="wrap ems-seo-wrap">
			<div class="ems-seo-hero">
				<div>
					<span class="ems-seo-kicker">TECHNICAL SEO</span>
					<h1>Link Health</h1>
					<p>Controlla manualmente i link interni pubblicati e segnala redirect, 4xx/5xx ed errori di raggiungibilità. EMS non crea redirect automaticamente.</p>
				</div>
				<div class="ems-seo-version">v<?php echo esc_html( EMS_LOCAL_SEO_VERSION ); ?></div>
			</div>

			<div class="ems-seo-panel">
				<p>Il controllo parte solo quando premi il pulsante e verifica al massimo <?php echo esc_html( (string) self::MAX_URLS ); ?> URL interni unici per esecuzione, per non sovraccaricare il sito.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ems_local_seo_run_link_health">
					<?php wp_nonce_field( 'ems_local_seo_run_link_health' ); ?>
					<?php submit_button( 'Controlla link interni', 'primary', 'submit', false ); ?>
				</form>
			</div>

			<?php if ( is_array( $result ) ) : ?>
				<div class="ems-seo-grid">
					<div class="ems-seo-card"><span>OK</span><strong><?php echo esc_html( (string) $result['summary']['ok'] ); ?></strong><small>2xx</small></div>
					<div class="ems-seo-card"><span>Redirect</span><strong><?php echo esc_html( (string) $result['summary']['redirect'] ); ?></strong><small>3xx da valutare nei link interni</small></div>
					<div class="ems-seo-card"><span>Rotti</span><strong><?php echo esc_html( (string) $result['summary']['broken'] ); ?></strong><small>4xx / 5xx</small></div>
					<div class="ems-seo-card"><span>Errori rete</span><strong><?php echo esc_html( (string) $result['summary']['error'] ); ?></strong><small>timeout o risposta non disponibile</small></div>
				</div>

				<p>Controllati <?php echo esc_html( (string) $result['checked'] ); ?> di <?php echo esc_html( (string) $result['total_found'] ); ?> URL interni rilevati. Ultimo controllo: <?php echo esc_html( (string) $result['generated_at'] ); ?>.</p>

				<div class="ems-seo-panel ems-seo-table-wrap">
					<table class="widefat striped">
						<thead><tr><th>Stato</th><th>URL</th><th>Destinazione redirect / errore</th><th>Usato da</th></tr></thead>
						<tbody>
						<?php foreach ( $result['items'] as $item ) : ?>
							<tr>
								<td>
									<?php if ( ! empty( $item['error'] ) ) : ?>
										<span class="ems-seo-badge ems-seo-warning">ERRORE</span>
									<?php elseif ( $item['status'] >= 400 ) : ?>
										<span class="ems-seo-badge ems-seo-warning"><?php echo esc_html( (string) $item['status'] ); ?></span>
									<?php elseif ( $item['status'] >= 300 ) : ?>
										<span class="ems-seo-badge ems-seo-info"><?php echo esc_html( (string) $item['status'] ); ?></span>
									<?php else : ?>
										<strong><?php echo esc_html( (string) $item['status'] ); ?></strong>
									<?php endif; ?>
								</td>
								<td><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['url'] ); ?></a></td>
								<td>
									<?php
									echo esc_html(
										! empty( $item['error'] )
											? $item['error']
											: ( ! empty( $item['location'] ) ? $item['location'] : '—' )
									);
									?>
								</td>
								<td>
									<?php foreach ( array_slice( $item['sources'], 0, 4 ) as $source ) : ?>
										<a href="<?php echo esc_url( $source['edit_url'] ); ?>"><?php echo esc_html( $source['title'] ?: '#' . $source['id'] ); ?></a><br>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
