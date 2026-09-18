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
    public EMS_Local_SEO_Effective_Meta $effective_meta;
    public EMS_Local_SEO_Verification $verification;
    public EMS_Local_SEO_Links $links;
    public EMS_Local_SEO_Content_Map $content_map;
    public EMS_Local_SEO_Local_Engine $local_engine;
    public EMS_Local_SEO_Search_Console $search_console;
    public EMS_Local_SEO_Opportunities $opportunities;
    public EMS_Local_SEO_Link_Health $link_health;
    public EMS_Local_SEO_Case_Studies $case_studies;
    public EMS_Local_SEO_Change_Journal $change_journal;
    public EMS_Local_SEO_Action_Center $action_center;

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
        self::maybe_upgrade();
        $this->compatibility = new EMS_Local_SEO_Compatibility();
        $this->settings      = new EMS_Local_SEO_Settings( $this->compatibility );
        $this->meta          = new EMS_Local_SEO_Meta( $this->compatibility );
        $this->schema        = new EMS_Local_SEO_Schema( $this->compatibility );
        $this->audit         = new EMS_Local_SEO_Audit( $this->compatibility );
        $this->effective_meta = new EMS_Local_SEO_Effective_Meta();
        $this->verification   = new EMS_Local_SEO_Verification( $this->effective_meta, $this->compatibility );
        $this->content_map   = new EMS_Local_SEO_Content_Map();
        $this->local_engine   = new EMS_Local_SEO_Local_Engine();
        $this->links         = new EMS_Local_SEO_Links( $this->local_engine );
        $this->search_console = new EMS_Local_SEO_Search_Console();
        $this->opportunities  = new EMS_Local_SEO_Opportunities( $this->search_console );
        $this->link_health    = new EMS_Local_SEO_Link_Health();
        $this->case_studies   = new EMS_Local_SEO_Case_Studies();
        $this->change_journal  = new EMS_Local_SEO_Change_Journal();
        $this->action_center   = new EMS_Local_SEO_Action_Center(
            $this->local_engine,
            $this->verification,
            $this->link_health,
            $this->search_console,
            $this->opportunities,
            $this->links,
            $this->change_journal
        );

        $this->compatibility->hooks();
        $this->settings->hooks();
        $this->meta->hooks();
        $this->schema->hooks();
        $this->audit->hooks();
        $this->verification->hooks();
        $this->links->hooks();
        $this->content_map->hooks();
        $this->local_engine->hooks();
        $this->search_console->hooks();
        $this->opportunities->hooks();
        $this->link_health->hooks();
        $this->case_studies->hooks();
        $this->change_journal->hooks();
        $this->action_center->hooks();

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

    public static function maybe_upgrade(): void {
        $stored_version = (string) get_option( 'ems_local_seo_version', '' );

        if ( EMS_LOCAL_SEO_VERSION === $stored_version ) {
            return;
        }

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
