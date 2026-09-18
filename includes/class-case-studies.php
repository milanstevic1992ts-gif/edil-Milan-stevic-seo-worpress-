<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMS_Local_SEO_Case_Studies {
	private const META = array(
		'_ems_case_study_enabled'   => 'boolean',
		'_ems_case_study_service'   => 'string',
		'_ems_case_study_locality'  => 'string',
		'_ems_case_study_completed' => 'string',
		'_ems_case_study_summary'   => 'string',
	);

	public function hooks(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ), 42 );
	}

	public function register_meta(): void {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			foreach ( self::META as $key => $type ) {
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
		if ( '_ems_case_study_enabled' === $key ) {
			return 'rest_sanitize_boolean';
		}
		if ( '_ems_case_study_summary' === $key ) {
			return 'sanitize_textarea_field';
		}

		return 'sanitize_text_field';
	}

	public function add_meta_box(): void {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			add_meta_box(
				'ems-local-seo-case-study',
				'EMS SEO · Lavoro reale / Case Study',
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'default'
			);
		}
	}

	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'ems_case_study_save', 'ems_case_study_nonce' );

		$enabled   = (bool) get_post_meta( $post->ID, '_ems_case_study_enabled', true );
		$service   = (string) get_post_meta( $post->ID, '_ems_case_study_service', true );
		$locality  = (string) get_post_meta( $post->ID, '_ems_case_study_locality', true );
		$completed = (string) get_post_meta( $post->ID, '_ems_case_study_completed', true );
		$summary   = (string) get_post_meta( $post->ID, '_ems_case_study_summary', true );
		?>
		<p><label><input type="checkbox" name="ems_case_study_enabled" value="1" <?php checked( $enabled ); ?>> <strong>Questa pagina descrive un lavoro realmente eseguito</strong></label></p>
		<div class="ems-seo-two-col">
			<label class="ems-seo-field"><span>Servizio principale</span><input type="text" class="widefat" name="ems_case_study_service" value="<?php echo esc_attr( $service ); ?>" placeholder="es. Ristrutturazione bagno"></label>
			<label class="ems-seo-field"><span>Zona / località</span><input type="text" class="widefat" name="ems_case_study_locality" value="<?php echo esc_attr( $locality ); ?>" placeholder="es. Trieste"></label>
		</div>
		<label class="ems-seo-field"><span>Periodo completamento</span><input type="month" name="ems_case_study_completed" value="<?php echo esc_attr( $completed ); ?>"></label>
		<label class="ems-seo-field"><span>Riassunto del lavoro</span><textarea class="widefat" rows="3" name="ems_case_study_summary" placeholder="Problema iniziale, lavorazioni principali e risultato."><?php echo esc_textarea( $summary ); ?></textarea></label>
		<p><small>EMS usa questi dati per organizzare i lavori reali, suggerire collegamenti e controllare le immagini. Non crea markup promozionale o recensioni automatiche.</small></p>
		<?php
	}

	public function save_meta_box( int $post_id ): void {
		if ( ! isset( $_POST['ems_case_study_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ems_case_study_nonce'] ) ), 'ems_case_study_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_ems_case_study_enabled', ! empty( $_POST['ems_case_study_enabled'] ) ? 1 : 0 );

		$fields = array(
			'_ems_case_study_service'   => 'ems_case_study_service',
			'_ems_case_study_locality'  => 'ems_case_study_locality',
			'_ems_case_study_completed' => 'ems_case_study_completed',
			'_ems_case_study_summary'   => 'ems_case_study_summary',
		);

		foreach ( $fields as $meta_key => $field ) {
			$raw = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
			$value = '_ems_case_study_summary' === $meta_key
				? sanitize_textarea_field( $raw )
				: sanitize_text_field( $raw );

			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		delete_transient( EMS_Local_SEO_Content_Map::TRANSIENT_KEY );
		delete_transient( EMS_Local_SEO_Local_Engine::TRANSIENT_KEY );
		delete_transient( EMS_Local_SEO_Links::TRANSIENT_KEY );
		do_action( 'ems_local_seo_observed_change', 'case_study_updated', array( 'post_id' => $post_id ) );
	}

	public function register_page(): void {
		add_submenu_page(
			'ems-local-seo',
			'Lavori reali EMS SEO',
			'Lavori reali',
			'manage_options',
			'ems-local-seo-case-studies',
			array( $this, 'render_page' )
		);
	}

	public function get_case_studies(): array {
		return get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'meta_key'       => '_ems_case_study_enabled',
				'meta_value'     => '1',
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
	}

	private function analyze_post( WP_Post $post ): array {
		$service  = trim( (string) get_post_meta( $post->ID, '_ems_case_study_service', true ) );
		$locality = trim( (string) get_post_meta( $post->ID, '_ems_case_study_locality', true ) );
		$summary  = trim( (string) get_post_meta( $post->ID, '_ems_case_study_summary', true ) );

		$image_ids = $this->content_image_ids( $post->post_content );
		$featured  = get_post_thumbnail_id( $post );
		if ( $featured ) {
			array_unshift( $image_ids, $featured );
		}
		$image_ids = array_values( array_unique( array_filter( $image_ids ) ) );

		$missing_alt = array();
		foreach ( $image_ids as $image_id ) {
			$alt = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
			if ( '' === $alt ) {
				$missing_alt[] = $image_id;
			}
		}

		$checks = array(
			'service'      => '' !== $service,
			'locality'     => '' !== $locality,
			'summary'      => mb_strlen( $summary ) >= 60,
			'featured'     => (bool) $featured,
			'images'       => count( $image_ids ) >= 2,
			'image_alts'   => empty( $missing_alt ),
		);

		$score = (int) round( 100 * count( array_filter( $checks ) ) / count( $checks ) );

		$suggested_alt = trim(
			implode(
				' ',
				array_filter(
					array(
						$service,
						'' !== $locality ? 'a ' . $locality : '',
						'– lavoro realizzato',
					)
				)
			)
		);

		return array(
			'post'          => $post,
			'service'       => $service,
			'locality'      => $locality,
			'summary'       => $summary,
			'image_count'   => count( $image_ids ),
			'missing_alt'   => $missing_alt,
			'checks'        => $checks,
			'score'         => $score,
			'suggested_alt' => $suggested_alt,
		);
	}

	private function content_image_ids( string $content ): array {
		$ids = array();

		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$ids[] = (int) $id;
			}
		}

		return $ids;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$posts = $this->get_case_studies();
		$rows  = array_map( array( $this, 'analyze_post' ), $posts );
		?>
		<div class="wrap ems-seo-wrap">
			<div class="ems-seo-hero">
				<div>
					<span class="ems-seo-kicker">PROVE DI LAVORO REALE</span>
					<h1>Lavori reali / Case Study</h1>
					<p>Trasforma i cantieri documentati in contenuti utili e verificabili: servizio, zona, descrizione, immagini reali e ALT coerenti.</p>
				</div>
				<div class="ems-seo-score"><?php echo esc_html( (string) count( $rows ) ); ?><small>lavori</small></div>
			</div>

			<div class="ems-seo-panel">
				<p>Per aggiungere un lavoro reale, apri una pagina o un articolo e abilita il box <strong>EMS SEO · Lavoro reale / Case Study</strong>. EMS non inventa località, fotografie o lavorazioni.</p>
			</div>

			<div class="ems-seo-panel ems-seo-table-wrap">
				<table class="widefat striped">
					<thead><tr><th>Completezza</th><th>Lavoro</th><th>Servizio / zona</th><th>Immagini</th><th>Suggerimento ALT</th><th></th></tr></thead>
					<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6">Nessun contenuto è ancora marcato come lavoro reale.</td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( (string) $row['score'] ); ?>%</strong></td>
								<td>
									<a href="<?php echo esc_url( get_permalink( $row['post'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $row['post'] ) ?: '#' . $row['post']->ID ); ?></a>
									<br><small><?php echo esc_html( $row['post']->post_status ); ?></small>
								</td>
								<td><?php echo esc_html( $row['service'] ?: 'Servizio mancante' ); ?><br><small><?php echo esc_html( $row['locality'] ?: 'Zona mancante' ); ?></small></td>
								<td><?php echo esc_html( (string) $row['image_count'] ); ?> totali · <?php echo esc_html( (string) count( $row['missing_alt'] ) ); ?> senza ALT</td>
								<td><small><?php echo esc_html( $row['suggested_alt'] ); ?></small></td>
								<td><a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $row['post']->ID, 'raw' ) ); ?>">Completa</a></td>
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
