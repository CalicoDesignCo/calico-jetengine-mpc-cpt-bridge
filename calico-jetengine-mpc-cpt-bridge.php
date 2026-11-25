<?php
/**
 * Plugin Name: Calico JetEngine MPC CPT Bridge
 * Description: Discovers custom post types and fields, then exposes only administrator-approved content through the WordPress Abilities API for MCP/MPC clients.
 * Version: 2.6.0
 * Plugin URI: https://github.com/CalicoDesignCo/calico-jetengine-mpc-cpt-bridge
 * Update URI: https://github.com/CalicoDesignCo/calico-jetengine-mpc-cpt-bridge
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Calico
 * License: GPL-2.0-or-later
 * Text Domain: calico-jetengine-mpc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-advanced-dates.php';
require_once __DIR__ . '/includes/class-updater.php';
Calico_JMCB_Updater::register( __FILE__ );

final class Calico_JetEngine_MPC_CPT_Bridge {
	const VERSION         = '2.6.0';
	const MAX_IMAGE_BYTES = 10485760;
	const CATEGORY      = 'calico-cpt-bridge';
	const OPT_SCAN      = 'calico_jmcb_scan_results';
	const OPT_TYPES     = 'calico_jmcb_allowed_types';
	const OPT_FIELDS    = 'calico_jmcb_allowed_fields';
	const OPT_FORMATS   = 'calico_jmcb_field_formats';
	const PAGE          = 'calico-jetengine-mpc-bridge';

	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
		add_action( 'admin_post_calico_jmcb_scan', array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_calico_jmcb_save', array( __CLASS__, 'handle_save' ) );
	}

	public static function activate() {
		if ( false === get_option( self::OPT_TYPES, false ) ) {
			add_option( self::OPT_TYPES, array() );
		}
		if ( false === get_option( self::OPT_FIELDS, false ) ) {
			add_option( self::OPT_FIELDS, array() );
		}
		if ( false === get_option( self::OPT_FORMATS, false ) ) {
			add_option( self::OPT_FORMATS, array() );
		}
		if ( false === get_option( self::OPT_SCAN, false ) ) {
			add_option( self::OPT_SCAN, array( 'scanned_at' => 0, 'types' => array() ), '', false );
		}
	}

	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Calico CPT Bridge', 'calico-jetengine-mpc' ),
				'description' => __( 'Administrator-approved custom post types and fields.', 'calico-jetengine-mpc' ),
			)
		);
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		self::ability(
			'list-types',
			__( 'List approved custom post types', 'calico-jetengine-mpc' ),
			__( 'Lists the custom post types and fields approved in the Calico bridge.', 'calico-jetengine-mpc' ),
			self::empty_schema(),
			self::list_types_output_schema(),
			array( __CLASS__, 'execute_list_types' ),
			array( __CLASS__, 'permission_read' ),
			array( 'readonly' => true, 'destructive' => false, 'idempotent' => true )
		);
		self::ability(
			'list-items',
			__( 'List custom post type items', 'calico-jetengine-mpc' ),
			__( 'Lists or searches items in an approved custom post type.', 'calico-jetengine-mpc' ),
			array(
				'type'       => 'object',
				'required'   => array( 'post_type' ),
				'properties' => array(
					'post_type' => array( 'type' => 'string' ),
					'search'    => array( 'type' => 'string', 'default' => '' ),
					'status'    => array( 'type' => 'string', 'default' => 'any' ),
					'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
					'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				),
				'additionalProperties' => false,
			),
			self::list_items_output_schema(),
			array( __CLASS__, 'execute_list_items' ),
			array( __CLASS__, 'permission_read' ),
			array( 'readonly' => true, 'destructive' => false, 'idempotent' => true )
		);
		self::ability(
			'get-item',
			__( 'Get a custom post type item', 'calico-jetengine-mpc' ),
			__( 'Reads one item and its approved custom fields.', 'calico-jetengine-mpc' ),
			self::item_schema(),
			self::item_output_schema(),
			array( __CLASS__, 'execute_get_item' ),
			array( __CLASS__, 'permission_read' ),
			array( 'readonly' => true, 'destructive' => false, 'idempotent' => true )
		);
		self::ability(
			'create-item',
			__( 'Create a custom post type item', 'calico-jetengine-mpc' ),
			__( 'Creates one item in an approved custom post type using only administrator-approved custom fields.', 'calico-jetengine-mpc' ),
			self::create_item_schema(),
			self::item_output_schema(),
			array( __CLASS__, 'execute_create_item' ),
			array( __CLASS__, 'permission_create' ),
			array( 'readonly' => false, 'destructive' => false, 'idempotent' => false )
		);
		self::ability(
			'get-media',
			__( 'Get a WordPress image attachment', 'calico-jetengine-mpc' ),
			__( 'Returns an image attachment URL, metadata, and available image sizes so an AI client can inspect or reuse it.', 'calico-jetengine-mpc' ),
			self::media_get_schema(),
			self::media_output_schema(),
			array( __CLASS__, 'execute_get_media' ),
			array( __CLASS__, 'permission_media_read' ),
			array( 'readonly' => true, 'destructive' => false, 'idempotent' => true )
		);
		self::ability(
			'update-media',
			__( 'Update WordPress image metadata', 'calico-jetengine-mpc' ),
			__( 'Updates an existing image attachment title, alt text, caption, or description and rejects stale revisions.', 'calico-jetengine-mpc' ),
			self::media_update_schema(),
			self::media_output_schema(),
			array( __CLASS__, 'execute_update_media' ),
			array( __CLASS__, 'permission_media_edit' ),
			array( 'readonly' => false, 'destructive' => false, 'idempotent' => true )
		);
		self::ability(
			'upload-media',
			__( 'Upload an image to the WordPress Media Library', 'calico-jetengine-mpc' ),
			__( 'Uploads one image from a public HTTPS URL or base64 data and returns the attachment ID for use as featured_image_id.', 'calico-jetengine-mpc' ),
			self::media_upload_schema(),
			self::media_output_schema(),
			array( __CLASS__, 'execute_upload_media' ),
			array( __CLASS__, 'permission_media_upload' ),
			array( 'readonly' => false, 'destructive' => false, 'idempotent' => false )
		);
		self::ability(
			'update-item',
			__( 'Update a custom post type item', 'calico-jetengine-mpc' ),
			__( 'Updates one approved item. Omitted values remain unchanged. Custom fields are restricted to the administrator-approved field list.', 'calico-jetengine-mpc' ),
			array(
				'type'       => 'object',
				'required'   => array( 'post_type', 'id' ),
				'properties' => array(
					'post_type'         => array( 'type' => 'string' ),
					'id'                => array( 'type' => 'integer', 'minimum' => 1 ),
					'title'             => array( 'type' => 'string' ),
					'content'           => array( 'type' => 'string' ),
					'excerpt'           => array( 'type' => 'string' ),
					'slug'              => array( 'type' => 'string' ),
					'status'            => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish' ) ),
					'featured_image_id' => array( 'type' => 'integer', 'minimum' => 0 ),
					'fields'             => array( 'type' => 'object', 'additionalProperties' => true ),
					'expected_modified' => array( 'type' => 'string' ),
					'expected_revision' => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			self::item_output_schema(),
			array( __CLASS__, 'execute_update_item' ),
			array( __CLASS__, 'permission_edit' ),
			array( 'readonly' => false, 'destructive' => false, 'idempotent' => false )
		);
	}

	private static function ability( $name, $label, $description, $input_schema, $output_schema, $callback, $permission, $annotations ) {
		wp_register_ability(
			'calico-jetengine-mpc/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input_schema,
				'output_schema'       => $output_schema,
				'execute_callback'    => $callback,
				'permission_callback' => $permission,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => $annotations,
				),
			)
		);
	}

	private static function empty_schema() {
		return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
	}

	private static function item_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'post_type', 'id' ),
			'properties' => array(
				'post_type' => array( 'type' => 'string' ),
				'id'        => array( 'type' => 'integer', 'minimum' => 1 ),
			),
			'additionalProperties' => false,
		);
	}

	private static function create_item_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'post_type', 'title' ),
			'properties' => array(
				'post_type'         => array( 'type' => 'string' ),
				'title'             => array( 'type' => 'string', 'minLength' => 1 ),
				'content'           => array( 'type' => 'string' ),
				'excerpt'           => array( 'type' => 'string' ),
				'slug'              => array( 'type' => 'string' ),
				'status'            => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish' ), 'default' => 'draft' ),
				'featured_image_id' => array( 'type' => 'integer', 'minimum' => 0 ),
				'fields'            => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'additionalProperties' => false,
		);
	}


	private static function media_get_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ),
			),
			'additionalProperties' => false,
		);
	}

	private static function media_upload_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'source_url'  => array( 'type' => 'string', 'description' => 'Public HTTPS URL of the image to import. Use either source_url or data_base64.' ),
				'data_base64' => array( 'type' => 'string', 'description' => 'Base64 image bytes or an image data URL. Use either data_base64 or source_url.' ),
				'filename'    => array( 'type' => 'string', 'description' => 'Required for base64 data; optional override for URL imports.' ),
				'title'       => array( 'type' => 'string' ),
				'alt_text'    => array( 'type' => 'string' ),
				'caption'     => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'parent_id'   => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
			),
			'additionalProperties' => false,
		);
	}

	private static function media_update_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'                => array( 'type' => 'integer', 'minimum' => 1 ),
				'title'             => array( 'type' => 'string' ),
				'alt_text'          => array( 'type' => 'string' ),
				'caption'           => array( 'type' => 'string' ),
				'description'       => array( 'type' => 'string' ),
				'expected_revision' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	private static function media_output_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'id', 'filename', 'mime_type', 'title', 'alt_text', 'caption', 'description', 'source_url', 'filesize', 'width', 'height', 'sizes', 'modified_gmt', 'revision' ),
			'properties' => array(
				'id'          => array( 'type' => 'integer', 'minimum' => 1 ),
				'filename'    => array( 'type' => 'string' ),
				'mime_type'   => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'alt_text'    => array( 'type' => 'string' ),
				'caption'     => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'source_url'  => array( 'type' => 'string' ),
				'filesize'    => array( 'type' => 'integer', 'minimum' => 0 ),
				'width'       => array( 'type' => 'integer', 'minimum' => 0 ),
				'height'      => array( 'type' => 'integer', 'minimum' => 0 ),
				'sizes'       => array( 'type' => 'object', 'additionalProperties' => true ),
				'modified_gmt'=> array( 'type' => 'string' ),
				'revision'    => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}
	private static function list_types_output_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'types' ),
			'properties' => array(
				'bridge_version' => array( 'type' => 'string' ),
				'types' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'post_type', 'label', 'registered', 'fields', 'field_definitions' ),
						'properties' => array(
							'post_type' => array( 'type' => 'string' ),
							'label'     => array( 'type' => 'string' ),
							'registered'=> array( 'type' => 'boolean' ),
							'fields'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
							'field_definitions' => array(
								'type' => 'array',
								'items' => array(
									'type' => 'object',
									'required' => array( 'name', 'detected_type', 'source', 'storage_format', 'input_format' ),
									'properties' => array(
										'name' => array( 'type' => 'string' ),
										'detected_type' => array( 'type' => 'string' ),
										'source' => array( 'type' => 'string' ),
										'storage_format' => array( 'type' => 'string' ),
										'input_format' => array( 'type' => 'string' ),
									),
									'additionalProperties' => false,
								),
							),
						),
						'additionalProperties' => false,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	private static function list_items_output_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'items', 'total', 'pages' ),
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'id', 'title', 'slug', 'status', 'modified_gmt', 'url' ),
						'properties' => array(
							'id'           => array( 'type' => 'integer' ),
							'title'        => array( 'type' => 'string' ),
							'slug'         => array( 'type' => 'string' ),
							'status'       => array( 'type' => 'string' ),
							'modified_gmt' => array( 'type' => 'string' ),
							'url'          => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'total' => array( 'type' => 'integer', 'minimum' => 0 ),
				'pages' => array( 'type' => 'integer', 'minimum' => 0 ),
			),
			'additionalProperties' => false,
		);
	}

	private static function item_output_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'id', 'post_type', 'title', 'content', 'excerpt', 'slug', 'status', 'modified_gmt', 'revision', 'featured_image_id', 'url', 'fields', 'field_display' ),
			'properties' => array(
				'id'                => array( 'type' => 'integer' ),
				'post_type'         => array( 'type' => 'string' ),
				'title'             => array( 'type' => 'string' ),
				'content'           => array( 'type' => 'string' ),
				'excerpt'           => array( 'type' => 'string' ),
				'slug'              => array( 'type' => 'string' ),
				'status'            => array( 'type' => 'string' ),
				'modified_gmt'      => array( 'type' => 'string' ),
				'revision'          => array( 'type' => 'string' ),
				'featured_image_id' => array( 'type' => 'integer', 'minimum' => 0 ),
				'url'               => array( 'type' => 'string' ),
				'fields'            => array( 'type' => 'object', 'additionalProperties' => true ),
				'field_display'     => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'additionalProperties' => false,
		);
	}

	public static function permission_read( $input = null ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( ! is_array( $input ) || empty( $input['post_type'] ) ) {
			return current_user_can( 'edit_posts' );
		}
		if ( ! empty( $input['id'] ) ) {
			$post = self::resolve_post( $input['post_type'], $input['id'] );
			return is_wp_error( $post ) ? $post : true;
		}
		$post_type = self::validate_type( $input['post_type'] );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}
		$obj = get_post_type_object( $post_type );
		return $obj && current_user_can( $obj->cap->edit_posts );
	}

	public static function permission_edit( $input = null ) {
		if ( ! is_array( $input ) || empty( $input['post_type'] ) || empty( $input['id'] ) ) {
			return current_user_can( 'edit_posts' );
		}
		$post = self::resolve_post( $input['post_type'], $input['id'], true );
		return is_wp_error( $post ) ? $post : true;
	}

	public static function permission_create( $input = null ) {
		if ( ! is_array( $input ) || empty( $input['post_type'] ) ) {
			return current_user_can( 'edit_posts' );
		}
		$post_type = self::validate_type( $input['post_type'] );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}
		$obj = get_post_type_object( $post_type );
		if ( ! $obj || ! current_user_can( $obj->cap->create_posts ) ) {
			return false;
		}
		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'draft';
		if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $obj->cap->publish_posts ) ) {
			return false;
		}
		return true;
	}


	public static function permission_media_read( $input = null ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}
		if ( ! is_array( $input ) || empty( $input['id'] ) ) {
			return true;
		}
		$attachment = get_post( absint( $input['id'] ) );
		if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment->ID ) ) {
			return new WP_Error( 'calico_media_not_found', __( 'No matching image attachment was found.', 'calico-jetengine-mpc' ) );
		}
		return current_user_can( 'read_post', $attachment->ID );
	}

	public static function permission_media_edit( $input = null ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}
		if ( ! is_array( $input ) || empty( $input['id'] ) ) {
			return true;
		}
		$attachment = get_post( absint( $input['id'] ) );
		if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment->ID ) ) {
			return new WP_Error( 'calico_media_not_found', __( 'No matching image attachment was found.', 'calico-jetengine-mpc' ) );
		}
		return current_user_can( 'edit_post', $attachment->ID );
	}

	public static function permission_media_upload() {
		return current_user_can( 'upload_files' );
	}
	private static function allowed_types() {
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) get_option( self::OPT_TYPES, array() ) ) ) ) );
	}

	private static function allowed_fields( $post_type ) {
		$all = (array) get_option( self::OPT_FIELDS, array() );
		return isset( $all[ $post_type ] ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $all[ $post_type ] ) ) ) ) : array();
	}

	private static function field_format_options() {
		return array(
			'auto'               => __( 'Auto-detect', 'calico-jetengine-mpc' ),
			'text'               => __( 'Text / unchanged', 'calico-jetengine-mpc' ),
			'date_timestamp_utc' => __( 'Date -> UTC timestamp', 'calico-jetengine-mpc' ),
			'date_iso'           => __( 'Date -> YYYY-MM-DD', 'calico-jetengine-mpc' ),
			'datetime_timestamp' => __( 'Date and time -> timestamp', 'calico-jetengine-mpc' ),
		);
	}

	private static function scanned_field( $post_type, $key ) {
		$scan = (array) get_option( self::OPT_SCAN, array() );
		if ( isset( $scan['types'][ $post_type ]['fields'][ $key ] ) ) {
			return (array) $scan['types'][ $post_type ]['fields'][ $key ];
		}
		return array();
	}

	private static function field_format( $post_type, $key ) {
		$formats = (array) get_option( self::OPT_FORMATS, array() );
		$value   = isset( $formats[ $post_type ][ $key ] ) ? sanitize_key( $formats[ $post_type ][ $key ] ) : 'auto';
		$options = self::field_format_options();
		if ( isset( $options[ $value ] ) && 'auto' !== $value ) {
			return $value;
		}
		$field     = self::scanned_field( $post_type, $key );
		$suggested = isset( $field['suggested_format'] ) ? sanitize_key( $field['suggested_format'] ) : 'auto';
		return isset( $options[ $suggested ] ) ? $suggested : 'auto';
	}

	private static function field_input_format_description( $format ) {
		switch ( $format ) {
			case 'date_timestamp_utc':
				return 'Send YYYY-MM-DD (recommended) or UTC-midnight Unix seconds. Stored as Unix seconds; field_display returns YYYY-MM-DD.';
			case 'date_iso':
				return 'Send YYYY-MM-DD (recommended) or Unix seconds. Stored and displayed as YYYY-MM-DD.';
			case 'datetime_timestamp':
				return 'Send an ISO 8601 date-time with an offset (recommended) or Unix seconds. Stored as Unix seconds; field_display returns ISO 8601 in the WordPress timezone.';
			case 'text':
				return 'Send text. The value is not converted as a date.';
			default:
				return 'Send the field value in its existing WordPress post-meta format.';
		}
	}

	private static function field_definitions( $post_type ) {
		$definitions = array();
		foreach ( self::allowed_fields( $post_type ) as $key ) {
			$field  = self::scanned_field( $post_type, $key );
			$format = self::field_format( $post_type, $key );
			$input_description = self::field_input_format_description( $format );
			if ( substr( $key, -8 ) === '__config' ) { $input_description = 'For JetEngine Advanced Date: send the complete JSON schedule here. Native JetEngine regenerates and verifies start/end rows; do not write timestamps separately.'; }
			elseif ( in_array( $key . '__config', self::allowed_fields( $post_type ), true ) ) { $input_description = 'If this is JetEngine Advanced Date, write its complete ' . $key . '__config schedule instead. This raw value is derived; verify its time, not only field_display.'; }
			$definitions[] = array(
				'name'           => $key,
				'detected_type'  => isset( $field['type'] ) ? (string) $field['type'] : 'mixed',
				'source'         => isset( $field['source'] ) ? (string) $field['source'] : 'approved',
				'storage_format' => $format,
				'input_format'   => $input_description,
			);
		}
		return $definitions;
	}

	private static function display_field_value( $post_type, $key, $value ) {
		$format = self::field_format( $post_type, $key );
		if ( 'date_timestamp_utc' === $format && self::is_plausible_timestamp( $value ) ) {
			return gmdate( 'Y-m-d', (int) $value );
		}
		if ( 'datetime_timestamp' === $format && self::is_plausible_timestamp( $value ) ) {
			return wp_date( DATE_ATOM, (int) $value, wp_timezone() );
		}
		return $value;
	}
	private static function validate_type( $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( ! in_array( $post_type, self::allowed_types(), true ) ) {
			return new WP_Error( 'calico_cpt_not_approved', __( 'This custom post type has not been approved in the Calico bridge.', 'calico-jetengine-mpc' ) );
		}
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'calico_cpt_not_registered', __( 'The approved custom post type is not registered in this request.', 'calico-jetengine-mpc' ) );
		}
		return $post_type;
	}

	private static function resolve_post( $post_type, $id, $edit = false ) {
		$post_type = self::validate_type( $post_type );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}
		$post = get_post( absint( $id ) );
		if ( ! $post || $post->post_type !== $post_type ) {
			return new WP_Error( 'calico_item_not_found', __( 'No matching item was found.', 'calico-jetengine-mpc' ) );
		}
		if ( ! current_user_can( $edit ? 'edit_post' : 'read_post', $post->ID ) ) {
			return new WP_Error( 'calico_item_forbidden', __( 'The current user cannot access this item.', 'calico-jetengine-mpc' ) );
		}
		return $post;
	}

	public static function execute_list_types() {
		$types = array();
		foreach ( self::allowed_types() as $slug ) {
			$obj = get_post_type_object( $slug );
			$types[] = array(
				'post_type' => $slug,
				'label'     => $obj ? $obj->label : $slug,
				'registered'=> (bool) $obj,
				'fields'    => self::allowed_fields( $slug ),
				'field_definitions' => self::field_definitions( $slug ),
			);
		}
		return array( 'bridge_version' => self::VERSION, 'types' => $types );
	}

	public static function execute_list_items( $input ) {
		$post_type = self::validate_type( $input['post_type'] );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}
		$obj = get_post_type_object( $post_type );
		if ( ! $obj || ! current_user_can( $obj->cap->edit_posts ) ) {
			return new WP_Error( 'calico_type_forbidden', __( 'The current user cannot list this content type.', 'calico-jetengine-mpc' ) );
		}
		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'any';
		if ( 'any' !== $status ) {
			$status_obj = get_post_status_object( $status );
			if ( ! $status_obj || $status_obj->internal || in_array( $status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
				return new WP_Error( 'calico_invalid_status', __( 'The requested post status cannot be listed.', 'calico-jetengine-mpc' ) );
			}
		}
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $status,
				'perm'           => 'readable',
				's'              => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
				'posts_per_page' => isset( $input['per_page'] ) ? min( 100, max( 1, absint( $input['per_page'] ) ) ) : 20,
				'paged'          => isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'id'           => $post->ID,
				'title'        => get_the_title( $post ),
				'slug'         => $post->post_name,
				'status'       => $post->post_status,
				'modified_gmt' => $post->post_modified_gmt,
				'url'          => (string) get_permalink( $post ),
			);
		}
		return array( 'items' => $items, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages );
	}

	public static function execute_get_item( $input ) {
		$post = self::resolve_post( $input['post_type'], $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$fields = array();
		$field_display = array();
		foreach ( self::allowed_fields( $post->post_type ) as $key ) {
			$fields[ $key ] = get_post_meta( $post->ID, $key, true );
			$field_display[ $key ] = self::display_field_value( $post->post_type, $key, $fields[ $key ] );
		}
		return array(
			'id'                => $post->ID,
			'post_type'         => $post->post_type,
			'title'             => $post->post_title,
			'content'           => $post->post_content,
			'excerpt'           => $post->post_excerpt,
			'slug'              => $post->post_name,
			'status'            => $post->post_status,
			'modified_gmt'      => $post->post_modified_gmt,
			'revision'          => self::item_revision( $post, $fields ),
			'featured_image_id' => (int) get_post_thumbnail_id( $post->ID ),
			'url'               => (string) get_permalink( $post ),
			'fields'            => $fields,
			'field_display'     => $field_display,
		);
	}


	public static function execute_get_media( $input ) {
		$attachment = get_post( absint( $input['id'] ) );
		if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment->ID ) ) {
			return new WP_Error( 'calico_media_not_found', __( 'No matching image attachment was found.', 'calico-jetengine-mpc' ) );
		}
		if ( ! current_user_can( 'read_post', $attachment->ID ) ) {
			return new WP_Error( 'calico_media_forbidden', __( 'The current user cannot read this image attachment.', 'calico-jetengine-mpc' ) );
		}
		return self::media_payload( $attachment );
	}

	public static function execute_update_media( $input ) {
		$attachment = get_post( absint( $input['id'] ) );
		if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment->ID ) ) {
			return new WP_Error( 'calico_media_not_found', __( 'No matching image attachment was found.', 'calico-jetengine-mpc' ) );
		}
		if ( ! current_user_can( 'edit_post', $attachment->ID ) || ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'calico_media_edit_forbidden', __( 'The current user cannot edit this image attachment.', 'calico-jetengine-mpc' ) );
		}
		if ( ! empty( $input['expected_revision'] ) && ! hash_equals( self::media_revision( $attachment ), sanitize_text_field( $input['expected_revision'] ) ) ) {
			return new WP_Error( 'calico_media_edit_conflict', __( 'The image metadata changed after it was read. Read it again before updating.', 'calico-jetengine-mpc' ) );
		}
		$editable = array( 'title', 'alt_text', 'caption', 'description' );
		$has_edit = false;
		foreach ( $editable as $name ) {
			if ( array_key_exists( $name, $input ) ) {
				$has_edit = true;
				break;
			}
		}
		if ( ! $has_edit ) {
			return new WP_Error( 'calico_media_edit_required', __( 'Provide at least one image metadata value to update.', 'calico-jetengine-mpc' ) );
		}
		$update = array( 'ID' => $attachment->ID );
		if ( array_key_exists( 'title', $input ) ) {
			$update['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( array_key_exists( 'caption', $input ) ) {
			$update['post_excerpt'] = wp_kses_post( $input['caption'] );
		}
		if ( array_key_exists( 'description', $input ) ) {
			$update['post_content'] = wp_kses_post( $input['description'] );
		}
		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( array_key_exists( 'alt_text', $input ) ) {
			update_post_meta( $attachment->ID, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		}
		return self::media_payload( get_post( $attachment->ID ) );
	}

	public static function execute_upload_media( $input ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'calico_media_upload_forbidden', __( 'The current user cannot upload files.', 'calico-jetengine-mpc' ) );
		}
		$has_url  = ! empty( $input['source_url'] );
		$has_data = ! empty( $input['data_base64'] );
		if ( $has_url === $has_data ) {
			return new WP_Error( 'calico_media_source_required', __( 'Provide exactly one image source: source_url or data_base64.', 'calico-jetengine-mpc' ) );
		}
		$parent_id = isset( $input['parent_id'] ) ? absint( $input['parent_id'] ) : 0;
		if ( $parent_id && ( ! get_post( $parent_id ) || ! current_user_can( 'edit_post', $parent_id ) ) ) {
			return new WP_Error( 'calico_media_parent_forbidden', __( 'The current user cannot attach media to the requested parent item.', 'calico-jetengine-mpc' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename = isset( $input['filename'] ) ? sanitize_file_name( $input['filename'] ) : '';
		$tmp_name = '';
		if ( $has_url ) {
			$source_url = esc_url_raw( $input['source_url'] );
			if ( 'https' !== wp_parse_url( $source_url, PHP_URL_SCHEME ) || ! wp_http_validate_url( $source_url ) ) {
				return new WP_Error( 'calico_media_invalid_url', __( 'The image source must be a valid public HTTPS URL.', 'calico-jetengine-mpc' ) );
			}
			if ( ! $filename ) {
				$filename = sanitize_file_name( wp_basename( (string) wp_parse_url( $source_url, PHP_URL_PATH ) ) );
			}
			if ( ! $filename ) {
				$filename = 'calico-image';
			}
			$tmp_name = wp_tempnam( $filename );
			if ( ! $tmp_name ) {
				return new WP_Error( 'calico_media_temp_failed', __( 'WordPress could not create a temporary upload file.', 'calico-jetengine-mpc' ) );
			}
			$response = wp_safe_remote_get(
				$source_url,
				array(
					'timeout'             => 20,
					'redirection'         => 3,
					'stream'              => true,
					'filename'            => $tmp_name,
					'limit_response_size' => self::MAX_IMAGE_BYTES + 1,
					'headers'             => array( 'Accept' => 'image/*' ),
				)
			);
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				@unlink( $tmp_name );
				return is_wp_error( $response ) ? $response : new WP_Error( 'calico_media_download_failed', __( 'The remote image could not be downloaded.', 'calico-jetengine-mpc' ) );
			}
		} else {
			if ( ! $filename ) {
				return new WP_Error( 'calico_media_filename_required', __( 'A filename with an image extension is required for base64 data.', 'calico-jetengine-mpc' ) );
			}
			$encoded = trim( (string) $input['data_base64'] );
			if ( preg_match( '#^data:image/[a-z0-9.+-]+;base64,#i', $encoded, $matches ) ) {
				$encoded = substr( $encoded, strlen( $matches[0] ) );
			}
			$encoded = preg_replace( '/\s+/', '', $encoded );
			if ( strlen( $encoded ) > (int) ceil( self::MAX_IMAGE_BYTES * 4 / 3 ) + 4 ) {
				return new WP_Error( 'calico_media_too_large', __( 'The image exceeds the 10 MB upload limit.', 'calico-jetengine-mpc' ) );
			}
			$bytes = base64_decode( $encoded, true );
			if ( false === $bytes || '' === $bytes ) {
				return new WP_Error( 'calico_media_invalid_base64', __( 'The supplied image data is not valid base64.', 'calico-jetengine-mpc' ) );
			}
			$tmp_name = wp_tempnam( $filename );
			if ( ! $tmp_name || false === file_put_contents( $tmp_name, $bytes ) ) {
				if ( $tmp_name ) {
					@unlink( $tmp_name );
				}
				return new WP_Error( 'calico_media_temp_failed', __( 'WordPress could not write the temporary upload file.', 'calico-jetengine-mpc' ) );
			}
			unset( $bytes );
		}

		$filesize = file_exists( $tmp_name ) ? (int) filesize( $tmp_name ) : 0;
		if ( ! $filesize || $filesize > self::MAX_IMAGE_BYTES ) {
			@unlink( $tmp_name );
			return new WP_Error( 'calico_media_too_large', __( 'The image is empty or exceeds the 10 MB upload limit.', 'calico-jetengine-mpc' ) );
		}
		$filetype = wp_check_filetype_and_ext( $tmp_name, $filename );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : '';
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ), true ) ) {
			@unlink( $tmp_name );
			return new WP_Error( 'calico_media_invalid_type', __( 'Only JPEG, PNG, GIF, WebP, and AVIF images are supported.', 'calico-jetengine-mpc' ) );
		}
		if ( ! empty( $filetype['proper_filename'] ) ) {
			$filename = sanitize_file_name( $filetype['proper_filename'] );
		}
		$file = array( 'name' => $filename, 'tmp_name' => $tmp_name );
		$post_data = array(
			'post_excerpt' => isset( $input['caption'] ) ? wp_kses_post( $input['caption'] ) : '',
			'post_content' => isset( $input['description'] ) ? wp_kses_post( $input['description'] ) : '',
		);
		$title = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
		$attachment_id = media_handle_sideload( $file, $parent_id, $title, $post_data );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_name );
			return $attachment_id;
		}
		if ( array_key_exists( 'alt_text', $input ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		}
		return self::media_payload( get_post( $attachment_id ) );
	}

	private static function media_revision( $attachment ) {
		return hash(
			'sha256',
			wp_json_encode(
				array(
					'id'           => $attachment->ID,
					'title'        => $attachment->post_title,
					'alt_text'     => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
					'caption'      => $attachment->post_excerpt,
					'description'  => $attachment->post_content,
					'modified_gmt' => $attachment->post_modified_gmt,
				)
			)
		);
	}

	private static function media_payload( $attachment ) {
		$metadata = wp_get_attachment_metadata( $attachment->ID );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$sizes    = array();
		foreach ( get_intermediate_image_sizes() as $size_name ) {
			$image = wp_get_attachment_image_src( $attachment->ID, $size_name );
			if ( $image ) {
				$sizes[ $size_name ] = array( 'url' => (string) $image[0], 'width' => (int) $image[1], 'height' => (int) $image[2] );
			}
		}
		$file = get_attached_file( $attachment->ID );
		return array(
			'id'          => (int) $attachment->ID,
			'filename'    => $file ? wp_basename( $file ) : '',
			'mime_type'   => (string) get_post_mime_type( $attachment ),
			'title'       => (string) $attachment->post_title,
			'alt_text'    => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'caption'     => (string) $attachment->post_excerpt,
			'description' => (string) $attachment->post_content,
			'source_url'  => (string) wp_get_attachment_url( $attachment->ID ),
			'filesize'    => $file && file_exists( $file ) ? (int) filesize( $file ) : 0,
			'width'       => isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
			'height'      => isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
			'sizes'       => $sizes,
			'modified_gmt'=> (string) $attachment->post_modified_gmt,
			'revision'    => self::media_revision( $attachment ),
		);
	}
	private static function item_revision( $post, $fields ) {
		return hash(
			'sha256',
			wp_json_encode(
				array(
					'id'                => $post->ID,
					'title'             => $post->post_title,
					'content'           => $post->post_content,
					'excerpt'           => $post->post_excerpt,
					'slug'              => $post->post_name,
					'status'            => $post->post_status,
					'modified_gmt'      => $post->post_modified_gmt,
					'featured_image_id' => (int) get_post_thumbnail_id( $post->ID ),
					'fields'            => $fields,
				)
			)
		);
	}

	public static function execute_create_item( $input ) {
		$post_type = self::validate_type( $input['post_type'] );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}
		$obj = get_post_type_object( $post_type );
		if ( ! $obj || ! current_user_can( $obj->cap->create_posts ) ) {
			return new WP_Error( 'calico_type_forbidden', __( 'The current user cannot create this content type.', 'calico-jetengine-mpc' ) );
		}
		$title = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
		if ( '' === trim( $title ) ) {
			return new WP_Error( 'calico_title_required', __( 'A non-empty title is required.', 'calico-jetengine-mpc' ) );
		}
		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'draft';
		if ( ! in_array( $status, array( 'draft', 'pending', 'private', 'publish' ), true ) ) {
			return new WP_Error( 'calico_invalid_status', __( 'The requested post status is not supported.', 'calico-jetengine-mpc' ) );
		}
		if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $obj->cap->publish_posts ) ) {
			return new WP_Error( 'calico_cannot_publish', __( 'The current user cannot publish or privatize this content type.', 'calico-jetengine-mpc' ) );
		}
		$image_id = 0;
		if ( array_key_exists( 'featured_image_id', $input ) ) {
			$image_id = absint( $input['featured_image_id'] );
			if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
				return new WP_Error( 'calico_invalid_image', __( 'The featured image ID is not an image attachment.', 'calico-jetengine-mpc' ) );
			}
			if ( $image_id && ! current_user_can( 'read_post', $image_id ) ) {
				return new WP_Error( 'calico_image_forbidden', __( 'The current user cannot access the featured image attachment.', 'calico-jetengine-mpc' ) );
			}
		}
		$requested_fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
		$allowed          = self::allowed_fields( $post_type );
		$field_keys       = array();
		foreach ( $requested_fields as $raw_key => $value ) {
			$key = sanitize_key( $raw_key );
			if ( ! in_array( $key, $allowed, true ) ) {
				return new WP_Error( 'calico_field_not_approved', sprintf( __( 'The field "%s" is not approved for this post type.', 'calico-jetengine-mpc' ), $key ) );
			}
			$field_keys[ $raw_key ] = $key;
		}
		$plans = Calico_JMCB_Advanced_Dates::prepare( $requested_fields, 0, $allowed );
		if ( is_wp_error( $plans ) ) { return $plans; }
		$requested_fields = Calico_JMCB_Advanced_Dates::strip_derived( $requested_fields, $plans );
		$insert = array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => isset( $input['content'] ) ? wp_kses_post( $input['content'] ) : '',
			'post_excerpt' => isset( $input['excerpt'] ) ? wp_kses_post( $input['excerpt'] ) : '',
		);
		if ( array_key_exists( 'slug', $input ) ) {
			$insert['post_name'] = sanitize_title( $input['slug'] );
		}
		$post_id = wp_insert_post( wp_slash( $insert ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$prepared_fields = array();
		foreach ( $requested_fields as $raw_key => $value ) {
			$key = $field_keys[ $raw_key ];
			if ( ! current_user_can( 'edit_post_meta', $post_id, $key ) ) {
				return self::rollback_created_item( $post_id, new WP_Error( 'calico_field_forbidden', sprintf( __( 'The current user cannot edit the field "%s".', 'calico-jetengine-mpc' ), $key ) ) );
			}
			$prepared_fields[ $key ] = null === $value ? null : self::sanitize_field_value( $post_type, $key, $value );
			if ( is_wp_error( $prepared_fields[ $key ] ) ) {
				return self::rollback_created_item( $post_id, $prepared_fields[ $key ] );
			}
		}
		foreach ( $plans as $base => $plan ) {
			foreach ( array( $base, $base . '__end_date', $base . '__config' ) as $key ) {
				if ( ! current_user_can( 'edit_post_meta', $post_id, $key ) ) { return self::rollback_created_item( $post_id, new WP_Error( 'calico_field_forbidden', 'Advanced Date field access denied.' ) ); }
			}
		}
		if ( $image_id && false === set_post_thumbnail( $post_id, $image_id ) && $image_id !== (int) get_post_thumbnail_id( $post_id ) ) {
			return self::rollback_created_item( $post_id, new WP_Error( 'calico_image_update_failed', __( 'The featured image could not be assigned.', 'calico-jetengine-mpc' ) ) );
		}
		foreach ( $prepared_fields as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			if ( false === update_post_meta( $post_id, $key, $value ) && get_post_meta( $post_id, $key, true ) !== $value ) {
				return self::rollback_created_item( $post_id, new WP_Error( 'calico_field_update_failed', sprintf( __( 'The field "%s" could not be saved.', 'calico-jetengine-mpc' ), $key ) ) );
			}
		}
		$schedule_result = Calico_JMCB_Advanced_Dates::save( $post_id, $plans );
		if ( is_wp_error( $schedule_result ) ) { return self::rollback_created_item( $post_id, $schedule_result ); }
		if ( 'draft' !== $status ) {
			$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ), true );
			if ( is_wp_error( $result ) ) {
				return self::rollback_created_item( $post_id, $result );
			}
		}
		$check = Calico_JMCB_Advanced_Dates::verify_all( $post_id, $allowed );
		if ( is_wp_error( $check ) ) { return self::rollback_created_item( $post_id, $check ); }
		return self::execute_get_item( array( 'post_type' => $post_type, 'id' => $post_id ) );
	}

	private static function rollback_created_item( $post_id, $error ) {
		if ( ! wp_delete_post( $post_id, true ) ) {
			$error->add_data( array( 'created_item_id' => $post_id, 'rollback_failed' => true ) );
		}
		return $error;
	}

	public static function execute_update_item( $input ) {
		$post = self::resolve_post( $input['post_type'], $input['id'], true );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$current_fields = array();
		foreach ( self::allowed_fields( $post->post_type ) as $key ) {
			$current_fields[ $key ] = get_post_meta( $post->ID, $key, true );
		}
		if ( ! empty( $input['expected_revision'] ) && ! hash_equals( self::item_revision( $post, $current_fields ), sanitize_text_field( $input['expected_revision'] ) ) ) {
			return new WP_Error( 'calico_edit_conflict', __( 'The item changed after it was read. Read it again before updating.', 'calico-jetengine-mpc' ) );
		}
		if ( empty( $input['expected_revision'] ) && ! empty( $input['expected_modified'] ) && $post->post_modified_gmt !== sanitize_text_field( $input['expected_modified'] ) ) {
			return new WP_Error( 'calico_edit_conflict', __( 'The item changed after it was read. Read it again before updating.', 'calico-jetengine-mpc' ) );
		}
		$requested_fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
		$allowed = self::allowed_fields( $post->post_type );
		$plans = Calico_JMCB_Advanced_Dates::prepare( $requested_fields, $post->ID, $allowed );
		if ( is_wp_error( $plans ) ) { return $plans; }
		$input['fields'] = Calico_JMCB_Advanced_Dates::strip_derived( $requested_fields, $plans );
		$prepared_fields = array();
		if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) {
			$allowed = self::allowed_fields( $post->post_type );
			foreach ( $input['fields'] as $raw_key => $value ) {
				$key = sanitize_key( $raw_key );
				if ( ! in_array( $key, $allowed, true ) ) {
					return new WP_Error( 'calico_field_not_approved', sprintf( __( 'The field "%s" is not approved for this post type.', 'calico-jetengine-mpc' ), $key ) );
				}
				if ( ! current_user_can( 'edit_post_meta', $post->ID, $key ) ) {
					return new WP_Error( 'calico_field_forbidden', sprintf( __( 'The current user cannot edit the field "%s".', 'calico-jetengine-mpc' ), $key ) );
				}
				$prepared_fields[ $key ] = null === $value ? null : self::sanitize_field_value( $post->post_type, $key, $value );
				if ( is_wp_error( $prepared_fields[ $key ] ) ) {
					return $prepared_fields[ $key ];
				}
			}
		}
		if ( array_key_exists( 'featured_image_id', $input ) ) {
			$image_id = absint( $input['featured_image_id'] );
			if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
				return new WP_Error( 'calico_invalid_image', __( 'The featured image ID is not an image attachment.', 'calico-jetengine-mpc' ) );
			}
			if ( $image_id && ! current_user_can( 'read_post', $image_id ) ) {
				return new WP_Error( 'calico_image_forbidden', __( 'The current user cannot access the featured image attachment.', 'calico-jetengine-mpc' ) );
			}
		}
		$update = array( 'ID' => $post->ID );
		if ( array_key_exists( 'title', $input ) )   { $update['post_title'] = sanitize_text_field( $input['title'] ); }
		if ( array_key_exists( 'content', $input ) ) { $update['post_content'] = wp_kses_post( $input['content'] ); }
		if ( array_key_exists( 'excerpt', $input ) ) { $update['post_excerpt'] = wp_kses_post( $input['excerpt'] ); }
		if ( array_key_exists( 'slug', $input ) )    { $update['post_name'] = sanitize_title( $input['slug'] ); }
		if ( array_key_exists( 'status', $input ) ) {
			$obj    = get_post_type_object( $post->post_type );
			$status = sanitize_key( $input['status'] );
			if ( in_array( $status, array( 'publish', 'private' ), true ) && ( ! $obj || ! current_user_can( $obj->cap->publish_posts ) ) ) {
				return new WP_Error( 'calico_cannot_publish', __( 'The current user cannot publish or privatize this content type.', 'calico-jetengine-mpc' ) );
			}
			$update['post_status'] = $status;
		}
		$snapshot = Calico_JMCB_Advanced_Dates::snapshot( $post->ID, $prepared_fields, $plans );
		if ( array_key_exists( 'featured_image_id', $input ) ) {
			$image_id = absint( $input['featured_image_id'] );
			$current  = (int) get_post_thumbnail_id( $post->ID );
			if ( $image_id !== $current ) {
				$result = $image_id ? set_post_thumbnail( $post->ID, $image_id ) : delete_post_thumbnail( $post->ID );
				if ( false === $result && $image_id !== (int) get_post_thumbnail_id( $post->ID ) ) {
					return new WP_Error( 'calico_image_update_failed', __( 'The featured image could not be updated.', 'calico-jetengine-mpc' ) );
				}
			}
		}
		foreach ( $prepared_fields as $key => $value ) {
			if ( null === $value ) {
				if ( metadata_exists( 'post', $post->ID, $key ) && ! delete_post_meta( $post->ID, $key ) ) {
					return new WP_Error( 'calico_field_update_failed', sprintf( __( 'The field "%s" could not be deleted.', 'calico-jetengine-mpc' ), $key ) );
				}
				continue;
			}
			if ( get_post_meta( $post->ID, $key, true ) !== $value && false === update_post_meta( $post->ID, $key, $value ) && get_post_meta( $post->ID, $key, true ) !== $value ) {
				return new WP_Error( 'calico_field_update_failed', sprintf( __( 'The field "%s" could not be updated.', 'calico-jetengine-mpc' ), $key ) );
			}
		}
		$schedule_result = Calico_JMCB_Advanced_Dates::save( $post->ID, $plans );
		if ( ! is_wp_error( $schedule_result ) ) { $schedule_result = Calico_JMCB_Advanced_Dates::verify_all( $post->ID, $allowed ); }
		if ( is_wp_error( $schedule_result ) ) {
			Calico_JMCB_Advanced_Dates::restore( $post->ID, $snapshot, $plans );
			return $schedule_result;
		}
		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				Calico_JMCB_Advanced_Dates::restore( $post->ID, $snapshot, $plans );
				return $result;
			}
		}
		$check = Calico_JMCB_Advanced_Dates::verify_all( $post->ID, $allowed );
		if ( is_wp_error( $check ) ) {
			Calico_JMCB_Advanced_Dates::restore( $post->ID, $snapshot, $plans );
			wp_update_post( array( 'ID' => $post->ID, 'post_status' => $post->post_status ) );
			return $check;
		}
		return self::execute_get_item( array( 'post_type' => $post->post_type, 'id' => $post->ID ) );
	}

	private static function is_plausible_timestamp( $value ) {
		if ( is_bool( $value ) || ! is_numeric( $value ) || (string) (int) $value !== trim( (string) $value ) ) {
			return false;
		}
		$timestamp = (int) $value;
		return $timestamp >= 946684800 && $timestamp <= 4133980800;
	}

	private static function strict_ymd( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : false;
	}

	private static function normalize_formatted_field( $key, $format, $value ) {
		if ( is_array( $value ) || is_object( $value ) || is_bool( $value ) ) {
			return new WP_Error( 'calico_invalid_field_format', sprintf( __( 'The field "%s" requires a scalar date or text value.', 'calico-jetengine-mpc' ), $key ) );
		}
		if ( 'date_timestamp_utc' === $format ) {
			if ( self::is_plausible_timestamp( $value ) ) {
				return (string) (int) $value;
			}
			$date = self::strict_ymd( trim( (string) $value ) );
			if ( $date ) {
				return (string) $date->getTimestamp();
			}
			return new WP_Error( 'calico_invalid_date', sprintf( __( 'The field "%s" requires YYYY-MM-DD or a plausible Unix timestamp.', 'calico-jetengine-mpc' ), $key ) );
		}
		if ( 'date_iso' === $format ) {
			if ( self::is_plausible_timestamp( $value ) ) {
				return gmdate( 'Y-m-d', (int) $value );
			}
			$date = self::strict_ymd( trim( (string) $value ) );
			return $date ? $date->format( 'Y-m-d' ) : new WP_Error( 'calico_invalid_date', sprintf( __( 'The field "%s" requires YYYY-MM-DD or a plausible Unix timestamp.', 'calico-jetengine-mpc' ), $key ) );
		}
		if ( 'datetime_timestamp' === $format ) {
			if ( self::is_plausible_timestamp( $value ) ) {
				return (string) (int) $value;
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/', trim( (string) $value ) ) ) {
				return new WP_Error( 'calico_invalid_datetime', sprintf( __( 'The field "%s" requires an ISO 8601 date-time or a plausible Unix timestamp.', 'calico-jetengine-mpc' ), $key ) );
			}
			try {
				$date = new DateTimeImmutable( trim( (string) $value ), wp_timezone() );
			} catch ( Exception $exception ) {
				return new WP_Error( 'calico_invalid_datetime', sprintf( __( 'The field "%s" requires an ISO 8601 date-time or a plausible Unix timestamp.', 'calico-jetengine-mpc' ), $key ) );
			}
			return self::is_plausible_timestamp( $date->getTimestamp() ) ? (string) $date->getTimestamp() : new WP_Error( 'calico_invalid_datetime', sprintf( __( 'The field "%s" date-time is outside the supported range.', 'calico-jetengine-mpc' ), $key ) );
		}
		return wp_kses_post( (string) $value );
	}

	private static function sanitize_field_value( $post_type, $key, $value ) {
		$format = self::field_format( $post_type, $key );
		if ( in_array( $format, array( 'text', 'date_timestamp_utc', 'date_iso', 'datetime_timestamp' ), true ) ) {
			return self::normalize_formatted_field( $key, $format, $value );
		}
		$registered = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', $post_type ) : array();
		if ( isset( $registered[ $key ] ) ) {
			return sanitize_meta( $key, $value, 'post', $post_type );
		}
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $child_key => $child_value ) {
				$sanitized[ $child_key ] = self::sanitize_field_value( $post_type, $key, $child_value );
			}
			return $sanitized;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		return wp_kses_post( (string) $value );
	}

	public static function scan_site() {
		$results = array();
		$objects = get_post_types( array( '_builtin' => false ), 'objects' );
		foreach ( $objects as $slug => $obj ) {
			if ( ! $obj->show_ui && ! $obj->public && ! $obj->show_in_rest ) {
				continue;
			}
			$results[ $slug ] = array(
				'label'              => $obj->label,
				'public'             => (bool) $obj->public,
				'show_ui'            => (bool) $obj->show_ui,
				'show_in_rest'       => (bool) $obj->show_in_rest,
				'publicly_queryable' => (bool) $obj->publicly_queryable,
				'count'              => self::count_items( $slug ),
				'fields'             => self::discover_fields( $slug ),
			);
		}
		ksort( $results );
		return array( 'scanned_at' => time(), 'types' => $results );
	}

	private static function count_items( $post_type ) {
		$counts = wp_count_posts( $post_type );
		$total  = 0;
		foreach ( (array) $counts as $status => $count ) {
			if ( ! in_array( $status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
				$total += (int) $count;
			}
		}
		return $total;
	}

	private static function discover_fields( $post_type ) {
		global $wpdb;
		$fields     = array();
		$registered = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', $post_type ) : array();
		foreach ( $registered as $key => $args ) {
			if ( self::field_is_safe_to_offer( $key ) ) {
				$type = ! empty( $args['type'] ) ? $args['type'] : 'mixed';
				$fields[ $key ] = array( 'type' => $type, 'source' => 'registered', 'suggested_format' => self::suggest_field_format( $key, $type, null ) );
			}
		}
		$sql = $wpdb->prepare(
			"SELECT pm.meta_key, MIN(NULLIF(pm.meta_value, '')) AS sample_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s GROUP BY pm.meta_key ORDER BY pm.meta_key ASC LIMIT 500",
			$post_type
		);
		$rows = $wpdb->get_results( $sql );
		foreach ( (array) $rows as $row ) {
			$key = (string) $row->meta_key;
			if ( ! self::field_is_safe_to_offer( $key ) ) {
				continue;
			}
			$type      = self::infer_type( $row->sample_value );
			$suggested = self::suggest_field_format( $key, $type, $row->sample_value );
			if ( isset( $fields[ $key ] ) ) {
				if ( 'auto' !== $suggested ) {
					$fields[ $key ]['suggested_format'] = $suggested;
				}
				continue;
			}
			$fields[ $key ] = array( 'type' => $type, 'source' => 'stored', 'suggested_format' => $suggested );
		}
		ksort( $fields );
		return $fields;
	}

	private static function field_is_safe_to_offer( $key ) {
		$key = (string) $key;
		if ( '' === $key || '_' === substr( $key, 0, 1 ) ) {
			return false;
		}
		$blocked = array( 'elementor', 'wp_', 'rank_math_', 'yoast_' );
		foreach ( $blocked as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return false;
			}
		}
		return true;
	}

	private static function infer_type( $value ) {
		$value = maybe_unserialize( $value );
		if ( is_array( $value ) ) { return 'array'; }
		if ( is_bool( $value ) )  { return 'boolean'; }
		if ( is_numeric( $value ) ) { return false !== strpos( (string) $value, '.' ) ? 'number' : 'integer'; }
		return 'string';
	}
	private static function suggest_field_format( $key, $type, $sample ) {
		$name = strtolower( (string) $key );
		if ( false !== strpos( $name, 'datetime' ) ) {
			return self::is_plausible_timestamp( $sample ) || 'integer' === $type ? 'datetime_timestamp' : 'auto';
		}
		if ( false === strpos( $name, 'date' ) ) {
			return 'auto';
		}
		if ( self::is_plausible_timestamp( $sample ) || 'integer' === $type ) {
			return 'date_timestamp_utc';
		}
		if ( self::strict_ymd( is_scalar( $sample ) ? trim( (string) $sample ) : '' ) ) {
			return 'date_iso';
		}
		return 'auto';
	}


	public static function dependency_notice() {
		if ( function_exists( 'wp_register_ability' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error"><p>
			<?php esc_html_e( 'Calico JetEngine MPC CPT Bridge requires WordPress 6.9 or newer because the Abilities API is unavailable.', 'calico-jetengine-mpc' ); ?>
		</p></div>
		<?php
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'tools_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script(
			'calico-jetengine-mpc-admin',
			plugin_dir_url( __FILE__ ) . 'assets/admin.js',
			array(),
			self::VERSION,
			true
		);
	}
	public static function admin_menu() {
		add_management_page(
			__( 'Calico JetEngine MPC CPT Bridge', 'calico-jetengine-mpc' ),
			__( 'Calico CPT Bridge', 'calico-jetengine-mpc' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to scan content types.', 'calico-jetengine-mpc' ) );
		}
		check_admin_referer( 'calico_jmcb_scan' );
		update_option( self::OPT_SCAN, self::scan_site(), false );
		wp_safe_redirect( add_query_arg( 'scanned', '1', admin_url( 'tools.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to approve content types.', 'calico-jetengine-mpc' ) );
		}
		check_admin_referer( 'calico_jmcb_save' );
		$scan      = (array) get_option( self::OPT_SCAN, array() );
		$detected  = isset( $scan['types'] ) ? array_keys( (array) $scan['types'] ) : array();
		$requested = isset( $_POST['types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['types'] ) ) : array();
		$types     = array_values( array_intersect( $requested, $detected ) );
		$fields    = array();
		$formats        = array();
		$format_options = self::field_format_options();
		$posted_formats = isset( $_POST['formats'] ) ? (array) wp_unslash( $_POST['formats'] ) : array();
		$posted_fields = isset( $_POST['fields'] ) ? (array) wp_unslash( $_POST['fields'] ) : array();
		foreach ( $types as $post_type ) {
			$known = isset( $scan['types'][ $post_type ]['fields'] ) ? array_keys( (array) $scan['types'][ $post_type ]['fields'] ) : array();
			$asked = isset( $posted_fields[ $post_type ] ) ? array_map( 'sanitize_key', (array) $posted_fields[ $post_type ] ) : array();
			$fields[ $post_type ] = array_values( array_intersect( $asked, $known ) );
			$formats[ $post_type ] = array();
			foreach ( $fields[ $post_type ] as $key ) {
				$format = isset( $posted_formats[ $post_type ][ $key ] ) ? sanitize_key( $posted_formats[ $post_type ][ $key ] ) : 'auto';
				if ( ! isset( $format_options[ $format ] ) ) {
					$format = 'auto';
				}
				$formats[ $post_type ][ $key ] = $format;
			}
		}
		update_option( self::OPT_TYPES, $types, false );
		update_option( self::OPT_FIELDS, $fields, false );
		update_option( self::OPT_FORMATS, $formats, false );
		wp_safe_redirect( add_query_arg( 'saved', '1', admin_url( 'tools.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$scan    = (array) get_option( self::OPT_SCAN, array() );
		$types   = isset( $scan['types'] ) ? (array) $scan['types'] : array();
		$allowed = self::allowed_types();
		$saved_formats  = (array) get_option( self::OPT_FORMATS, array() );
		$format_options = self::field_format_options();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Calico JetEngine MPC CPT Bridge', 'calico-jetengine-mpc' ); ?></h1>
			<p><?php esc_html_e( 'Scan the site, review detected custom post types and fields, then explicitly approve only what MCP clients may access.', 'calico-jetengine-mpc' ); ?></p>
			<?php if ( isset( $_GET['scanned'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Scan completed.', 'calico-jetengine-mpc' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Approved abilities updated.', 'calico-jetengine-mpc' ); ?></p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0">
				<input type="hidden" name="action" value="calico_jmcb_scan">
				<?php wp_nonce_field( 'calico_jmcb_scan' ); ?>
				<?php submit_button( __( 'Scan Post Types and Fields', 'calico-jetengine-mpc' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="calico_jmcb_save">
				<?php wp_nonce_field( 'calico_jmcb_save' ); ?>
				<p><label><input type="checkbox" id="calico-jmcb-select-all-types"> <?php esc_html_e( 'Select all detected post types', 'calico-jetengine-mpc' ); ?></label></p>
				<?php foreach ( $types as $slug => $data ) :
					$enabled = in_array( $slug, $allowed, true );
					$chosen  = self::allowed_fields( $slug );
				?>
				<div class="postbox calico-jmcb-type-card" style="padding:16px;margin-bottom:14px">
					<h2 style="margin-top:0"><label><input type="checkbox" class="calico-jmcb-type" name="types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $enabled ); ?>> <?php echo esc_html( $data['label'] ); ?> <code><?php echo esc_html( $slug ); ?></code></label></h2>
					<p><?php echo esc_html( sprintf( '%d items · Public: %s · REST: %s · Admin UI: %s', (int) $data['count'], $data['public'] ? 'yes' : 'no', $data['show_in_rest'] ? 'yes' : 'no', $data['show_ui'] ? 'yes' : 'no' ) ); ?></p>
					<?php if ( empty( $data['fields'] ) ) : ?>
						<p><em><?php esc_html_e( 'No eligible post-meta fields were detected. Core title/content/image editing can still be approved.', 'calico-jetengine-mpc' ); ?></em></p>
					<?php else : ?>
						<p><strong><?php esc_html_e( 'Approved custom fields', 'calico-jetengine-mpc' ); ?></strong> &nbsp; <label><input type="checkbox" class="calico-jmcb-select-all-fields"> <?php esc_html_e( 'Select all custom fields', 'calico-jetengine-mpc' ); ?></label></p>
						<p><small><?php esc_html_e( 'Choose how each field is stored. Auto uses the scan suggestion; date fields accept readable ISO input and are normalized before saving.', 'calico-jetengine-mpc' ); ?></small></p>
						<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:10px">
						<?php foreach ( $data['fields'] as $key => $field ) :
							$selected_format = isset( $saved_formats[ $slug ][ $key ] ) ? sanitize_key( $saved_formats[ $slug ][ $key ] ) : 'auto';
							$suggested       = isset( $field['suggested_format'] ) ? sanitize_key( $field['suggested_format'] ) : 'auto';
						?>
							<div style="padding:8px;border:1px solid #dcdcde;border-radius:3px">
								<label><input type="checkbox" class="calico-jmcb-field" name="fields[<?php echo esc_attr( $slug ); ?>][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $chosen, true ) ); ?>> <code><?php echo esc_html( $key ); ?></code> <small>(<?php echo esc_html( $field['type'] ); ?>)</small></label>
								<select name="formats[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $key ); ?>]" style="display:block;margin:7px 0 0 24px;max-width:calc(100% - 24px)">
								<?php foreach ( $format_options as $format_key => $format_label ) :
									$label = $format_label;
									if ( 'auto' === $format_key && 'auto' !== $suggested && isset( $format_options[ $suggested ] ) ) {
										$label .= ' — ' . sprintf( __( 'suggests %s', 'calico-jetengine-mpc' ), $format_options[ $suggested ] );
									}
								?>
									<option value="<?php echo esc_attr( $format_key ); ?>" <?php selected( $selected_format, $format_key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
								</select>
							</div>
						<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
				<?php if ( empty( $types ) ) : ?><p><?php esc_html_e( 'No scan results yet. Run the scanner first.', 'calico-jetengine-mpc' ); ?></p><?php endif; ?>
				<?php submit_button( __( 'Save Approved MCP Abilities', 'calico-jetengine-mpc' ) ); ?>
			</form>
			<p><strong><?php esc_html_e( 'Security note:', 'calico-jetengine-mpc' ); ?></strong> <?php esc_html_e( 'Nothing is exposed until an administrator checks a post type and saves. Hidden underscore-prefixed and common system/plugin fields are excluded from the approval list.', 'calico-jetengine-mpc' ); ?></p>
			<p><?php esc_html_e( 'Storage note: this bridge supports fields stored as WordPress post metadata. JetEngine custom database tables and relations require a dedicated integration.', 'calico-jetengine-mpc' ); ?></p>
		</div>
		<?php
	}
}

register_activation_hook( __FILE__, array( 'Calico_JetEngine_MPC_CPT_Bridge', 'activate' ) );
Calico_JetEngine_MPC_CPT_Bridge::init();
