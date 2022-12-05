<?php

namespace ScalingWPDB;

use CarbonPHP\Error\PublicAlert;
use CarbonPHP\Error\ThrowableHandler;
use mysqli;
use Throwable;
use wpdb;

/**
 * The main LudicrousDB class, which extends wpdb
 *
 * @since 1.0.0
 */
class ScalingWPDB extends JitConnect
{

    /**
     * Set a flag to prevent reading from slaves which might be lagging after a write
     *
     * @since 1.0.0
     */
    public function send_reads_to_masters()
    {
        $this->srtm = true;
    }


    /**
     * Connect selected database
     *
     * @param string $dbhname Database name.
     * @param string $host Internet address: host:port of server on internet.
     * @param string $user Database user.
     * @param string $password Database password.
     *
     * @return bool|mysqli|resource
     * @since 1.0.0
     *
     */
    protected function single_db_connect($dbhname, $host, $user, $password): mysqli|bool
    {
        try {

            /** @noinspection PhpUndefinedConstantInspection */
            $client_flags = defined('MYSQL_CLIENT_FLAGS') ? MYSQL_CLIENT_FLAGS : 0;

            $this->dbhs[$dbhname] = mysqli_init();

            // mysqli_real_connect doesn't support the host param including a port or socket
            // like mysql_connect does. This duplicates how mysql_connect detects a port and/or socket file.
            $port = null;

            $socket = null;

            $port_or_socket = strstr($host, ':');

            if (!empty($port_or_socket)) {

                $host = substr($host, 0, strpos($host, ':'));

                $port_or_socket = substr($port_or_socket, 1);

                if (!str_starts_with($port_or_socket, '/')) {

                    $port = (int)$port_or_socket;

                    $maybe_socket = strstr($port_or_socket, ':');

                    if (!empty($maybe_socket)) {

                        $socket = substr($maybe_socket, 1);

                    }

                } else {

                    $socket = $port_or_socket;

                }

            }

            // Detail found here - https://core.trac.wordpress.org/ticket/31018
            $pre_host = '';

            // If DB_HOST begins with a 'p:', allow it to be passed to mysqli_real_connect().
            // mysqli supports persistent connections starting with PHP 5.3.0.
            if ((true === $this->persistent) && PHP_VERSION_ID >= 50300) {
                $pre_host = 'p:';
            }

            mysqli_real_connect($this->dbhs[$dbhname], $pre_host . $host, $user, $password, null, $port, $socket, $client_flags);

            if ($this->dbhs[$dbhname]->connect_errno) {

                $this->dbhs[$dbhname] = false;

                return false;

            }

            return $this->dbhs[$dbhname];

        } catch (Throwable $e) {

            ThrowableHandler::generateLogAndExit($e);

        }

    }


    /**
     * Disconnect and remove connection from open connections list
     *
     * @param string $dbhname Dataname key name.
     * @since 1.0.0
     *
     */
    public function disconnect(string $dbhname): void
    {
        $k = array_search($dbhname, $this->open_connections, true);

        if (!empty($k)) {

            unset($this->open_connections[$k]);

        }

        if ($this->dbh_type_check($this->dbhs[$dbhname])) {

            $this->close($this->dbhs[$dbhname]);

        }

        unset($this->dbhs[$dbhname]);
    }


    /**
     * Check the responsiveness of a TCP/IP daemon
     *
     * @param string $host Host.
     * @param int $port Port or socket.
     * @param float $float_timeout Timeout as float number.
     * @return bool true when $host:$post responds within $float_timeout seconds, else false
     * @since 1.0.0
     *
     */
    public function check_tcp_responsiveness(string $host, int $port, float $float_timeout): bool
    {

        // Get the cache key
        $cache_key = $this->tcp_get_cache_key($host, $port);

        // Persistent cached value exists
        $cached_value = $this->tcp_cache_get($cache_key);

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
        $errno = 0;
        $errstr = '';

        // Try to get a new socket
        $socket = (WP_DEBUG)
            ? fsockopen($host, $port, $errno, $errstr, $float_timeout)
            : @fsockopen($host, $port, $errno, $errstr, $float_timeout); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        // No socket
        if (false === $socket) {

            $this->tcp_cache_set($cache_key, 'down');

            return "[ > {$float_timeout} ] ({$errno}) '{$errstr}'";

        }

        // Close the socket
        fclose($socket);

        // Using API
        $this->tcp_cache_set($cache_key, 'up');

        return true;
    }


    /**
     * Should we try to ping the MySQL host?
     *
     * @param string $dbhname Database name.
     *
     * @return bool
     * @since 4.1.0
     *
     */
    public function should_mysql_ping($dbhname = ''): bool
    {

        // Bail early if no MySQL ping
        if (empty($this->check_dbh_heartbeats)) {
            return false;
        }

        // Shouldn't happen
        if (empty($dbhname) || empty($this->dbhname_heartbeats[$dbhname])) {
            return true;
        }

        // MySQL server has gone away
        if (!empty($this->dbhname_heartbeats[$dbhname]['last_errno']) && (DB_SERVER_GONE_ERROR === $this->dbhname_heartbeats[$dbhname]['last_errno'])) {
            unset($this->dbhname_heartbeats[$dbhname]['last_errno']);

            return true;
        }

        // More than 0.1 seconds of inactivity on that dbhname
        if (microtime(true) - $this->dbhname_heartbeats[$dbhname]['last_used'] > $this->recheck_timeout) {
            return true;
        }

        return false;
    }

