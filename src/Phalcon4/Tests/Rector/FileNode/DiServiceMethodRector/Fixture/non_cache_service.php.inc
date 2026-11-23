<?php

namespace RectorPhalcon\Phalcon4\Tests\Rector\FileNode\DiServiceMethodRector\Fixture;

final class NonCacheService
{
    public function run($di): void
    {
        $session = $di->get('session');
        $session->exists('key');
        $session->save('key', 'value');
    }
}
-----
<?php

namespace RectorPhalcon\Phalcon4\Tests\Rector\FileNode\DiServiceMethodRector\Fixture;

final class NonCacheService
{
    public function run($di): void
    {
        $session = $di->get('session');
        $session->exists('key');
        $session->save('key', 'value');
    }
}
