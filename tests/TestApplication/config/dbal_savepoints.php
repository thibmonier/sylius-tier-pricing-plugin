<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Test application only: every test runs inside a transaction, so the transactions of the
 * plugin are nested. DBAL 4 always nests with savepoints; DBAL 3 (Sylius 2.2) only when asked,
 * otherwise an inner rollback marks the test transaction "rollback only" and undoes nothing.
 */
return static function (ContainerConfigurator $container): void {
    $version = InstalledVersions::getVersion('doctrine/dbal');
    if (null !== $version && str_starts_with($version, '3.')) {
        $container->extension('doctrine', ['dbal' => ['use_savepoints' => true]]);
    }
};
