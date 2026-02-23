<?php

namespace MageZero\ClusterMaintenance\Model\Storage;

use Magento\Framework\App\DeploymentConfig;

class RedisStorage implements StorageInterface
{
    private const FLAG_KEY = 'maintenance:flag';
    private const IPS_KEY = 'maintenance:ips';
    private const DEFAULT_DB = 2;
    private const DEFAULT_PORT = 6379;
    private const CONNECT_TIMEOUT = 2.0;

    private DeploymentConfig $deploymentConfig;
    private ?\Redis $redis = null;

    public function __construct(DeploymentConfig $deploymentConfig)
    {
        $this->deploymentConfig = $deploymentConfig;
    }

    public function hasFlag(): bool
    {
        return (bool) $this->getConnection()->exists(self::FLAG_KEY);
    }

    public function setFlag(bool $enabled): void
    {
        $redis = $this->getConnection();
        if ($enabled) {
            $redis->set(self::FLAG_KEY, '1');
        } else {
            $redis->del(self::FLAG_KEY);
        }
    }

    public function getAddresses(): string
    {
        $value = $this->getConnection()->get(self::IPS_KEY);
        if ($value === false || $value === '') {
            return '';
        }
        return trim($value);
    }

    public function setAddresses(string $addresses): void
    {
        $redis = $this->getConnection();
        if ($addresses === '') {
            $redis->del(self::IPS_KEY);
        } else {
            $redis->set(self::IPS_KEY, $addresses);
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function getConnection(): \Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        try {
            $host = (string) ($this->deploymentConfig->get(
                'cache/frontend/default/backend_options/server'
            ) ?? '127.0.0.1');
            $port = (int) ($this->deploymentConfig->get(
                'cache/frontend/default/backend_options/port'
            ) ?? self::DEFAULT_PORT);

            $redis = new \Redis();
            $redis->connect($host, $port, self::CONNECT_TIMEOUT);
            $redis->select(self::DEFAULT_DB);
            $this->redis = $redis;
            return $this->redis;
        } catch (\RedisException $e) {
            throw new \RuntimeException('Redis connection failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
