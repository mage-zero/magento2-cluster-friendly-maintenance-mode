<?php

namespace MageZero\ClusterMaintenance\Model\Storage;

/**
 * Shared-state storage backend for cluster-wide maintenance mode.
 *
 * All methods throw on failure so the caller can fall back to file-based mode.
 */
interface StorageInterface
{
    /**
     * @return bool True if the maintenance flag is set.
     * @throws \RuntimeException
     */
    public function hasFlag(): bool;

    /**
     * @param bool $enabled True to enable, false to disable.
     * @throws \RuntimeException
     */
    public function setFlag(bool $enabled): void;

    /**
     * @return string Comma-separated IP addresses, or empty string if none.
     * @throws \RuntimeException
     */
    public function getAddresses(): string;

    /**
     * @param string $addresses Comma-separated IP addresses. Empty string deletes.
     * @throws \RuntimeException
     */
    public function setAddresses(string $addresses): void;
}
