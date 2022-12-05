<?php

namespace ScalingWPDB;

trait Variables
{

    /**
     * The last table that was queried
     *
     * @var string
     */
    public string $last_table = '';

    /**
     * After any SQL_CALC_FOUND_ROWS query, the query "SELECT FOUND_ROWS()"
     * is sent and the MySQL result resource stored here. The next query
     * for FOUND_ROWS() will retrieve this. We do this to prevent any
     * intervening queries from making FOUND_ROWS() inaccessible. You may
     * prevent this by adding "NO_SELECT_FOUND_ROWS" in a comment
     *
     * @var resource
     */
    public $last_found_rows_result = null;

    /**
     * Whether to store queries in an array. Useful for debugging and profiling
     *
     * @var bool
     */
    public bool $save_queries = false;


    /**
     * Associative array (dbhname => dbh) for established MySQL connections
     *
     * @var array
     */
    public array $dbhs = array();

    /**
     * The multi-dimensional array of datasets and servers
     *
     * @var array
     */
    public array $ludicrous_servers = array();

    /**
     * Optional directory of tables and their datasets
     *
     * @var array
     */
    public array $ludicrous_tables = array();

    /**
     * Optional directory of callbacks to determine datasets from queries
     *
     * @var array
     */
    public array $ludicrous_callbacks = array();

    public mixed $callback_result = null;
    /**
     * Custom callback to save debug info in $this->queries
     *
     * @var callable
     */
    public $save_query_callback = null;

    /**
     * Whether to use mysql_pconnect instead of mysql_connect
     *
     * @var bool
     */
    public bool $persistent = false;

    /**
     * Allow bail if connection fails
     *
     * @var bool
     */
    public bool $allow_bail = false;

    /**
     * The maximum number of db links to keep open. The least-recently used
     * link will be closed when the number of links exceeds this
     *
     * @var int
     */
    public int $max_connections = 10;

    /**
     * Whether to check with fsockopen prior to mysql_connect
     *
     * @var bool
     */
    public bool $check_tcp_responsiveness = true;

    /**
     * The amount of time to wait before trying again to ping mysql server.
     *
     * @var float
     */
    public float $recheck_timeout = 0.1;

    /**
     * Whether to check for heartbeats
     *
     * @var bool
     */
    public bool $check_dbh_heartbeats = true;

    /**
     * Keeps track of the dbhname usage and errors.
     *
     * @var array
     */
    public array $dbhname_heartbeats = array();

    /**
     * Send Reads To Masters. This disables slave connections while true.
     * Otherwise it is an array of written tables
     */
    public array|bool $srtm = array();

    /**
     * The log of db connections made and the time each one took
     */
    public array $db_connections = array();

    /**
     * The list of unclosed connections sorted by LRU
     */
    public array $open_connections = array();

    /**
     * Lookup array (dbhname => host:port)
     */
    public array $dbh2host = array();

    /**
     * The last server used and the database name selected
     *
     * @var array
     */
    public array $last_used_server = array();

    /**
     * Lookup array (dbhname => (server, db name) ) for re-selecting the db
     * when a link is re-used
     */
    public array $used_servers = array();

    /**
     * Whether to save debug_backtrace in save_query_callback. You may wish
     * to disable this, e.g. when tracing out-of-memory problems.
     */
    public bool $save_backtrace = true;

    /**
     * Maximum lag in seconds. Set null to disable. Requires callbacks
     */
    public ?int $default_lag_threshold = null;

    /**
     * In memory cache for tcp connected status.
     */
    private array $tcp_cache = array();

    /**
     * Name of object cache group.
     */
    public string $cache_group = 'ludicrousdb';

    /**
     * Whether to ignore slave lag.
     */
    private bool $ignore_slave_lag = false;

    /**
     * Number of unique servers.
     */
    private ?int $unique_servers = null;


}