<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Handle data for the current customers session.
 * Implements the WC_Session abstract class
 *
 * Based on WooCommerce's WC_Session_Handler which is in-turn partly based on WP SESSION by Eric Mann.
 * Those implementations used the wp_options table to store sessions.
 * This relocates data storage to a dedicated table to avoid bloating and frequent invalidation of the WP options cache.
 *
 * @class 		DF_WC_Session_Handler
 * @version		1.1.1
 * @author 		Jeff Brand
 * @author 		WooThemes
 */
class DF_WC_Session_Handler extends WC_Session {

	/** cookie name */
	private $_cookie;

	/** session due to expire timestamp */
	private $_session_expiring;

	/** session expiration timestamp */
	private $_session_expiration;

	/** Bool based on whether a cookie exists **/
	private $_has_cookie = false;

	/** Table name for custom session records **/
	private $_table;

	/** Cache group **/
	private $_cachegroup = 'df_wc_sessions';

	/** Override use of cache API */
	private $_force_cache = null;

	//@todo: Deprecate this in the future based on https://github.com/woothemes/woocommerce/issues/6846
	public function set( $key, $value ) {
		if ( $value !== $this->get( $key ) ) {
			parent::set( $key, $value );
		}
	}

	/**
	 * Constructor for the session class.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		global $wpdb;
		$this->_table = $wpdb->prefix . 'df_wc_sessions';
		$this->_cookie = 'wp_woocommerce_session_' . COOKIEHASH;

		if ( $cookie = $this->get_session_cookie() ) {
			$this->_customer_id        = $cookie[0];
			$this->_session_expiration = $cookie[1];
			$this->_session_expiring   = $cookie[2];
			$this->_has_cookie         = true;

			// Update session if its close to expiring
			if ( time() > $this->_session_expiring ) {
				$this->set_session_expiration();
				$this->update_expiration( $this->_customer_id, $this->_session_expiration );
				// This doesn't update the cache's expiration.
			}

		} else {
			$this->set_session_expiration();
			$this->_customer_id = $this->generate_customer_id();
		}

		$this->_data = $this->get_session_data();

    	// Actions
    	add_action( 'woocommerce_set_cart_cookies', array( $this, 'set_customer_session_cookie' ), 10 );
    	add_action( 'woocommerce_cleanup_sessions', array( $this, 'cleanup_sessions' ), 10 );
    	add_action( 'shutdown', array( $this, 'save_data' ), 20 );
    	add_action( 'clear_auth_cookie', array( $this, 'destroy_session' ) );
    	if ( ! is_user_logged_in() ) {
    		add_action( 'woocommerce_thankyou', array( $this, 'destroy_session' ) );
    	}
    }

    /**
     * Sets the session cookie on-demand (usually after adding an item to the cart).
     *
     * Since the cookie name (as of 2.1) is prepended with wp, cache systems like batcache will not cache pages when set.
     *
     * Warning: Cookies will only be set if this is called before the headers are sent.
     */
    public function set_customer_session_cookie( $set ) {
    	if ( $set ) {
	    	// Set/renew our cookie
			$to_hash           = $this->_customer_id . $this->_session_expiration;
			$cookie_hash       = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
			$cookie_value      = $this->_customer_id . '||' . $this->_session_expiration . '||' . $this->_session_expiring . '||' . $cookie_hash;
			$this->_has_cookie = true;

	    	// Set the cookie
	    	wc_setcookie( $this->_cookie, $cookie_value, $this->_session_expiration, apply_filters( 'wc_session_use_secure_cookie', false ) );
	    }
    }

    /**
     * Return true if the current user has an active session, i.e. a cookie to retrieve values
     * @return boolean
     */
    public function has_session() {
    	return isset( $_COOKIE[ $this->_cookie ] ) || $this->_has_cookie || is_user_logged_in();
    }

    /**
     * set_session_expiration function.
     *
     * @access public
     * @return void
     */
    public function set_session_expiration() {
	    $this->_session_expiring    = time() + intval( apply_filters( 'wc_session_expiring', 60 * 60 * 47 ) ); // 47 Hours
		$this->_session_expiration  = time() + intval( apply_filters( 'wc_session_expiration', 60 * 60 * 48 ) ); // 48 Hours
    }

