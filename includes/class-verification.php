<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Verification {
	public const STATE_OPTION = 'ems_local_seo_verification_state_v1';
	private const LOCK_OPTION = 'ems_local_seo_verification_lock_v1';
	private const BATCH_SIZE  = 8;

	private EMS_Local_SEO_Effective_Meta $reader;
	private EMS_Local_SEO_Compatibility $compatibility;

	public function __construct(
		EMS_Local_SEO_Effective_Meta $reader,
		EMS_Local_SEO_Compatibility $compatibility
	) {
		$this->reader        = $reader;
		$this->compatibility = $compatibility;
	}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 18 );
		add_action( 'admin_post_ems_local_seo_verification_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_ems_local_seo_verification_next', array( $this, 'handle_next' ) );
		add_action( 'admin_post_ems_local_seo_verification_reset', array( $this, 'handle_reset' ) );
		add_action( 'save_post', array( $this, 'mark_stale' ), 20, 1 );
	}

	public function register_page(): void {
		add_submenu_page(
			'ems-local-seo',
			'Verifica HTML pubblico EMS SEO',
			'Verifica HTML',
			'manage_options',
			'ems-local-seo-verification',
			array( $this, 'render' )
		);
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

	public function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	public function handle_start(): void {
		$this->guard_action( 'ems_local_seo_verification_start' );

		$ids = get_posts(
			array(
				'post_type'      => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );

		$state = array(
			'status'       => empty( $ids ) ? 'complete' : 'running',
			'started_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
			'completed_at' => empty( $ids ) ? current_time( 'mysql' ) : '',
			'total'        => count( $ids ),
			'cursor'       => 0,
			'ids'          => $ids,
			'results'      => array(),
			'issues'       => array(),
			'stale'        => false,
			'stale_from'   => '',
			'owner_hint'   => $this->compatibility->has_tsf() ? 'The SEO Framework attivo' : 'Nessun TSF rilevato',
		);

		update_option( self::STATE_OPTION, $state, false );
		$this->redirect();
	}

	public function handle_next(): void {
		$this->guard_action( 'ems_local_seo_verification_next' );

		if ( ! $this->acquire_lock() ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'ems-local-seo-verification',
						'ems_verify' => 'locked',
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
		$this->guard_action( 'ems_local_seo_verification_reset' );
		delete_option( self::STATE_OPTION );
		delete_option( self::LOCK_OPTION );
		$this->redirect();
	}

	private function process_batch(): void {
		$state = $this->get_state();

		if ( empty( $state ) || 'running' !== ( $state['status'] ?? '' ) ) {
			return;
		}

		$ids    = array_values( array_map( 'intval', (array) ( $state['ids'] ?? array() ) ) );
		$cursor = max( 0, (int) ( $state['cursor'] ?? 0 ) );
		$total  = count( $ids );
		$end    = min( $total, $cursor + self::BATCH_SIZE );

		for ( $index = $cursor; $index < $end; $index++ ) {
			$post_id = $ids[ $index ];
			$post    = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				$state['results'][ $post_id ] = array(
					'post_id' => $post_id,
					'error'   => 'Contenuto non più disponibile durante la scansione.',
				);
				continue;
			}

			$result = $this->reader->read_post( $post );
			$result['title_label'] = get_the_title( $post );
			$result['edit_url']    = get_edit_post_link( $post_id, 'raw' );
			$state['results'][ $post_id ] = $result;
		}

		$state['cursor']     = $end;
		$state['updated_at'] = current_time( 'mysql' );
		$state['issues']     = $this->build_issues( (array) $state['results'] );

		if ( $end >= $total ) {
			$state['status']       = 'complete';
			$state['completed_at'] = current_time( 'mysql' );
		}

		update_option( self::STATE_OPTION, $state, false );
	}

	private function build_issues( array $results ): array {
		$issues       = array();
		$titles       = array();
		$descriptions = array();
		$canonicals   = array();

		foreach ( $results as $post_id => $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$label = (string) ( $result['title_label'] ?? '#' . $post_id );
			$url   = (string) ( $result['url'] ?? '' );

			if ( ! empty( $result['error'] ) ) {
				$issues[] = $this->issue( $post_id, $label, $url, 'warning', 'fetch_error', (string) $result['error'] );
				continue;
			}

			$status = (int) ( $result['status'] ?? 0 );
			if ( $status < 200 || $status >= 400 ) {
				$issues[] = $this->issue( $post_id, $label, $url, 'warning', 'http_status', 'HTML pubblico restituito con HTTP ' . $status . '.' );
			}

			if ( 1 !== (int) ( $result['title_count'] ?? 0 ) ) {
				$issues[] = $this->issue(
					$post_id,
					$label,
					$url,
					'warning',
					'title_count',
					'Trovati ' . (int) ( $result['title_count'] ?? 0 ) . ' tag <title> nell’HTML pubblico; atteso 1.'
				);
			}

			if ( (int) ( $result['description_count'] ?? 0 ) > 1 ) {
				$issues[] = $this->issue(
					$post_id,
					$label,
					$url,
					'warning',
					'description_count',
					'Trovate più meta description nell’HTML pubblico.'
				);
			}

			if ( 1 !== (int) ( $result['canonical_count'] ?? 0 ) ) {
				$issues[] = $this->issue(
					$post_id,
					$label,
					$url,
					'warning',
					'canonical_count',
					'Trovati ' . (int) ( $result['canonical_count'] ?? 0 ) . ' canonical nell’HTML pubblico; atteso 1.'
				);
			}

			if ( ! empty( $result['noindex'] ) ) {
				$issues[] = $this->issue( $post_id, $label, $url, 'warning', 'public_noindex', 'La pagina pubblicata espone noindex nell’HTML pubblico.' );
			}

			$canonical = trim( (string) ( $result['canonical'] ?? '' ) );
			if ( '' !== $canonical && '' !== $url && $this->normalize_url( $canonical ) !== $this->normalize_url( $url ) ) {
				$issues[] = $this->issue(
					$post_id,
					$label,
					$url,
					'info',
					'canonical_mismatch',
					'Canonical pubblico diverso dal permalink: ' . $canonical
				);
			}

			$title = mb_strtolower( trim( (string) ( $result['title'] ?? '' ) ) );
			if ( '' !== $title ) {
				$titles[ $title ][] = array( $post_id, $label, $url );
			}

			$description = mb_strtolower( trim( (string) ( $result['description'] ?? '' ) ) );
			if ( '' !== $description ) {
				$descriptions[ $description ][] = array( $post_id, $label, $url );
			}

			$canonical_key = $this->normalize_url( $canonical );
			if ( '' !== $canonical_key ) {
				$canonicals[ $canonical_key ][] = array( $post_id, $label, $url );
			}
		}

		$issues = array_merge(
			$issues,
			$this->duplicate_issues( $titles, 'duplicate_public_title', 'Titolo pubblico duplicato tra più URL.' ),
			$this->duplicate_issues( $descriptions, 'duplicate_public_description', 'Meta description pubblica duplicata tra più URL.' ),
			$this->duplicate_issues( $canonicals, 'duplicate_public_canonical', 'Più URL pubblici dichiarano lo stesso canonical.' )
		);

		return $issues;
	}

	private function duplicate_issues( array $groups, string $code, string $message ): array {
		$issues = array();

		foreach ( $groups as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			foreach ( $group as $item ) {
				$issues[] = $this->issue( (int) $item[0], (string) $item[1], (string) $item[2], 'warning', $code, $message );
			}
		}

		return $issues;
	}

	private function issue( int $post_id, string $label, string $url, string $severity, string $code, string $message ): array {
		return array(
			'post_id'  => $post_id,
			'title'    => $label,
			'url'      => $url,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			'severity' => $severity,
			'code'     => $code,
			'message'  => $message,
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

	private function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return untrailingslashit( $url );
		}

		$scheme = isset( $parts['scheme'] ) ? mb_strtolower( (string) $parts['scheme'] ) : 'https';
		$host   = mb_strtolower( (string) $parts['host'] );
		$path   = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '/';

		return $scheme . '://' . $host . untrailingslashit( $path );
	}

	private function guard_action( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'ems-local-seo' ) );
		}

		check_admin_referer( $nonce_action );
	}

	private function redirect(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=ems-local-seo-verification' ) );
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
		$issues   = (array) ( $state['issues'] ?? array() );
		?>
		<div class="wrap ems-seo-wrap">
			<div class="ems-seo-hero">
				<div>
					<span class="ems-seo-kicker">HTML PUBBLICO</span>
					<h1>Verifica metadati effettivi</h1>
					<p>Confronta il database con ciò che viene realmente pubblicato. Scansione a lotti riprendibile; nessuna modifica alle pagine.</p>
				</div>
				<div class="ems-seo-score"><?php echo esc_html( (string) $coverage ); ?><small>% coperto</small></div>
			</div>

			<?php if ( ! empty( $state['stale'] ) ) : ?>
				<div class="notice notice-warning inline"><p>Contenuti modificati dopo l’avvio della scansione. I risultati restano consultabili ma sono marcati come potenzialmente non aggiornati.</p></div>
			<?php endif; ?>

			<div class="ems-seo-panel">
				<p><strong>Stato:</strong> <?php echo esc_html( $status ); ?> · <strong>Copertura:</strong> <?php echo esc_html( $cursor . '/' . $total ); ?> URL · <strong>Lotto:</strong> <?php echo esc_html( (string) self::BATCH_SIZE ); ?> pagine.</p>
				<?php if ( ! empty( $state['owner_hint'] ) ) : ?><p><strong>Motore SEO rilevato:</strong> <?php echo esc_html( (string) $state['owner_hint'] ); ?>.</p><?php endif; ?>

				<div style="display:flex;gap:8px;flex-wrap:wrap">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ems_local_seo_verification_start">
						<?php wp_nonce_field( 'ems_local_seo_verification_start' ); ?>
						<?php submit_button( empty( $state ) ? 'Avvia scansione' : 'Ricomincia da zero', 'secondary', 'submit', false ); ?>
					</form>

					<?php if ( 'running' === $status ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ems_local_seo_verification_next">
							<?php wp_nonce_field( 'ems_local_seo_verification_next' ); ?>
							<?php submit_button( 'Analizza prossimo lotto', 'primary', 'submit', false ); ?>
						</form>
					<?php endif; ?>

					<?php if ( ! empty( $state ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ems_local_seo_verification_reset">
							<?php wp_nonce_field( 'ems_local_seo_verification_reset' ); ?>
							<?php submit_button( 'Azzera risultati', 'delete', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $state ) ) : ?>
				<div class="ems-seo-panel">
					<h2>Copertura dichiarata</h2>
					<p>EMS ha letto l’HTML pubblico di <strong><?php echo esc_html( (string) $cursor ); ?></strong> contenuti sui <strong><?php echo esc_html( (string) $total ); ?></strong> messi in coda. Le pagine non ancora elaborate non vengono considerate “senza errori”.</p>
				</div>

				<div class="ems-seo-panel ems-seo-table-wrap">
					<table class="widefat striped">
						<thead><tr><th>Priorità</th><th>Pagina</th><th>Riscontro HTML pubblico</th><th></th></tr></thead>
						<tbody>
						<?php if ( empty( $issues ) ) : ?>
							<tr><td colspan="4">Nessun problema rilevato nella porzione già scansionata.</td></tr>
						<?php else : ?>
							<?php foreach ( $issues as $issue ) : ?>
								<tr>
									<td><span class="ems-seo-badge ems-seo-<?php echo esc_attr( $issue['severity'] ); ?>"><?php echo esc_html( strtoupper( $issue['severity'] ) ); ?></span></td>
									<td><a href="<?php echo esc_url( $issue['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $issue['title'] ?: '#' . $issue['post_id'] ); ?></a></td>
									<td><?php echo esc_html( $issue['message'] ); ?><br><small><?php echo esc_html( $issue['code'] ); ?></small></td>
									<td><a class="button button-small" href="<?php echo esc_url( $issue['edit_url'] ); ?>">Apri</a></td>
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
