<?php

declare(strict_types=1);


namespace craft\cachecascade;

use Yii;
use yii\base\InvalidConfigException;
use yii\caching\Cache;
use yii\caching\CacheInterface;
use yii\di\Instance;

/**
 * CascadeCache cascades through multiple cache components on failure.
 *
 * Unlike Symfony's ChainAdapter which writes to ALL adapters for multi-tier caching,
 * this component is designed for resilience/failover - it writes to the first
 * available cache and cascades to the next only on failure.
 *
 * @property-read CacheInterface[] $resolvedCaches The resolved cache instances
 */
class CascadeCache extends Cache
{
    /**
     * @event CacheFailedEvent Triggered when a cache operation fails.
     * The event's `$shouldCascade` property controls whether to cascade to the next cache (default: true).
     */
    public const EVENT_CACHE_FAILED = 'cacheFailed';

    /**
     * @var array Array of cache component configurations, IDs, or instances.
     * Listed in priority order (first = primary, last = final fallback).
     *
     * Each element can be:
     * - A string: Component ID (e.g., 'redis', 'cache')
     * - An array: Yii2 component configuration
     * - An object: CacheInterface instance
     *
     * Example:
     * ```php
     * 'caches' => [
     *     'redis',                                              // Component ID
     *     ['class' => \yii\caching\FileCache::class],          // Configuration array
     *     $myCacheInstance,                                     // Instance
     * ]
     * ```
     */
    public array $caches = [];

    /**
     * @var CacheInterface[]|null Resolved cache instances (lazy-loaded)
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
     * Resolves all configured caches to CacheInterface instances.
     *
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

    /**
     * Executes an operation across caches with cascade logic.
     *
     * @param string $operation Operation name for logging
     * @param callable $callback Function to execute on each cache: fn(CacheInterface): mixed
     * @param mixed $failureValue Value that indicates operation failure (triggers cascade)
     * @return mixed The result from the first successful cache, or $failureValue if all fail
     */
    protected function cascadeOperation(string $operation, callable $callback, $failureValue = false)
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

    // -------------------------------------------------------------------------
    // Public API overrides
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function get($key)
    {
        return $this->cascadeOperation('get', static fn (CacheInterface $cache) => $cache->get($key));
    }

    /**
     * @inheritdoc
     */
    public function exists($key): bool
    {
        return $this->cascadeOperation('exists', static fn (CacheInterface $cache) => $cache->exists($key), false);
    }

    /**
     * @inheritdoc
     */
    public function multiGet($keys): array
    {
        return $this->cascadeOperation('multiGet', static fn (CacheInterface $cache) => $cache->multiGet($keys), []);
    }

    /**
     * @inheritdoc
     */
    public function set($key, $value, $duration = null, $dependency = null): bool
    {
        return $this->cascadeOperation('set', static fn (CacheInterface $cache) => $cache->set($key, $value, $duration, $dependency));
    }

    /**
     * @inheritdoc
     */
    public function multiSet($items, $duration = null, $dependency = null): array
    {
        return $this->cascadeOperation('multiSet', static fn (CacheInterface $cache) => $cache->multiSet($items, $duration, $dependency), array_keys($items));
    }

    /**
     * @inheritdoc
     */
    public function add($key, $value, $duration = 0, $dependency = null): bool
    {
        return $this->cascadeOperation('add', static fn (CacheInterface $cache) => $cache->add($key, $value, $duration, $dependency));
    }

    /**
     * @inheritdoc
     */
    public function multiAdd($items, $duration = 0, $dependency = null): array
    {
        return $this->cascadeOperation('multiAdd', static fn (CacheInterface $cache) => $cache->multiAdd($items, $duration, $dependency), array_keys($items));
    }

    /**
     * @inheritdoc
     */
    public function delete($key): bool
    {
        return $this->cascadeOperation('delete', static fn (CacheInterface $cache) => $cache->delete($key));
    }

    /**
     * @inheritdoc
     */
    public function flush(): bool
    {
        return $this->cascadeOperation('flush', static fn (CacheInterface $cache) => $cache->flush());
    }

    /**
     * @inheritdoc
     */
    public function getOrSet($key, $callable, $duration = null, $dependency = null)
    {
        return $this->cascadeOperation('getOrSet', static fn (CacheInterface $cache) => $cache->getOrSet($key, $callable, $duration, $dependency));
    }

    // -------------------------------------------------------------------------
    // Abstract method stubs (required by parent Cache class)
    // These are not used since we override the public methods directly.
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    protected function getValue($key)
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function setValue($key, $value, $duration): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function addValue($key, $value, $duration): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function deleteValue($key): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function flushValues(): bool
    {
        return false;
    }
}
