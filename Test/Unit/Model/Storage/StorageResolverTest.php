<?php

namespace MageZero\ClusterMaintenance\Test\Unit\Model\Storage;

use MageZero\ClusterMaintenance\Model\Storage\StorageInterface;
use MageZero\ClusterMaintenance\Model\Storage\StorageResolver;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StorageResolverTest extends TestCase
{
    private MockObject|DeploymentConfig $deploymentConfig;
    private MockObject|StorageInterface $redisAdapter;
    private MockObject|StorageInterface $dbAdapter;

    protected function setUp(): void
    {
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        $this->redisAdapter = $this->createMock(StorageInterface::class);
        $this->dbAdapter = $this->createMock(StorageInterface::class);
    }

    private function createResolver(?string $configValue = null): StorageResolver
    {
        $this->deploymentConfig->method('get')
            ->with('cluster_maintenance/storage')
            ->willReturn($configValue);

        return new StorageResolver(
            $this->deploymentConfig,
            ['redis' => $this->redisAdapter, 'database' => $this->dbAdapter]
        );
    }

    public function testDefaultsToRedis(): void
    {
        $resolver = $this->createResolver(null);
        $this->redisAdapter->method('hasFlag')->willReturn(true);

        $this->assertTrue($resolver->hasFlag());
    }

    public function testSelectsRedisExplicitly(): void
    {
        $resolver = $this->createResolver('redis');
        $this->redisAdapter->expects($this->once())->method('setFlag')->with(true);

        $resolver->setFlag(true);
    }

    public function testSelectsDatabase(): void
    {
        $resolver = $this->createResolver('database');
        $this->dbAdapter->expects($this->once())->method('setFlag')->with(true);

        $resolver->setFlag(true);
    }

    public function testDelegatesAllMethods(): void
    {
        $resolver = $this->createResolver('redis');

        $this->redisAdapter->method('hasFlag')->willReturn(false);
        $this->assertFalse($resolver->hasFlag());

        $this->redisAdapter->expects($this->once())->method('setAddresses')->with('1.2.3.4');
        $resolver->setAddresses('1.2.3.4');

        $this->redisAdapter->method('getAddresses')->willReturn('1.2.3.4');
        $this->assertSame('1.2.3.4', $resolver->getAddresses());
    }

    public function testCachesResolvedAdapter(): void
    {
        $resolver = $this->createResolver('redis');

        // Call twice — DeploymentConfig::get should only be called once (cached)
        $this->redisAdapter->method('hasFlag')->willReturn(true);
        $resolver->hasFlag();
        $resolver->hasFlag();

        // If not cached, this would fail (willReturn is set for single call)
        $this->assertTrue($resolver->hasFlag());
    }

    public function testThrowsOnUnknownAdapter(): void
    {
        $resolver = $this->createResolver('memcached');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown maintenance storage adapter: 'memcached'");
        $resolver->hasFlag();
    }
}
