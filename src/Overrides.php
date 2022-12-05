<?php

namespace ScalingWPDB;

use CarbonPHP\Error\PublicAlert;
use CarbonPHP\Error\ThrowableHandler;
use mysqli;
use mysqli_result;
use Throwable;
use WP_Error;
use wpdb;

abstract class Overrides extends Info
{


    public function db_connect($allow_bail = true): bool
    {
        return true;
    }

    /**
     * Kill cached query results
     *
     * @since 1.0.0
     */
    public function flush(): void
    {
        $this->last_error = '';

        $this->num_rows = 0;

        parent::flush();

    }

    /**
     * Gets ready to make database connections
     *
     * @param array $args db class vars
     * @noinspection MagicMethodsValidityInspection
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct($args = null)
    {

        if (WP_DEBUG && WP_DEBUG_DISPLAY) {

            $this->show_errors();

        }

        // Maybe override class vars
        if (is_array($args)) {

            $class_vars = array_keys(get_class_vars(__CLASS__));

            foreach ($class_vars as $var) {

                if (isset($args[$var])) {

                    $this->{$var} = $args[$var];

                }

            }

        }

        // Set collation and character set
        $this->init_charset();
    }

    /**
     * Change the current SQL mode, and ensure its WordPress compatibility
     *
     * If no modes are passed, it will ensure the current MySQL server
     * modes are compatible
     *
     * @param array $modes Optional. A list of SQL modes to set.
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     * @since 1.0.0
     *
     */
    public function set_sql_mode($modes = [], mixed $dbh_or_table = false): void
    {
        $dbh = $this->get_db_object($dbh_or_table);

        if (!$this->dbh_type_check($dbh)) {

            return;

        }

        if (empty($modes)) {

            $res = mysqli_query($dbh, 'SELECT @@SESSION.sql_mode');

            if (empty($res)) {

                return;

            }

            $modes_array = mysqli_fetch_array($res);

            if (empty($modes_array[0])) {
                return;
            }

            $modes_str = $modes_array[0];

            if (empty($modes_str)) {

                return;

            }

            $modes = explode(',', $modes_str);

        }

        $modes = array_change_key_case($modes, CASE_UPPER);

        /**
         * Filter the list of incompatible SQL modes to exclude.
         *
         * @param array $incompatible_modes An array of incompatible modes.
         */
        $incompatible_modes = (array)apply_filters('incompatible_sql_modes', $this->incompatible_modes);

        foreach ($modes as $i => $mode) {

            if (in_array($mode, $incompatible_modes, true)) {

                unset($modes[$i]);

            }

        }

        $modes_str = implode(',', $modes);

        mysqli_query($dbh, "SET SESSION sql_mode='{$modes_str}'");

    }

    /**
     * Load the column metadata from the last query.
     *
     * @since 1.0.0
     *
     * @access protected
     */
    protected function load_col_info(): void
    {

        // Bail if not enough info
        if (!empty($this->col_info) || (false === $this->result)) {

            return;

        }

        $this->col_info = array();

        $num_fields = mysqli_num_fields($this->result);

        for ($i = 0; $i < $num_fields; $i++) {

            $this->col_info[$i] = mysqli_fetch_field($this->result);

        }

    }

