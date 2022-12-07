<?php

namespace ScalingWPDB;

use mysqli;
use wpdb;

class Functions extends wpdb
{
    use Variables;

    /**
     * Internal function to perform the mysql_query() call
     *
     * @since 1.0.0
     *
     * @access protected
     * @see wpdb::query()
     *
     * @param string $query The query to run.
     * @param bool   $dbh_or_table  Database or table name. Defaults to false.
     */
    protected function _do_query( $query, $dbh_or_table = false ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
        $dbh = $this->get_db_object( $dbh_or_table );

        if ( ! $this->dbh_type_check( $dbh ) ) {
            return false;
        }

        if ( true === $this->use_mysqli ) {
            $result = mysqli_query( $dbh, $query );
        } else {
            $result = mysql_query( $query, $dbh );
        }

        // Maybe log last used to heartbeats
        if ( ! empty( $this->check_dbh_heartbeats ) ) {

            // Lookup name
            $name = $this->lookup_dbhs_name( $dbh );

            // Set last used for this dbh
            if ( ! empty( $name ) ) {
                $this->dbhname_heartbeats[ $name ]['last_used'] = microtime( true );
            }
        }

        return $result;
    }

    /**
     * Find a dbh name value for a given $dbh object.
     *
     * @since 5.0.0
     *
     * @param object $dbh The dbh object for which to find the dbh name
     *
     * @return string The dbh name
     */
    private function lookup_dbhs_name( $dbh = false ) {

        // Loop through database hosts and look for this one
        foreach ( $this->dbhs as $dbhname => $other_dbh ) {

            // Match found so return the key
            if ( $dbh === $other_dbh ) {
                return $dbhname;
            }
        }

        // No match
        return false;
    }

    /**
     * Get the db connection object
     *
     * @since 1.0.0
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     */
    protected function get_db_object( $dbh_or_table = false ) {

        // No database
        $dbh = false;

        // Database
        if ( $this->dbh_type_check( $dbh_or_table ) ) {
            $dbh = &$dbh_or_table;

            // Database
        } elseif ( ( false === $dbh_or_table ) && $this->dbh_type_check( $this->dbh ) ) {
            $dbh = &$this->dbh;

            // Table name
        } elseif ( is_string( $dbh_or_table ) ) {
            $dbh = $this->justInTimeConnect( "SELECT FROM {$dbh_or_table} {$this->users}" );
        }

        return $dbh;
    }

    /**
     * Check databse object type.
     *
     * @param resource|mysqli $dbh Database resource.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected function dbh_type_check( $dbh ) {

        if (( true === $this->use_mysqli ) && ( $dbh instanceof mysqli )) {
            return true;
        }

        if (is_resource( $dbh )) {
            return true;
        }

        return false;
    }

    /**
     * Add the connection parameters for a database
     *
     * @since 1.0.0
     *
     * @param array $db Default to empty array.
     */
    public function add_database( array $db = array() ) {

        // Setup some sane default values
        $database_defaults = array(
            'dataset'       => 'global',
            'write'         => 1,
            'read'          => 1,
            'timeout'       => 0.2,
            'port'          => 3306,
            'lag_threshold' => null,
        );

        // Merge using defaults
        $db      = wp_parse_args( $db, $database_defaults );

        // Break these apart to make code easier to understand below
        $dataset = $db['dataset'];
        $read    = $db['read'];
        $write   = $db['write'];

        // We do not include the dataset in the array. It's used as a key.
        unset( $db['dataset'] );

        // Maybe add database to array of read's
        if ( ! empty( $read ) ) {
            $this->ludicrous_servers[ $dataset ]['read'][ $read ][] = $db;
        }

        // Maybe add database to array of write's
        if ( ! empty( $write ) ) {
            $this->ludicrous_servers[ $dataset ]['write'][ $write ][] = $db;
        }
    }

    /**
     * Specify the dataset where a table is found
     *
     * @since 1.0.0
     *
     * @param string $dataset  Database.
     * @param string $table    Table name.
     */
    public function add_table( $dataset, $table ) {
        $this->ludicrous_tables[ $table ] = $dataset;
    }

    /**
     * Add a callback to a group of callbacks
     *
     * The default group is 'dataset', used to examine queries & determine dataset
     *
     * @since 1.0.0
     *
     * @param  $callback Callback on a dataset.
     * @param    $group   string Key name of dataset.
     */
    public function add_callback( $callback, $group = 'dataset' ) {
        $this->ludicrous_callbacks[ $group ][] = $callback;
    }

