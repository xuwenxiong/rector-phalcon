<?php

declare(strict_types=1);

namespace RectorPhalcon\Phalcon4\Rector\FileNode;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Rector\PhpParser\Node\BetterNodeFinder;
use Rector\PhpParser\Node\FileNode;
use Rector\Rector\AbstractRector;
use Rector\RuleDoc\CodeSample\CodeSample;
use Rector\RuleDoc\RuleDefinition;
use RectorPhalcon\PHPStan\DiServiceRegistry;

/**
 * Migrates Phalcon 3 cache-backend methods on variables fetched from the DI
 * container, where PHPStan cannot infer the type (e.g. a custom getDI() that
 * returns mixed).
 *
 * Instead of relying on type inference, this rule looks up the service name
 * in DiServiceRegistry (built at configuration time by DiServiceScanner from
 * set()/setShared() closures). If the service was registered as Phalcon\Cache,
 * exists()/save() calls on variables fetched via get()/getShared() are renamed
 * to the Phalcon 4 PSR-16 equivalents has()/set().
 */
final class DiServiceMethodRector extends AbstractRector
{
    /** @var array<string, string> */
    private const METHOD_MAP = [
        'exists' => 'has',
        'save' => 'set',
    ];

    private BetterNodeFinder $betterNodeFinder;

    public function __construct(BetterNodeFinder $betterNodeFinder)
    {
        $this->betterNodeFinder = $betterNodeFinder;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Rename cache methods on DI-fetched services (registered as Phalcon\Cache) from Phalcon 3 to Phalcon 4',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
$cache = $this->getDI()->get('cache');
$cache->exists('key');
$cache->save('key', 'value');
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
$cache = $this->getDI()->get('cache');
$cache->has('key');
$cache->set('key', 'value');
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [FileNode::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (! $node instanceof FileNode) {
            return null;
        }

        // Variables assigned from Di::get('service')/getShared('service') where
        // the service is a registered Phalcon\Cache.
        $cacheVariables = [];
        $assigns = $this->betterNodeFinder->findInstanceOf($node->stmts, Assign::class);
        foreach ($assigns as $assign) {
            if (! $assign->var instanceof Variable || ! is_string($assign->var->name)) {
                continue;
            }
            $serviceName = $this->diFetchServiceName($assign->expr);
            if ($serviceName === null) {
                continue;
            }
            if (DiServiceRegistry::get($serviceName) !== 'Phalcon\Cache') {
                continue;
            }
            $cacheVariables[$assign->var->name] = true;
        }

        if ($cacheVariables === []) {
            return null;
        }

        $changed = false;
        $methodCalls = $this->betterNodeFinder->findInstanceOf($node->stmts, MethodCall::class);
        foreach ($methodCalls as $methodCall) {
            if (! $methodCall->var instanceof Variable || ! is_string($methodCall->var->name)) {
                continue;
            }
            if (! isset($cacheVariables[$methodCall->var->name])) {
                continue;
            }
            if (! $methodCall->name instanceof Identifier) {
                continue;
            }
            $oldName = strtolower($methodCall->name->name);
            if (! isset(self::METHOD_MAP[$oldName])) {
                continue;
            }
            $methodCall->name->name = self::METHOD_MAP[$oldName];
            $changed = true;
        }

        return $changed ? $node : null;
    }

    /**
     * If the expression is ->get('name')/getShared('name'), return the literal
     * service name; otherwise null.
     */
    private function diFetchServiceName(Node $expr): ?string
    {
        if (! $expr instanceof MethodCall || ! $expr->name instanceof Identifier) {
            return null;
        }
        if (! in_array(strtolower($expr->name->name), ['get', 'getshared'], true)) {
            return null;
        }
        $firstArg = $expr->getArgs()[0] ?? null;

        return ($firstArg !== null && $firstArg->value instanceof String_)
            ? $firstArg->value->value
            : null;
    }
}
