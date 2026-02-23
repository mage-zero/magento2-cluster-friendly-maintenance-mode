<?php

namespace MageZero\ClusterMaintenance\Model\Storage;

use Magento\Framework\App\DeploymentConfig;

/**
 * Auto-selects storage adapter based on Magento's existing configuration.
 *
 * If Redis cache is configured (cache/frontend/default/backend_options/server
 * exists in env.php), uses Redis. Otherwise falls back to database.
 *
 * No customer configuration required.
 */
class StorageResolver implements StorageInterface
{
    private DeploymentConfig $deploymentConfig;
    private RedisStorage $redisStorage;
    private DatabaseStorage $databaseStorage;
    private ?StorageInterface $resolved = null;

    public function __construct(
        DeploymentConfig $deploymentConfig,
        RedisStorage $redisStorage,
        DatabaseStorage $databaseStorage
    ) {
        $this->deploymentConfig = $deploymentConfig;
        $this->redisStorage = $redisStorage;
        $this->databaseStorage = $databaseStorage;
    }

    public function hasFlag(): bool
    {
        return $this->getAdapter()->hasFlag();
    }

    public function setFlag(bool $enabled): void
    {
        $this->getAdapter()->setFlag($enabled);
    }

    public function getAddresses(): string
    {
        return $this->getAdapter()->getAddresses();
    }

    public function setAddresses(string $addresses): void
    {
        $this->getAdapter()->setAddresses($addresses);
    }

    private function getAdapter(): StorageInterface
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $redisHost = $this->deploymentConfig->get('cache/frontend/default/backend_options/server');
        $this->resolved = $redisHost ? $this->redisStorage : $this->databaseStorage;

        return $this->resolved;
    }
}
