<?php

/**
 * Handle data for the current customers session.
 * Implements the WC_Session abstract class
 *
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

			$this->_data = $this->get_session_data();

			// Update session if its close to expiring
			if ( !empty( $this->_data ) && time() > $this->_session_expiring ) {
				$this->set_session_expiration();
				$this->update_expiration( $this->_customer_id, $this->_session_expiration );
			}

		} else {
			$this->set_session_expiration();
			$this->_customer_id = $this->generate_customer_id();
			$this->_data = array();
		}


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
		//@todo: Caching?

		$val = $wpdb->get_var( $wpdb->prepare( "SELECT data FROM $this->_table WHERE customer_id=%s LIMIT 1", $this->_customer_id ) );
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
			$now = time();
			$wpdb->query( $wpdb->prepare( "DELETE FROM $this->_table WHERE expiration < %d", $now ) );
		}
	}

	public function destroy_all_sessions() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE $this->_table" );
		return true;
	}

	public function update_expiration( $customer_id, $expiration ) {
		global $wpdb;
		error_log( 'Updating expiration date:' );
		$wpdb->update( $this->_table, array( 'expiration' => (int) $expiration ), compact( 'customer_id' ) );
	}

}
