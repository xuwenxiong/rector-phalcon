<?php

declare(strict_types=1);

namespace RectorPhalcon\Set\Tests;

use PHPUnit\Framework\TestCase;
use RectorPhalcon\PHPStan\DiServiceRegistry;
use RectorPhalcon\Set\DiServiceScanner;

final class DiServiceScannerTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $storage = sys_get_temp_dir() . '/rector-phalcon-di-test-' . getmypid() . '.json';
        DiServiceRegistry::setStorageFile($storage);
        DiServiceRegistry::clear();
    }

    protected function tearDown(): void
    {
        DiServiceRegistry::clear();
        DiServiceRegistry::setStorageFile(sys_get_temp_dir() . '/rector-phalcon-di-services.json');
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function testExtractsSetSharedRegistration(): void
    {
        $file = $this->writeTemp(<<<'PHP'
<?php
$di->setShared('app.cache', function () {
    return new \Phalcon\Cache($adapter);
});
PHP);

        DiServiceScanner::scan([$file]);

        $this->assertSame('Phalcon\Cache', DiServiceRegistry::get('app.cache'));
    }

    public function testExtractsSetRegistrationWithArrowFunction(): void
    {
        $file = $this->writeTemp(<<<'PHP'
<?php
$di->set('logger', fn() => new \Phalcon\Logger('messages', []));
PHP);

        DiServiceScanner::scan([$file]);

        $this->assertSame('Phalcon\Logger', DiServiceRegistry::get('logger'));
    }

    public function testIgnoresRegistrationsWithoutNewReturn(): void
    {
        $file = $this->writeTemp(<<<'PHP'
<?php
$di->setShared('foo', function () use ($obj) {
    return $obj;
});
PHP);

        DiServiceScanner::scan([$file]);

        $this->assertNull(DiServiceRegistry::get('foo'));
    }

    public function testRegistryPersistsAcrossFreshState(): void
    {
        $file = $this->writeTemp(<<<'PHP'
<?php
$di->setShared('cache', fn() => new \Phalcon\Cache([]));
PHP);

        DiServiceScanner::scan([$file]);
        $this->assertSame('Phalcon\Cache', DiServiceRegistry::get('cache'));

        // Simulate a fresh worker process: drop static state and re-read from disk.
        DiServiceRegistry::reset();
        $this->assertSame('Phalcon\Cache', DiServiceRegistry::get('cache'));
    }

    private function writeTemp(string $contents): string
    {
        $path = sys_get_temp_dir() . '/rector-phalcon-scanner-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
