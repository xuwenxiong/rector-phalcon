<?php

namespace RectorPhalcon\Phalcon4\Tests\Rector\FileNode\DiServiceMethodRector\Fixture;

final class CacheMethods
{
    public function run($di): void
    {
        $cache = $di->get('cache');
        if ($cache->exists('key', 3600)) {
            $cache->save('key', 'value', 3600);
        }
    }
}
-----
<?php

namespace RectorPhalcon\Phalcon4\Tests\Rector\FileNode\DiServiceMethodRector\Fixture;

final class CacheMethods
{
    public function run($di): void
    {
        $cache = $di->get('cache');
        if ($cache->has('key', 3600)) {
            $cache->set('key', 'value', 3600);
        }
    }
}
