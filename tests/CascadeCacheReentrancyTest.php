<?php

declare(strict_types=1);


namespace craft\cachecascade\tests;

use craft\cachecascade\CascadeCache;
use PHPUnit\Framework\TestCase;
use yii\caching\ArrayCache;
use yii\caching\CacheInterface;

class CascadeCacheReentrancyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Yii', false)) {
            require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
        }

        if (\Yii::$app === null) {
            new \yii\console\Application([
                'id' => 'test-app',
                'basePath' => dirname(__DIR__),
            ]);
        }
    }

    public function testReentrantGetReturnsFalse(): void
    {
        $reentrantCache = $this->createMock(CacheInterface::class);

        $cache = new CascadeCache([
            'caches' => [$reentrantCache],
        ]);

        // When set() is called, it triggers a re-entrant get() on the same CascadeCache
        $reentrantCache->method('set')->willReturnCallback(static function () use ($cache) {
            // This simulates what happens when DbCache::setValue() triggers
            // schema cache resolution back through CascadeCache
            $result = $cache->get('reentrant-key');
            static::assertFalse($result, 'Re-entrant get() should return false');
            return true;
        });

        static::assertTrue($cache->set('key', 'value'));
    }

    public function testReentrantSetReturnsFalse(): void
    {
        $reentrantCache = $this->createMock(CacheInterface::class);

        $cache = new CascadeCache([
            'caches' => [$reentrantCache],
        ]);

        $callCount = 0;
        $reentrantCache->method('set')->willReturnCallback(static function () use ($cache, &$callCount) {
            $callCount++;
            if ($callCount === 1) {
                // First (outer) call triggers a re-entrant set
                $result = $cache->set('schema-key', 'schema-data');
                static::assertFalse($result, 'Re-entrant set() should return false');
            }
            return true;
        });

        static::assertTrue($cache->set('key', 'value'));
        static::assertSame(1, $callCount, 'Inner cache set() should only be called once (re-entrant was blocked)');
    }

    public function testNormalOperationsWorkAfterReentrantCall(): void
    {
        $primary = new ArrayCache();

        $cache = new CascadeCache([
            'caches' => [$primary],
        ]);

        // First set triggers a re-entrant get via getOrSet callback
        $cache->getOrSet('outer', static function () use ($cache) {
            // Re-entrant call should be blocked
            $result = $cache->get('nested-key');
            static::assertFalse($result, 'Re-entrant call should be blocked');
            return 'outer-value';
        });

        // After the re-entrant operation completes, depth is back to 0
        $cache->set('post-reentrant', 'works');
        static::assertSame('works', $cache->get('post-reentrant'));
    }

    public function testReentrancyGuardResetsOnException(): void
    {
        $failing = $this->createMock(CacheInterface::class);
        $failing->method('get')->willThrowException(new \RuntimeException('Failed'));
        $failing->method('set')->willThrowException(new \RuntimeException('Failed'));

        $cache = new CascadeCache([
            'caches' => [$failing],
        ]);

        // All caches fail — returns failure value
        static::assertFalse($cache->get('key'));

        // Guard should be reset — next call should still attempt the cascade
        static::assertFalse($cache->set('key', 'value'));
    }

    public function testReentrantMultiGetReturnsEmptyArray(): void
    {
        $reentrantCache = $this->createMock(CacheInterface::class);

        $cache = new CascadeCache([
            'caches' => [$reentrantCache],
        ]);

        $reentrantCache->method('set')->willReturnCallback(static function () use ($cache) {
            $result = $cache->multiGet(['k1', 'k2']);
            static::assertSame([], $result, 'Re-entrant multiGet() should return empty array');
            return true;
        });

        static::assertTrue($cache->set('key', 'value'));
    }

    public function testReentrantExistsReturnsFalse(): void
    {
        $primary = new ArrayCache();
        $primary->set('existing-key', 'value');

        $cache = new CascadeCache([
            'caches' => [$primary],
        ]);

        // Verify exists works normally
        static::assertTrue($cache->exists('existing-key'));

        // Now test that re-entrant exists returns false
        $reentrantCache = $this->createMock(CacheInterface::class);

        $cache2 = new CascadeCache([
            'caches' => [$reentrantCache],
        ]);

        $reentrantCache->method('set')->willReturnCallback(static function () use ($cache2) {
            $result = $cache2->exists('any-key');
            static::assertFalse($result, 'Re-entrant exists() should return false');
            return true;
        });

        static::assertTrue($cache2->set('key', 'value'));
    }
}
