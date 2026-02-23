<?php

namespace MageZero\ClusterMaintenance\Model\Storage;

use Magento\Framework\App\ResourceConnection;

class DatabaseStorage implements StorageInterface
{
    private const TABLE = 'maintenance_mode';
    private const FLAG_KEY = 'flag';
    private const IPS_KEY = 'ips';

    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function hasFlag(): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $select = $connection->select()
            ->from($table, ['flag_value'])
            ->where('flag_key = ?', self::FLAG_KEY);
        return (bool) $connection->fetchOne($select);
    }

    public function setFlag(bool $enabled): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        if ($enabled) {
            $connection->insertOnDuplicate(
                $table,
                ['flag_key' => self::FLAG_KEY, 'flag_value' => '1'],
                ['flag_value']
            );
        } else {
            $connection->delete($table, ['flag_key = ?' => self::FLAG_KEY]);
        }
    }

    public function getAddresses(): string
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $select = $connection->select()
            ->from($table, ['flag_value'])
            ->where('flag_key = ?', self::IPS_KEY);
        $value = $connection->fetchOne($select);
        if ($value === false || $value === '' || $value === null) {
            return '';
        }
        return trim($value);
    }

    public function setAddresses(string $addresses): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        if ($addresses === '') {
            $connection->delete($table, ['flag_key = ?' => self::IPS_KEY]);
        } else {
            $connection->insertOnDuplicate(
                $table,
                ['flag_key' => self::IPS_KEY, 'flag_value' => $addresses],
                ['flag_value']
            );
        }
    }
}
