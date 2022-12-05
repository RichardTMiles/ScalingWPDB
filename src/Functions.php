<?php

namespace ScalingWPDB;

use wpdb;

/**
 * LudicrousDB Functions
 *
 * @package Plugins/LudicrousDB/Functions
 */
abstract class Functions
{

    /**
     * Determine the likelihood that this query could alter anything
     *
     * Statements are considered read-only when:
     * 1. not including UPDATE nor other "may-be-write" strings
     * 2. begin with SELECT etc.
     *
     * @param string $q Query.
     *
     * @return bool
     * @since 1.0.0
     *
     */
    public static function is_write_query($q = '')
    {

        // Trim potential whitespace or subquery chars
        $q = ltrim($q, "\r\n\t (");

        // Possible writes
        if (preg_match('/(?:^|\s)(?:ALTER|CREATE|ANALYZE|CHECK|OPTIMIZE|REPAIR|CALL|DELETE|DROP|INSERT|LOAD|REPLACE|UPDATE|SET|RENAME\s+TABLE)(?:\s|$)/i', $q)) {
            return true;
        }

        // Not possible non-writes (phew!)
        return !preg_match('/^(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)(?:\s|$)/i', $q);
    }
    /**
     * Set the default constants for LudicrousDB
     *
     * @since 3.0.0
     */
    public static function ldb_default_constants(): void
    {

        // Database Lag
        define('DB_LAG_OK', 1);
        define('DB_LAG_BEHIND', 2);
        define('DB_LAG_UNKNOWN', 3);
        define('DB_SERVER_GONE_ERROR', 2006);

        // The config file was defined earlier.
        if (defined('DB_CONFIG_FILE') && file_exists(DB_CONFIG_FILE)) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedIf
            // Do nothing here
            return;
            // The config file resides in ABSPATH.
        }

        if (file_exists(ABSPATH . 'db-config.php')) {

            define('DB_CONFIG_FILE', ABSPATH . 'db-config.php');

            // The config file resides in WP_CONTENT_DIR.
        } elseif (file_exists(WP_CONTENT_DIR . '/db-config.php')) {

            define('DB_CONFIG_FILE', WP_CONTENT_DIR . '/db-config.php');

            // The config file resides one level above ABSPATH but is not part of
            // another install.
        } elseif (file_exists(dirname(ABSPATH) . '/db-config.php') && !file_exists(dirname(ABSPATH) . '/wp-settings.php')) {

            define('DB_CONFIG_FILE', dirname(ABSPATH) . '/db-config.php');

        }

    }

    /**
     * Convenience function to add a database table
     *
     * This is backwards-compatible with a procedural config style.
     *
     * @param string $dataset Database.
     * @param string $table Table name.
     */
    public static function ldb_add_db_table($dataset, $table)
    {
        $GLOBALS['wpdb']->add_table($dataset, $table);
    }

    /**
     * Convenience function to add a database server.
     *
     * This is backwards-compatible with a procedural config style.
     *
     * lhost, part, and dc were removed from LudicrousDB because the read and write
     * parameters provide enough power to achieve the desired effects via config.
     *
     * @param string $dataset Dataset:          The name of the dataset. Just use "global" if you don't need horizontal partitioning.
     * @param int $part Partition:        The vertical partition number (1, 2, 3, etc.). Use "0" if you don't need vertical partitioning.
     * @param string $dc Datacenter:       Where the database server is located. Airport codes are convenient. Use whatever.
     * @param int $read Read group:       Tries all servers in lowest number group before trying higher number group. Typical: 1 for slaves, 2 for master. This will cause reads to go to slaves.
     * @param bool $write Write flag:       Is this server writable? Works the same as $read. Typical: 1 for master, 0 for slaves.
     * @param string $host Internet address: host:port of server on Internet.
     * @param string $lhost Local address:    host:port of server for use when in same datacenter. Leave empty if no local address exists.
     * @param string $name Database name.
     * @param string $user Database user.
     * @param string $password Database password.
     * @param int $timeout Timeout.
     */
    public static function ldb_add_db_server($dataset, $part, $dc, $read, $write, $host, $lhost, $name, $user, $password, $timeout = 0.2)
    {

        // dc is not used in LudicrousDB. This produces the desired effect of
        // trying to connect to local servers before remote servers. Also
        // increases time allowed for TCP responsiveness check.
        if (!empty($dc) && (defined('DATACENTER') && (DATACENTER !== $dc))) {
            $read += 10000;
            $write += 10000;
            $timeout = 0.7;
        }

        // Maybe add part to dataset
        if (!empty($part)) {
            $dataset = "{$dataset}_{$part}";
        }

        // Put variables into array
        $database = compact('dataset', 'read', 'write', 'host', 'name', 'user', 'password', 'timeout');

        // Add database array to main object
        $GLOBALS['wpdb']->add_database($database);

        // Current datacenter
        if (defined('DATACENTER') && (DATACENTER === $dc)) {

            // lhost is not used in LudicrousDB. This configures LudicrousDB with an
            // additional server to represent the local hostname so it tries to
            // connect over the private interface before the public one.
            if (!empty($lhost)) {

                $database['host'] = $lhost;

                if (!empty($read)) {
                    $database['read'] = $read - 0.5;
                }

                if (!empty($write)) {
                    $database['write'] = $write - 0.5;
                }

                $GLOBALS['wpdb']->add_database($database);
            }
        }
    }

    /**
     * Callbacks are executed in the order in which they are registered until one
     * of them returns something other than null
     *
     * @param string $group Group, key name in array.
     * @param array $args Args passed to callback. Default to null.
     * @since 1.0.0
     */
    public static function run_callbacks(wpdb|ScalingWPDB $wpdb, string $group, array $args = []): mixed
    {
        if (!isset($wpdb->ludicrous_callbacks[$group]) || !is_array($wpdb->ludicrous_callbacks[$group])) {

            return null;

        }

        $args[] = &$wpdb;

        foreach ($wpdb->ludicrous_callbacks[$group] as $func) {

            $result = call_user_func_array($func, $args);

            if (null !== $result) {

                return $result;

            }

        }

        return null;

    }

    /**
     * Run lag cache callbacks and return current lag
     *
     * @param ScalingWPDB $wpdb
     * @return int
     * @since 2.1.0
     */
    public static function get_lag_cache(ScalingWPDB $wpdb): int
    {
        $wpdb->lag = self::run_callbacks($wpdb, 'get_lag_cache');

        return $wpdb->check_lag();
    }

}

