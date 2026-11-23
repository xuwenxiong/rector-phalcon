<?php

declare(strict_types=1);

namespace RectorPhalcon\Set;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\Node\Stmt\Return_;
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
 */
final class DiServiceScanner
{
    /** @var list<string> */
    private const EXCLUDED_DIRS = ['vendor', 'node_modules', '.git', 'var', 'cache', 'tmp'];

    /**
     * @param list<string> $paths
     */
    public static function scan(array $paths): void
    {
        DiServiceRegistry::clear();

        if ($paths === []) {
            $paths = self::pathsFromArgv();
        }

        // DI registrations usually live in a separate bootstrap/services file
        // that is not among the paths being refactored. Scan the whole working
        // directory (project root) so those registrations are always found.
        // The CLI-provided paths are kept too, in case they point outside cwd.
        $scanPaths = $paths;
        $cwd = getcwd();
        if ($cwd !== false && ! in_array($cwd, $scanPaths, true)) {
            $scanPaths[] = $cwd;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $nodeFinder = new NodeFinder();

        foreach (self::phpFiles($scanPaths) as $filePath) {
            $contents = @file_get_contents($filePath);
            if ($contents === false) {
                continue;
            }
            try {
                $stmts = $parser->parse($contents);
            } catch (\Throwable) {
                continue;
            }
            if ($stmts === null) {
                continue;
            }

            $calls = $nodeFinder->findInstanceOf($stmts, MethodCall::class);
            foreach ($calls as $call) {
                self::processRegistration($call, $nodeFinder);
            }
        }
    }

    private static function processRegistration(MethodCall $call, NodeFinder $nodeFinder): void
    {
        if (! $call->name instanceof Node\Identifier) {
            return;
        }
        if (! in_array(strtolower($call->name->name), ['set', 'setshared'], true)) {
            return;
        }

        $serviceNameArg = $call->getArgs()[0] ?? null;
        $factoryArg = $call->getArgs()[1] ?? null;
        if ($serviceNameArg === null || ! $serviceNameArg->value instanceof String_) {
            return;
        }
        if ($factoryArg === null) {
            return;
        }

        $factory = $factoryArg->value;
        if (! $factory instanceof Closure && ! $factory instanceof Node\Expr\ArrowFunction) {
            return;
        }

        $className = self::resolveReturnedClass($factory, $nodeFinder);
        if ($className !== null) {
            DiServiceRegistry::set($serviceNameArg->value->value, $className);
        }
    }

    private static function resolveReturnedClass(Node $factory, NodeFinder $nodeFinder): ?string
    {
        // Arrow function: fn() => new Xxx(...)
        if ($factory instanceof Node\Expr\ArrowFunction && $factory->expr instanceof New_) {
            return self::classNameFromNew($factory->expr);
        }

        // Closure: find return statements
        $returns = $nodeFinder->findInstanceOf($factory->stmts ?? [], Return_::class);
        foreach ($returns as $return) {
            if ($return->expr instanceof New_) {
                $class = self::classNameFromNew($return->expr);
                if ($class !== null) {
                    return $class;
                }
            }
        }

        return null;
    }

    private static function classNameFromNew(New_ $new): ?string
    {
        if (! $new->class instanceof Name) {
            return null;
        }

        return $new->class->toString();
    }

    /**
     * @return list<string>
     */
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
