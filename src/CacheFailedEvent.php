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
    public CacheInterface $cache;

    public string $operation;

    public \Throwable $exception;

    /**
     * @var bool Set to `false` to stop cascading and re-throw the exception.
     */
    public bool $shouldCascade = true;
}
