<?php

declare(strict_types=1);

namespace RectorPhalcon\Set;

use RectorPhalcon\PHPStan\DiServiceRegistry;

/**
 * Scans project PHP files for DI service registrations such as:
 *
 *     $di->setShared('cache', function () {
 *         return new \Phalcon\Cache($adapter);
 *     });
 *
 * and records a service-name -> class-name map in DiServiceRegistry, so the
 * PHPStan extension can type `Di::get('cache')` as Phalcon\Cache.
 *
 * Uses only PHP's built-in token_get_all(): Rector ships php-parser with
 * prefixed (scoped) class names, so referencing PhpParser\ParserFactory here
 * is not portable across consumer projects.
 */
final class DiServiceScanner
{
    /** @var list<string> */
    private const EXCLUDED_DIRS = ['vendor', 'node_modules', '.git', 'var', 'cache', 'tmp'];

    /**
     * @param list<string> $paths
     */
    public static function scan(array $paths, bool $includeCwd = true): void
    {
        DiServiceRegistry::clear();

        if ($paths === []) {
            $paths = self::pathsFromArgv();
        }

        // DI registrations usually live in a separate bootstrap/services file
        // that is not among the paths being refactored. Scan the whole working
        // directory (project root) so those registrations are always found.
        $scanPaths = $paths;
        if ($includeCwd) {
            $cwd = getcwd();
            if ($cwd !== false && ! in_array($cwd, $scanPaths, true)) {
                $scanPaths[] = $cwd;
            }
        }

        foreach (self::phpFiles($scanPaths) as $filePath) {
            $contents = @file_get_contents($filePath);
            if ($contents === false) {
                continue;
            }
            self::scanCode($contents);
        }
    }

    /**
     * Token-based scan: set()/setShared('service', <closure>) and the class
     * name from the closure's first `return new Xxx`.
     */
    private static function scanCode(string $code): void
    {
        $tokens = token_get_all($code);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! self::isMethodNameToken($tokens[$i], ['set', 'setshared'])) {
                continue;
            }

            $openParenAt = self::nextSignificant($tokens, $i + 1);
            if ($openParenAt === null || $openParenAt >= $count || $tokens[$openParenAt] !== '(') {
                continue;
            }

            $serviceName = self::firstStringArg($tokens, $openParenAt);
            if ($serviceName === null) {
                continue;
            }

            // Find `function`/`fn` within this call's argument list.
            $closureAt = null;
            $depth = 0;
            for ($j = $openParenAt; $j < $count; $j++) {
                $t = $tokens[$j];
                if ($t === '(') {
                    $depth++;
                    continue;
                }
                if ($t === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                    continue;
                }
                if ($depth === 1 && is_array($t) && in_array($t[0], [T_FUNCTION, T_FN], true)) {
                    $closureAt = $j;
                    break;
                }
            }
            if ($closureAt === null) {
                continue;
            }

            $className = self::findReturnedClass($tokens, $closureAt);
            if ($className !== null) {
                DiServiceRegistry::set($serviceName, self::normalizeClassName($className));
            }
        }
    }

    private static function findReturnedClass(array $tokens, int $closureAt): ?string
    {
        $count = count($tokens);

        // Arrow function: fn () => new Xxx — first T_DOUBLE_ARROW after closure.
        if (is_array($tokens[$closureAt]) && $tokens[$closureAt][0] === T_FN) {
            for ($i = $closureAt + 1; $i < $count; $i++) {
                if ($tokens[$i] !== T_DOUBLE_ARROW && ! (is_array($tokens[$i]) && $tokens[$i][0] === T_DOUBLE_ARROW)) {
                    continue;
                }
                $next = self::nextSignificant($tokens, $i + 1);
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_NEW) {
                    $classAt = self::nextSignificant($tokens, $next + 1);
                    if ($classAt !== null && is_array($tokens[$classAt])) {
                        return self::readClassName($tokens, $classAt);
                    }
                }
                break;
            }
            return null;
        }

        // Regular closure: `return new Xxx`.
        for ($i = $closureAt + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || $token[0] !== T_RETURN) {
                continue;
            }
            $next = self::nextSignificant($tokens, $i + 1);
            if ($next === null || ! is_array($tokens[$next]) || $tokens[$next][0] !== T_NEW) {
                continue;
            }
            $classAt = self::nextSignificant($tokens, $next + 1);
            if ($classAt === null || ! is_array($tokens[$classAt])) {
                continue;
            }

            return self::readClassName($tokens, $classAt);
        }

        return null;
    }

    private static function readClassName(array $tokens, int $start): ?string
    {
        $parts = [];
        $count = count($tokens);
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $parts[] = $token[1];
            } elseif ($token === '\\') {
                $parts[] = '\\';
            } else {
                break;
            }
        }

        return $parts === [] ? null : implode('', $parts);
    }

    /** @param mixed $token */
    private static function isMethodNameToken($token, array $names): bool
    {
        return is_array($token)
            && $token[0] === T_STRING
            && in_array(strtolower($token[1]), $names, true);
    }

    private static function firstStringArg(array $tokens, int $openParenAt): ?string
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $openParenAt; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(') {
                $depth++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    return null;
                }
                continue;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $depth === 1) {
                $value = $token[1];
                $quote = $value[0] ?? '';
                if ($quote === "'" || $quote === '"') {
                    $value = substr($value, 1, -1);
                }
                return $value;
            }
        }

        return null;
    }

    /** @return int|null */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }

        return null;
    }

    private static function normalizeClassName(string $className): string
    {
        $className = ltrim($className, '\\');
        if (preg_match('~^Phalcon\\\\Cache\\\\Backend\\\\[A-Za-z]+$~i', $className) === 1) {
            return 'Phalcon\Cache';
        }

        return $className;
    }

    /** @return list<string> */
    private static function pathsFromArgv(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        $paths = [];
        $skipNext = false;
        $optionsWithValue = ['--config', '-c', '--output-format', '--memory-limit', '--cache-directory', '--parallel-job-size'];

        foreach ($argv as $index => $arg) {
            if ($index === 0) {
                continue;
            }
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if (in_array($arg, $optionsWithValue, true)) {
                $skipNext = true;
                continue;
            }
            if (str_starts_with($arg, '-')) {
                continue;
            }
            if (in_array($arg, ['process', 'check', 'worker'], true)) {
                continue;
            }
            $paths[] = $arg;
        }

        return $paths;
    }

    /**
     * @param list<string> $paths
     * @return iterable<string>
     */
    private static function phpFiles(array $paths): iterable
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                if (str_ends_with($path, '.php')) {
                    yield $path;
                }
                continue;
            }
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if (self::isExcluded($file->getPathname())) {
                    continue;
                }
                yield $file->getPathname();
            }
        }
    }

    private static function isExcluded(string $path): bool
    {
        foreach (self::EXCLUDED_DIRS as $dir) {
            if (str_contains($path, DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
