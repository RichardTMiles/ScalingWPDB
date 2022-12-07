<?php

namespace ScalingWPDB;

use mysqli_result;
use WP_Error;
use wpdb;

abstract class Overrides extends Functions
{

    /**
     * The number of times to retry reconnecting before dying
     *
     * @access protected
     * @see wpdb::check_connection()
     * @var int
     */
    public $reconnect_retries = 3;

    /**
     * Gets ready to make database connections
     *
     * @since 1.0.0
     *
     * @param array $args db class vars
     */
    public function __construct( $args = null ) {


        // Database Lag
        define( 'DB_LAG_OK', 1 );
        define( 'DB_LAG_BEHIND', 2 );
        define( 'DB_LAG_UNKNOWN', 3 );
        define( 'DB_SERVER_GONE_ERROR', 2006 );

        if ( WP_DEBUG && WP_DEBUG_DISPLAY ) {
            $this->show_errors();
        }

        /*
         *  Use ext/mysqli if it exists and:
         *  - WP_USE_EXT_MYSQL is defined as false, or
         *  - We are a development version of WordPress, or
         *  - We are running PHP 5.5 or greater, or
         *  - ext/mysql is not loaded.
         */
        if ( function_exists( 'mysqli_connect' ) ) {
            if ( defined( 'WP_USE_EXT_MYSQL' ) ) {
                $this->use_mysqli = ! WP_USE_EXT_MYSQL;
            } elseif ( version_compare( phpversion(), '5.5', '>=' ) || ! function_exists( 'mysql_connect' ) ) {
                $this->use_mysqli = true;
            } elseif ( false !== strpos( $GLOBALS['wp_version'], '-' ) ) {
                $this->use_mysqli = true;
            }
        }

        // Maybe override class vars
        if ( is_array( $args ) ) {
            $class_vars = array_keys( get_class_vars( __CLASS__ ) );
            foreach ( $class_vars as $var ) {
                if ( isset( $args[ $var ] ) ) {
                    $this->{$var} = $args[ $var ];
                }
            }
        }

        // Set collation and character set
        $this->init_charset();
    }

    /**
     * Sets $this->charset and $this->collate
     *
     * @since 1.0.0
     *
     * @global array $wp_global
     */
    public function init_charset() {
        global $wp_version;

        // Defaults
        $charset = $collate = '';

        // Multisite
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            if ( version_compare( $wp_version, '4.2', '<' ) ) {
                $charset = 'utf8';
                $collate = 'utf8_general_ci';
            } else {
                $charset = 'utf8mb4';
                $collate = 'utf8mb4_unicode_520_ci';
            }
        }

        // Use constant if defined
        if ( defined( 'DB_COLLATE' ) ) {
            $collate = DB_COLLATE;
        }

        // Use constant if defined
        if ( defined( 'DB_CHARSET' ) ) {
            $charset = DB_CHARSET;
        }

        // determine_charset is only in WordPress 4.6
        if ( method_exists( $this, 'determine_charset' ) ) {
            $determined = $this->determine_charset( $charset, $collate );
            $charset    = $determined['charset'];
            $collate    = $determined['collate'];
            unset( $determined );
        }

