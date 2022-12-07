<?php

namespace ScalingWPDB;

use CarbonPHP\Error\PublicAlert;
use CarbonPHP\Error\ThrowableHandler;
use Throwable;

abstract class JitConnect extends Info
{

    /**
     * Figure out which db server should handle the query, and connect to it
     *
     * @param string $query Query.
     *
     * @return resource MySQL database connection
     * @since 1.0.0
     *
     */
    public function connectBasedOnQuery(string $query): mixed
    {

        try {

            // Bail if empty query
            if (empty($query)) {

                return false;

            }

            // can be empty/false if the query is e.g. "COMMIT"
            $this->table = $this->get_table_from_query($query);

            if (empty($this->table)) {

                $this->table = 'no-table';

            }

            $this->last_table = $this->table;

            // Use current table with no callback results
            if (isset($this->ludicrous_tables[$this->table])) {

                $dataset = $this->ludicrous_tables[$this->table];

                $this->callback_result = null;

                // Run callbacks and either extract or update dataset
            } else {

                // Run callbacks and get result
                $this->callback_result = Functions::run_callbacks($this, 'dataset', $query);

                // Set if not null
                if (null !== $this->callback_result) {

                    if (is_array($this->callback_result)) {

                        extract($this->callback_result, EXTR_OVERWRITE);

                    } else {

                        $dataset = $this->callback_result;

                    }

                }

            }

            if (!isset($dataset)) {
                $dataset = 'global';
            }

            if (empty($dataset)) {
                throw new PublicAlert("Unable to determine which dataset to query. ({$this->table})");
            }

            $this->dataset = $dataset;

            Functions::run_callbacks($this, 'dataset_found', $dataset);

            if (empty($this->ludicrous_servers)) {

                if ($this->dbh_type_check($this->dbh)) {

                    return $this->dbh;

                }

                if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME')) {

                    throw new PublicAlert('We were unable to query because there was no database defined.');

                }

                // Fallback to wpdb db_connect method.

                $this->dbuser = DB_USER;

                $this->dbpassword = DB_PASSWORD;

                $this->dbname = DB_NAME;

                $this->dbhost = DB_HOST;

                parent::db_connect();

                return $this->dbh;
            }

            // Determine whether the query must be sent to the master (a writable server)
            if ($this->srtm === true || isset($this->srtm[$this->table])) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
                $use_master = true;
            } elseif (Functions::is_write_query($query)) {
                $use_master = true;
                if (is_array($this->srtm)) {
                    $this->srtm[$this->table] = true;
                }

                // Detect queries that have a join in the srtm array.
            } elseif (!isset($use_master) && is_array($this->srtm) && !empty($this->srtm)) {
                $use_master = false;
                $query_match = substr($query, 0, 1000);

                foreach ($this->srtm as $key => $value) {
                    if (false !== stripos($query_match, $key)) {
                        $use_master = true;
                        break;
                    }
                }
            } else {
                $use_master = false;
            }

            if (!empty($use_master)) {
                $this->dbhname = $dbhname = $dataset . '__w';
                $operation = 'write';
            } else {
                $this->dbhname = $dbhname = $dataset . '__r';
                $operation = 'read';
            }

            // Try to reuse an existing connection
            while (isset($this->dbhs[$dbhname]) && $this->dbh_type_check($this->dbhs[$dbhname])) {

                // Find the connection for incrementing counters
                foreach (array_keys($this->db_connections) as $i) {
                    if ($this->db_connections[$i]['dbhname'] == $dbhname) {
                        $conn = &$this->db_connections[$i];
                    }
                }

                if (isset($server['name'])) {
                    $name = $server['name'];

                    // A callback has specified a database name so it's possible the
                    // existing connection selected a different one.
                    if ($name != $this->used_servers[$dbhname]['name']) {
                        if (!$this->select($name, $this->dbhs[$dbhname])) {

                            // This can happen when the user varies and lacks
                            // permission on the $name database
                            if (isset($conn['disconnect (select failed)'])) {
                                ++$conn['disconnect (select failed)'];
                            } else {
                                $conn['disconnect (select failed)'] = 1;
                            }

                            $this->disconnect($dbhname);
                            break;
                        }
                        $this->used_servers[$dbhname]['name'] = $name;
                    }
                } else {
                    $name = $this->used_servers[$dbhname]['name'];
                }

                $this->current_host = $this->dbh2host[$dbhname];

                // Keep this connection at the top of the stack to prevent disconnecting frequently-used connections
                if ($k = array_search($dbhname, $this->open_connections, true)) {
                    unset($this->open_connections[$k]);
                    $this->open_connections[] = $dbhname;
                }

                $this->last_used_server = $this->used_servers[$dbhname];
                $this->last_connection = compact('dbhname', 'name');

                if ($this->should_mysql_ping($dbhname) && !$this->check_connection(false, $this->dbhs[$dbhname])) {
                    if (isset($conn['disconnect (ping failed)'])) {
                        ++$conn['disconnect (ping failed)'];
                    } else {
                        $conn['disconnect (ping failed)'] = 1;
                    }

                    $this->disconnect($dbhname);
                    break;
                }

                if (isset($conn['queries'])) {
                    ++$conn['queries'];
                } else {
                    $conn['queries'] = 1;
                }

                return $this->dbhs[$dbhname];
            }

