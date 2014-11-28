<?php
/*
Plugin Name: WooCommerce Table-Based Sessions
Description: Drop-in replacement to relocate WC Sessions from <code>wp_options</code> table to a dedicated table to improve option cache performance and simplify query code. Based on original WC_Session code from WooCommerce core.
Version: 1.0
Author: Jeff Brand
*/


DF_Session_Loader::setup();

class DF_Session_Loader {
	static $db_version_key = 'df_wc_session_table_version';
	static $db_version     = 1;
	static $table_name;

	static function setup() {
		global $wpdb;
		self::$table_name = $wpdb->prefix . 'df_wc_sessions';

		register_activation_hook( __FILE__,        array( __CLASS__, 'check_db' ) );
		add_action( 'admin_init',                  array( __CLASS__, 'check_db' ) );

		add_filter( 'woocommerce_session_handler', array( __CLASS__, 'session_handler' ) );
	}

	static function session_handler( $handler ) {
		require_once( dirname( __FILE__ ) . '/class-df-wc-session-handler.php' );
		return 'DF_WC_Session_Handler';
	}

	static function check_db() {
		if ( self::$db_version != get_option( self::$db_version_key ) ) {
			self::upgrade_db();
		}
	}

	static function upgrade_db() {
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		$sql = self::session_table_sql( self::$table_name );
		dbDelta( $sql );
		update_option( self::$db_version_key, self::$db_version );
	}

	static function session_table_sql( $table ) {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE $table (
			customer_id CHAR(32),
			expiration  INT(11),
			data        TEXT,
			PRIMARY KEY  (customer_id)
		) $charset_collate;";
	}

}