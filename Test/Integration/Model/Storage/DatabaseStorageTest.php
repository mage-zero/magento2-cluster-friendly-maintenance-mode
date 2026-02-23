<?php

namespace MageZero\ClusterMaintenance\Test\Integration\Model\Storage;

use MageZero\ClusterMaintenance\Model\Storage\DatabaseStorage;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for DatabaseStorage against a real MySQL instance.
 *
 * The maintenance_mode table is created by etc/db_schema.xml during
 * Magento setup:upgrade which the integration test framework runs.
 */
class DatabaseStorageTest extends TestCase
{
    private DatabaseStorage $storage;
    private ResourceConnection $resource;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->resource = $objectManager->get(ResourceConnection::class);
        $this->storage = new DatabaseStorage($this->resource);

        // Clean slate for each test
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('maintenance_mode');
        $connection->delete($table);
    }

    protected function tearDown(): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('maintenance_mode');
        $connection->delete($table);
    }

    // ── hasFlag / setFlag ───────────────────────────────────────────────

    public function testHasFlagReturnsFalseWhenNotSet(): void
    {
        $this->assertFalse($this->storage->hasFlag());
    }

    public function testSetFlagEnableThenHasFlagReturnsTrue(): void
    {
        $this->storage->setFlag(true);
        $this->assertTrue($this->storage->hasFlag());
    }

    public function testSetFlagDisableThenHasFlagReturnsFalse(): void
    {
        $this->storage->setFlag(true);
        $this->assertTrue($this->storage->hasFlag());

        $this->storage->setFlag(false);
        $this->assertFalse($this->storage->hasFlag());
    }

    public function testSetFlagEnableIsIdempotent(): void
    {
        $this->storage->setFlag(true);
        $this->storage->setFlag(true);
        $this->assertTrue($this->storage->hasFlag());
    }

    public function testSetFlagDisableIsIdempotent(): void
    {
        $this->storage->setFlag(false);
        $this->assertFalse($this->storage->hasFlag());
    }

    // ── getAddresses / setAddresses ─────────────────────────────────────

    public function testGetAddressesReturnsEmptyStringWhenNotSet(): void
    {
        $this->assertSame('', $this->storage->getAddresses());
    }

    public function testSetAndGetSingleAddress(): void
    {
        $this->storage->setAddresses('1.2.3.4');
        $this->assertSame('1.2.3.4', $this->storage->getAddresses());
    }

    public function testSetAndGetMultipleAddresses(): void
    {
        $this->storage->setAddresses('1.2.3.4,5.6.7.8,10.0.0.1');
        $this->assertSame('1.2.3.4,5.6.7.8,10.0.0.1', $this->storage->getAddresses());
    }

    public function testSetAddressesCidrRange(): void
    {
        $this->storage->setAddresses('10.0.0.0/8,192.168.0.0/16');
        $this->assertSame('10.0.0.0/8,192.168.0.0/16', $this->storage->getAddresses());
    }

    public function testSetAddressesEmptyDeletesStoredAddresses(): void
    {
        $this->storage->setAddresses('1.2.3.4');
        $this->assertSame('1.2.3.4', $this->storage->getAddresses());

        $this->storage->setAddresses('');
        $this->assertSame('', $this->storage->getAddresses());
    }

    public function testSetAddressesOverwritesPrevious(): void
    {
        $this->storage->setAddresses('1.2.3.4');
        $this->storage->setAddresses('5.6.7.8');
        $this->assertSame('5.6.7.8', $this->storage->getAddresses());
    }

    // ── Flag and addresses are independent ──────────────────────────────

    public function testFlagAndAddressesAreIndependent(): void
    {
        $this->storage->setFlag(true);
        $this->storage->setAddresses('1.2.3.4');

        $this->assertTrue($this->storage->hasFlag());
        $this->assertSame('1.2.3.4', $this->storage->getAddresses());

        // Disabling flag doesn't affect addresses
        $this->storage->setFlag(false);
        $this->assertFalse($this->storage->hasFlag());
        $this->assertSame('1.2.3.4', $this->storage->getAddresses());

        // Clearing addresses doesn't affect flag
        $this->storage->setFlag(true);
        $this->storage->setAddresses('');
        $this->assertTrue($this->storage->hasFlag());
        $this->assertSame('', $this->storage->getAddresses());
    }
}
