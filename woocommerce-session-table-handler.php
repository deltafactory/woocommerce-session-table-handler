<?php
/*
Plugin Name: WooCommerce Table-Based Sessions
Description: Drop-in replacement to relocate WC Sessions from <code>wp_options</code> table to a dedicated table. This should improve option cache performance and simplify query code. Based on original WC_Session code from WooCommerce core.
Version: 1.1
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
		add_action( 'admin_menu',                  array( __CLASS__, 'admin_menu' ), 20 );
	}

	static function session_handler( $handler ) {
		require_once( dirname( __FILE__ ) . '/class-df-wc-session-handler.php' );
		return 'DF_WC_Session_Handler';
	}

	// For use on admin since WooCommerce doesn't normally load sessions there.
	static function load_session_handler() {
		$wc_path = dirname( WC_PLUGIN_FILE );
		include_once( $wc_path . '/includes/abstracts/abstract-wc-session.php' );
		include_once( $wc_path . '/includes/class-wc-session-handler.php' );

		$session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
		$wc_session    = new $session_class();

		return $wc_session;
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

	static function admin_menu() {
		$loader = array( __CLASS__, 'admin_page_loader' );
		add_submenu_page( 'woocommerce', 'Table Sessions', 'Table Sessions', 'manage_woocommerce', 'df-session-table', $loader );
	}

	static function admin_page_loader() {
		global $plugin_page;

		$path = __DIR__ . '/admin/' . $plugin_page . '.php';
		if ( file_exists( $path ) ) {
			require( $path );
		}
	}

	static function count_sessions( $expired = false ) {
		global $wpdb;
		$table = self::$table_name;

		$sql = "SELECT SUM( customer_id REGEXP '^[0-9]+$' ) AS user, SUM(1) AS total FROM $table";
		if ( $expired ) {
			$sql .= $wpdb->escape( ' WHERE expiration >= %d', time() );
		}

		$count = array_merge( array( 'user' => 0, 'total' => 0 ), (array) $wpdb->get_row( $sql, ARRAY_A ) );
		$count['guest'] = $count['total'] - $count['user'];

		return array_map( 'intval', $count );
	}
}