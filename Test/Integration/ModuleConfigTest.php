<?php

namespace MageZero\ClusterMaintenance\Test\Integration;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

class ModuleConfigTest extends TestCase
{
    private const MODULE_NAME = 'MageZero_ClusterMaintenance';

    public function testModuleIsRegistered(): void
    {
        $registrar = new ComponentRegistrar();
        $paths = $registrar->getPaths(ComponentRegistrar::MODULE);

        $this->assertArrayHasKey(self::MODULE_NAME, $paths);
    }
}
