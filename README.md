# Yii2 Cache Cascade

A Yii2 cache component that cascades through multiple cache drivers on failure, preventing app downtime due to cache outages.

> **Note:** This is not a multi-store/write-through cache. Only one cache component is used at a time. This makes the most sense when using a fast, in-memory cache like `ArrayCache` as a fallback, to prevent your application from crashing while the primary cache (e.g., Redis) is unavailable or restabilizing.

## Installation

```bash
composer require craftcms/yii2-cache-cascade
```

## Usage

Configure the cache component in your Yii2 application config:

```php
use craft\cachecascade\CascadeCache;
use craft\cachecascade\CacheFailedEvent;

'components' => [
    'cache' => [
        'class' => CascadeCache::class,
        'caches' => [
            'redisCache',
            [
                'class' => \yii\caching\ArrayCache::class,
            ],
        ],
        'on cacheFailed' => function (CacheFailedEvent $event) {
            // Custom logging
            Yii::error(
                "Cache failover: {$event->operation} failed on " . get_class($event->cache) . ': ' . $event->exception->getMessage(),
                'cache'
            );

            // Or send to external monitoring
            // MyMonitoring::trackCacheFailure(get_class($event->cache), $event->exception);

            // Optionally prevent cascading (will re-throw the exception)
            // $event->shouldCascade = false;
        },
    ],
    'redisCache' => [
        'class' => \yii\redis\Cache::class,
        'redis' => [
            'hostname' => 'localhost',
            'port' => 6379,
            'connectionTimeout' => 1,
            'dataTimeout' => 1,
            'retries' => 1,
            'retryInterval' => 0,
        ],
    ],
],
```

## Configuration

### `caches`

An array of cache components in priority order. Each element can be:

- **String**: A component ID (e.g., `'redis'`, `'cache'`)
- **Array**: A Yii2 component configuration
- **Object**: A `CacheInterface` instance

## Events

The component triggers a `CascadeCache::EVENT_CACHE_FAILED` event when a cache operation fails (shown in the usage example above). Use this for custom logging, monitoring, or to control cascade behavior.

### `CacheFailedEvent` Properties

| Property | Type | Description |
|----------|------|-------------|
| `$cache` | `CacheInterface` | The cache that failed |
| `$operation` | `string` | The operation that failed (`'get'`, `'set'`, etc.) |
| `$exception` | `\Throwable` | The exception that was thrown |
| `$shouldCascade` | `bool` | Whether to cascade to the next cache (default: `true`) |

## License

MIT
