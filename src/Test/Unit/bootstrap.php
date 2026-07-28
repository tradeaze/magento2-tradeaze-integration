<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 *
 * PHPUnit bootstrap for running the unit tests standalone (outside a full
 * Magento installation). Registers Magento's test-framework autoloader so
 * generated classes (factories, extension attributes) can be mocked even
 * though they only exist as generated code at runtime.
 */
declare(strict_types=1);

use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesInterfaceGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader;

require_once __DIR__ . '/../../../vendor/autoload.php';

$generatorIo = new Io(
    new File(),
    sys_get_temp_dir() . '/tradeaze-module-unit-tests/generation'
);
$generatedClassesAutoloader = new GeneratedClassesAutoloader(
    [
        new ExtensionAttributesGenerator(),
        new ExtensionAttributesInterfaceGenerator(),
        new FactoryGenerator(),
    ],
    $generatorIo
);
spl_autoload_register([$generatedClassesAutoloader, 'load']);
