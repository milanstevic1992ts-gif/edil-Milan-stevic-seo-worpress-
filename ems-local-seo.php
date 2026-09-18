<?php
/**
 * Plugin Name: EMS Local SEO
 * Plugin URI: https://triesteincostruzione.com/
 * Description: SEO locale e tecnico per EDIL MILAN STEVIC: metadata, LocalBusiness/Service schema, audit interno e compatibilità con altri plugin SEO.
 * Version: 1.1.0
 * Author: EDIL MILAN STEVIC
 * Author URI: https://triesteincostruzione.com/
 * Text Domain: ems-local-seo
 * Requires at least: 6.7
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EMS_LOCAL_SEO_VERSION', '1.1.0' );
define( 'EMS_LOCAL_SEO_FILE', __FILE__ );
define( 'EMS_LOCAL_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'EMS_LOCAL_SEO_URL', plugin_dir_url( __FILE__ ) );

require_once EMS_LOCAL_SEO_DIR . 'includes/class-compatibility.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-settings.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-meta.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-schema.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-audit.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-effective-meta.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-verification.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-links.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-content-map.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-local-engine.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-search-console.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-opportunities.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-link-health.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-case-studies.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-change-journal.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-conversion-signals.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-action-center.php';
require_once EMS_LOCAL_SEO_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'EMS_Local_SEO_Plugin', 'activate' ) );

add_action(
    'plugins_loaded',
    static function (): void {
        EMS_Local_SEO_Plugin::instance()->boot();
    }
);