	/**
	 * Generate a unique customer ID for guests, or return user ID if logged in.
	 *
	 * Uses Portable PHP password hashing framework to generate a unique cryptographically strong ID.
	 *
	 * @access public
	 * @return int|string
	 */
	public function generate_customer_id() {
		if ( is_user_logged_in() ) {
			return get_current_user_id();
		} else {
			require_once( ABSPATH . 'wp-includes/class-phpass.php');
			$hasher = new PasswordHash( 8, false );
			return md5( $hasher->get_random_bytes( 32 ) );
		}
	}

	/**
	 * get_session_cookie function.
	 *
	 * @access public
	 * @return mixed
	 */
	public function get_session_cookie() {
		if ( empty( $_COOKIE[ $this->_cookie ] ) ) {
			return false;
		}

		list( $customer_id, $session_expiration, $session_expiring, $cookie_hash ) = explode( '||', $_COOKIE[ $this->_cookie ] );

		// Validate hash
		$to_hash = $customer_id . $session_expiration;
		$hash    = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );

		if ( $hash != $cookie_hash ) {
			return false;
		}

		return array( $customer_id, $session_expiration, $session_expiring, $cookie_hash );
	}


/////////////////////////////////////////////////////////////////////////

	/**
	 * get_session_data function.
	 *
	 * @access public
	 * @return array
	 */
	public function get_session_data() {
		global $wpdb;

		$val = $this->use_cache() ? wp_cache_get( $this->_customer_id, $this->_cachegroup ) : false;

		if ( ! $val ) {
			$val = $wpdb->get_var( $wpdb->prepare( "SELECT data FROM $this->_table WHERE customer_id=%s LIMIT 1", $this->_customer_id ) );
		}

		return $val ? (array) @unserialize( $val ) : array();
	}

	/**
	 * save_data function.
	 *
	 * @access public
	 * @return void
	 */
	public function save_data() {
		global $wpdb;

		// Dirty if something changed - prevents saving nothing new
		if ( $this->_dirty && $this->has_session() ) {
			$data = array(
				'customer_id' => $this->_customer_id,
				'expiration'  => $this->_session_expiration,
				'data'        => serialize( $this->_data )
			);

			if ( $this->use_cache() ) {
				wp_cache_delete( $this->_customer_id, $this->_cachegroup );
			}

			$wpdb->replace( $this->_table, $data );
		}
	}

	/**
	 * Destroy all session data
	 */
	public function destroy_session() {
		// Clear cookie
		wc_setcookie( $this->_cookie, '', time() - YEAR_IN_SECONDS, apply_filters( 'wc_session_use_secure_cookie', false ) );

		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM $this->_table WHERE customer_id=%s", $this->_customer_id ) );

		// Clear cart
		wc_empty_cart();

		// Clear cache
		if ( $this->use_cache() ) {
			wp_cache_delete( $this->_customer_id, $this->_cachegroup );
		}

		// Clear data
		$this->_data        = array();
		$this->_dirty       = false;
		$this->_customer_id = $this->generate_customer_id();
	}

	/**
	 * cleanup_sessions function.
	 *
	 * @access public
	 * @return void
	 */
	public function cleanup_sessions() {
		global $wpdb;

		if ( ! defined( 'WP_SETUP_CONFIG' ) && ! defined( 'WP_INSTALLING' ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $this->_table WHERE expiration < %d", time() ) );

			if ( $this->use_cache() ) {
				wp_cache_flush();
			}
		}
	}

	public function destroy_all_sessions() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE $this->_table" );

		if ( $this->use_cache() ) {
			wp_cache_flush();
		}

		return true;
	}

	public function update_expiration( $customer_id, $expiration ) {
		global $wpdb;
		$wpdb->update( $this->_table, array( 'expiration' => (int) $expiration ), compact( 'customer_id' ) );
	}

	/**
	 * Force use of cache
	 * null: use external cache when present (default)
	 * true: always use cache
	 * false: never use cache
	 */
	public function force_cache( $force ) {
		$this->_force_cache = $force;
	}

	private function use_cache() {
		return ( null !== $this->_force_cache ) ? $this->_force_cache : wp_using_ext_object_cache();
	}
}
