<?php

declare(strict_types=1);

namespace RectorPhalcon\PHPStan;

/**
 * Holds the DI service name -> class name map collected from setShared()/set()
 * registrations during configuration time. Read by DiServiceReturnTypeExtension
 * to give `Di::get('name')` a precise type.
 *
 * Rector runs file processing in parallel worker processes. The configuration
 * phase (scanner) runs in the main process while the PHPStan extension runs in
 * workers, so the map is persisted to a temp file to cross process boundaries.
 * Static state is kept as a fast path for the single-process / debug runs.
 */
final class DiServiceRegistry
{
    /** @var array<string, class-string>|null */
    private static ?array $services = null;

    private static ?string $storageFile = null;

    public static function setStorageFile(string $path): void
    {
        self::$storageFile = $path;
    }

    /** @param class-string $className */
    public static function set(string $serviceName, string $className): void
    {
        self::ensureLoaded();
        self::$services[$serviceName] = $className;
        self::persist();
    }

    /** @return class-string|null */
    public static function get(string $serviceName): ?string
    {
        self::ensureLoaded();

        return self::$services[$serviceName] ?? null;
    }

    /** @return array<string, class-string> */
    public static function all(): array
    {
        self::ensureLoaded();

        return self::$services;
    }

    /**
     * Drop the in-memory cache so the next access reloads from the persisted
     * file. Used to simulate a fresh worker process.
     */
    public static function reset(): void
    {
        self::$services = null;
    }

    public static function clear(): void
    {
        self::$services = [];
        $file = self::storageFilePath();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private static function ensureLoaded(): void
    {
        if (self::$services !== null) {
            return;
        }

        self::$services = [];
        $file = self::storageFilePath();
        if (is_file($file)) {
            $contents = @file_get_contents($file);
            if ($contents !== false) {
                $data = json_decode($contents, true);
                if (is_array($data)) {
                    /** @var array<string, class-string> $data */
                    self::$services = $data;
                }
            }
        }
    }

    private static function persist(): void
    {
        $file = self::storageFilePath();
        $dir = dirname($file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($file, json_encode(self::$services));
    }

    private static function storageFilePath(): string
    {
        if (self::$storageFile !== null) {
            return self::$storageFile;
        }

        return sys_get_temp_dir() . '/rector-phalcon-di-services.json';
    }
}
