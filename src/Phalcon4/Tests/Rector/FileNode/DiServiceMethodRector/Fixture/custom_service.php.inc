<?php

namespace RectorPhalcon\Phalcon4\Tests\Rector\FileNode\DiServiceMethodRector\Fixture;

final class CustomService
{
    public function run($di): void
    {
        $cache = $this->getDI()->getShared('myCache');
        $cache->exists('key');
        $cache->save('key', 'value');
    }
}
-----
<?php

namespace RectorPhalcon\Phalcon4\Tests\Rector\FileNode\DiServiceMethodRector\Fixture;

final class CustomService
{
    public function run($di): void
    {
        $cache = $this->getDI()->getShared('myCache');
        $cache->has('key');
        $cache->set('key', 'value');
    }
}
