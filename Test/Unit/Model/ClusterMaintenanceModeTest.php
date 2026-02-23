<?php

namespace MageZero\ClusterMaintenance\Test\Unit\Model;

use MageZero\ClusterMaintenance\Model\ClusterMaintenanceMode;
use MageZero\ClusterMaintenance\Model\Storage\StorageInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Utility\IPAddress;
use Magento\Framework\Event\Manager;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ClusterMaintenanceModeTest extends TestCase
{
    private MockObject|StorageInterface $storage;
    private MockObject|Manager $eventManager;
    private MockObject|WriteInterface $flagDir;
    private ClusterMaintenanceMode $model;

    /** @var MockObject|null IPAddress mock on 2.4.8+, null on older versions */
    private $ipAddress;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(StorageInterface::class);
        $this->eventManager = $this->createMock(Manager::class);
        $this->flagDir = $this->createMock(WriteInterface::class);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')
            ->with(DirectoryList::VAR_DIR)
            ->willReturn($this->flagDir);

        // The constructor uses ObjectManager to resolve IPAddress (2.4.8+)
        // and EventManager fallback. Mock it for unit test isolation.
        $objectManager = $this->createMock(ObjectManagerInterface::class);

        $returnMap = [
            [Manager::class, $this->eventManager],
        ];

        if (class_exists(IPAddress::class)) {
            $this->ipAddress = $this->createMock(IPAddress::class);
            $returnMap[] = [IPAddress::class, $this->ipAddress];
        } else {
            $this->ipAddress = null;
        }

        $objectManager->method('get')->willReturnMap($returnMap);
        \Magento\Framework\App\ObjectManager::setInstance($objectManager);

        $this->model = new ClusterMaintenanceMode(
            $filesystem,
            $this->storage,
            $this->eventManager
        );
    }

    protected function tearDown(): void
    {
        // Reset the ObjectManager singleton to avoid leaking into other tests
        $reflection = new \ReflectionClass(\Magento\Framework\App\ObjectManager::class);
        $property = $reflection->getProperty('_instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    // ── isOn() ──────────────────────────────────────────────────────────

    public function testIsOnReturnsTrueWhenFlagSet(): void
    {
        $this->storage->method('hasFlag')->willReturn(true);
        $this->assertTrue($this->model->isOn());
    }

    public function testIsOnReturnsFalseWhenFlagNotSet(): void
    {
        $this->storage->method('hasFlag')->willReturn(false);
        $this->assertFalse($this->model->isOn());
    }

    public function testIsOnReturnsFalseWhenNoAddrAndFlagNotSet(): void
    {
        $this->storage->method('hasFlag')->willReturn(false);
        $this->assertFalse($this->model->isOn(''));
    }

    public function testIsOnReturnsFalseForExactIpMatch(): void
    {
        $this->storage->method('hasFlag')->willReturn(true);
        $this->storage->method('getAddresses')->willReturn('1.2.3.4,5.6.7.8');
        $this->assertFalse($this->model->isOn('1.2.3.4'));
    }

    public function testIsOnReturnsFalseForSecondIpInList(): void
    {
        $this->storage->method('hasFlag')->willReturn(true);
        $this->storage->method('getAddresses')->willReturn('1.2.3.4,5.6.7.8');
        $this->assertFalse($this->model->isOn('5.6.7.8'));
    }

    public function testIsOnReturnsTrueForNonAllowedIp(): void
    {
        $this->storage->method('hasFlag')->willReturn(true);
        $this->storage->method('getAddresses')->willReturn('1.2.3.4');
        // '1.2.3.4' has no CIDR prefix — isIpInRange returns false regardless of backend
        $this->assertTrue($this->model->isOn('9.9.9.9'));
    }

    public function testIsOnReturnsFalseForIpInCidrRange(): void
    {
        $this->storage->method('hasFlag')->willReturn(true);
        $this->storage->method('getAddresses')->willReturn('10.0.0.0/8');

        // On 2.4.8+, IPAddress mock handles CIDR; on older, inline cidrMatch does
        if ($this->ipAddress !== null) {
            $this->ipAddress->method('isValidRange')->with('10.0.0.0/8')->willReturn(true);
            $this->ipAddress->method('rangeContainsAddress')
                ->with('10.0.0.0/8', '10.1.2.3')
                ->willReturn(true);
        }

        $this->assertFalse($this->model->isOn('10.1.2.3'));
    }

    public function testIsOnReturnsTrueWhenIpNotInCidrRange(): void
    {
        $this->storage->method('hasFlag')->willReturn(true);
        $this->storage->method('getAddresses')->willReturn('10.0.0.0/8');

        if ($this->ipAddress !== null) {
            $this->ipAddress->method('isValidRange')->with('10.0.0.0/8')->willReturn(true);
            $this->ipAddress->method('rangeContainsAddress')
                ->with('10.0.0.0/8', '192.168.1.1')
                ->willReturn(false);
        }

        $this->assertTrue($this->model->isOn('192.168.1.1'));
    }

    public function testIsOnWithEmptyAddressListReturnsTrueWhenFlagSet(): void
    {
        $this->storage->method('hasFlag')->willReturn(true);
        $this->storage->method('getAddresses')->willReturn('');
        $this->assertTrue($this->model->isOn('1.2.3.4'));
    }

    // ── set() ───────────────────────────────────────────────────────────

    public function testSetEnablesMaintenanceMode(): void
    {
        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('maintenance_mode_changed', ['isOn' => true]);
        $this->storage->expects($this->once())
            ->method('setFlag')
            ->with(true);

        $this->assertTrue($this->model->set(true));
    }

    public function testSetDisablesMaintenanceMode(): void
    {
        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('maintenance_mode_changed', ['isOn' => false]);
        $this->storage->expects($this->once())
            ->method('setFlag')
            ->with(false);

        $this->assertTrue($this->model->set(false));
    }

    public function testSetDispatchesEventOnFallback(): void
    {
        $this->storage->method('setFlag')
            ->willThrowException(new \RuntimeException('Storage unavailable'));
        $this->flagDir->method('touch')->willReturn(true);

        // Parent::set() dispatches the event on fallback
        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('maintenance_mode_changed', ['isOn' => true]);

        $this->model->set(true);
    }

    // ── setAddresses() ──────────────────────────────────────────────────

    public function testSetAddressesStoresCommaSeparatedIps(): void
    {
        $this->storage->expects($this->once())
            ->method('setAddresses')
            ->with('1.2.3.4,5.6.7.8');

        $this->assertTrue($this->model->setAddresses('1.2.3.4,5.6.7.8'));
    }

    public function testSetAddressesSingleIp(): void
    {
        $this->storage->expects($this->once())
            ->method('setAddresses')
            ->with('192.168.1.1');

        $this->assertTrue($this->model->setAddresses('192.168.1.1'));
    }

    public function testSetAddressesCidrRange(): void
    {
        $this->storage->expects($this->once())
            ->method('setAddresses')
            ->with('10.0.0.0/8');

        $this->assertTrue($this->model->setAddresses('10.0.0.0/8'));
    }

    public function testSetAddressesClearsWhenEmpty(): void
    {
        $this->storage->expects($this->once())
            ->method('setAddresses')
            ->with('');

        $this->assertTrue($this->model->setAddresses(''));
    }

    public function testSetAddressesThrowsOnInvalidFormat(): void
    {
        $this->storage->expects($this->never())->method('setAddresses');
        $this->expectException(\InvalidArgumentException::class);
        $this->model->setAddresses('not valid, spaces');
    }

    // ── getAddressInfo() ────────────────────────────────────────────────

    public function testGetAddressInfoReturnsArrayOfIps(): void
    {
        $this->storage->method('getAddresses')->willReturn('1.2.3.4,5.6.7.8');
        $this->assertSame(['1.2.3.4', '5.6.7.8'], $this->model->getAddressInfo());
    }

    public function testGetAddressInfoReturnsSingleIp(): void
    {
        $this->storage->method('getAddresses')->willReturn('10.0.0.1');
        $this->assertSame(['10.0.0.1'], $this->model->getAddressInfo());
    }

    public function testGetAddressInfoReturnsEmptyArrayWhenNoAddresses(): void
    {
        $this->storage->method('getAddresses')->willReturn('');
        $this->assertSame([], $this->model->getAddressInfo());
    }

    // ── Fallback to file-based ──────────────────────────────────────────

    public function testIsOnFallsBackToFileOnStorageException(): void
    {
        $this->storage->method('hasFlag')
            ->willThrowException(new \RuntimeException('Storage unavailable'));
        $this->flagDir->method('isExist')->willReturn(false);

        $this->assertFalse($this->model->isOn());
    }

    public function testIsOnFallsBackAndReturnsTrueWhenFlagFileExists(): void
    {
        $this->storage->method('hasFlag')
            ->willThrowException(new \RuntimeException('Storage unavailable'));
        $this->flagDir->method('isExist')->willReturn(true);

        $this->assertTrue($this->model->isOn());
    }

    public function testSetEnableFallsBackToFileOnStorageException(): void
    {
        $this->storage->method('setFlag')
            ->willThrowException(new \RuntimeException('Storage unavailable'));
        $this->flagDir->expects($this->once())->method('touch')->willReturn(true);

        $this->assertTrue($this->model->set(true));
    }

    public function testSetDisableFallsBackToFileOnStorageException(): void
    {
        $this->storage->method('setFlag')
            ->willThrowException(new \RuntimeException('Storage unavailable'));
        $this->flagDir->method('isExist')->willReturn(true);
        $this->flagDir->expects($this->once())->method('delete')->willReturn(true);

        $this->assertTrue($this->model->set(false));
    }

    public function testSetAddressesFallsBackToFileOnStorageException(): void
    {
        $this->storage->method('setAddresses')
            ->willThrowException(new \RuntimeException('Storage unavailable'));
        $this->flagDir->expects($this->once())
            ->method('writeFile')
            ->with('.maintenance.ip', '1.2.3.4')
            ->willReturn(10);

        $this->assertTrue($this->model->setAddresses('1.2.3.4'));
    }

    public function testGetAddressInfoFallsBackToFileOnStorageException(): void
    {
        $this->storage->method('getAddresses')
            ->willThrowException(new \RuntimeException('Storage unavailable'));
        $this->flagDir->method('isExist')->willReturn(true);
        $this->flagDir->method('readFile')->willReturn('1.2.3.4,5.6.7.8');

        $this->assertSame(['1.2.3.4', '5.6.7.8'], $this->model->getAddressInfo());
    }
}
