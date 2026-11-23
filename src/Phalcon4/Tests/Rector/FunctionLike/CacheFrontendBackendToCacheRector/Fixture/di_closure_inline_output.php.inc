<?php

namespace RectorPhalcon\Phalcon4\Tests\Rector\FunctionLike\CacheFrontendBackendToCacheRector\Fixture;

final class DiClosureInlineOutput
{
    public function register($di, $config): void
    {
        $di->setShared('cache', function () use ($config) {
            return new \Phalcon\Cache\Backend\File(new \Phalcon\Cache\Frontend\Output(), array(
                'cacheDir' => $config->app->cache_path
            ));
        });
    }
}
-----
<?php

namespace RectorPhalcon\Phalcon4\Tests\Rector\FunctionLike\CacheFrontendBackendToCacheRector\Fixture;

final class DiClosureInlineOutput
{
    public function register($di, $config): void
    {
        $di->setShared('cache', function () use ($config) {
            return new \Phalcon\Cache((new \Phalcon\Cache\AdapterFactory(new \Phalcon\Storage\SerializerFactory()))->newInstance('stream', ['defaultSerializer' => 'none', 'storageDir' => $config->app->cache_path]));
        });
    }
}