            if (!empty($use_master) && defined('MASTER_DB_DEAD')) {
                throw new PublicAlert('We are updating the database. Please try back in 5 minutes. If you are posting to your blog please hit the refresh button on your browser in a few minutes to post the data again. It will be posted as soon as the database is back online.');
            }

            if (empty($this->ludicrous_servers[$dataset][$operation])) {
                throw new PublicAlert("No databases available with {$this->table} ({$dataset})");
            }

            // Put the groups in order by priority
            ksort($this->ludicrous_servers[$dataset][$operation]);

            // Make a list of at least $this->reconnect_retries connections to try, repeating as necessary.
            $servers = array();

            do {

                foreach ($this->ludicrous_servers[$dataset][$operation] as $group => $items) {

                    $keys = array_keys($items);

                    shuffle($keys);

                    foreach ($keys as $key) {

                        $servers[] = compact('group', 'key');

                    }

                }

                if (!$tries_remaining = count($servers)) {

                    throw new PublicAlert("No database servers were found to match the query. ({$this->table}, {$dataset})");

                }

                if (is_null($this->unique_servers)) {

                    $this->unique_servers = $tries_remaining;

                }

            } while ($tries_remaining < $this->reconnect_retries);

            // Connect to a database server
            do {

                $unique_lagged_slaves = array();

                $success = false;

                foreach ($servers as $group_key) {

                    --$tries_remaining;

                    // If all servers are lagged, we need to start ignoring the lag and retry
                    if (count($unique_lagged_slaves) === $this->unique_servers) {

                        break;

                    }

                    // $group, $key
                    $group = $group_key['group'];
                    $key = $group_key['key'];

                    // $host, $user, $password, $name, $read, $write [, $lag_threshold, $timeout ]
                    $db_config = $this->ludicrous_servers[$dataset][$operation][$group][$key];
                    $host = $db_config['host'];
                    $user = $db_config['user'];
                    $password = $db_config['password'];
                    $name = $db_config['name'];
                    $write = $db_config['write'];
                    $read = $db_config['read'];
                    $timeout = $db_config['timeout'];
                    $port = $db_config['port'];
                    $lag_threshold = $db_config['lag_threshold'];

                    // Split host:port into $host and $port
                    if (strpos($host, ':')) {
                        [$host, $port] = explode(':', $host);
                    }

                    // Overlay $server if it was extracted from a callback
                    if (isset($server) && is_array($server)) {
                        extract($server, EXTR_OVERWRITE);
                    }

                    // Split again in case $server had host:port
                    if (strpos($host, ':')) {
                        [$host, $port] = explode(':', $host);
                    }

                    // Make sure there's always a port number
                    if (empty($port)) {
                        $port = 3306;
                    }

                    // Use a default timeout of 200ms
                    if (!isset($timeout)) {
                        $timeout = 0.2;
                    }

                    // Get the minimum group here, in case $server rewrites it
                    if (!isset($min_group) || ($min_group > $group)) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
                        $min_group = $group;
                    }

                    $host_and_port = "{$host}:{$port}";

                    // Can be used by the lag callbacks
                    $this->lag_cache_key = $host_and_port;

                    $this->lag_threshold = isset($lag_threshold)
                        ? $lag_threshold
                        : $this->default_lag_threshold;

                    // Check for a lagged slave, if applicable
                    if (empty($use_master)
                        && empty($write)
                        && empty($this->ignore_slave_lag)
                        && isset($this->lag_threshold)
                        && !isset($server['host'])
                        && ($lagged_status = Functions::get_lag_cache($this)) === DB_LAG_BEHIND) {

                        // If it is the last lagged slave and it is with the best preference we will ignore its lag
                        if (!isset($unique_lagged_slaves[$host_and_port]) && $this->unique_servers === count($unique_lagged_slaves) + 1 && $group === $min_group) {

                            $this->lag_threshold = null;
                        } else {

                            $unique_lagged_slaves[$host_and_port] = $this->lag;

                            continue;

                        }

                    }

                    $this->timer_start();

                    // Maybe check TCP responsiveness
                    $tcp = !empty($this->check_tcp_responsiveness)
                        ? $this->check_tcp_responsiveness($host, $port, $timeout)
                        : null;

                    // Connect if necessary or possible
                    if (!empty($use_master) || empty($tries_remaining) || (true === $tcp)) {
                        $this->single_db_connect($dbhname, $host_and_port, $user, $password);
                    } else {
                        $this->dbhs[$dbhname] = false;
                    }

                    $elapsed = $this->timer_stop();

                    if ($this->dbh_type_check($this->dbhs[$dbhname])) {
                        /**
                         * If we care about lag, disconnect lagged slaves and try to find others.
                         * We don't disconnect if it is the last lagged slave and it is with the best preference.
                         */
                        if (empty($use_master)
                            && empty($write)
                            && empty($this->ignore_slave_lag)
                            && isset($this->lag_threshold)
                            && !isset($server['host'])
                            && ($lagged_status !== DB_LAG_OK)
                            && ($lagged_status = $this->get_lag()) === DB_LAG_BEHIND && !(
                                !isset($unique_lagged_slaves[$host_and_port])
                                && ($this->unique_servers == (count($unique_lagged_slaves) + 1))
                                && ($group == $min_group)
                            )
                        ) {
                            $unique_lagged_slaves[$host_and_port] = $this->lag;
                            $this->disconnect($dbhname);
                            $this->dbhs[$dbhname] = false;
                            $success = false;
                            $msg = "Replication lag of {$this->lag}s on {$host_and_port} ({$dbhname})";
                            $this->print_error($msg);
                            continue;
                        }

                        $this->set_sql_mode(array(), $this->dbhs[$dbhname]);

                        if ($this->select($name, $this->dbhs[$dbhname])) {
                            $this->current_host = $host_and_port;
                            $this->dbh2host[$dbhname] = $host_and_port;

                            // Define these to avoid undefined variable notices
                            $queries = isset($queries) ? $queries : 1; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
                            $lag = isset($this->lag) ? $this->lag : 0;

                            $this->last_connection = compact('dbhname', 'host', 'port', 'user', 'name', 'tcp', 'elapsed', 'success', 'queries', 'lag');
                            $this->db_connections[] = $this->last_connection;
                            $this->open_connections[] = $dbhname;
                            $success = true;
                            break;
                        }

                    }

                    $success = false;

                    $this->last_connection = compact('dbhname', 'host', 'port', 'user', 'name', 'tcp', 'elapsed', 'success');

                    $this->db_connections[] = $this->last_connection;

                    if ($this->dbh_type_check($this->dbhs[$dbhname])) {
                        $error = mysqli_error($this->dbhs[$dbhname]);
                        $errno = mysqli_errno($this->dbhs[$dbhname]);
                    }

                    $msg = date('Y-m-d H:i:s') . " Can't select {$dbhname} - \n"; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $msg .= "'referrer' => '{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}',\n";
                    $msg .= "'host' => {$host},\n";

                    if (!empty($error)) {
                        $msg .= "'error' => {$error},\n";
                    }

                    if (!empty($errno)) {
                        $msg .= "'errno' => {$errno},\n";

                        // Maybe log the error to heartbeats
                        if (!empty($this->check_dbh_heartbeats)) {
                            $this->dbhname_heartbeats[$dbhname]['last_errno'] = $errno;
                        }
                    }

                    $msg .= "'tcp_responsive' => " . ($tcp === true
                            ? 'true'
                            : $tcp) . ",\n";

                    $msg .= "'lagged_status' => " . (isset($lagged_status)
                            ? $lagged_status
                            : DB_LAG_UNKNOWN);

                    $this->print_error($msg);
                }

