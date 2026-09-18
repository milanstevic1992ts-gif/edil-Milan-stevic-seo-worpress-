<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Link_Health {
	public const STATE_OPTION = 'ems_local_seo_link_health_state_v2';
	private const LOCK_OPTION = 'ems_local_seo_link_health_lock_v2';
	private const BATCH_SIZE  = 12;

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 45 );
		add_action( 'admin_post_ems_local_seo_link_health_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_ems_local_seo_link_health_next', array( $this, 'handle_next' ) );
		add_action( 'admin_post_ems_local_seo_link_health_reset', array( $this, 'handle_reset' ) );
		add_action( 'save_post', array( $this, 'mark_stale' ), 20, 1 );
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

	public function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	public function mark_stale( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$state = $this->get_state();
		if ( empty( $state ) || 'idle' === ( $state['status'] ?? 'idle' ) ) {
			return;
		}

		$state['stale']      = true;
		$state['stale_from'] = current_time( 'mysql' );
		update_option( self::STATE_OPTION, $state, false );
	}

	public function handle_start(): void {
		$this->guard_action( 'ems_local_seo_link_health_start' );

		$targets = $this->discover_targets();
		$state   = array(
			'status'       => empty( $targets ) ? 'complete' : 'running',
			'started_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
			'completed_at' => empty( $targets ) ? current_time( 'mysql' ) : '',
			'total'        => count( $targets ),
			'cursor'       => 0,
			'targets'      => array_values( $targets ),
			'items'        => array(),
			'summary'      => $this->empty_summary(),
			'stale'        => false,
			'stale_from'   => '',
			'coverage'     => array(
				'post_content' => true,
				'classic_menus' => true,
				'block_navigation' => post_type_exists( 'wp_navigation' ),
			),
		);

		update_option( self::STATE_OPTION, $state, false );
		$this->redirect();
	}

	public function handle_next(): void {
		$this->guard_action( 'ems_local_seo_link_health_next' );

		if ( ! $this->acquire_lock() ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'      => 'ems-local-seo-link-health',
						'ems_links' => 'locked',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		try {
			$this->process_batch();
		} finally {
			$this->release_lock();
		}

		$this->redirect();
	}

	public function handle_reset(): void {
		$this->guard_action( 'ems_local_seo_link_health_reset' );
		delete_option( self::STATE_OPTION );
		delete_option( self::LOCK_OPTION );
		$this->redirect();
	}

	private function discover_targets(): array {
		$targets = array();

		$post_types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		if ( post_type_exists( 'wp_navigation' ) ) {
			$post_types[] = 'wp_navigation';
		}
		$post_types = array_values( array_unique( $post_types ) );

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$source_type = 'wp_navigation' === $post->post_type ? 'block_navigation' : 'post_content';
			$this->append_links(
				$targets,
				$this->extract_internal_links( (string) $post->post_content ),
				array(
					'id'       => $post->ID,
					'title'    => get_the_title( $post ) ?: '#' . $post->ID,
					'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
					'type'     => $source_type,
				)
			);
		}

		if ( function_exists( 'wp_get_nav_menus' ) && function_exists( 'wp_get_nav_menu_items' ) ) {
			$menus = wp_get_nav_menus();

			foreach ( (array) $menus as $menu ) {
				$items = wp_get_nav_menu_items( $menu->term_id );

				foreach ( (array) $items as $item ) {
					$url = isset( $item->url ) ? esc_url_raw( (string) $item->url ) : '';
					if ( ! $this->is_internal_url( $url ) ) {
						continue;
					}

					$this->append_links(
						$targets,
						array( $this->strip_fragment( $url ) ),
						array(
							'id'       => 0,
							'title'    => 'Menu: ' . $menu->name,
							'edit_url' => admin_url( 'nav-menus.php?action=edit&menu=' . (int) $menu->term_id ),
							'type'     => 'classic_menu',
						)
					);
				}
			}
		}

		ksort( $targets );

		return $targets;
	}

	private function append_links( array &$targets, array $urls, array $source ): void {
		foreach ( $urls as $url ) {
			if ( '' === $url ) {
				continue;
			}

			if ( ! isset( $targets[ $url ] ) ) {
				$targets[ $url ] = array(
					'url'     => $url,
					'sources' => array(),
				);
			}

			$key = md5( (string) $source['type'] . '|' . (string) $source['id'] . '|' . (string) $source['title'] );
			$targets[ $url ]['sources'][ $key ] = $source;
		}
	}

	private function process_batch(): void {
		$state = $this->get_state();

		if ( empty( $state ) || 'running' !== ( $state['status'] ?? '' ) ) {
			return;
		}

		$targets = array_values( (array) ( $state['targets'] ?? array() ) );
		$cursor  = max( 0, (int) ( $state['cursor'] ?? 0 ) );
		$total   = count( $targets );
		$end     = min( $total, $cursor + self::BATCH_SIZE );

		for ( $index = $cursor; $index < $end; $index++ ) {
			$target = $targets[ $index ];
			$url    = (string) ( $target['url'] ?? '' );
			$status = $this->check_url( $url );

			$state['items'][ $url ] = array(
				'url'      => $url,
				'status'   => $status['status'],
				'location' => $status['location'],
				'error'    => $status['error'],
				'sources'  => array_values( (array) ( $target['sources'] ?? array() ) ),
			);
		}

		$state['cursor']     = $end;
		$state['updated_at'] = current_time( 'mysql' );
		$state['summary']    = $this->summarize( (array) $state['items'] );

		if ( $end >= $total ) {
			$state['status']       = 'complete';
			$state['completed_at'] = current_time( 'mysql' );
		}

		update_option( self::STATE_OPTION, $state, false );
	}

	private function extract_internal_links( string $html ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$urls = array();

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

			$href = $this->strip_fragment( $href );

			if ( $this->is_internal_url( $href ) ) {
				$urls[] = esc_url_raw( $href );
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	private function strip_fragment( string $url ): string {
		$fragmentless = strtok( $url, '#' );

		return false === $fragmentless ? '' : $fragmentless;
	}

	private function is_internal_url( string $url ): bool {
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		if ( str_contains( $url, '/wp-admin/' ) || str_contains( $url, '/wp-login.php' ) ) {
			return false;
		}

		$home_host = mb_strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$url_host  = mb_strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return '' !== $home_host && $home_host === $url_host;
	}

	private function check_url( string $url ): array {
		$args = array(
			'timeout'     => 4,
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

	private function summarize( array $items ): array {
		$summary = $this->empty_summary();

		foreach ( $items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( ! empty( $row['error'] ) || 0 === (int) ( $row['status'] ?? 0 ) ) {
				$summary['error']++;
			} elseif ( (int) $row['status'] >= 400 ) {
				$summary['broken']++;
			} elseif ( (int) $row['status'] >= 300 ) {
				$summary['redirect']++;
			} else {
				$summary['ok']++;
			}
		}

		return $summary;
	}

	private function empty_summary(): array {
		return array(
			'ok'       => 0,
			'redirect' => 0,
			'broken'   => 0,
			'error'    => 0,
		);
	}

	private function acquire_lock(): bool {
		$now    = time();
		$expiry = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $expiry > $now ) {
			return false;
		}

		if ( $expiry > 0 ) {
			delete_option( self::LOCK_OPTION );
		}

		return add_option( self::LOCK_OPTION, $now + 90, '', false );
	}

	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	private function guard_action( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
		}

		check_admin_referer( $nonce_action );
	}

	private function redirect(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=ems-local-seo-link-health' ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state    = $this->get_state();
		$total    = (int) ( $state['total'] ?? 0 );
		$cursor   = min( $total, (int) ( $state['cursor'] ?? 0 ) );
		$coverage = $total > 0 ? (int) round( 100 * $cursor / $total ) : 0;
		$status   = (string) ( $state['status'] ?? 'idle' );
		$summary  = (array) ( $state['summary'] ?? $this->empty_summary() );
		$items    = array_values( (array) ( $state['items'] ?? array() ) );

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$rank = static function ( array $row ): int {
					if ( ! empty( $row['error'] ) || 0 === (int) ( $row['status'] ?? 0 ) ) {
						return 0;
					}
					if ( (int) $row['status'] >= 400 ) {
						return 1;
					}
					if ( (int) $row['status'] >= 300 ) {
						return 2;
					}
					return 3;
				};

				return $rank( $a ) <=> $rank( $b );
			}
		);
		?>
		<div class="wrap ems-seo-wrap">
			<div class="ems-seo-hero">
				<div>
					<span class="ems-seo-kicker">TECHNICAL SEO</span>
					<h1>Link Health</h1>
					<p>Scansione completa e riprendibile dei link interni rilevati in contenuti e navigazione. EMS non crea redirect automaticamente.</p>
				</div>
				<div class="ems-seo-score"><?php echo esc_html( (string) $coverage ); ?><small>% coperto</small></div>
			</div>

			<?php if ( ! empty( $state['stale'] ) ) : ?>
				<div class="notice notice-warning inline"><p>Contenuti modificati dopo l’avvio. I risultati restano visibili ma la scansione è potenzialmente non aggiornata.</p></div>
			<?php endif; ?>

			<div class="ems-seo-panel">
				<p><strong>Stato:</strong> <?php echo esc_html( $status ); ?> · <strong>Controllati:</strong> <?php echo esc_html( $cursor . '/' . $total ); ?> URL · <strong>Lotto:</strong> <?php echo esc_html( (string) self::BATCH_SIZE ); ?> URL.</p>
				<?php if ( ! empty( $state['coverage'] ) ) : ?>
					<p><small>Copertura sorgenti: contenuto pubblico sì · menu classici sì · navigazione a blocchi <?php echo ! empty( $state['coverage']['block_navigation'] ) ? 'sì' : 'non rilevata'; ?>.</small></p>
				<?php endif; ?>

				<div style="display:flex;gap:8px;flex-wrap:wrap">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ems_local_seo_link_health_start">
						<?php wp_nonce_field( 'ems_local_seo_link_health_start' ); ?>
						<?php submit_button( empty( $state ) ? 'Prepara scansione completa' : 'Ricomincia da zero', 'secondary', 'submit', false ); ?>
					</form>

					<?php if ( 'running' === $status ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ems_local_seo_link_health_next">
							<?php wp_nonce_field( 'ems_local_seo_link_health_next' ); ?>
							<?php submit_button( 'Controlla prossimo lotto', 'primary', 'submit', false ); ?>
						</form>
					<?php endif; ?>

					<?php if ( ! empty( $state ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ems_local_seo_link_health_reset">
							<?php wp_nonce_field( 'ems_local_seo_link_health_reset' ); ?>
							<?php submit_button( 'Azzera risultati', 'delete', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $state ) ) : ?>
				<div class="ems-seo-grid">
					<div class="ems-seo-card"><span>OK</span><strong><?php echo esc_html( (string) ( $summary['ok'] ?? 0 ) ); ?></strong><small>2xx</small></div>
					<div class="ems-seo-card"><span>Redirect</span><strong><?php echo esc_html( (string) ( $summary['redirect'] ?? 0 ) ); ?></strong><small>3xx da valutare</small></div>
					<div class="ems-seo-card"><span>Rotti</span><strong><?php echo esc_html( (string) ( $summary['broken'] ?? 0 ) ); ?></strong><small>4xx / 5xx</small></div>
					<div class="ems-seo-card"><span>Errori rete</span><strong><?php echo esc_html( (string) ( $summary['error'] ?? 0 ) ); ?></strong><small>timeout / risposta non disponibile</small></div>
				</div>

				<div class="ems-seo-panel ems-seo-table-wrap">
					<table class="widefat striped">
						<thead><tr><th>Stato</th><th>URL</th><th>Destinazione / errore</th><th>Usato da</th></tr></thead>
						<tbody>
						<?php if ( empty( $items ) ) : ?>
							<tr><td colspan="4">Nessun URL è stato ancora controllato.</td></tr>
						<?php else : ?>
							<?php foreach ( $items as $item ) : ?>
								<tr>
									<td>
										<?php if ( ! empty( $item['error'] ) || 0 === (int) $item['status'] ) : ?>
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
									<td><?php echo esc_html( ! empty( $item['error'] ) ? $item['error'] : ( $item['location'] ?: '—' ) ); ?></td>
									<td>
										<?php foreach ( array_slice( $item['sources'], 0, 5 ) as $source ) : ?>
											<?php if ( ! empty( $source['edit_url'] ) ) : ?><a href="<?php echo esc_url( $source['edit_url'] ); ?>"><?php endif; ?>
											<?php echo esc_html( $source['title'] ); ?>
											<?php if ( ! empty( $source['edit_url'] ) ) : ?></a><?php endif; ?>
											<br><small><?php echo esc_html( $source['type'] ); ?></small><br>
										<?php endforeach; ?>
									</td>
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
}
