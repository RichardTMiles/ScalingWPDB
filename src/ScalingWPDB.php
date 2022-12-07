<?php

namespace ScalingWPDB;

use mysqli;
use mysqli_result;
use WP_Error;
use wpdb;

class ScalingWPDB extends Overrides {



    /**
     * Check the responsiveness of a TCP/IP daemon
     *
     * @since 1.0.0
     *
     * @param string $host Host.
     * @param int    $port Port or socket.
     * @param float  $float_timeout Timeout as float number.
     * @return bool true when $host:$post responds within $float_timeout seconds, else false
     */
    public function check_tcp_responsiveness( $host, $port, $float_timeout ) {

        // Get the cache key
        $cache_key = $this->tcp_get_cache_key( $host, $port );

        // Persistent cached value exists
        $cached_value = $this->tcp_cache_get( $cache_key );

        // Confirmed up or down response
        if ( 'up' === $cached_value ) {
            $this->tcp_responsive = true;

            return true;
        } elseif ( 'down' === $cached_value ) {
            $this->tcp_responsive = false;

            return false;
        }

        // Defaults
        $errno  = 0;
        $errstr = '';

        // Try to get a new socket
        $socket = ( WP_DEBUG )
            ? fsockopen( $host, $port, $errno, $errstr, $float_timeout )
            : @fsockopen( $host, $port, $errno, $errstr, $float_timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        // No socket
        if ( false === $socket ) {
            $this->tcp_cache_set( $cache_key, 'down' );

            return "[ > {$float_timeout} ] ({$errno}) '{$errstr}'";
        }

        // Close the socket
        fclose( $socket );

        // Using API
        $this->tcp_cache_set( $cache_key, 'up' );

        return true;
    }

    /**
     * Run lag cache callbacks and return current lag
     *
     * @since 2.1.0
     *
     * @return int
     */
    public function get_lag_cache() {
        $this->lag = $this->run_callbacks( 'get_lag_cache' );

        return $this->check_lag();
    }

    /**
     * Should we try to ping the MySQL host?
     *
     * @since 4.1.0
     *
     * @param string $dbhname Database name.
     *
     * @return bool
     */
    public function should_mysql_ping( $dbhname = '' ) {

        // Bail early if no MySQL ping
        if ( empty( $this->check_dbh_heartbeats ) ) {
            return false;
        }

        // Shouldn't happen
        if ( empty( $dbhname ) || empty( $this->dbhname_heartbeats[ $dbhname ] ) ) {
            return true;
        }

        // MySQL server has gone away
        if ( ! empty( $this->dbhname_heartbeats[ $dbhname ]['last_errno'] ) && ( DB_SERVER_GONE_ERROR === $this->dbhname_heartbeats[ $dbhname ]['last_errno'] ) ) {
            unset( $this->dbhname_heartbeats[ $dbhname ]['last_errno'] );

            return true;
        }

        // More than 0.1 seconds of inactivity on that dbhname
        if ( microtime( true ) - $this->dbhname_heartbeats[ $dbhname ]['last_used'] > $this->recheck_timeout ) {
            return true;
        }

        return false;
    }

    /**
     * Run lag callbacks and return current lag
     *
     * @since 2.1.0
     *
     * @return int
     */
    public function get_lag() {
        $this->lag = $this->run_callbacks( 'get_lag' );

        return $this->check_lag();
    }

    /**
     * Check lag
     *
     * @since 2.1.0
     *
     * @return int
     */
    public function check_lag() {
        if ( false === $this->lag ) {
            return DB_LAG_UNKNOWN;
        }

        if ( $this->lag > $this->lag_threshold ) {
            return DB_LAG_BEHIND;
        }

        return DB_LAG_OK;
    }


    /**
     * Given a string, a character set and a table, ask the DB to check the string encoding.
     * Classes that extend wpdb can override this function without needing to copy/paste
     * all of wpdb::strip_invalid_text().
     *
     * NOTE: This must be called after LudicrousDB::db_connect, so that wpdb::dbh is set correctly
     *
     * @since 1.0.0
     *
     * @param string $string String to convert
     * @param string $charset Character set to test against (uses MySQL character set names)
     *
     * @return mixed The converted string, or a WP_Error if the conversion fails
     */
    protected function strip_invalid_text_using_db( $string, $charset ) {
        $query  = $this->prepare( "SELECT CONVERT( %s USING {$charset} )", $string );
        $result = $this->_do_query( $query, $this->dbh );

        // Bail with error if no result
        if ( empty( $result ) ) {
            return new WP_Error( 'wpdb_convert_text_failure' );
        }

        // Fetch row
        $row = ( true === $this->use_mysqli )
            ? mysqli_fetch_row( $result )
            : mysql_fetch_row( $result );

        // Bail with error if no rows
        if ( ! is_array( $row ) || count( $row ) < 1 ) {
            return new WP_Error( 'wpdb_convert_text_failure' );
        }

        return $row[0];
    }

    /** TCP Cache *************************************************************/

    /**
     * Get the cache key used for TCP responses
     *
     * @since 3.0.0
     *
     * @param string $host Host
     * @param string $port Port or socket.
     *
     * @return string
     */
    protected function tcp_get_cache_key( $host, $port ) {
        return "{$host}:{$port}";
    }

    /**
     * Get the number of seconds TCP response is good for
     *
     * @since 3.0.0
     *
     * @return int
     */
    protected function tcp_get_cache_expiration() {
        return 10;
    }

    /**
     * Check if TCP is using a persistent cache or not.
     *
     * @since 5.1.0
     *
     * @return bool True if yes. False if no.
     */
    protected function tcp_is_cache_persistent() {

        // Check if using external object cache
        if ( wp_using_ext_object_cache() ) {

            // Make sure the global group is added
            $this->add_global_group();

            // Yes
            return true;
        }

        // No
        return false;
    }

    /**
     * Get cached up/down value of previous TCP response.
     *
     * Falls back to local cache if persistent cache is not available.
     *
     * @since 3.0.0
     *
     * @param string $key Results of tcp_get_cache_key()
     *
     * @return mixed Results of wp_cache_get()
     */
    protected function tcp_cache_get( $key = '' ) {

        // Bail for invalid key
        if ( empty( $key ) ) {
            return false;
        }

        // Get from persistent cache
        if ( $this->tcp_is_cache_persistent() ) {
            return wp_cache_get( $key, $this->cache_group );

            // Fallback to local cache
        } elseif ( ! empty( $this->tcp_cache[ $key ] ) ) {

            // Not expired
            if ( ! empty( $this->tcp_cache[ $key ]['expiration'] ) && ( time() < $this->tcp_cache[ $key ]['expiration'] ) ) {

                // Return value or false if empty
                return ! empty( $this->tcp_cache[ $key ]['value'] )
                    ? $this->tcp_cache[ $key ]['value']
                    : false;

                // Expired, so delete and proceed
            } else {
                $this->tcp_cache_delete( $key );
            }
        }

        return false;
    }

    /**
     * Set cached up/down value of current TCP response.
     *
     * Falls back to local cache if persistent cache is not available.
     *
     * @since 3.0.0
     *
     * @param string $key Results of tcp_get_cache_key()
     * @param string $value "up" or "down" based on TCP response
     *
     * @return bool Results of wp_cache_set() or true
     */
    protected function tcp_cache_set( $key = '', $value = '' ) {

        // Bail if invalid values were passed
        if ( empty( $key ) || empty( $value ) ) {
            return false;
        }

        // Get expiration
        $expires = $this->tcp_get_cache_expiration();

        // Add to persistent cache
        if ( $this->tcp_is_cache_persistent() ) {
            return wp_cache_set( $key, $value, $this->cache_group, $expires );

            // Fallback to local cache
        } else {
            $this->tcp_cache[ $key ] = array(
                'value'      => $value,
                'expiration' => time() + $expires
            );
        }

        return true;
    }

    /**
     * Delete cached up/down value of current TCP response.
     *
     * Falls back to local cache if persistent cache is not available.
     *
     * @since 5.1.0
     *
     * @param string $key Results of tcp_get_cache_key()
     *
     * @return bool Results of wp_cache_delete() or true
     */
    protected function tcp_cache_delete( $key = '' ) {

        // Bail if invalid key
        if ( empty( $key ) ) {
            return false;
        }

        // Delete from persistent cache
        if ( $this->tcp_is_cache_persistent() ) {
            return wp_cache_delete( $key, $this->cache_group );

            // Fallback to local cache
        } else {
            unset( $this->tcp_cache[ $key ] );
        }

        return true;
    }

    /**
     * Add global cache group.
     *
     * Only run once, as that is all that is required.
     *
     * @since 4.3.0
     */
    protected function add_global_group() {
        static $added = null;

        // Bail if added or caching not available yet
        if ( true === $added ) {
            return;
        }

        // Add the cache group
        if ( function_exists( 'wp_cache_add_global_groups' ) ) {
            wp_cache_add_global_groups( $this->cache_group );
        }

        // Set added
        $added = true;
    }











}