        // Set charset & collation
        $this->charset = $charset;
        $this->collate = $collate;
    }

    /**
     * Closes the current database connection
     *
     * @since 1.0.0
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     *
     * @return bool True if the connection was successfully closed, false if it wasn't,
     *              or the connection doesn't exist.
     */
    public function close( $dbh_or_table = false ) {
        $dbh = $this->get_db_object( $dbh_or_table );

        if ( ! $this->dbh_type_check( $dbh ) ) {
            return false;
        }

        if ( true === $this->use_mysqli ) {
            $closed = mysqli_close( $dbh );
        } else {
            $closed = mysql_close( $dbh );
        }

        if ( ! empty( $closed ) ) {
            $this->dbh = null;
        }

        return $closed;
    }

    /**
     * Logs query data.
     *
     * @since 4.3.0
     *
     * @param string $query           The query's SQL.
     * @param float  $query_time      Total time spent on the query, in seconds.
     * @param string $query_callstack Comma separated list of the calling functions.
     * @param float  $query_start     Unix timestamp of the time at the start of the query.
     * @param array  $query_data      Custom query data.
     * }
     */
    public function log_query( $query = '', $query_time = 0, $query_callstack = '', $query_start = 0, $query_data = array() ) {

        /**
         * Filters the custom query data being logged.
         *
         * Caution should be used when modifying any of this data, it is recommended that any additional
         * information you need to store about a query be added as a new associative entry to the fourth
         * element $query_data.
         *
         * @since 5.3.0
         *
         * @param array  $query_data      Custom query data.
         * @param string $query           The query's SQL.
         * @param float  $query_time      Total time spent on the query, in seconds.
         * @param string $query_callstack Comma separated list of the calling functions.
         * @param float  $query_start     Unix timestamp of the time at the start of the query.
         */
        $query_data = apply_filters( 'log_query_custom_data', $query_data, $query, $query_time, $query_callstack, $query_start );

        // Pass to custom callback...
        if ( is_callable( $this->save_query_callback ) ) {
            $this->queries[] = call_user_func_array(
                $this->save_query_callback,
                array(
                    $query,
                    $query_time,
                    $query_callstack,
                    $query_start,
                    $query_data,
                    &$this,
                )
            );

            // ...or save it to the queries array
        } else {
            $this->queries[] = array(
                $query,
                $query_time,
                $query_callstack,
                $query_start,
                $query_data,
            );
        }
    }

    /**
     * Whether or not MySQL database is at least the required minimum version.
     * The additional argument allows the caller to check a specific database
     *
     * @since 1.0.0
     *
     * @global $wp_version
     * @global $required_mysql_version
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     *
     * @return WP_Error
     */
    public function check_database_version( $dbh_or_table = false ) {
        global $wp_version, $required_mysql_version;

        // Make sure the server has the required MySQL version
        $mysql_version = preg_replace( '|[^0-9\.]|', '', $this->db_version( $dbh_or_table ) );
        if ( version_compare( $mysql_version, $required_mysql_version, '<' ) ) {
            // translators: 1. WordPress version, 2. MySql Version.
            return new WP_Error( 'database_version', sprintf( __( '<strong>ERROR</strong>: WordPress %1$s requires MySQL %2$s or higher', 'ludicrousdb' ), $wp_version, $required_mysql_version ) );
        }
    }

    /**
     * This function is called when WordPress is generating the table schema to determine whether or not the current database
     * supports or needs the collation statements
     *
     * The additional argument allows the caller to check a specific database
     *
     * @since 1.0.0
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     *
     * @return bool
     */
    public function supports_collation( $dbh_or_table = false ) {
        _deprecated_function( __FUNCTION__, '3.5', 'wpdb::has_cap( \'collation\' )' );

        return $this->has_cap( 'collation', $dbh_or_table );
    }

    /**
     * Generic function to determine if a database supports a particular feature
     * The additional argument allows the caller to check a specific database
     *
     * @since 1.0.0
     *
     * @param string                $db_cap the feature
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     *
     * @return bool
     */
    public function has_cap( $db_cap, $dbh_or_table = false ) {
        $version = $this->db_version( $dbh_or_table );

        switch ( strtolower( $db_cap ) ) {
            case 'collation':    // @since 2.5.0
            case 'group_concat': // @since 2.7.0
            case 'subqueries':   // @since 2.7.0
                return version_compare( $version, '4.1', '>=' );
            case 'set_charset':
                return version_compare( $version, '5.0.7', '>=' );
            case 'utf8mb4':      // @since 4.1.0
                if ( version_compare( $version, '5.5.3', '<' ) ) {
                    return false;
                }

                $dbh = $this->get_db_object( $dbh_or_table );

                if ( $this->dbh_type_check( $dbh ) ) {
                    if ( true === $this->use_mysqli ) {
                        $client_version = mysqli_get_client_info( );
                    } else {
                        $client_version = mysql_get_client_info( $dbh );
                    }

                    /*
                     * libmysql has supported utf8mb4 since 5.5.3, same as the MySQL server.
                     * mysqlnd has supported utf8mb4 since 5.0.9.
                     */
                    if ( false !== strpos( $client_version, 'mysqlnd' ) ) {
                        $client_version = preg_replace( '/^\D+([\d.]+).*/', '$1', $client_version );

                        return version_compare( $client_version, '5.0.9', '>=' );
                    } else {
                        return version_compare( $client_version, '5.5.3', '>=' );
                    }
                }
                break;
            case 'utf8mb4_520': // @since 4.6.0
                return version_compare( $version, '5.6', '>=' );
        }

        return false;
    }

    /**
     * Retrieve the name of the function that called wpdb.
     *
     * Searches up the list of functions until it reaches
     * the one that would most logically had called this method.
     *
     * @since 2.5.0
     *
     * @return string Comma separated list of the calling functions.
     */
    public function get_caller() {
        return ( true === $this->save_backtrace )
            ? wp_debug_backtrace_summary( __CLASS__ )
            : null;
    }

    /**
     * The database version number
     *
     * @since 1.0.0
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     *
     * @return false|string false on failure, version number on success
     */
    public function db_version( $dbh_or_table = false ) {
        return preg_replace( '/[^0-9.].*/', '', $this->db_server_info( $dbh_or_table ) );
    }

    /**
     * Retrieves full MySQL server information.
     *
     * @since 5.0.0
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     *
     * @return string|false Server info on success, false on failure.
     */
    public function db_server_info( $dbh_or_table = false ) {
        $dbh = $this->get_db_object( $dbh_or_table );

        if ( ! $this->dbh_type_check( $dbh ) ) {
            return false;
        }

        $server_info = ( true === $this->use_mysqli )
            ? mysqli_get_server_info( $dbh )
            : mysql_get_server_info( $dbh );

        return $server_info;
    }

    /**
     * Retrieves a tables character set.
     *
     * NOTE: This must be called after LudicrousDB::db_connect, so that wpdb::dbh is set correctly
     *
     * @param string $table Table name
     *
     * @return mixed The table character set, or WP_Error if we couldn't find it
     */
    protected function get_table_charset( $table ) {
        $tablekey = strtolower( $table );

        /**
         * Filter the table charset value before the DB is checked.
         *
         * Passing a non-null value to the filter will effectively short-circuit
         * checking the DB for the charset, returning that value instead.
         *
         * @param string $charset The character set to use. Default null.
         * @param string $table The name of the table being checked.
         */
        $charset = apply_filters( 'pre_get_table_charset', null, $table );
        if ( null !== $charset ) {
            return $charset;
        }

        if ( isset( $this->table_charset[ $tablekey ] ) ) {
            return $this->table_charset[ $tablekey ];
        }

        $charsets = $columns = array();

        $table_parts = explode( '.', $table );
        $table       = '`' . implode( '`.`', $table_parts ) . '`';
        $results     = $this->get_results( "SHOW FULL COLUMNS FROM {$table}" );

        if ( empty( $results ) ) {
            return new WP_Error( 'wpdb_get_table_charset_failure' );
        }

        foreach ( $results as $column ) {
            $columns[ strtolower( $column->Field ) ] = $column; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
        }

        $this->col_meta[ $tablekey ] = $columns;

        foreach ( $columns as $column ) {
            if ( ! empty( $column->Collation ) ) {  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                list( $charset ) = explode( '_', $column->Collation );  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

                // If the current connection can't support utf8mb4 characters, let's only send 3-byte utf8 characters.
                if ( ( 'utf8mb4' === $charset ) && ! $this->has_cap( 'utf8mb4' ) ) {
                    $charset = 'utf8';
                }

                $charsets[ strtolower( $charset ) ] = true;
            }

            list( $type ) = explode( '(', $column->Type );  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

            // A binary/blob means the whole query gets treated like this.
            if ( in_array( strtoupper( $type ), array(
                'BINARY',
                'VARBINARY',
                'TINYBLOB',
                'MEDIUMBLOB',
                'BLOB',
                'LONGBLOB',
            ), true ) ) {
                $this->table_charset[ $tablekey ] = 'binary';

                return 'binary';
            }
        }

        // utf8mb3 is an alias for utf8.
        if ( isset( $charsets['utf8mb3'] ) ) {
            $charsets['utf8'] = true;
            unset( $charsets['utf8mb3'] );
        }

        // Check if we have more than one charset in play.
        $count = count( $charsets );
        if ( 1 === $count ) {
            $charset = key( $charsets );

            // No charsets, assume this table can store whatever.
        } elseif ( 0 === $count ) {
            $charset = false;

            // More than one charset. Remove latin1 if present and recalculate.
        } else {
            unset( $charsets['latin1'] );
            $count = count( $charsets );

            // Only one charset (besides latin1).
            if ( 1 === $count ) {
                $charset = key( $charsets );

                // Two charsets, but they're utf8 and utf8mb4, use utf8.
            } elseif ( 2 === $count && isset( $charsets['utf8'], $charsets['utf8mb4'] ) ) {
                $charset = 'utf8';

                // Two mixed character sets. ascii.
            } else {
                $charset = 'ascii';
            }
        }

        $this->table_charset[ $tablekey ] = $charset;

        return $charset;
    }



    /**
     * Figure out which db server should handle the query, and connect to it
     *
     * @since 1.0.0
     *
     * @param string $query Query.
     *
     * @return bool MySQL database connection
     */
    abstract public function justInTimeConnect(string $query = '');


    /**
     * Change the current SQL mode, and ensure its WordPress compatibility
     *
     * If no modes are passed, it will ensure the current MySQL server
     * modes are compatible
     *
     * @since 1.0.0
     *
     * @param array                 $modes Optional. A list of SQL modes to set.
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     */
    public function set_sql_mode( $modes = array(), $dbh_or_table = false ) {
        $dbh = $this->get_db_object( $dbh_or_table );

        if ( ! $this->dbh_type_check( $dbh ) ) {
            return;
        }

        if ( empty( $modes ) ) {
            if ( true === $this->use_mysqli ) {
                $res = mysqli_query( $dbh, 'SELECT @@SESSION.sql_mode' );
            } else {
                $res = mysql_query( 'SELECT @@SESSION.sql_mode', $dbh );
            }

            if ( empty( $res ) ) {
                return;
            }

            if ( true === $this->use_mysqli ) {
                $modes_array = mysqli_fetch_array( $res );
                if ( empty( $modes_array[0] ) ) {
                    return;
                }
                $modes_str = $modes_array[0];
            } else {
                $modes_str = mysql_result( $res, 0 );
            }

            if ( empty( $modes_str ) ) {
                return;
            }

            $modes = explode( ',', $modes_str );
        }

        $modes = array_change_key_case( $modes, CASE_UPPER );

        /**
         * Filter the list of incompatible SQL modes to exclude.
         *
         * @param array $incompatible_modes An array of incompatible modes.
         */
        $incompatible_modes = (array) apply_filters( 'incompatible_sql_modes', $this->incompatible_modes );
        foreach ( $modes as $i => $mode ) {
            if ( in_array( $mode, $incompatible_modes, true ) ) {
                unset( $modes[ $i ] );
            }
        }

        $modes_str = implode( ',', $modes );

        if ( true === $this->use_mysqli ) {
            mysqli_query( $dbh, "SET SESSION sql_mode='{$modes_str}'" );
        } else {
            mysql_query( "SET SESSION sql_mode='{$modes_str}'", $dbh );
        }
    }

    /**
     * Selects a database using the current database connection
     *
     * The database name will be changed based on the current database
     * connection. On failure, the execution will bail and display an DB error
     *
     * @since 1.0.0
     *
     * @param string                $db MySQL database name
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     */
    public function select( $db, $dbh_or_table = false ) {
        $dbh = $this->get_db_object( $dbh_or_table );

        if ( ! $this->dbh_type_check( $dbh ) ) {
            return false;
        }

        if ( true === $this->use_mysqli ) {
            $success = mysqli_select_db( $dbh, $db );
        } else {
            $success = mysql_select_db( $db, $dbh );
        }

        return $success;
    }

    /**
     * Load the column metadata from the last query.
     *
     * @since 1.0.0
     *
     * @access protected
     */
    protected function load_col_info() {

        // Bail if not enough info
        if ( ! empty( $this->col_info ) || ( false === $this->result ) ) {
            return;
        }

        $this->col_info = array();

        if ( true === $this->use_mysqli ) {
            $num_fields = mysqli_num_fields( $this->result );
            for ( $i = 0; $i < $num_fields; $i ++ ) {
                $this->col_info[ $i ] = mysqli_fetch_field( $this->result );
            }
        } else {
            $num_fields = mysql_num_fields( $this->result );
            for ( $i = 0; $i < $num_fields; $i ++ ) {
                $this->col_info[ $i ] = mysql_fetch_field( $this->result, $i );
            }
        }
    }

    /**
     * Force addslashes() for the escapes
     *
     * LudicrousDB makes connections when a query is made which is why we can't
     * use mysql_real_escape_string() for escapes
     *
     * This is also the reason why we don't allow certain charsets.
     *
     * See set_charset().
     *
     * @since 1.0.0
     * @param string $string String to escape.
     */
    public function _real_escape( $string ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore

        // Slash the query part
        $escaped = addslashes( $string );

        // Maybe use WordPress core placeholder method
        if ( method_exists( $this, 'add_placeholder_escape' ) ) {
            $escaped = $this->add_placeholder_escape( $escaped );
        }

        return $escaped;
    }

    /**
     * Sets the connection's character set
     *
     * @since 1.0.0
     *
     * @param resource $dbh The resource given by mysql_connect
     * @param string   $charset The character set (optional)
     * @param string   $collate The collation (optional)
     */
    public function set_charset( $dbh, $charset = null, $collate = null ) {
        if ( ! isset( $charset ) ) {
            $charset = $this->charset;
        }
        if ( ! isset( $collate ) ) {
            $collate = $this->collate;
        }
        if ( empty( $charset ) || empty( $collate ) ) {
            wp_die( "{$charset}  {$collate}" );
        }
        if ( ! in_array( strtolower( $charset ), array( 'utf8', 'utf8mb4', 'latin1' ), true ) ) {
            wp_die( "{$charset} charset isn't supported in LudicrousDB for security reasons" );
        }
        if ( $this->has_cap( 'collation', $dbh ) && ! empty( $charset ) ) {
            $set_charset_succeeded = true;
            if ( ( true === $this->use_mysqli ) && function_exists( 'mysqli_set_charset' ) && $this->has_cap( 'set_charset', $dbh ) ) {
                $set_charset_succeeded = mysqli_set_charset( $dbh, $charset );
            } elseif ( function_exists( 'mysql_set_charset' ) && $this->has_cap( 'set_charset', $dbh ) ) {
                $set_charset_succeeded = mysql_set_charset( $charset, $dbh );
            }
            if ( $set_charset_succeeded ) {
                $query = $this->prepare( 'SET NAMES %s', $charset );
                if ( ! empty( $collate ) ) {
                    $query .= $this->prepare( ' COLLATE %s', $collate );
                }
                $this->_do_query( $query, $dbh );
            }
        }
    }


    /**
     * Kill cached query results
     *
     * @since 1.0.0
     */
    public function flush() {
        $this->last_error = '';
        $this->num_rows   = 0;
        parent::flush();
    }

    /**
     * Check that the connection to the database is still up. If not, try
     * to reconnect
     *
     * If this function is unable to reconnect, it will forcibly die, or if after the
     * the template_redirect hook has been fired, return false instead
     *
     * If $allow_bail is false, the lack of database connection will need
     * to be handled manually
     *
     * @since 1.0.0
     *
     * @param bool   $allow_bail Optional. Allows the function to bail. Default true.
     * @param bool   $dbh_or_table Optional.
     * @param string $query Optional. Query string passed db_connect
     *
     * @return bool|void True if the connection is up.
     */
    public function check_connection( $allow_bail = true, $dbh_or_table = false, $query = '' ) {
        $dbh = $this->get_db_object( $dbh_or_table );

        if ( $this->dbh_type_check( $dbh ) ) {
            if ( true === $this->use_mysqli ) {
                if ( mysqli_ping( $dbh ) ) {
                    return true;
                }
            } else {
                if ( mysql_ping( $dbh ) ) {
                    return true;
                }
            }
        }

        if ( false === $allow_bail ) {
            return false;
        }

        $error_reporting = false;

        // Disable warnings, as we don't want to see a multitude of "unable to connect" messages
        if ( WP_DEBUG ) {
            $error_reporting = error_reporting();
            error_reporting( $error_reporting & ~E_WARNING );
        }

        for ( $tries = 1; $tries <= $this->reconnect_retries; $tries ++ ) {

            // On the last try, re-enable warnings. We want to see a single instance of the
            // "unable to connect" message on the bail() screen, if it appears.
            if ( $this->reconnect_retries === $tries && WP_DEBUG ) {
                error_reporting( $error_reporting );
            }

            if ( $this->justInTimeConnect( $query ) ) {
                if ( $error_reporting ) {
                    error_reporting( $error_reporting );
                }

                return true;
            }

            sleep( 1 );
        }

        // If template_redirect has already happened, it's too late for wp_die()/dead_db().
        // Let's just return and hope for the best.
        if ( did_action( 'template_redirect' ) ) {
            return false;
        }

        wp_load_translations_early();

        $message  = '<h1>' . __( 'Error reconnecting to the database', 'ludicrousdb' ) . "</h1>\n";
        $message .= '<p>' . sprintf(
            /* translators: %s: database host */
                __( 'This means that we lost contact with the database server at %s. This could mean your host&#8217;s database server is down.', 'ludicrousdb' ),
                '<code>' . htmlspecialchars( $this->dbhost, ENT_QUOTES ) . '</code>'
            ) . "</p>\n";
        $message .= "<ul>\n";
        $message .= '<li>' . __( 'Are you sure that the database server is running?', 'ludicrousdb' ) . "</li>\n";
        $message .= '<li>' . __( 'Are you sure that the database server is not under particularly heavy load?', 'ludicrousdb' ) . "</li>\n";
        $message .= "</ul>\n";
        $message .= '<p>' . sprintf(
            /* translators: %s: support forums URL */
                __( 'If you&#8217;re unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href="%s">WordPress Support Forums</a>.', 'ludicrousdb' ),
                __( 'https://wordpress.org/support/', 'ludicrousdb' )
            ) . "</p>\n";

        // We weren't able to reconnect, so we better bail.
        $this->bail( $message, 'db_connect_fail' );

        // Call dead_db() if bail didn't die, because this database is no more. It has ceased to be (at least temporarily).
        dead_db();
    }

    /**
     * Basic query. See documentation for more details.
     *
     * @since 1.0.0
     *
     * @param string $query Query.
     *
     * @return int number of rows
     */
    public function query( $query ) {

        // initialise return
        $return_val = 0;
        $this->flush();

        // Some queries are made before plugins are loaded
        if ( function_exists( 'apply_filters' ) ) {

            /**
             * Filter the database query.
             *
             * Some queries are made before the plugins have been loaded,
             * and thus cannot be filtered with this method.
             *
             * @param string $query Database query.
             */
            $query = apply_filters( 'query', $query );
        }

        // Some queries are made before plugins are loaded
        if ( function_exists( 'apply_filters_ref_array' ) ) {

            /**
             * Filter the return value before the query is run.
             *
             * Passing a non-null value to the filter will effectively short-circuit
             * the DB query, stopping it from running, then returning this value instead.
             *
             * This uses apply_filters_ref_array() to allow $this to be manipulated, so
             * values can be set just-in-time to match your particular use-case.
             *
             * You probably will never need to use this filter, but if you do, there's
             * no other way to do what you're trying to do, so here you go!
             *
             * @since 4.0.0
             *
             * @param string      null   The filtered return value. Default is null.
             * @param string $query Database query.
             * @param ScalingWPDB &$this Current instance of LudicrousDB, passed by reference.
             */
            $return_val = apply_filters_ref_array( 'pre_query', array( null, $query, &$this ) );
            if ( null !== $return_val ) {
                $this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

                return $return_val;
            }
        }

        // Bail if query is empty (via application error or 'query' filter)
        if ( empty( $query ) ) {
            $this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

            return $return_val;
        }

        // Log how the function was called
        $this->func_call = "\$db->query(\"{$query}\")";

        // If we're writing to the database, make sure the query will write safely.
        if ( $this->check_current_query && ! $this->check_ascii( $query ) ) {
            $stripped_query = $this->strip_invalid_text_from_query( $query );

            // strip_invalid_text_from_query() can perform queries, so we need
            // to flush again, just to make sure everything is clear.
            $this->flush();

            if ( $stripped_query !== $query ) {
                $this->insert_id = 0;
                $return_val      = false;
                $this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

                return $return_val;
            }
        }

        $this->check_current_query = true;

        // Keep track of the last query for debug..
        $this->last_query = $query;

        if ( preg_match( '/^\s*SELECT\s+FOUND_ROWS(\s*)/i', $query )
            &&
            (
                (
                    ( false === $this->use_mysqli )
                    &&
                    is_resource( $this->last_found_rows_result )
                )
                ||
                (
                    ( true === $this->use_mysqli )
                    &&
                    ( $this->last_found_rows_result instanceof mysqli_result )
                )
            )
        ) {
            $this->result = $this->last_found_rows_result;
            $elapsed      = 0;
        } else {
            $this->dbh = $this->justInTimeConnect( $query );

            if ( ! $this->dbh_type_check( $this->dbh ) ) {
                $this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

                return false;
            }

            $this->timer_start();
            $this->result = $this->_do_query( $query, $this->dbh );
            $elapsed      = $this->timer_stop();

            ++ $this->num_queries;

            if ( preg_match( '/^\s*SELECT\s+SQL_CALC_FOUND_ROWS\s/i', $query ) ) {
                if ( false === strpos( $query, 'NO_SELECT_FOUND_ROWS' ) ) {
                    $this->timer_start();
                    $this->last_found_rows_result = $this->_do_query( 'SELECT FOUND_ROWS()', $this->dbh );
                    $elapsed                     += $this->timer_stop();
                    ++ $this->num_queries;
                    $query .= '; SELECT FOUND_ROWS()';
                }
            } else {
                $this->last_found_rows_result = null;
            }

            if ( ! empty( $this->save_queries ) || ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) ) {
                $this->log_query(
                    $query,
                    $elapsed,
                    $this->get_caller(),
                    $this->time_start,
                    array()
                );
            }
        }

        // If there is an error then take note of it
        if ( $this->dbh_type_check( $this->dbh ) ) {
            if ( true === $this->use_mysqli ) {
                $this->last_error = mysqli_error( $this->dbh );
            } else {
                $this->last_error = mysql_error( $this->dbh );
            }
        }

        if ( ! empty( $this->last_error ) ) {
            $this->print_error( $this->last_error );
            $return_val = false;
            $this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

            return $return_val;
        }

        if ( preg_match( '/^\s*(create|alter|truncate|drop)\s/i', $query ) ) {
            $return_val = $this->result;
        } elseif ( preg_match( '/^\\s*(insert|delete|update|replace|alter) /i', $query ) ) {
            if ( true === $this->use_mysqli ) {
                $this->rows_affected = mysqli_affected_rows( $this->dbh );
            } else {
                $this->rows_affected = mysql_affected_rows( $this->dbh );
            }

            // Take note of the insert_id
            if ( preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
                if ( true === $this->use_mysqli ) {
                    $this->insert_id = mysqli_insert_id( $this->dbh );
                } else {
                    $this->insert_id = mysql_insert_id( $this->dbh );
                }
            }

            // Return number of rows affected
            $return_val = $this->rows_affected;
        } else {
            $num_rows          = 0;
            $this->last_result = array();

            if ( ( true === $this->use_mysqli ) && ( $this->result instanceof mysqli_result ) ) {
                $this->load_col_info();
                while ( $row = mysqli_fetch_object( $this->result ) ) {
                    $this->last_result[ $num_rows ] = $row;
                    $num_rows ++;
                }
            } elseif ( is_resource( $this->result ) ) {
                $this->load_col_info();
                while ( $row = mysql_fetch_object( $this->result ) ) {
                    $this->last_result[ $num_rows ] = $row;
                    $num_rows ++;
                }
            }

            // Log number of rows the query returned
            // and return number of rows selected
            $this->num_rows = $num_rows;
            $return_val     = $num_rows;
        }

        $this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

        // Some queries are made before plugins are loaded
        if ( function_exists( 'do_action_ref_array' ) ) {

            /**
             * Runs after a query is finished.
             *
             * @since 4.0.0
             *
             * @param string $query Database query.
             * @param ScalingWPDB &$this Current instance of LudicrousDB, passed by reference.
             */
            do_action_ref_array( 'queried', array( $query, &$this ) );
        }

        return $return_val;
    }

}