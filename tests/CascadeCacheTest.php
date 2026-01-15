<?php

declare(strict_types=1);


namespace craft\cachecascade\tests;

use craft\cachecascade\CascadeCache;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidConfigException;
use yii\caching\ArrayCache;
use yii\caching\CacheInterface;

class CascadeCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock Yii application for testing
        if (!class_exists('Yii', false)) {
            require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
        }

        // Create minimal app if not exists
        if (\Yii::$app === null) {
            new \yii\console\Application([
                'id' => 'test-app',
                'basePath' => dirname(__DIR__),
            ]);
        }
    }

    public function testEmptyConfigThrowsException(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('at least one cache');

        new CascadeCache(['caches' => []]);
    }

    public function testGetFromPrimaryCache(): void
    {
        $primary = new ArrayCache();
        $primary->set('test-key', 'primary-value');

        $secondary = new ArrayCache();
        $secondary->set('test-key', 'secondary-value');

        $cache = new CascadeCache([
            'caches' => [$primary, $secondary],
        ]);

        static::assertSame('primary-value', $cache->get('test-key'));
    }

    public function testNoCascadeOnFalseByDefault(): void
    {
        $primary = new ArrayCache(); // Empty, will return false

        $secondary = new ArrayCache();
        $secondary->set('test-key', 'fallback-value');

        $cache = new CascadeCache([
            'caches' => [$primary, $secondary],
        ]);

        // Default behavior: no cascade on false, so returns false from primary
        static::assertFalse($cache->get('test-key'));
    }

    public function testFallbackOnException(): void
    {
        $primary = $this->createMock(CacheInterface::class);
        $primary->method('get')->willThrowException(new \RuntimeException('Connection failed'));

        $secondary = new ArrayCache();
        $secondary->set('test-key', 'fallback-value');

        $cache = new CascadeCache([
            'caches' => [$primary, $secondary],
        ]);

        static::assertSame('fallback-value', $cache->get('test-key'));
    }

    public function testWriteToFirstAvailable(): void
    {
        $failing = $this->createMock(CacheInterface::class);
        $failing->method('set')->willThrowException(new \RuntimeException('Connection failed'));

        $working = new ArrayCache();

        $cache = new CascadeCache([
            'caches' => [$failing, $working],
        ]);

        $result = $cache->set('key', 'value');
        static::assertTrue($result);
        static::assertSame('value', $working->get('key'));
    }

    public function testNoCascadeOnSetReturningFalse(): void
    {
        $failing = $this->createMock(CacheInterface::class);
        $failing->method('set')->willReturn(false);

        $working = new ArrayCache();

        $cache = new CascadeCache([
            'caches' => [$failing, $working],
        ]);

        // Default behavior: no cascade on false return
        $result = $cache->set('key', 'value');
        static::assertFalse($result);
        static::assertFalse($working->get('key')); // Not written to secondary
    }

    public function testAllCachesFailReturnsFailureValue(): void
    {
        $failing1 = $this->createMock(CacheInterface::class);
        $failing1->method('get')->willReturn(false);

        $failing2 = $this->createMock(CacheInterface::class);
        $failing2->method('get')->willReturn(false);

        $cache = new CascadeCache([
            'caches' => [$failing1, $failing2],
        ]);

        static::assertFalse($cache->get('missing-key'));
    }

    public function testCacheFailedEventTriggered(): void
    {
        $eventTriggered = false;
        $capturedEvent = null;

        $exception = new \RuntimeException('Connection failed');
        $primary = $this->createMock(CacheInterface::class);
        $primary->method('get')->willThrowException($exception);

        $secondary = new ArrayCache();
        $secondary->set('key', 'value');

        $cache = new CascadeCache([
            'caches' => [$primary, $secondary],
        ]);

        $cache->on(CascadeCache::EVENT_CACHE_FAILED, static function ($event) use (&$eventTriggered, &$capturedEvent) {
            $eventTriggered = true;
            $capturedEvent = $event;
        });

        $cache->get('key');

        static::assertTrue($eventTriggered);
        static::assertSame('get', $capturedEvent->operation);
        static::assertSame($exception, $capturedEvent->exception);
        static::assertTrue($capturedEvent->shouldCascade);
    }

    public function testEventCanPreventCascade(): void
    {
        $exception = new \RuntimeException('Connection failed');
        $primary = $this->createMock(CacheInterface::class);
        $primary->method('get')->willThrowException($exception);

        $secondary = new ArrayCache();
        $secondary->set('key', 'value');

        $cache = new CascadeCache([
            'caches' => [$primary, $secondary],
        ]);

        $cache->on(CascadeCache::EVENT_CACHE_FAILED, static function ($event) {
            $event->shouldCascade = false; // Prevent cascade
        });

        // Should re-throw the exception since cascade was prevented
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection failed');

        $cache->get('key');
    }

    public function testDeleteOperation(): void
    {
        $primary = new ArrayCache();
        $primary->set('key', 'value');

        $cache = new CascadeCache([
            'caches' => [$primary],
        ]);

        static::assertTrue($cache->delete('key'));
        static::assertFalse($primary->get('key'));
    }

    public function testFlushOperation(): void
    {
        $primary = new ArrayCache();
        $primary->set('key1', 'value1');
        $primary->set('key2', 'value2');

        $cache = new CascadeCache([
            'caches' => [$primary],
        ]);

        static::assertTrue($cache->flush());
        static::assertFalse($primary->get('key1'));
        static::assertFalse($primary->get('key2'));
    }

    public function testExistsOperation(): void
    {
        $primary = new ArrayCache();
        $primary->set('existing', 'value');

        $cache = new CascadeCache([
            'caches' => [$primary],
        ]);

        static::assertTrue($cache->exists('existing'));
        static::assertFalse($cache->exists('non-existing'));
    }

    public function testAddOperation(): void
    {
        $primary = new ArrayCache();

        $cache = new CascadeCache([
            'caches' => [$primary],
        ]);

        // First add should succeed
        static::assertTrue($cache->add('key', 'value'));
        static::assertSame('value', $primary->get('key'));

        // Second add should fail (key exists)
        static::assertFalse($cache->add('key', 'new-value'));
        static::assertSame('value', $primary->get('key')); // Original value unchanged
    }

    public function testMultiGetOperation(): void
    {
        $primary = new ArrayCache();
        $primary->set('key1', 'value1');
        $primary->set('key2', 'value2');

        $cache = new CascadeCache([
            'caches' => [$primary],
        ]);

        $result = $cache->multiGet(['key1', 'key2', 'key3']);

        static::assertSame('value1', $result['key1']);
        static::assertSame('value2', $result['key2']);
        static::assertFalse($result['key3']);
    }

    public function testMultiSetOperation(): void
    {
        $primary = new ArrayCache();

        $cache = new CascadeCache([
            'caches' => [$primary],
        ]);

        $result = $cache->multiSet([
            'key1' => 'value1',
            'key2' => 'value2',
        ]);

        static::assertEquals([], $result); // Empty array means no failures
        static::assertSame('value1', $primary->get('key1'));
        static::assertSame('value2', $primary->get('key2'));
    }

    public function testGetOrSetOperation(): void
    {
        $primary = new ArrayCache();

        $cache = new CascadeCache([
            'caches' => [$primary],
        ]);

        $callCount = 0;
        $callable = static function () use (&$callCount) {
            $callCount++;
            return 'computed-value';
        };

        // First call should invoke callable
        $result1 = $cache->getOrSet('key', $callable);
        static::assertSame('computed-value', $result1);
        static::assertSame(1, $callCount);

        // Second call should return cached value
        $result2 = $cache->getOrSet('key', $callable);
        static::assertSame('computed-value', $result2);
        static::assertSame(1, $callCount); // Not incremented
    }

    public function testInlineConfigResolution(): void
    {
        $cache = new CascadeCache([
            'caches' => [
                ['class' => ArrayCache::class],
            ],
        ]);

        $resolved = $cache->getResolvedCaches();

        static::assertCount(1, $resolved);
        static::assertInstanceOf(ArrayCache::class, $resolved[0]);
    }

    public function testInvalidCacheConfigThrowsException(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Failed to resolve cache at index 0');

        $cache = new CascadeCache([
            'caches' => [
                ['class' => 'NonExistentCacheClass'],
            ],
        ]);

        $cache->getResolvedCaches();
    }
}
