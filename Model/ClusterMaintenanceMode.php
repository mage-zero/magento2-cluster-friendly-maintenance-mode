<?php

namespace MageZero\ClusterMaintenance\Model;

use MageZero\ClusterMaintenance\Model\Storage\StorageInterface;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\Utility\IPAddress;
use Magento\Framework\Event\Manager;
use Magento\Framework\Filesystem;

/**
 * Cluster-wide maintenance mode backed by a shared storage backend.
 *
 * Replaces the default file-based MaintenanceMode so that all containers
 * in a Docker Swarm (or any multi-node cluster) see the same state.
 *
 * Falls back to the parent (file-based) implementation if the storage
 * backend is unreachable.
 */
class ClusterMaintenanceMode extends MaintenanceMode
{
    private StorageInterface $storage;
    private IPAddress $ipAddressUtil;
    private Manager $eventMgr;

    public function __construct(
        Filesystem $filesystem,
        IPAddress $ipAddress,
        StorageInterface $storage,
        ?Manager $eventManager = null
    ) {
        parent::__construct($filesystem, $ipAddress, $eventManager);
        $this->storage = $storage;
        $this->ipAddressUtil = $ipAddress;
        $this->eventMgr = $eventManager
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(Manager::class);
    }

    /**
     * @inheritdoc
     */
    public function isOn($remoteAddr = '')
    {
        try {
            if (!$this->storage->hasFlag()) {
                return false;
            }

            if ($remoteAddr) {
                $allowedAddresses = $this->getAddressInfo();
                foreach ($allowedAddresses as $allowed) {
                    if ($allowed === $remoteAddr) {
                        return false;
                    }
                    if (!$this->ipAddressUtil->isValidRange($allowed)) {
                        continue;
                    }
                    if ($this->ipAddressUtil->rangeContainsAddress($allowed, $remoteAddr)) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            return parent::isOn($remoteAddr);
        }
    }

    /**
     * @inheritdoc
     */
    public function set($isOn)
    {
        try {
            $this->storage->setFlag((bool) $isOn);
            $this->eventMgr->dispatch('maintenance_mode_changed', ['isOn' => $isOn]);
            return true;
        } catch (\Exception $e) {
            return parent::set($isOn);
        }
    }

    /**
     * @inheritdoc
     */
    public function setAddresses($addresses)
    {
        $addresses = (string) $addresses;

        if ($addresses !== '' && !preg_match('/^[^\s,]+(,[^\s,]+)*$/', $addresses)) {
            throw new \InvalidArgumentException("One or more IP-addresses is expected (comma-separated)\n");
        }

        try {
            $this->storage->setAddresses($addresses);
            return true;
        } catch (\Exception $e) {
            return parent::setAddresses($addresses);
        }
    }

    /**
     * @inheritdoc
     */
    public function getAddressInfo()
    {
        try {
            $value = $this->storage->getAddresses();
            if ($value === '') {
                return [];
            }
            return explode(',', $value);
        } catch (\Exception $e) {
            return parent::getAddressInfo();
        }
    }
}
