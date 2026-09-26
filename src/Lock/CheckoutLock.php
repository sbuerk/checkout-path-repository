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
 * Exclusive, advisory `flock()` on a lock file, used to serialise concurrent
 * `checkouts:clone` runs (e.g. two containers starting at the same time and
 * sharing the checkout directory through a bind mount).
 *
 * The lock is bound to the returned {@see AcquiredLock}; the operating system
 * releases it as well when the process ends, so a crashed run never leaves a
 * stale lock behind. The lock file itself is kept on purpose: deleting it
 * while another process waits on it would let a third process lock a new
 * inode concurrently.
 *
 * Stateless service.
 */
final class CheckoutLock
{
    public const DEFAULT_TIMEOUT_SECONDS = 600.0;
    private const POLL_INTERVAL_MICROSECONDS = 100_000;

    /**
     * @param callable():void|null $onWait called once when the lock is held by another process
     * @throws LockException
     */
    public function acquire(string $file, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS, ?callable $onWait = null): AcquiredLock
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new LockException(sprintf('Could not create the directory "%s" for the lock file.', $directory));
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            throw new LockException(sprintf('Could not open the lock file "%s".', $file));
        }

        $deadline = microtime(true) + max(0.0, $timeoutSeconds);
        $notified = false;
        while (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if ($wouldBlock !== 1) {
                fclose($handle);
                throw new LockException(sprintf('Could not lock "%s".', $file));
            }
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new LockException(sprintf(
                    'Timed out after %s seconds waiting for the lock "%s" held by another process.',
                    rtrim(rtrim(number_format($timeoutSeconds, 1, '.', ''), '0'), '.'),
                    $file,
                ));
            }
            if (!$notified && $onWait !== null) {
                $onWait();
                $notified = true;
            }
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        return new AcquiredLock($handle, $file);
    }
}