    /**
     * Determine the likelihood that this query could alter anything
     *
     * Statements are considered read-only when:
     * 1. not including UPDATE nor other "may-be-write" strings
     * 2. begin with SELECT etc.
     *
     * @since 1.0.0
     *
     * @param string $q Query.
     *
     * @return bool
     */
    public function is_write_query( $q = '' ) {

        // Trim potential whitespace or subquery chars
        $q = ltrim( $q, "\r\n\t (" );

        // Possible writes
        if ( preg_match( '/(?:^|\s)(?:ALTER|CREATE|ANALYZE|CHECK|OPTIMIZE|REPAIR|CALL|DELETE|DROP|INSERT|LOAD|REPLACE|UPDATE|SET|RENAME\s+TABLE)(?:\s|$)/i', $q ) ) {
            return true;
        }

        // Not possible non-writes (phew!)
        return ! preg_match( '/^(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)(?:\s|$)/i', $q );
    }

    /**
     * Set a flag to prevent reading from slaves which might be lagging after a write
     *
     * @since 1.0.0
     */
    public function send_reads_to_masters() {
        $this->srtm = true;
    }

    /**
     * Callbacks are executed in the order in which they are registered until one
     * of them returns something other than null
     *
     * @since 1.0.0
     * @param string $group Group, key name in array.
     * @param array  $args   Args passed to callback. Default to null.
     */
    public function run_callbacks( $group, $args = null ) {
        if ( ! isset( $this->ludicrous_callbacks[ $group ] ) || ! is_array( $this->ludicrous_callbacks[ $group ] ) ) {
            return null;
        }

        if ( ! isset( $args ) ) {
            $args = array( &$this );
        } elseif ( is_array( $args ) ) {
            $args[] = &$this;
        } else {
            $args = array( $args, &$this );
        }

        foreach ( $this->ludicrous_callbacks[ $group ] as $func ) {
            $result = call_user_func_array( $func, $args );
            if ( isset( $result ) ) {
                return $result;
            }
        }
    }



    /**
     * Connect selected database
     *
     * @since 1.0.0
     *
     * @param string $dbhname Database name.
     * @param string $host Internet address: host:port of server on internet.
     * @param string $user Database user.
     * @param string $password Database password.
     *
     * @return bool|mysqli|resource
     */
    protected function single_db_connect( $dbhname, $host, $user, $password ) {
        $this->is_mysql = true;

        /*
         * Deprecated in 3.9+ when using MySQLi. No equivalent
         * $new_link parameter exists for mysqli_* functions.
         */
        $new_link     = defined( 'MYSQL_NEW_LINK' ) ? MYSQL_NEW_LINK : true;
        $client_flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;

        if ( true === $this->use_mysqli ) {
            $this->dbhs[ $dbhname ] = mysqli_init();

            // mysqli_real_connect doesn't support the host param including a port or socket
            // like mysql_connect does. This duplicates how mysql_connect detects a port and/or socket file.
            $port           = null;
            $socket         = null;
            $port_or_socket = strstr( $host, ':' );

            if ( ! empty( $port_or_socket ) ) {
                $host           = substr( $host, 0, strpos( $host, ':' ) );
                $port_or_socket = substr( $port_or_socket, 1 );

                if ( 0 !== strpos( $port_or_socket, '/' ) ) {
                    $port         = intval( $port_or_socket );
                    $maybe_socket = strstr( $port_or_socket, ':' );

                    if ( ! empty( $maybe_socket ) ) {
                        $socket = substr( $maybe_socket, 1 );
                    }
                } else {
                    $socket = $port_or_socket;
                }
            }

            // Detail found here - https://core.trac.wordpress.org/ticket/31018
            $pre_host = '';

            // If DB_HOST begins with a 'p:', allow it to be passed to mysqli_real_connect().
            // mysqli supports persistent connections starting with PHP 5.3.0.
            if ( ( true === $this->persistent ) && version_compare( phpversion(), '5.3.0', '>=' ) ) {
                $pre_host = 'p:';
            }

            mysqli_real_connect( $this->dbhs[ $dbhname ], $pre_host . $host, $user, $password, null, $port, $socket, $client_flags );

            if ( $this->dbhs[ $dbhname ]->connect_errno ) {
                $this->dbhs[ $dbhname ] = false;

                return false;
            }
        } else {

            // Check if functions exists (they do not in PHP 7)
            if ( ( true === $this->persistent ) && function_exists( 'mysql_pconnect' ) ) {
                $this->dbhs[ $dbhname ] = mysql_pconnect( $host, $user, $password, $new_link, $client_flags );
            } elseif ( function_exists( 'mysql_connect' ) ) {
                $this->dbhs[ $dbhname ] = mysql_connect( $host, $user, $password, $new_link, $client_flags );
            }
        }
    }


    /**
     * Disconnect and remove connection from open connections list
     *
     * @since 1.0.0
     *
     * @param string $dbhname Dataname key name.
     */
    public function disconnect( $dbhname ) {

        $k = array_search( $dbhname, $this->open_connections, true );
        if ( ! empty( $k ) ) {
            unset( $this->open_connections[ $k ] );
        }

        if ( $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {
            $this->close( $this->dbhs[ $dbhname ] );
        }

        unset( $this->dbhs[ $dbhname ] );
    }

}