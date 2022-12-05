<?php

namespace ScalingWPDB;

use mysqli;
use wpdb;

abstract class Info extends wpdb
{
    use Variables;

    abstract public function connectBasedOnQuery(string $query): mixed;

    /**
     * Check databse object type.
     *
     * @param resource|mysqli $dbh Database resource.
     *
     * @return bool
     * @since 1.0.0
     *
     */
    public function dbh_type_check($dbh): bool
    {
        return $dbh instanceof mysqli;
    }

    /**
     * Get the db connection object
     *
     * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
     * @since 1.0.0
     *
     */
    protected function get_db_object(mixed $dbh_or_table = false): bool|string|mysqli|null
    {

        // No database
        $dbh = false;

        // Database
        if ($this->dbh_type_check($dbh_or_table)) {

            $dbh = &$dbh_or_table; // & is unnecessary?

            // Database
        } elseif ((false === $dbh_or_table) && $this->dbh_type_check($this->dbh)) {

            $dbh = &$this->dbh;

            // Table name
        } elseif (is_string($dbh_or_table)) {

            /** @noinspection SqlDialectInspection */
            /** @noinspection SqlNoDataSourceInspection */
            $dbh = $this->connectBasedOnQuery("SELECT FROM {$dbh_or_table} {$this->users}");

        }

        return $dbh;

    }

    /**
     * Internal function to perform the mysql_query() call
     *
     * @param string $query The query to run.
     * @param bool $dbh_or_table Database or table name. Defaults to false.
     * @since 1.0.0
     *
     * @access protected
     * @see wpdb::query()
     *
     */
    protected function _do_query(string $query, mixed $dbh_or_table = false)
    { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
        $dbh = $this->get_db_object($dbh_or_table);

        if (!$this->dbh_type_check($dbh)) {
            return false;
        }

        $result = mysqli_query($dbh, $query);

        // Maybe log last used to heartbeats
        if (!empty($this->check_dbh_heartbeats)) {

            // Lookup name
            $name = $this->lookup_dbhs_name($dbh);

            // Set last used for this dbh
            if (!empty($name)) {
                $this->dbhname_heartbeats[$name]['last_used'] = microtime(true);
            }
        }

        return $result;
    }

    /**
     * Find a dbh name value for a given $dbh object.
     *
     * @param object $dbh The dbh object for which to find the dbh name
     *
     * @return string The dbh name
     * @since 5.0.0
     *
     */
    protected function lookup_dbhs_name($dbh = false): bool|string
    {

        // Loop through database hosts and look for this one
        foreach ($this->dbhs as $dbhname => $other_dbh) {

            // Match found so return the key
            if ($dbh === $other_dbh) {
                return $dbhname;
            }
        }

        // No match
        return false;
    }

    /**
     * Add the connection parameters for a database
     *
     * @param array $db Default to empty array.
     * @since 1.0.0
     *
     */
    public function add_database(array $db = array()): void
    {

        // Setup some sane default values
        $database_defaults = array(
            'dataset' => 'global',
            'write' => 1,
            'read' => 1,
            'timeout' => 0.2,
            'port' => 3306,
            'lag_threshold' => null,
        );

        // Merge using defaults
        $db = wp_parse_args($db, $database_defaults);

        // Break these apart to make code easier to understand below
        $dataset = $db['dataset'];

        $read = $db['read'];

        $write = $db['write'];

        // We do not include the dataset in the array. It's used as a key.
        unset($db['dataset']);

        // Maybe add database to array of read's
        if (!empty($read)) {

            $this->ludicrous_servers[$dataset]['read'][$read][] = $db;

        }

        // Maybe add database to array of write's
        if (!empty($write)) {

            $this->ludicrous_servers[$dataset]['write'][$write][] = $db;

        }

    }

    /**
     * Specify the dataset where a table is found
     *
     * @param string $dataset Database.
     * @param string $table Table name.
     * @since 1.0.0
     *
     */
    public function add_table($dataset, $table): void
    {
        $this->ludicrous_tables[$table] = $dataset;
    }

    /**
     * Add a callback to a group of callbacks
     *
     * The default group is 'dataset', used to examine queries & determine dataset
     *
     * @param function $callback Callback on a dataset.
     * @param string $group Key name of dataset.
     * @since 1.0.0
     *
     */
    public function add_callback($callback, $group = 'dataset'): void
    {
        $this->ludicrous_callbacks[$group][] = $callback;
    }


}