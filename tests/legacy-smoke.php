<?php
require '/wordpress/wp-load.php';
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

require_once '/wordpress/wp-content/plugins/calico-jetengine-mpc-cpt-bridge/calico-jetengine-mpc-cpt-bridge.php';

function calico_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

register_post_type(
	'calico_event',
	array(
		'label'        => 'Events',
		'public'       => true,
		'show_ui'      => true,
		'show_in_rest' => true,
		'supports'     => array( 'title', 'editor', 'thumbnail' ),
	)
);

$admin_id = wp_create_user( 'calico-v240-admin', wp_generate_password(), 'calico-v240@example.test' );
$admin = new WP_User( $admin_id );
$admin->set_role( 'administrator' );
wp_set_current_user( $admin_id );

$seed_id = wp_insert_post( array( 'post_type' => 'calico_event', 'post_status' => 'publish', 'post_title' => 'Seed event' ) );
update_post_meta( $seed_id, 'event-date-start', '1791331200' );
update_post_meta( $seed_id, 'event-date-end', '1791331200' );
update_post_meta( $seed_id, 'event-time', '7pm - 9pm' );

$scan = Calico_JetEngine_MPC_CPT_Bridge::scan_site();
calico_assert( 'date_timestamp_utc' === $scan['types']['calico_event']['fields']['event-date-start']['suggested_format'], 'scanner recognizes UTC-midnight JetEngine date timestamps' );

update_option( 'calico_jmcb_scan_results', $scan );
update_option( 'calico_jmcb_allowed_types', array( 'calico_event' ) );
update_option( 'calico_jmcb_allowed_fields', array( 'calico_event' => array( 'event-date-start', 'event-date-end', 'event-time' ) ) );
update_option( 'calico_jmcb_field_formats', array( 'calico_event' => array( 'event-date-start' => 'auto', 'event-date-end' => 'date_timestamp_utc', 'event-time' => 'text' ) ) );

$types = Calico_JetEngine_MPC_CPT_Bridge::execute_list_types();
$definitions = wp_list_pluck( $types['types'][0]['field_definitions'], 'storage_format', 'name' );
calico_assert( 'date_timestamp_utc' === $definitions['event-date-start'], 'list-types explains the effective date storage format' );

$seed = Calico_JetEngine_MPC_CPT_Bridge::execute_get_item( array( 'post_type' => 'calico_event', 'id' => $seed_id ) );
calico_assert( '1791331200' === $seed['fields']['event-date-start'], 'get-item preserves the raw date value' );
calico_assert( '2026-10-07' === $seed['field_display']['event-date-start'], 'get-item returns a readable date without timezone drift' );

$created = Calico_JetEngine_MPC_CPT_Bridge::execute_create_item(
	array(
		'post_type' => 'calico_event',
		'title'     => 'Created event',
		'fields'    => array( 'event-date-start' => '2026-10-08', 'event-time' => '7pm - 9pm' ),
	)
);
calico_assert( ! is_wp_error( $created ), 'create-item accepts readable ISO dates' );
calico_assert( '1791417600' === get_post_meta( $created['id'], 'event-date-start', true ), 'create-item stores the JetEngine date as UTC-midnight seconds' );
calico_assert( '2026-10-08' === $created['field_display']['event-date-start'], 'created item confirms the readable date' );

$before_invalid = (int) wp_count_posts( 'calico_event' )->draft;
$invalid = Calico_JetEngine_MPC_CPT_Bridge::execute_create_item( array( 'post_type' => 'calico_event', 'title' => 'Bad date', 'fields' => array( 'event-date-start' => 'October-ish' ) ) );
calico_assert( is_wp_error( $invalid ) && 'calico_invalid_date' === $invalid->get_error_code(), 'invalid dates are rejected explicitly' );
calico_assert( $before_invalid === (int) wp_count_posts( 'calico_event' )->draft, 'invalid create rolls back the incomplete draft' );

$updated = Calico_JetEngine_MPC_CPT_Bridge::execute_update_item( array( 'post_type' => 'calico_event', 'id' => $created['id'], 'expected_revision' => $created['revision'], 'fields' => array( 'event-date-end' => '2026-10-09' ) ) );
calico_assert( ! is_wp_error( $updated ) && '2026-10-09' === $updated['field_display']['event-date-end'], 'update-item normalizes and verifies dates' );

$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z1xkAAAAASUVORK5CYII=';
$media = Calico_JetEngine_MPC_CPT_Bridge::execute_upload_media( array( 'data_base64' => $png, 'filename' => 'flyer.png', 'title' => 'Flyer' ) );
calico_assert( ! is_wp_error( $media ) && ! empty( $media['revision'] ), 'upload-media returns conflict-safe media metadata' );
$media_updated = Calico_JetEngine_MPC_CPT_Bridge::execute_update_media( array( 'id' => $media['id'], 'expected_revision' => $media['revision'], 'alt_text' => 'Event flyer description', 'description' => 'AI-written image description.' ) );
calico_assert( ! is_wp_error( $media_updated ) && 'Event flyer description' === $media_updated['alt_text'], 'update-media edits image alt text and description' );
$stale = Calico_JetEngine_MPC_CPT_Bridge::execute_update_media( array( 'id' => $media['id'], 'expected_revision' => $media['revision'], 'title' => 'Stale edit' ) );
calico_assert( is_wp_error( $stale ) && 'calico_media_edit_conflict' === $stale->get_error_code(), 'update-media rejects stale revisions' );

echo "ALL V2.4 SMOKE TESTS PASSED\n";
