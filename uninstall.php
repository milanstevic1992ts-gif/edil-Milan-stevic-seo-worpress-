<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$settings = get_option( 'ems_local_seo_settings', array() );
if ( empty( $settings['delete_data_uninstall'] ) ) {
    return;
}

delete_option( 'ems_local_seo_settings' );
delete_option( 'ems_local_seo_version' );
delete_option( 'ems_local_seo_detected_plugins' );
delete_transient( 'ems_local_seo_audit_v1' );

global $wpdb;
$meta_keys = array(
    '_ems_seo_title', '_ems_seo_description', '_ems_seo_canonical', '_ems_seo_noindex',
    '_ems_seo_nofollow', '_ems_seo_primary_query', '_ems_seo_schema_type', '_ems_seo_service_name',
);

foreach ( $meta_keys as $meta_key ) {
    $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
}
