<?php

declare(strict_types=1);


namespace craft\cachecascade;

use yii\base\Event;
use yii\caching\CacheInterface;

/**
 * CacheFailedEvent is triggered when a cache operation fails.
 *
 * Set `$shouldCascade` to `false` to prevent cascading to the next cache
 * and re-throw the exception instead.
 */
class CacheFailedEvent extends Event
{
    /**
     * @var CacheInterface The cache that failed
     */
    public CacheInterface $cache;

    /**
     * @var string The operation that failed (e.g., 'get', 'set', 'delete')
     */
    public string $operation;

    /**
     * @var \Throwable The exception that was thrown
     */
    public \Throwable $exception;

    /**
     * @var bool Whether to cascade to the next cache. Defaults to `true`.
     * Set to `false` to stop cascading and re-throw the exception.
     */
    public bool $shouldCascade = true;
}
