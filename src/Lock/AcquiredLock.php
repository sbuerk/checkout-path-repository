<?php

declare(strict_types=1);

/*
 * This file is part of the "sbuerk/checkout-path-repository" composer plugin.
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 *
 * For the full copyright and license information, please read the LICENSE file
 * that was distributed with this source code.
 */

namespace SBUERK\CheckoutPathRepository\Lock;

/**
 * A held lock. Released explicitly with {@see release()} or, as a safety net,
 * when the object is destroyed.
 */
final class AcquiredLock
{
    /**
     * @var resource|null
     */
    private $handle;

    /**
     * @param resource $handle
     */
    public function __construct($handle, public readonly string $file)
    {
        $this->handle = $handle;
    }

    public function __destruct()
    {
        $this->release();
    }

    public function isHeld(): bool
    {
        return $this->handle !== null;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