    /**
     * Run lag callbacks and return current lag
     *
     * @return int
     * @since 2.1.0
     *
     */
    public function get_lag(): int
    {
        $this->lag = Functions::run_callbacks($this,'get_lag');

        return $this->check_lag();
    }

    /**
     * Check lag
     *
     * @return int
     * @since 2.1.0
     *
     */
    public function check_lag(): int
    {
        if (false === $this->lag) {
            return DB_LAG_UNKNOWN;
        }

        if ($this->lag > $this->lag_threshold) {
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
     * @param string $string String to convert
     * @param string $charset Character set to test against (uses MySQL character set names)
     *
     * @return string The converted string
     * @since 1.0.0
     *
     * @noinspection PhpUnused
     */
    protected function strip_invalid_text_using_db(string $string, string $charset): string
    {
        try {

            $query = $this->prepare("SELECT CONVERT( %s USING {$charset} )", $string);

            $result = $this->_do_query($query, $this->dbh);

            // Bail with error if no result
            if (empty($result)) {

                throw new PublicAlert('wpdb_convert_text_failure');

            }

            // Fetch row
            $row = mysqli_fetch_row($result);

            // Bail with error if no rows
            if (!is_array($row) || count($row) < 1) {

                throw new PublicAlert('wpdb_convert_text_failure');

            }

            return $row[0];

        } catch (Throwable $e) {

            ThrowableHandler::generateLogAndExit($e);

        }

    }

    /** TCP Cache *************************************************************/

    /**
     * Get the cache key used for TCP responses
     *
     * @param string $host Host
     * @param string $port Port or socket.
     *
     * @return string
     * @since 3.0.0
     *
     */
    protected function tcp_get_cache_key(string $host, string $port): string
    {
        return "{$host}:{$port}";
    }

    /**
     * Get the number of seconds TCP response is good for
     *
     * @return int
     * @since 3.0.0
     *
     */
    protected function tcp_get_cache_expiration(): int
    {
        return 10;
    }

    /**
     * Check if TCP is using a persistent cache or not.
     *
     * @return bool True if yes. False if no.
     * @since 5.1.0
     *
     */
    protected function tcp_is_cache_persistent(): bool
    {

        // Check if using external object cache
        if (wp_using_ext_object_cache()) {

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
     * @param string $key Results of tcp_get_cache_key()
     *
     * @return mixed Results of wp_cache_get()
     * @since 3.0.0
     *
     */
    protected function tcp_cache_get(string $key = ''): mixed
    {

        // Bail for invalid key
        if (empty($key)) {

            return false;

        }

        // Get from persistent cache
        if ($this->tcp_is_cache_persistent()) {

            return wp_cache_get($key, $this->cache_group);

        }

        // Fallback to local cache
        if (!empty($this->tcp_cache[$key])) {

            // Not expired
            if (!empty($this->tcp_cache[$key]['expiration']) && (time() < $this->tcp_cache[$key]['expiration'])) {

                // Return value or false if empty
                return !empty($this->tcp_cache[$key]['value'])
                    ? $this->tcp_cache[$key]['value']
                    : false;

                // Expired, so delete and proceed
            }

            $this->tcp_cache_delete($key);
        }

        return false;
    }

    /**
     * Set cached up/down value of current TCP response.
     *
     * Falls back to local cache if persistent cache is not available.
     *
     * @param string $key Results of tcp_get_cache_key()
     * @param string $value "up" or "down" based on TCP response
     *
     * @return bool Results of wp_cache_set() or true
     * @since 3.0.0
     *
     */
    protected function tcp_cache_set(string $key = '', string $value = ''): bool
    {

        // Bail if invalid values were passed
        if (empty($key) || empty($value)) {

            return false;

        }

        // Get expiration
        $expires = $this->tcp_get_cache_expiration();

        // Add to persistent cache
        if ($this->tcp_is_cache_persistent()) {

            return wp_cache_set($key, $value, $this->cache_group, $expires);

        }

        // Fallback to local cache
        $this->tcp_cache[$key] = array(
            'value' => $value,
            'expiration' => time() + $expires
        );

        return true;
    }

    /**
     * Delete cached up/down value of current TCP response.
     *
     * Falls back to local cache if persistent cache is not available.
     *
     * @param string $key Results of tcp_get_cache_key()
     *
     * @return bool Results of wp_cache_delete() or true
     * @since 5.1.0
     *
     */
    protected function tcp_cache_delete(string $key = ''): bool
    {

        // Bail if invalid key
        if (empty($key)) {

            return false;

        }

        // Delete from persistent cache
        if ($this->tcp_is_cache_persistent()) {

            return wp_cache_delete($key, $this->cache_group);

        }

        // Fallback to local cache
        unset($this->tcp_cache[$key]);

        return true;
    }

    /**
     * Add global cache group.
     *
     * Only run once, as that is all that is required.
     *
     * @since 4.3.0
     */
    protected function add_global_group(): void
    {
        static $added = null;

        // Bail if added or caching not available yet
        if (true === $added) {

            return;

        }

        // Add the cache group
        if (function_exists('wp_cache_add_global_groups')) {

            wp_cache_add_global_groups($this->cache_group);

        }

        // Set added
        $added = true;
    }


}
