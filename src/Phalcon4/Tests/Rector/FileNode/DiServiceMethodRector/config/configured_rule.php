<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorPhalcon\PHPStan\DiServiceRegistry;
use RectorPhalcon\Phalcon4\Rector\FileNode\DiServiceMethodRector;

return static function (RectorConfig $rectorConfig): void {
    // Simulate DiServiceScanner having found: setShared('cache', fn() => new Phalcon\Cache)
    DiServiceRegistry::set('cache', 'Phalcon\Cache');
    DiServiceRegistry::set('myCache', 'Phalcon\Cache');
    $rectorConfig->rule(DiServiceMethodRector::class);
};
