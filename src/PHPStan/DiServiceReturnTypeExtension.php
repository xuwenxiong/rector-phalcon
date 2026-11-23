<?php

declare(strict_types=1);

namespace RectorPhalcon\PHPStan;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Gives `Di::get('service')` and `Di::getShared('service')` a precise return
 * type based on the service registrations collected by DiServiceScanner.
 *
 * Registered via src/PHPStan/di-services.neon, loaded by the 3 -> 4 set through
 * RectorConfig::phpstanConfig().
 *
 * One instance per DI class name is registered, because Phalcon 3/4 use
 * `Phalcon\Di` while Phalcon 5 uses `Phalcon\Di\Di`.
 */
final class DiServiceReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    private string $diClass;

    public function __construct(string $diClass)
    {
        $this->diClass = $diClass;
    }

    public function getClass(): string
    {
        return $this->diClass;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array(strtolower($methodReflection->getName()), ['get', 'getshared'], true);
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): ?Type {
        $firstArg = $methodCall->getArgs()[0] ?? null;
        if ($firstArg === null) {
            return null;
        }

        $serviceNames = $scope->getType($firstArg->value)->getConstantStrings();
        if (count($serviceNames) !== 1) {
            return null;
        }

        $className = DiServiceRegistry::get($serviceNames[0]->getValue());
        if ($className === null) {
            return null;
        }

        return new ObjectType($className);
    }
}
