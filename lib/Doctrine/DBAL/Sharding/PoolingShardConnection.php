<?php

namespace Doctrine\DBAL\Sharding;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use Doctrine\DBAL\Events;
use Doctrine\DBAL\Sharding\ShardChoser\ShardChoser;

/**
 * Sharding implementation that pools many different connections
 * internally and serves data from the currently active connection.
 *
 * The internals of this class are:
 *
 * - All sharding clients are specified and given a shard-id during
 *   configuration.
 * - By default, the global shard is selected. If no global shard is configured
 *   an exception is thrown on access.
 * - Selecting a shard by distribution value delegates the mapping
 *   "distributionValue" => "client" to the ShardChooser interface.
 * - An exception is thrown if trying to switch shards during an open
 *   transaction.
 *
 * Instantiation through the DriverManager looks like:
 *
 * @example
 *
 * $conn = DriverManager::getConnection(array(
 *    'wrapperClass' => 'Doctrine\DBAL\Sharding\PoolingShardConnection',
 *    'driver' => 'pdo_mysql',
 *    'global' => array('user' => '', 'password' => '', 'host' => '', 'dbname' => ''),
 *    'shards' => array(
 *        array('id' => 1, 'user' => 'slave1', 'password', 'host' => '', 'dbname' => ''),
 *        array('id' => 2, 'user' => 'slave2', 'password', 'host' => '', 'dbname' => ''),
 *    ),
 *    'shardChoser' => 'Doctrine\DBAL\Sharding\ShardChoser\MultiTenantShardChoser',
 * ));
 * $shardManager = $conn->getShardManager();
 * $shardManager->selectGlobal();
 * $shardManager->selectShard($value);
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
class PoolingShardConnection extends Connection
{
    /**
     * @var array
     */
    private $activeConnections;

    /**
     * @var integer
     */
    private $activeShardId;

    /**
     * @var array
     */
    private $connections;

    /**
     * @param array                         $params
     * @param \Doctrine\DBAL\Driver         $driver
     * @param \Doctrine\DBAL\Configuration  $config
     * @param \Doctrine\Common\EventManager $eventManager
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(array $params, Driver $driver, Configuration $config = null, EventManager $eventManager = null)
    {
        if ( !isset($params['global']) || !isset($params['shards'])) {
            throw new \InvalidArgumentException("Connection Parameters require 'global' and 'shards' configurations.");
        }

        if ( !isset($params['shardChoser'])) {
            throw new \InvalidArgumentException("Missing Shard Choser configuration 'shardChoser'");
        }

        if (is_string($params['shardChoser'])) {
            $params['shardChoser'] = new $params['shardChoser'];
        }

        if ( ! ($params['shardChoser'] instanceof ShardChoser)) {
            throw new \InvalidArgumentException("The 'shardChoser' configuration is not a valid instance of Doctrine\DBAL\Sharding\ShardChoser\ShardChoser");
        }

        $this->connections[0] = array_merge($params, $params['global']);

        foreach ($params['shards'] as $shard) {
            if ( ! isset($shard['id'])) {
                throw new \InvalidArgumentException("Missing 'id' for one configured shard. Please specify a unique shard-id.");
            }

            if ( !is_numeric($shard['id']) || $shard['id'] < 1) {
                throw new \InvalidArgumentException("Shard Id has to be a non-negative number.");
            }

            if (isset($this->connections[$shard['id']])) {
                throw new \InvalidArgumentException("Shard " . $shard['id'] . " is duplicated in the configuration.");
            }

            $this->connections[$shard['id']] = array_merge($params, $shard);
        }

        parent::__construct($params, $driver, $config, $eventManager);
    }

    /**
     * Get active shard id.
     *
     * @return integer
     */
    public function getActiveShardId()
    {
        return $this->activeShardId;
    }

    /**
     * {@inheritdoc}
     */
    public function getParams()
    {
        return $this->activeShardId ? $this->connections[$this->activeShardId] : $this->connections[0];
    }

    /**
     * {@inheritdoc}
     */
    public function getHost()
    {
        $params = $this->getParams();

        return $params['host'] ?? parent::getHost();
    }

    /**
     * {@inheritdoc}
     */
    public function getPort()
    {
        $params = $this->getParams();

        return $params['port'] ?? parent::getPort();
    }

    /**
     * {@inheritdoc}
     */
    public function getUsername()
    {
        $params = $this->getParams();

        return $params['user'] ?? parent::getUsername();
    }

    /**
     * {@inheritdoc}
     */
    public function getPassword()
    {
        $params = $this->getParams();

        return $params['password'] ?? parent::getPassword();
    }

    /**
     * Connects to a given shard.
     *
     * @param mixed $shardId
     *
     * @return boolean
     *
     * @throws \Doctrine\DBAL\Sharding\ShardingException
     */
    public function connect($shardId = null)
    {
        if ($shardId === null && $this->conn) {
            return false;
        }

        if ($shardId !== null && $shardId === $this->activeShardId) {
            return false;
        }

        if ($this->getTransactionNestingLevel() > 0) {
            throw new ShardingException("Cannot switch shard when transaction is active.");
        }

        $this->activeShardId = (int)$shardId;

        if (isset($this->activeConnections[$this->activeShardId])) {
            $this->conn = $this->activeConnections[$this->activeShardId];

            return false;
        }

        $this->conn = $this->activeConnections[$this->activeShardId] = $this->connectTo($this->activeShardId);

        if ($this->eventManager->hasListeners(Events::postConnect)) {
            $eventArgs = new ConnectionEventArgs($this);
            $this->eventManager->dispatchEvent(Events::postConnect, $eventArgs);
        }

        return true;
    }

    /**
     * Connects to a specific connection.
     *
     * @param string $shardId
     *
     * @return \Doctrine\DBAL\Driver\Connection
     */
    protected function connectTo($shardId)
    {
        $params = $this->getParams();

        $driverOptions = $params['driverOptions'] ?? [];

        $connectionParams = $this->connections[$shardId];

        $user = $connectionParams['user'] ?? null;
        $password = $connectionParams['password'] ?? null;

        return $this->driver->connect($connectionParams, $user, $password, $driverOptions);
    }

    /**
     * @param string|null $shardId
     *
     * @return boolean
     */
    public function isConnected($shardId = null)
    {
        if ($shardId === null) {
            return $this->conn !== null;
        }

        return isset($this->activeConnections[$shardId]);
    }

    /**
     * @return void
     */
    public function close()
    {
        $this->conn             = null;
        $this->activeConnections = null;
        $this->activeShardId     = null;
    }
}
