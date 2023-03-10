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
    public function check_tcp_responsiveness( string $host, int $port, float $float_timeout ): bool|string
    {

        // Get the cache key
        $cache_key = $this->tcp_get_cache_key( $host, $port );

        // Persistent cached value exists
        $cached_value = $this->tcp_cache_get( $cache_key );

        // Confirmed up or down response
        if ('up' === $cached_value) {
            $this->tcp_responsive = true;

            return true;
        }

        if ('down' === $cached_value) {
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

    public function db_connect( $query = '' ) {
        return true;
    }

    public function justInTimeConnect(string $query = '') {

        // Bail if empty query
        if ( empty( $query ) ) {
            return false;
        }

        // can be empty/false if the query is e.g. "COMMIT"
        $this->table = $this->get_table_from_query( $query );
        if ( empty( $this->table ) ) {
            $this->table = 'no-table';
        }
        $this->last_table = $this->table;

        // Use current table with no callback results
        if ( isset( $this->ludicrous_tables[ $this->table ] ) ) {
            $dataset               = $this->ludicrous_tables[ $this->table ];
            $this->callback_result = null;

            // Run callbacks and either extract or update dataset
        } else {

            // Run callbacks and get result
            $this->callback_result = $this->run_callbacks( 'dataset', $query );

            // Set if not null
            if ( ! is_null( $this->callback_result ) ) {
                if ( is_array( $this->callback_result ) ) {
                    extract( $this->callback_result, EXTR_OVERWRITE );
                } else {
                    $dataset = $this->callback_result;
                }
            }
        }

        if ( ! isset( $dataset ) ) {
            $dataset = 'global';
        }

        if ( empty( $dataset ) ) {
            return $this->bail( "Unable to determine which dataset to query. ({$this->table})" );
        } else {
            $this->dataset = $dataset;
        }

        $this->run_callbacks( 'dataset_found', $dataset );

        if ( empty( $this->ludicrous_servers ) ) {
            if ( $this->dbh_type_check( $this->dbh ) ) {
                return $this->dbh;
            }

            if ( ! defined( 'DB_HOST' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) ) {
                return $this->bail( 'We were unable to query because there was no database defined.' );
            }

            // Fallback to wpdb db_connect method.

            $this->dbuser     = DB_USER;
            $this->dbpassword = DB_PASSWORD;
            $this->dbname     = DB_NAME;
            $this->dbhost     = DB_HOST;

            parent::db_connect();

            return $this->dbh;
        }

        global $use_master;

        // Determine whether the query must be sent to the master (a writable server)
        if ( ! empty( $use_master ) || ( $this->srtm === true ) || isset( $this->srtm[ $this->table ] ) ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
            $use_master = true;
        } elseif ( $this->is_write_query( $query ) ) {
            $use_master = true;
            if ( is_array( $this->srtm ) ) {
                $this->srtm[ $this->table ] = true;
            }

            // Detect queries that have a join in the srtm array.
        } elseif ( ! isset( $use_master ) && is_array( $this->srtm ) && ! empty( $this->srtm ) ) {
            $use_master  = false;
            $query_match = substr( $query, 0, 1000 );

            foreach ( $this->srtm as $key => $value ) {
                if ( false !== stripos( $query_match, $key ) ) {
                    $use_master = true;
                    break;
                }
            }
        } else {
            $use_master = false;
        }

        if ( ! empty( $use_master ) ) {
            $this->dbhname = $dbhname = $dataset . '__w';
            $operation     = 'write';
        } else {
            $this->dbhname = $dbhname = $dataset . '__r';
            $operation     = 'read';
        }

        // Try to reuse an existing connection
        while ( isset( $this->dbhs[ $dbhname ] ) && $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {

            // Find the connection for incrementing counters
            foreach ( array_keys( $this->db_connections ) as $i ) {
                if ( $this->db_connections[ $i ]['dbhname'] == $dbhname ) {
                    $conn = &$this->db_connections[ $i ];
                }
            }

            if ( isset( $server['name'] ) ) {
                $name = $server['name'];

                // A callback has specified a database name so it's possible the
                // existing connection selected a different one.
                if ( $name != $this->used_servers[ $dbhname ]['name'] ) {
                    if ( ! $this->select( $name, $this->dbhs[ $dbhname ] ) ) {

                        // This can happen when the user varies and lacks
                        // permission on the $name database
                        if ( isset( $conn['disconnect (select failed)'] ) ) {
                            ++ $conn['disconnect (select failed)'];
                        } else {
                            $conn['disconnect (select failed)'] = 1;
                        }

                        $this->disconnect( $dbhname );
                        break;
                    }
                    $this->used_servers[ $dbhname ]['name'] = $name;
                }
            } else {
                $name = $this->used_servers[ $dbhname ]['name'];
            }

            $this->current_host = $this->dbh2host[ $dbhname ];

            // Keep this connection at the top of the stack to prevent disconnecting frequently-used connections
            if ( $k = array_search( $dbhname, $this->open_connections, true ) ) {
                unset( $this->open_connections[ $k ] );
                $this->open_connections[] = $dbhname;
            }

            $this->last_used_server = $this->used_servers[ $dbhname ];
            $this->last_connection  = compact( 'dbhname', 'name' );

            if ( $this->should_mysql_ping( $dbhname ) && ! $this->check_connection( false, $this->dbhs[ $dbhname ] ) ) {
                if ( isset( $conn['disconnect (ping failed)'] ) ) {
                    ++ $conn['disconnect (ping failed)'];
                } else {
                    $conn['disconnect (ping failed)'] = 1;
                }

                $this->disconnect( $dbhname );
                break;
            }

            if ( isset( $conn['queries'] ) ) {
                ++ $conn['queries'];
            } else {
                $conn['queries'] = 1;
            }

            return $this->dbhs[ $dbhname ];
        }

        if ( ! empty( $use_master ) && defined( 'MASTER_DB_DEAD' ) ) {
            return $this->bail( 'We are updating the database. Please try back in 5 minutes. If you are posting to your blog please hit the refresh button on your browser in a few minutes to post the data again. It will be posted as soon as the database is back online.' );
        }

        if ( empty( $this->ludicrous_servers[ $dataset ][ $operation ] ) ) {
            return $this->bail( "No databases available with {$this->table} ({$dataset})" );
        }

        // Put the groups in order by priority
        ksort( $this->ludicrous_servers[ $dataset ][ $operation ] );

        // Make a list of at least $this->reconnect_retries connections to try, repeating as necessary.
        $servers = array();
        do {
            foreach ( $this->ludicrous_servers[ $dataset ][ $operation ] as $group => $items ) {
                $keys = array_keys( $items );
                shuffle( $keys );

                foreach ( $keys as $key ) {
                    $servers[] = compact( 'group', 'key' );
                }
            }

            if ( ! $tries_remaining = count( $servers ) ) {
                return $this->bail( "No database servers were found to match the query. ({$this->table}, {$dataset})" );
            }

            if ( is_null( $this->unique_servers ) ) {
                $this->unique_servers = $tries_remaining;
            }
        } while ( $tries_remaining < $this->reconnect_retries );

        // Connect to a database server
        do {
            $unique_lagged_slaves = array();
            $success              = false;

            foreach ( $servers as $group_key ) {
                -- $tries_remaining;

                // If all servers are lagged, we need to start ignoring the lag and retry
                if ( count( $unique_lagged_slaves ) == $this->unique_servers ) {
                    break;
                }

                // $group, $key
                $group = $group_key['group'];
                $key   = $group_key['key'];

                // $host, $user, $password, $name, $read, $write [, $lag_threshold, $timeout ]
                $db_config     = $this->ludicrous_servers[ $dataset ][ $operation ][ $group ][ $key ];
                $host          = $db_config['host'];
                $user          = $db_config['user'];
                $password      = $db_config['password'];
                $name          = $db_config['name'];
                $write         = $db_config['write'];
                $read          = $db_config['read'];
                $timeout       = $db_config['timeout'];
                $port          = $db_config['port'];
                $lag_threshold = $db_config['lag_threshold'];

                // Split host:port into $host and $port
                if ( strpos( $host, ':' ) ) {
                    list( $host, $port ) = explode( ':', $host );
                }

                // Overlay $server if it was extracted from a callback
                if ( isset( $server ) && is_array( $server ) ) {
                    extract( $server, EXTR_OVERWRITE );
                }

                // Split again in case $server had host:port
                if ( strpos( $host, ':' ) ) {
                    list( $host, $port ) = explode( ':', $host );
                }

                // Make sure there's always a port number
                if ( empty( $port ) ) {
                    $port = 3306;
                }

                // Use a default timeout of 200ms
                if ( ! isset( $timeout ) ) {
                    $timeout = 0.2;
                }

                // Get the minimum group here, in case $server rewrites it
                if ( ! isset( $min_group ) || ( $min_group > $group ) ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
                    $min_group = $group;
                }

                $host_and_port = "{$host}:{$port}";

                // Can be used by the lag callbacks
                $this->lag_cache_key = $host_and_port;
                $this->lag_threshold = isset( $lag_threshold )
                    ? $lag_threshold
                    : $this->default_lag_threshold;

                // Check for a lagged slave, if applicable
                if ( empty( $use_master ) && empty( $write ) && empty ( $this->ignore_slave_lag ) && isset( $this->lag_threshold ) && ! isset( $server['host'] ) && ( $lagged_status = $this->get_lag_cache() ) === DB_LAG_BEHIND ) {

                    // If it is the last lagged slave and it is with the best preference we will ignore its lag
                    if ( ! isset( $unique_lagged_slaves[ $host_and_port ] ) && $this->unique_servers == count( $unique_lagged_slaves ) + 1 && $group == $min_group ) {
                        $this->lag_threshold = null;
                    } else {
                        $unique_lagged_slaves[ $host_and_port ] = $this->lag;
                        continue;
                    }
                }

                $this->timer_start();

                // Maybe check TCP responsiveness
                $tcp = ! empty( $this->check_tcp_responsiveness )
                    ? $this->check_tcp_responsiveness( $host, $port, $timeout )
                    : null;

                // Connect if necessary or possible
                if ( ! empty( $use_master ) || empty( $tries_remaining ) || ( true === $tcp ) ) {
                    $this->single_db_connect( $dbhname, $host_and_port, $user, $password );
                } else {
                    $this->dbhs[ $dbhname ] = false;
                }

                $elapsed = $this->timer_stop();

                if ( $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {
                    /**
                     * If we care about lag, disconnect lagged slaves and try to find others.
                     * We don't disconnect if it is the last lagged slave and it is with the best preference.
                     */
                    if ( empty( $use_master )
                        && empty( $write )
                        && empty( $this->ignore_slave_lag )
                        && isset( $this->lag_threshold )
                        && ! isset( $server['host'] )
                        && ( $lagged_status !== DB_LAG_OK )
                        && ( $lagged_status = $this->get_lag() ) === DB_LAG_BEHIND && ! (
                            ! isset( $unique_lagged_slaves[ $host_and_port ] )
                            && ( $this->unique_servers == ( count( $unique_lagged_slaves ) + 1 ) )
                            && ( $group == $min_group )
                        )
                    ) {
                        $unique_lagged_slaves[ $host_and_port ] = $this->lag;
                        $this->disconnect( $dbhname );
                        $this->dbhs[ $dbhname ] = false;
                        $success                = false;
                        $msg                    = "Replication lag of {$this->lag}s on {$host_and_port} ({$dbhname})";
                        $this->print_error( $msg );
                        continue;
                    }

                    $this->set_sql_mode( array(), $this->dbhs[ $dbhname ] );
                    if ( $this->select( $name, $this->dbhs[ $dbhname ] ) ) {
                        $this->current_host         = $host_and_port;
                        $this->dbh2host[ $dbhname ] = $host_and_port;

                        // Define these to avoid undefined variable notices
                        $queries = isset( $queries   ) ? $queries : 1; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
                        $lag     = isset( $this->lag ) ? $this->lag : 0;

                        $this->last_connection    = compact( 'dbhname', 'host', 'port', 'user', 'name', 'tcp', 'elapsed', 'success', 'queries', 'lag' );
                        $this->db_connections[]   = $this->last_connection;
                        $this->open_connections[] = $dbhname;
                        $success                  = true;
                        break;
                    }
                }

                $success                = false;
                $this->last_connection  = compact( 'dbhname', 'host', 'port', 'user', 'name', 'tcp', 'elapsed', 'success' );
                $this->db_connections[] = $this->last_connection;

                if ( $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {
                    if ( true === $this->use_mysqli ) {
                        $error = mysqli_error( $this->dbhs[ $dbhname ] );
                        $errno = mysqli_errno( $this->dbhs[ $dbhname ] );
                    } else {
                        $error = mysql_error( $this->dbhs[ $dbhname ] );
                        $errno = mysql_errno( $this->dbhs[ $dbhname ] );
                    }
                }

                $msg  = date( 'Y-m-d H:i:s' ) . " Can't select {$dbhname} - \n"; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                $msg .= "'referrer' => '{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}',\n";
                $msg .= "'host' => {$host},\n";

                if ( ! empty( $error ) ) {
                    $msg .= "'error' => {$error},\n";
                }

                if ( ! empty( $errno ) ) {
                    $msg .= "'errno' => {$errno},\n";

                    // Maybe log the error to heartbeats
                    if ( ! empty( $this->check_dbh_heartbeats ) ) {
                        $this->dbhname_heartbeats[ $dbhname ]['last_errno'] = $errno;
                    }
                }

                $msg .= "'tcp_responsive' => " . ( $tcp === true
                        ? 'true'
                        : $tcp ) . ",\n";

                $msg .= "'lagged_status' => " . ( isset( $lagged_status )
                        ? $lagged_status
                        : DB_LAG_UNKNOWN );

                $this->print_error( $msg );
            }

            if ( empty( $success )
                || ! isset( $this->dbhs[ $dbhname ] )
                || ! $this->dbh_type_check( $this->dbhs[ $dbhname ] )
            ) {

                // Lagged slaves were not used. Ignore the lag for this connection attempt and retry.
                if ( empty( $this->ignore_slave_lag ) && count( $unique_lagged_slaves ) ) {
                    $this->ignore_slave_lag = true;
                    $tries_remaining        = count( $servers );
                    continue;
                }

                $this->run_callbacks( 'db_connection_error', array(
                    'host'      => $host,
                    'port'      => $port,
                    'operation' => $operation,
                    'table'     => $this->table,
                    'dataset'   => $dataset,
                    'dbhname'   => $dbhname,
                ) );

                return $this->bail( "Unable to connect to {$host}:{$port} to {$operation} table '{$this->table}' ({$dataset})" );
            }

            break;
        } while ( true );

        $this->set_charset( $this->dbhs[ $dbhname ] );

        $this->dbh                      = $this->dbhs[ $dbhname ]; // needed by $wpdb->_real_escape()
        $this->last_used_server         = compact( 'host', 'user', 'name', 'read', 'write' );
        $this->used_servers[ $dbhname ] = $this->last_used_server;

        while ( ( false === $this->persistent ) && count( $this->open_connections ) > $this->max_connections ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found
            $oldest_connection = array_shift( $this->open_connections );
            if ( $this->dbhs[ $oldest_connection ] != $this->dbhs[ $dbhname ] ) {
                $this->disconnect( $oldest_connection );
            }
        }

        return $this->dbhs[ $dbhname ];
    }


}