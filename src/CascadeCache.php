<?php

declare(strict_types=1);


namespace craft\cachecascade;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\caching\CacheInterface;
use yii\di\Instance;

/**
 * CascadeCache cascades through multiple cache components on failure.
 *
 * Unlike Symfony's ChainAdapter which writes to ALL adapters for multi-tier caching,
 * this component is designed for resilience/failover - it writes to the first
 * available cache and cascades to the next only on failure.
 *
 * @property-read CacheInterface[] $resolvedCaches
 */
class CascadeCache extends Component implements CacheInterface
{
    /**
     * @event CacheFailedEvent Triggered when a cache operation fails.
     * The event's `$shouldCascade` property controls whether to cascade to the next cache (default: true).
     */
    public const EVENT_CACHE_FAILED = 'cacheFailed';

    /**
     * @var array Array of cache components/configs in priority order.
     */
    public array $caches = [];

    /**
     * @var CacheInterface[]|null
     */
    private ?array $_resolvedCaches = null;

    /**
     * @inheritdoc
     * @throws InvalidConfigException if no caches are configured
     */
    public function init(): void
    {
        parent::init();

        if ($this->caches === []) {
            throw new InvalidConfigException(
                'CascadeCache requires at least one cache to be configured in the "caches" property.'
            );
        }
    }

    /**
     * @return CacheInterface[]
     * @throws InvalidConfigException if a cache cannot be resolved
     */
    public function getResolvedCaches(): array
    {
        if ($this->_resolvedCaches !== null) {
            return $this->_resolvedCaches;
        }

        $this->_resolvedCaches = [];

        foreach ($this->caches as $index => $cache) {
            try {
                $resolved = Instance::ensure($cache, CacheInterface::class);
                $this->_resolvedCaches[] = $resolved;
            } catch (\Throwable $e) {
                throw new InvalidConfigException(
                    "Failed to resolve cache at index {$index}: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $this->_resolvedCaches;
    }

    protected function cascadeOperation(string $operation, callable $callback, mixed $failureValue = false): mixed
    {
        foreach ($this->getResolvedCaches() as $cache) {
            try {
                return $callback($cache);
            } catch (\Throwable $exception) {
                Yii::warning(
                    "CascadeCache: {$operation} failed on " . get_class($cache) . ': ' . $exception->getMessage(),
                    __METHOD__
                );

                $event = new CacheFailedEvent([
                    'cache' => $cache,
                    'operation' => $operation,
                    'exception' => $exception,
                ]);

                $this->trigger(self::EVENT_CACHE_FAILED, $event);

                if (!$event->shouldCascade) {
                    throw $exception;
                }
            }
        }

        return $failureValue;
    }

    /** @inheritdoc */
    public function buildKey($key)
    {
        return $this->cascadeOperation('buildKey', static fn(CacheInterface $cache) => $cache->buildKey($key), $key);
    }

    /** @inheritdoc */
    public function get($key)
    {
        return $this->cascadeOperation('get', static fn(CacheInterface $cache) => $cache->get($key));
    }

    /** @inheritdoc */
    public function exists($key): bool
    {
        return $this->cascadeOperation('exists', static fn(CacheInterface $cache) => $cache->exists($key), false);
    }

    /** @inheritdoc */
    public function multiGet($keys): array
    {
        return $this->cascadeOperation('multiGet', static fn(CacheInterface $cache) => $cache->multiGet($keys), []);
    }

    /** @inheritdoc */
    public function set($key, $value, $duration = null, $dependency = null): bool
    {
        return $this->cascadeOperation('set', static fn(CacheInterface $cache) => $cache->set($key, $value, $duration, $dependency));
    }

    /** @inheritdoc */
    public function multiSet($items, $duration = null, $dependency = null): array
    {
        return $this->cascadeOperation('multiSet', static fn(CacheInterface $cache) => $cache->multiSet($items, $duration, $dependency), array_keys($items));
    }

    /** @inheritdoc */
    public function add($key, $value, $duration = 0, $dependency = null): bool
    {
        return $this->cascadeOperation('add', static fn(CacheInterface $cache) => $cache->add($key, $value, $duration, $dependency));
    }

    /** @inheritdoc */
    public function multiAdd($items, $duration = 0, $dependency = null): array
    {
        return $this->cascadeOperation('multiAdd', static fn(CacheInterface $cache) => $cache->multiAdd($items, $duration, $dependency), array_keys($items));
    }

    /** @inheritdoc */
    public function delete($key): bool
    {
        return $this->cascadeOperation('delete', static fn(CacheInterface $cache) => $cache->delete($key));
    }

    /** @inheritdoc */
    public function flush(): bool
    {
        return $this->cascadeOperation('flush', static fn(CacheInterface $cache) => $cache->flush());
    }

    /** @inheritdoc */
    public function getOrSet($key, $callable, $duration = null, $dependency = null)
    {
        return $this->cascadeOperation('getOrSet', static fn(CacheInterface $cache) => $cache->getOrSet($key, $callable, $duration, $dependency));
    }

    /** @inheritdoc */
    public function offsetExists($key): bool
    {
        return $this->exists($key);
    }

    /** @inheritdoc */
    public function offsetGet($key): mixed
    {
        return $this->get($key);
    }

    /** @inheritdoc */
    public function offsetSet($key, $value): void
    {
        $this->set($key, $value);
    }

    /** @inheritdoc */
    public function offsetUnset($key): void
    {
        $this->delete($key);
    }
}
