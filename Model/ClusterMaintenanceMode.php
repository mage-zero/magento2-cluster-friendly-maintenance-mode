<?php

namespace MageZero\ClusterMaintenance\Model;

use MageZero\ClusterMaintenance\Model\Storage\StorageInterface;
use Magento\Framework\App\MaintenanceMode;
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
 *
 * Compatible with Magento 2.4.4+ (adapts to constructor changes in 2.4.8).
 */
class ClusterMaintenanceMode extends MaintenanceMode
{
    private StorageInterface $storage;

    /** @var object|null IPAddress utility (Magento 2.4.8+) or null */
    private ?object $ipAddressUtil;

    private Manager $eventMgr;

    private const IP_ADDRESS_CLASS = \Magento\Framework\App\Utility\IPAddress::class;

    public function __construct(
        Filesystem $filesystem,
        StorageInterface $storage,
        ?Manager $eventManager = null
    ) {
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $this->initializeParentMaintenanceMode($filesystem, $eventManager, $om);

        $this->storage = $storage;
        $this->eventMgr = $eventManager ?? $om->get(Manager::class);
    }

    /**
     * Invoke parent constructor safely across Magento framework versions.
     *
     * Different framework versions accept different constructor shapes:
     * - (Filesystem, EventManager?)
     * - (Filesystem, IPAddress)
     * - (Filesystem, IPAddress, EventManager?)
     *
     * Reflection avoids hardcoding one signature and keeps DI compile stable.
     */
    private function initializeParentMaintenanceMode(
        Filesystem $filesystem,
        ?Manager $eventManager,
        \Magento\Framework\ObjectManagerInterface $objectManager
    ): void {
        $ctor = new \ReflectionMethod(MaintenanceMode::class, '__construct');
        $parameters = $ctor->getParameters();
        $args = [$filesystem];

        $this->ipAddressUtil = null;
        $resolvedEventManager = $eventManager ?? $objectManager->get(Manager::class);

        foreach (array_slice($parameters, 1) as $parameter) {
            $parameterName = $parameter->getName();
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            if ($parameterName === 'ipAddress' || $typeName === self::IP_ADDRESS_CLASS) {
                if (class_exists(self::IP_ADDRESS_CLASS)) {
                    $ipAddress = $objectManager->get(self::IP_ADDRESS_CLASS);
                    $args[] = $ipAddress;
                    $this->ipAddressUtil = $ipAddress;
                    continue;
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $args[] = $parameter->getDefaultValue();
                }
                continue;
            }

            if ($parameterName === 'eventManager' || $typeName === Manager::class) {
                $args[] = $resolvedEventManager;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
            }
        }

        $ctor->invokeArgs($this, $args);
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
                    if ($this->isIpInRange($allowed, $remoteAddr)) {
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

    /**
     * Check if an IP address falls within a CIDR range.
     *
     * Uses Magento's IPAddress utility on 2.4.8+, falls back to
     * inline inet_pton matching on older versions.
     */
    private function isIpInRange(string $range, string $address): bool
    {
        if (strpos($range, '/') === false) {
            return false;
        }

        if ($this->ipAddressUtil !== null) {
            return $this->ipAddressUtil->isValidRange($range)
                && $this->ipAddressUtil->rangeContainsAddress($range, $address);
        }

        return $this->cidrMatch($range, $address);
    }

    /**
     * Inline CIDR match for pre-2.4.8 Magento without IPAddress utility.
     */
    private function cidrMatch(string $range, string $address): bool
    {
        [$subnet, $bits] = explode('/', $range, 2);
        $bits = (int) $bits;

        $subnetBin = inet_pton($subnet);
        $addressBin = inet_pton($address);

        if ($subnetBin === false || $addressBin === false) {
            return false;
        }
        if (strlen($subnetBin) !== strlen($addressBin)) {
            return false;
        }

        $mask = str_repeat("\xff", (int) ($bits / 8));
        $remainder = $bits % 8;
        if ($remainder) {
            $mask .= pack('C', 0xff << (8 - $remainder));
        }
        $mask = str_pad($mask, strlen($subnetBin), "\x00");

        return ($addressBin & $mask) === ($subnetBin & $mask);
    }
}
