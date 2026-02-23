<?php

namespace MageZero\ClusterMaintenance\Test\Unit\Model\Storage;

use MageZero\ClusterMaintenance\Model\Storage\DatabaseStorage;
use MageZero\ClusterMaintenance\Model\Storage\RedisStorage;
use MageZero\ClusterMaintenance\Model\Storage\StorageResolver;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StorageResolverTest extends TestCase
{
    private MockObject|DeploymentConfig $deploymentConfig;
    private MockObject|RedisStorage $redisStorage;
    private MockObject|DatabaseStorage $databaseStorage;

    protected function setUp(): void
    {
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        $this->redisStorage = $this->createMock(RedisStorage::class);
        $this->databaseStorage = $this->createMock(DatabaseStorage::class);
    }

    private function createResolver(?string $redisHost): StorageResolver
    {
        $this->deploymentConfig->method('get')
            ->with('cache/frontend/default/backend_options/server')
            ->willReturn($redisHost);

        return new StorageResolver(
            $this->deploymentConfig,
            $this->redisStorage,
            $this->databaseStorage
        );
    }

    public function testUsesRedisWhenCacheConfigured(): void
    {
        $resolver = $this->createResolver('redis-host');
        $this->redisStorage->expects($this->once())->method('hasFlag')->willReturn(true);
        $this->databaseStorage->expects($this->never())->method('hasFlag');

        $this->assertTrue($resolver->hasFlag());
    }

    public function testFallsToDatabaseWhenNoRedisConfig(): void
    {
        $resolver = $this->createResolver(null);
        $this->databaseStorage->expects($this->once())->method('hasFlag')->willReturn(false);
        $this->redisStorage->expects($this->never())->method('hasFlag');

        $this->assertFalse($resolver->hasFlag());
    }

    public function testDelegatesAllMethodsToRedis(): void
    {
        $resolver = $this->createResolver('redis-host');

        $this->redisStorage->expects($this->once())->method('setFlag')->with(true);
        $resolver->setFlag(true);

        $this->redisStorage->method('getAddresses')->willReturn('1.2.3.4');
        $this->assertSame('1.2.3.4', $resolver->getAddresses());

        $this->redisStorage->expects($this->once())->method('setAddresses')->with('5.6.7.8');
        $resolver->setAddresses('5.6.7.8');
    }

    public function testDelegatesAllMethodsToDatabase(): void
    {
        $resolver = $this->createResolver(null);

        $this->databaseStorage->expects($this->once())->method('setFlag')->with(false);
        $resolver->setFlag(false);

        $this->databaseStorage->method('getAddresses')->willReturn('');
        $this->assertSame('', $resolver->getAddresses());
    }

    public function testCachesResolvedAdapter(): void
    {
        $resolver = $this->createResolver('redis-host');

        $this->redisStorage->method('hasFlag')->willReturn(true);
        $resolver->hasFlag();
        $resolver->hasFlag();
        $this->assertTrue($resolver->hasFlag());
    }
}