                if (empty($success)
                    || !isset($this->dbhs[$dbhname])
                    || !$this->dbh_type_check($this->dbhs[$dbhname])
                ) {

                    // Lagged slaves were not used. Ignore the lag for this connection attempt and retry.
                    if (empty($this->ignore_slave_lag) && count($unique_lagged_slaves)) {
                        $this->ignore_slave_lag = true;
                        $tries_remaining = count($servers);
                        continue;
                    }

                    Functions::run_callbacks($this, 'db_connection_error', array(
                        'host' => $host,
                        'port' => $port,
                        'operation' => $operation,
                        'table' => $this->table,
                        'dataset' => $dataset,
                        'dbhname' => $dbhname,
                    ));

                    throw new PublicAlert("Unable to connect to {$host}:{$port} to {$operation} table '{$this->table}' ({$dataset})");
                }

                break;
            } while (true);

            $this->set_charset($this->dbhs[$dbhname]);

            $this->dbh = $this->dbhs[$dbhname]; // needed by $this->_real_escape()

            $this->last_used_server = compact('host', 'user', 'name', 'read', 'write');

            $this->used_servers[$dbhname] = $this->last_used_server;

            while ((false === $this->persistent) && count($this->open_connections) > $this->max_connections) {

                $oldest_connection = array_shift($this->open_connections);

                if ($this->dbhs[$oldest_connection] != $this->dbhs[$dbhname]) {

                    $this->disconnect($oldest_connection);

                }

            }

            return $this->dbhs[$dbhname];

        } catch (Throwable $e) {

            ThrowableHandler::generateLogAndExit($e);

        }

    }

}