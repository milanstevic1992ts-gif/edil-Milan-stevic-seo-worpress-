<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class EMS_Local_SEO_Compatibility {
    private array $detected = array();

    public function hooks(): void {
        add_action( 'admin_init', array( $this, 'refresh' ), 5 );
    }

    public function refresh(): void {
        $this->detected = $this->detect();
        update_option( 'ems_local_seo_detected_plugins', $this->detected, false );
    }

    public function get_detected(): array {
        if ( empty( $this->detected ) ) {
            $stored = get_option( 'ems_local_seo_detected_plugins', array() );
            if ( is_array( $stored ) ) {
                $this->detected = $stored;
            }
        }

        return $this->detected;
    }

    public function has_external_seo_plugin(): bool {
        if ( ! empty( $this->get_detected() ) ) {
            return true;
        }

        return function_exists( 'tsf' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' );
    }

    public function has_tsf(): bool {
        $detected = $this->get_detected();

        return isset( $detected['autodescription/autodescription.php'] ) || function_exists( 'tsf' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' );
    }

    public function owns_frontend_meta(): bool {
        return ! $this->has_external_seo_plugin();
    }

    public function detect(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active = (array) get_option( 'active_plugins', array() );
        if ( is_multisite() ) {
            $network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
            $active  = array_values( array_unique( array_merge( $active, $network ) ) );
        }

        $known = array(
            'wordpress-seo/wp-seo.php'                    => 'Yoast SEO',
            'seo-by-rank-math/rank-math.php'              => 'Rank Math SEO',
            'wp-seopress/seopress.php'                    => 'SEOPress',
            'autodescription/autodescription.php'         => 'The SEO Framework',
            'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
            'seo-press/seopress.php'                      => 'SEOPress',
        );

        $detected = array();
        foreach ( $known as $plugin_file => $name ) {
            if ( in_array( $plugin_file, $active, true ) ) {
                $detected[ $plugin_file ] = $name;
            }
        }

        return $detected;
    }
}
