<?php

declare(strict_types=1);


namespace craft\cachecascade\tests;

use craft\cachecascade\CascadeCache;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidConfigException;
use yii\caching\ArrayCache;
use yii\caching\CacheInterface;

class CascadeCacheCooldownTest extends TestCase
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

    public function testNegativeCooldownDurationThrowsException(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('cooldownDuration');

        new CascadeCache([
            'caches' => [new ArrayCache()],
            'cooldownDuration' => -1,
        ]);
    }

    public function testFailedCacheIsSkippedUntilCooldownExpires(): void
    {
        $primaryCalls = 0;
        $primary = $this->createMock(CacheInterface::class);
        $primary->method('get')
            ->willReturnCallback(static function () use (&$primaryCalls) {
                $primaryCalls++;

                if ($primaryCalls === 1) {
                    throw new \RuntimeException('Connection failed');
                }

                return 'primary-value';
            });

        $cache = new TestableCascadeCache([
            'caches' => [$primary, $this->fallbackCache()],
            'cooldownDuration' => 10,
            'currentTime' => 100,
        ]);

        static::assertSame('fallback-value', $cache->get('test-key'));

        $cache->currentTime = 105;

        static::assertSame('fallback-value', $cache->get('test-key'));
        static::assertSame(1, $primaryCalls);

        $cache->currentTime = 111;

        static::assertSame('primary-value', $cache->get('test-key'));
        static::assertSame(2, $primaryCalls);
    }

    public function testDefaultCooldownDurationPreservesOperationBasedRetries(): void
    {
        $primary = $this->createMock(CacheInterface::class);
        $primary->expects($this->exactly(2))
            ->method('get')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $cache = new CascadeCache([
            'caches' => [$primary, $this->fallbackCache()],
        ]);

        static::assertSame('fallback-value', $cache->get('test-key'));
        static::assertSame('fallback-value', $cache->get('test-key'));
    }

    public function testPreventedCascadeDoesNotStartCooldown(): void
    {
        $primaryCalls = 0;
        $primary = $this->createMock(CacheInterface::class);
        $primary->method('get')
            ->willReturnCallback(static function () use (&$primaryCalls) {
                $primaryCalls++;
                throw new \RuntimeException('Connection failed');
            });

        $cache = new CascadeCache([
            'caches' => [$primary, $this->fallbackCache()],
        ]);

        $cache->on(CascadeCache::EVENT_CACHE_FAILED, static function ($event) {
            if ($event->operation === 'get') {
                $event->shouldCascade = false;
            }
        });

        try {
            $cache->get('key');
            static::fail('Expected prevented cascade to rethrow the original exception.');
        } catch (\RuntimeException $exception) {
            static::assertSame('Connection failed', $exception->getMessage());
        }

        try {
            $cache->get('key');
            static::fail('Expected prevented cascade to rethrow the original exception.');
        } catch (\RuntimeException $exception) {
            static::assertSame('Connection failed', $exception->getMessage());
        }

        static::assertSame(2, $primaryCalls);
    }

    private function fallbackCache(): ArrayCache
    {
        $cache = new ArrayCache();
        $cache->set('test-key', 'fallback-value');

        return $cache;
    }
}

class TestableCascadeCache extends CascadeCache
{
    public int $currentTime = 0;

    protected function currentTime(): int
    {
        return $this->currentTime;
    }
}