    /**
     * Sets $this->charset and $this->collate
     *
     * @since 1.0.0
     *
     * @global array $wp_global
     */
    public function init_charset(): void
    {
        global $wp_version;

        // Defaults
        $charset = $collate = '';

        // Multisite
        if (function_exists('is_multisite') && is_multisite()) {

            if (version_compare($wp_version, '4.2', '<')) {

                $charset = 'utf8';

                $collate = 'utf8_general_ci';

            } else {

                $charset = 'utf8mb4';

                $collate = 'utf8mb4_unicode_520_ci';

            }

        }

        // Use constant if defined
        if (defined('DB_COLLATE')) {

            $collate = DB_COLLATE;

        }

        // Use constant if defined
        if (defined('DB_CHARSET')) {

            $charset = DB_CHARSET;

        }

        // determine_charset is only in WordPress 4.6
        if (method_exists($this, 'determine_charset')) {

            $determined = $this->determine_charset($charset, $collate);

            $charset = $determined['charset'];

            $collate = $determined['collate'];

            unset($determined);

        }

        // Set charset & collation
        $this->charset = $charset;

        $this->collate = $collate;

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
     * @param string $string String to escape.
     * @since 1.0.0
     */
    public function _real_escape($string): string
    { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore

        // Slash the query part
        $escaped = addslashes($string);

        // Maybe use WordPress core placeholder method
        if (method_exists($this, 'add_placeholder_escape')) {

            $escaped = $this->add_placeholder_escape($escaped);

        }

        return $escaped;
    }


    /**
     * Sets the connection's character set
     *
     * @param mysqli $dbh The resource given by mysql_connect
     * @param string $charset The character set (optional)
     * @param string $collate The collation (optional)
     * @since 1.0.0
     *
     */
    public function set_charset($dbh, $charset = null, $collate = null): void
    {
        if (!isset($charset)) {

            $charset = $this->charset;

        }

        if (!isset($collate)) {

            $collate = $this->collate;

        }

        if (empty($charset) || empty($collate)) {

            /** @noinspection ForgottenDebugOutputInspection */
            wp_die("{$charset}  {$collate}");

        }

        if (!in_array(strtolower($charset), array('utf8', 'utf8mb4', 'latin1'), true)) {

            /** @noinspection ForgottenDebugOutputInspection */
            wp_die("{$charset} charset isn't supported in LudicrousDB for security reasons");

        }

        if (!empty($charset) && $this->has_cap('collation')) {

            $set_charset_succeeded = true;

            if (function_exists('mysqli_set_charset') && $this->has_cap('set_charset')) {

                $set_charset_succeeded = mysqli_set_charset($dbh, $charset);

            }

            if ($set_charset_succeeded) {

                $query = $this->prepare('SET NAMES %s', $charset);

                if (!empty($collate)) {

                    $query .= $this->prepare(' COLLATE %s', $collate);

                }

                $this->_do_query($query, $dbh);

            }

        }
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
     * @param bool $allow_bail Optional. Allows the function to bail. Default true.
     * @param bool $dbh_or_table Optional.
     * @param string $query Optional. Query string passed db_connect
     *
     * @return bool|void True if the connection is up.
     * @since 1.0.0
     *
     */
    public function check_connection($allow_bail = true, mixed $dbh_or_table = false, string $query = '')
    {
        try {

            $dbh = $this->get_db_object($dbh_or_table);

            if ($this->dbh_type_check($dbh)
                && mysqli_ping($dbh)) {

                return true;

            }

            if (false === $allow_bail) {

                return false;

            }

            $error_reporting = false;

            // Disable warnings, as we don't want to see a multitude of "unable to connect" messages
            if (WP_DEBUG) {

                $error_reporting = error_reporting();

                error_reporting($error_reporting & ~E_WARNING);

            }

            for ($tries = 1; $tries <= $this->reconnect_retries; $tries++) {

                // On the last try, re-enable warnings. We want to see a single instance of the
                // "unable to connect" message on the bail() screen, if it appears.
                if ($this->reconnect_retries === $tries && WP_DEBUG) {
                    error_reporting($error_reporting);
                }

                if ($this->db_connect($query)) {
                    if ($error_reporting) {
                        error_reporting($error_reporting);
                    }

                    return true;
                }

                sleep(1);
            }

            // If template_redirect has already happened, it's too late for wp_die()/dead_db().
            // Let's just return and hope for the best.
            if (did_action('template_redirect')) {
                return false;
            }

            wp_load_translations_early();

            $message = '<h1>' . __('Error reconnecting to the database', 'ludicrousdb') . "</h1>\n";
            $message .= '<p>' . sprintf(
                /* translators: %s: database host */
                    __('This means that we lost contact with the database server at %s. This could mean your host&#8217;s database server is down.', 'ludicrousdb'),
                    '<code>' . htmlspecialchars($this->dbhost, ENT_QUOTES) . '</code>'
                ) . "</p>\n";
            $message .= "<ul>\n";
            $message .= '<li>' . __('Are you sure that the database server is running?', 'ludicrousdb') . "</li>\n";
            $message .= '<li>' . __('Are you sure that the database server is not under particularly heavy load?', 'ludicrousdb') . "</li>\n";
            $message .= "</ul>\n";
            $message .= '<p>' . sprintf(
                /* translators: %s: support forums URL */
                    __('If you&#8217;re unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href="%s">WordPress Support Forums</a>.', 'ludicrousdb'),
                    __('https://wordpress.org/support/', 'ludicrousdb')
                ) . "</p>\n";

            // We weren't able to reconnect, so we better bail.
            throw new PublicAlert($message, 'db_connect_fail');

        } catch (Throwable $e) {

            ThrowableHandler::generateLogAndExit($e);

        }

    }

    /**
     * Basic query. See documentation for more details.
     *
     * @param string $query Query.
     *
     * @return int number of rows
     * @since 1.0.0
     *
     */
    public function query($query): bool|int|mysqli_result|null
    {

        // initialise return
        $return_val = 0;

        $this->flush();

        // Some queries are made before plugins are loaded
        if (function_exists('apply_filters')) {

            /**
             * Filter the database query.
             *
             * Some queries are made before the plugins have been loaded,
             * and thus cannot be filtered with this method.
             *
             * @param string $query Database query.
             */
            $query = apply_filters('query', $query);
        }

        // Some queries are made before plugins are loaded
        if (function_exists('apply_filters_ref_array')) {

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
             * @param string      null   The filtered return value. Default is null.
             * @param string $query Database query.
             * @param LudicrousDB &$this Current instance of LudicrousDB, passed by reference.
             * @since 4.0.0
             *
             */
            $return_val = apply_filters_ref_array('pre_query', array(null, $query, &$this));
            if (null !== $return_val) {
                Functions::run_callbacks($this, 'sql_query_log', array($query, $return_val, $this->last_error));

                return $return_val;
            }
        }

        // Bail if query is empty (via application error or 'query' filter)
        if (empty($query)) {

            Functions::run_callbacks($this, 'sql_query_log', array($query, $return_val, $this->last_error));

            return $return_val;

        }

        // Log how the function was called
        $this->func_call = "\$db->query(\"{$query}\")";

        // If we're writing to the database, make sure the query will write safely.
        if ($this->check_current_query && !$this->check_ascii($query)) {
            $stripped_query = $this->strip_invalid_text_from_query($query);

            // strip_invalid_text_from_query() can perform queries, so we need
            // to flush again, just to make sure everything is clear.
            $this->flush();

            if ($stripped_query !== $query) {
                $this->insert_id = 0;
                $return_val = false;
                Functions::run_callbacks($this, 'sql_query_log', array($query, $return_val, $this->last_error));

                return $return_val;
            }
        }

        $this->check_current_query = true;

        // Keep track of the last query for debug..
        $this->last_query = $query;

        if (preg_match('/^\s*SELECT\s+FOUND_ROWS(\s*)/i', $query)
            &&
            (
                (
                    (false === $this->use_mysqli)
                    &&
                    is_resource($this->last_found_rows_result)
                )
                ||
                (
                    (true === $this->use_mysqli)
                    &&
                    ($this->last_found_rows_result instanceof mysqli_result)
                )
            )
        ) {
            $this->result = $this->last_found_rows_result;
            $elapsed = 0;
        } else {

            $this->dbh = $this->db_connect($query);

            if (!$this->dbh_type_check($this->dbh)) {

                Functions::run_callbacks($this, 'sql_query_log', array($query, $return_val, $this->last_error));

                return false;
            }

            $this->timer_start();

            $this->result = $this->_do_query($query, $this->dbh);

            $elapsed = $this->timer_stop();

            ++$this->num_queries;

            if (preg_match('/^\s*SELECT\s+SQL_CALC_FOUND_ROWS\s/i', $query)) {
                if (false === strpos($query, 'NO_SELECT_FOUND_ROWS')) {
                    $this->timer_start();
                    $this->last_found_rows_result = $this->_do_query('SELECT FOUND_ROWS()', $this->dbh);
                    $elapsed += $this->timer_stop();
                    ++$this->num_queries;
                    $query .= '; SELECT FOUND_ROWS()';
                }
            } else {
                $this->last_found_rows_result = null;
            }

            /** @noinspection PhpUndefinedConstantInspection */
            if (!empty($this->save_queries) || (defined('SAVEQUERIES') && SAVEQUERIES)) {
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
        if ($this->dbh_type_check($this->dbh)) {

            $this->last_error = mysqli_error($this->dbh);

        }

        if (!empty($this->last_error)) {

            $this->print_error($this->last_error);

            $return_val = false;

            Functions::run_callbacks($this, 'sql_query_log', array($query, $return_val, $this->last_error));

            return $return_val;
        }

        if (preg_match('/^\s*(create|alter|truncate|drop)\s/i', $query)) {
            $return_val = $this->result;
        } elseif (preg_match('/^\\s*(insert|delete|update|replace|alter) /i', $query)) {

            $this->rows_affected = mysqli_affected_rows($this->dbh);


            // Take note of the insert_id
            if (preg_match('/^\s*(insert|replace)\s/i', $query)) {

                $this->insert_id = mysqli_insert_id($this->dbh);

            }

            // Return number of rows affected
            $return_val = $this->rows_affected;
        } else {

            $num_rows = 0;

            $this->last_result = array();

            if ($this->result instanceof mysqli_result) {

                $this->load_col_info();

                while ($row = mysqli_fetch_object($this->result)) {

                    $this->last_result[$num_rows] = $row;

                    $num_rows++;

                }

            }

            // Log number of rows the query returned
            // and return number of rows selected
            $this->num_rows = $num_rows;

            $return_val = $num_rows;

        }

        Functions::run_callbacks($this, 'sql_query_log', array($query, $return_val, $this->last_error));

        // Some queries are made before plugins are loaded
        if (function_exists('do_action_ref_array')) {

            /**
             * Runs after a query is finished.
             *
             * @param string $query Database query.
             * @param LudicrousDB &$this Current instance of LudicrousDB, passed by reference.
             * @since 4.0.0
             *
             */
            do_action_ref_array('queried', array($query, &$this));
        }

        return $return_val;
    }


    /**
     * Selects a database using the current database connection
     *
     * The database name will be changed based on the current database
     * connection. On failure, the execution will bail and display an DB error
     *
     * @param string $db MySQL database name
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     * @since 1.0.0
     *
     */
    public function select($db, mixed $dbh_or_table = false)
    {
        $dbh = $this->get_db_object($dbh_or_table);

        if (!$this->dbh_type_check($dbh)) {
            return false;
        }

        return mysqli_select_db($dbh, $db);

    }

    /**
     * Closes the current database connection
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     *
     * @return bool True if the connection was successfully closed, false if it wasn't,
     *              or the connection doesn't exist.
     * @since 1.0.0
     *
     */
    public function close(mixed $dbh_or_table = false)
    {
        $dbh = $this->get_db_object($dbh_or_table);

        if (!$this->dbh_type_check($dbh)) {
            return false;
        }

        $closed = mysqli_close($dbh);

        if (!empty($closed)) {

            $this->dbh = null;

        }

        return $closed;
    }

    /**
     * Whether or not MySQL database is at least the required minimum version.
     * The additional argument allows the caller to check a specific database
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     * @global $required_mysql_version
     * @since 1.0.0
     * @global $wp_version
     */
    public function check_database_version(mixed $dbh_or_table = false): void
    {
        try {

            global $wp_version, $required_mysql_version;

            // Make sure the server has the required MySQL version
            $mysql_version = preg_replace('|[^0-9\.]|', '', $this->db_version($dbh_or_table));

            if (version_compare($mysql_version, $required_mysql_version, '<')) {
                // translators: 1. WordPress version, 2. MySql Version.
                throw new PublicAlert('database_version', sprintf(__('<strong>ERROR</strong>: WordPress %1$s requires MySQL %2$s or higher', 'ludicrousdb'), $wp_version, $required_mysql_version));

            }

        } catch (\Throwable $e) {

            ThrowableHandler::generateLogAndExit($e);

        }

    }

    /**
     * This function is called when WordPress is generating the table schema to determine whether or not the current database
     * supports or needs the collation statements
     *
     * The additional argument allows the caller to check a specific database
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     *
     * @return bool
     * @since 1.0.0
     *
     */
    public function supports_collation(mixed $dbh_or_table = false): bool
    {
        _deprecated_function(__FUNCTION__, '3.5', 'wpdb::has_cap( \'collation\' )');

        return $this->has_cap('collation', $dbh_or_table);
    }

    /**
     * Generic function to determine if a database supports a particular feature
     * The additional argument allows the caller to check a specific database
     *
     * @param string $db_cap the feature
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     *
     * @return bool
     * @since 1.0.0
     *
     */
    public function has_cap($db_cap, mixed $dbh_or_table = false): bool
    {
        $version = $this->db_version($dbh_or_table);

        switch (strtolower($db_cap)) {
            case 'collation':    // @since 2.5.0
            case 'group_concat': // @since 2.7.0
            case 'subqueries':   // @since 2.7.0
                return version_compare($version, '4.1', '>=');
            case 'set_charset':
                return version_compare($version, '5.0.7', '>=');
            case 'utf8mb4':      // @since 4.1.0
                if (version_compare($version, '5.5.3', '<')) {
                    return false;
                }

                $dbh = $this->get_db_object($dbh_or_table);

                if ($this->dbh_type_check($dbh)) {

                    $client_version = mysqli_get_client_info($dbh);

                    /*
					 * libmysql has supported utf8mb4 since 5.5.3, same as the MySQL server.
					 * mysqlnd has supported utf8mb4 since 5.0.9.
					 */
                    if (str_contains($client_version, 'mysqlnd')) {

                        $client_version = preg_replace('/^\D+([\d.]+).*/', '$1', $client_version);

                        return version_compare($client_version, '5.0.9', '>=');

                    }

                    return version_compare($client_version, '5.5.3', '>=');

                }

                break;

            case 'utf8mb4_520': // @since 4.6.0
                return version_compare($version, '5.6', '>=');

        }

        return false;
    }

    /**
     * Retrieve the name of the function that called wpdb.
     *
     * Searches up the list of functions until it reaches
     * the one that would most logically had called this method.
     *
     * @return string Comma separated list of the calling functions.
     * @since 2.5.0
     *
     */
    public function get_caller(): null|string|array
    {
        return (true === $this->save_backtrace)
            ? wp_debug_backtrace_summary(__CLASS__)
            : null;
    }

    /**
     * The database version number
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     *
     * @return false|string false on failure, version number on success
     * @since 1.0.0
     *
     */
    public function db_version(mixed $dbh_or_table = false): string|null
    {
        return preg_replace('/[^0-9.].*/', '', $this->db_server_info($dbh_or_table));
    }

    /**
     * Retrieves full MySQL server information.
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     *
     * @return string|false Server info on success, false on failure.
     * @since 5.0.0
     *
     */
    public function db_server_info(mixed $dbh_or_table = false): bool|string
    {
        $dbh = $this->get_db_object($dbh_or_table);

        if (!$this->dbh_type_check($dbh)) {
            return false;
        }

        return mysqli_get_server_info($dbh);
    }

    /**
     * Logs query data.
     *
     * @param string $query The query's SQL.
     * @param float $query_time Total time spent on the query, in seconds.
     * @param string $query_callstack Comma separated list of the calling functions.
     * @param float $query_start Unix timestamp of the time at the start of the query.
     * @param array $query_data Custom query data.
     * }
     * @since 4.3.0
     *
     */
    public function log_query($query = '', $query_time = 0, $query_callstack = '', $query_start = 0, $query_data = array()): void
    {

        /**
         * Filters the custom query data being logged.
         *
         * Caution should be used when modifying any of this data, it is recommended that any additional
         * information you need to store about a query be added as a new associative entry to the fourth
         * element $query_data.
         *
         * @param array $query_data Custom query data.
         * @param string $query The query's SQL.
         * @param float $query_time Total time spent on the query, in seconds.
         * @param string $query_callstack Comma separated list of the calling functions.
         * @param float $query_start Unix timestamp of the time at the start of the query.
         * @since 5.3.0
         *
         */
        $query_data = apply_filters('log_query_custom_data', $query_data, $query, $query_time, $query_callstack, $query_start);

        // Pass to custom callback or just save it.
        $this->queries[] = is_callable($this->save_query_callback)
            ? call_user_func_array(
                $this->save_query_callback,
                array(
                    $query,
                    $query_time,
                    $query_callstack,
                    $query_start,
                    $query_data,
                    &$this,
                )
            )
            : array(
                $query,
                $query_time,
                $query_callstack,
                $query_start,
                $query_data,
            );


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
    protected function get_table_charset($table): string
    {
        try {

            $tablekey = strtolower($table);

            /**
             * Filter the table charset value before the DB is checked.
             *
             * Passing a non-null value to the filter will effectively short-circuit
             * checking the DB for the charset, returning that value instead.
             *
             * @param string $charset The character set to use. Default null.
             * @param string $table The name of the table being checked.
             */
            $charset = apply_filters('pre_get_table_charset', null, $table);

            if (null !== $charset) {

                return $charset;

            }

            if (isset($this->table_charset[$tablekey])) {

                return $this->table_charset[$tablekey];

            }

            $charsets = $columns = array();

            $table_parts = explode('.', $table);
            $table = '`' . implode('`.`', $table_parts) . '`';
            $results = $this->get_results("SHOW FULL COLUMNS FROM {$table}");

            if (empty($results)) {
                throw new PublicAlert('wpdb_get_table_charset_failure');
            }

            foreach ($results as $column) {
                $columns[strtolower($column->Field)] = $column; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
            }

            $this->col_meta[$tablekey] = $columns;

            foreach ($columns as $column) {
                if (!empty($column->Collation)) {  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    [$charset] = explode('_', $column->Collation);  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

                    // If the current connection can't support utf8mb4 characters, let's only send 3-byte utf8 characters.
                    if (('utf8mb4' === $charset) && !$this->has_cap('utf8mb4')) {
                        $charset = 'utf8';
                    }

                    $charsets[strtolower($charset)] = true;
                }

                [$type] = explode('(', $column->Type);  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

                // A binary/blob means the whole query gets treated like this.
                if (in_array(strtoupper($type), array(
                    'BINARY',
                    'VARBINARY',
                    'TINYBLOB',
                    'MEDIUMBLOB',
                    'BLOB',
                    'LONGBLOB',
                ), true)) {
                    $this->table_charset[$tablekey] = 'binary';

                    return 'binary';
                }
            }

            // utf8mb3 is an alias for utf8.
            if (isset($charsets['utf8mb3'])) {
                $charsets['utf8'] = true;
                unset($charsets['utf8mb3']);
            }

            // Check if we have more than one charset in play.
            $count = count($charsets);
            if (1 === $count) {
                $charset = key($charsets);

                // No charsets, assume this table can store whatever.
            } elseif (0 === $count) {
                $charset = false;

                // More than one charset. Remove latin1 if present and recalculate.
            } else {
                unset($charsets['latin1']);
                $count = count($charsets);

                // Only one charset (besides latin1).
                if (1 === $count) {
                    $charset = key($charsets);

                    // Two charsets, but they're utf8 and utf8mb4, use utf8.
                } elseif (2 === $count && isset($charsets['utf8'], $charsets['utf8mb4'])) {
                    $charset = 'utf8';

                    // Two mixed character sets. ascii.
                } else {
                    $charset = 'ascii';
                }
            }

            $this->table_charset[$tablekey] = $charset;

            return $charset;

        } catch (Throwable $e) {

            ThrowableHandler::generateLogAndExit($e);

        }

    }

}