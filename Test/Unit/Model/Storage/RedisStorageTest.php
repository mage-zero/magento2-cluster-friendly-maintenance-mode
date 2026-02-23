<?php

namespace MageZero\ClusterMaintenance\Test\Unit\Model\Storage;

use MageZero\ClusterMaintenance\Model\Storage\RedisStorage;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RedisStorageTest extends TestCase
{
    private MockObject|DeploymentConfig $deploymentConfig;
    private MockObject|\Redis $redis;
    private RedisStorage $storage;

    protected function setUp(): void
    {
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        $this->redis = $this->createMock(\Redis::class);

        $this->storage = new RedisStorage($this->deploymentConfig);

        // Inject mock Redis to avoid real connections
        $ref = new \ReflectionClass($this->storage);
        $prop = $ref->getProperty('redis');
        $prop->setAccessible(true);
        $prop->setValue($this->storage, $this->redis);
    }

    // ── hasFlag ─────────────────────────────────────────────────────────

    public function testHasFlagReturnsTrueWhenKeyExists(): void
    {
        $this->redis->method('exists')->with('maintenance:flag')->willReturn(1);
        $this->assertTrue($this->storage->hasFlag());
    }

    public function testHasFlagReturnsFalseWhenKeyMissing(): void
    {
        $this->redis->method('exists')->with('maintenance:flag')->willReturn(0);
        $this->assertFalse($this->storage->hasFlag());
    }

    // ── setFlag ─────────────────────────────────────────────────────────

    public function testSetFlagEnableSetsKey(): void
    {
        $this->redis->expects($this->once())
            ->method('set')
            ->with('maintenance:flag', '1');

        $this->storage->setFlag(true);
    }

    public function testSetFlagDisableDeletesKey(): void
    {
        $this->redis->expects($this->once())
            ->method('del')
            ->with('maintenance:flag');

        $this->storage->setFlag(false);
    }

    // ── getAddresses ────────────────────────────────────────────────────

    public function testGetAddressesReturnsStoredValue(): void
    {
        $this->redis->method('get')->with('maintenance:ips')->willReturn('1.2.3.4,5.6.7.8');
        $this->assertSame('1.2.3.4,5.6.7.8', $this->storage->getAddresses());
    }

    public function testGetAddressesReturnsEmptyStringWhenKeyMissing(): void
    {
        $this->redis->method('get')->with('maintenance:ips')->willReturn(false);
        $this->assertSame('', $this->storage->getAddresses());
    }

    public function testGetAddressesReturnsEmptyStringWhenValueEmpty(): void
    {
        $this->redis->method('get')->with('maintenance:ips')->willReturn('');
        $this->assertSame('', $this->storage->getAddresses());
    }

    // ── setAddresses ────────────────────────────────────────────────────

    public function testSetAddressesStoresValue(): void
    {
        $this->redis->expects($this->once())
            ->method('set')
            ->with('maintenance:ips', '1.2.3.4,5.6.7.8');

        $this->storage->setAddresses('1.2.3.4,5.6.7.8');
    }

    public function testSetAddressesEmptyDeletesKey(): void
    {
        $this->redis->expects($this->once())
            ->method('del')
            ->with('maintenance:ips');

        $this->storage->setAddresses('');
    }

    // ── Connection failure ──────────────────────────────────────────────

    public function testHasFlagThrowsOnRedisException(): void
    {
        $this->redis->method('exists')
            ->willThrowException(new \RedisException('Connection lost'));

        $this->expectException(\RedisException::class);
        $this->storage->hasFlag();
    }

    public function testSetFlagThrowsOnRedisException(): void
    {
        $this->redis->method('set')
            ->willThrowException(new \RedisException('Connection lost'));

        $this->expectException(\RedisException::class);
        $this->storage->setFlag(true);
    }
}
