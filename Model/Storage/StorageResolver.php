<?php

namespace MageZero\ClusterMaintenance\Model\Storage;

use Magento\Framework\App\DeploymentConfig;

/**
 * Resolves the active storage adapter based on deployment config.
 *
 * Customer configures in env.php:
 *   'cluster_maintenance' => ['storage' => 'redis']  // or 'database'
 *
 * Defaults to 'redis' when not configured.
 */
class StorageResolver implements StorageInterface
{
    private DeploymentConfig $deploymentConfig;

    /** @var StorageInterface[] */
    private array $adapters;

    private ?StorageInterface $resolved = null;

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param StorageInterface[] $adapters
     */
    public function __construct(
        DeploymentConfig $deploymentConfig,
        array $adapters = []
    ) {
        $this->deploymentConfig = $deploymentConfig;
        $this->adapters = $adapters;
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

        $type = (string) ($this->deploymentConfig->get('cluster_maintenance/storage') ?? 'redis');

        if (!isset($this->adapters[$type])) {
            throw new \RuntimeException(
                "Unknown maintenance storage adapter: '{$type}'. Available: "
                . implode(', ', array_keys($this->adapters))
            );
        }

        $this->resolved = $this->adapters[$type];
        return $this->resolved;
    }
}
