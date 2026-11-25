<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'calico_jmcb_scan_results' );
delete_option( 'calico_jmcb_allowed_types' );
delete_option( 'calico_jmcb_allowed_fields' );
delete_option( 'calico_jmcb_field_formats' );

delete_site_transient( 'calico_jmcb_public_release_v1' );
