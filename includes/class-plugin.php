<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class EMS_Local_SEO_Plugin {
    private static ?self $instance = null;
    private bool $booted = false;

    public EMS_Local_SEO_Compatibility $compatibility;
    public EMS_Local_SEO_Settings $settings;
    public EMS_Local_SEO_Meta $meta;
    public EMS_Local_SEO_Schema $schema;
    public EMS_Local_SEO_Audit $audit;
    public EMS_Local_SEO_Links $links;
    public EMS_Local_SEO_Content_Map $content_map;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {}

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }

        $this->booted        = true;
        $this->compatibility = new EMS_Local_SEO_Compatibility();
        $this->settings      = new EMS_Local_SEO_Settings( $this->compatibility );
        $this->meta          = new EMS_Local_SEO_Meta( $this->compatibility );
        $this->schema        = new EMS_Local_SEO_Schema( $this->compatibility );
        $this->audit         = new EMS_Local_SEO_Audit( $this->compatibility );
        $this->links         = new EMS_Local_SEO_Links();
        $this->content_map   = new EMS_Local_SEO_Content_Map();

        $this->compatibility->hooks();
        $this->settings->hooks();
        $this->meta->hooks();
        $this->schema->hooks();
        $this->audit->hooks();
        $this->links->hooks();
        $this->content_map->hooks();

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function enqueue_admin_assets( string $hook_suffix ): void {
        if ( ! str_contains( $hook_suffix, 'ems-local-seo' ) && ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        wp_enqueue_style(
            'ems-local-seo-admin',
            EMS_LOCAL_SEO_URL . 'assets/admin.css',
            array(),
            EMS_LOCAL_SEO_VERSION
        );
    }

    public static function activate(): void {
        $defaults = EMS_Local_SEO_Settings::defaults();
        $current  = get_option( EMS_Local_SEO_Settings::OPTION_KEY, array() );

        if ( ! is_array( $current ) ) {
            $current = array();
        }

        update_option(
            EMS_Local_SEO_Settings::OPTION_KEY,
            wp_parse_args( $current, $defaults ),
            false
        );

        update_option( 'ems_local_seo_version', EMS_LOCAL_SEO_VERSION, false );
    }
}
