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

namespace SBUERK\CheckoutPathRepository\Tests\Unit\Lock;

use Composer\Util\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SBUERK\CheckoutPathRepository\Lock\CheckoutLock;
use SBUERK\CheckoutPathRepository\Lock\LockException;

/**
 * flock() locks belong to the open file description, so two handles opened
 * by the same process contend exactly like two processes do.
 */
final class CheckoutLockTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = __DIR__ . '/../../../.cache/tests/unit-lock/' . $this->name();
        (new Filesystem())->emptyDirectory($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->removeDirectory($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function acquiresAndReleasesTheLock(): void
    {
        $file = $this->directory . '/nested/.checkouts.lock';
        $lock = (new CheckoutLock())->acquire($file, 0.0);

        self::assertTrue($lock->isHeld());
        self::assertFileExists($file, 'missing parent directories are created');

        $lock->release();
        self::assertFalse($lock->isHeld());
        self::assertFileExists($file, 'the lock file is kept on purpose');

        $again = (new CheckoutLock())->acquire($file, 0.0);
        self::assertTrue($again->isHeld());
        $again->release();
    }

    #[Test]
    public function timesOutWhileAnotherHolderHasTheLock(): void
    {
        $file = $this->directory . '/.checkouts.lock';
        $held = (new CheckoutLock())->acquire($file, 0.0);
        $waited = 0;

        try {
            (new CheckoutLock())->acquire($file, 0.3, static function () use (&$waited): void {
                $waited++;
            });
            self::fail('Expected a LockException');
        } catch (LockException $e) {
            self::assertStringContainsString('Timed out after 0.3 seconds waiting for the lock', $e->getMessage());
        } finally {
            $held->release();
        }
        self::assertSame(1, $waited, 'the wait callback is called exactly once');
    }

    #[Test]
    public function succeedsOnceTheOtherHolderReleases(): void
    {
        $file = $this->directory . '/.checkouts.lock';
        $held = (new CheckoutLock())->acquire($file, 0.0);
        $waited = false;

        // The wait callback releases the competing lock: the next poll wins.
        $lock = (new CheckoutLock())->acquire($file, 5.0, static function () use ($held, &$waited): void {
            $waited = true;
            $held->release();
        });

        self::assertTrue($waited);
        self::assertTrue($lock->isHeld());
        $lock->release();
    }

    #[Test]
    public function destroyingTheHandleReleasesTheLock(): void
    {
        $file = $this->directory . '/.checkouts.lock';
        $held = (new CheckoutLock())->acquire($file, 0.0);
        unset($held);

        $lock = (new CheckoutLock())->acquire($file, 0.0);
        self::assertTrue($lock->isHeld());
        $lock->release();
    }

    #[Test]
    public function lockIsExclusiveAgainstAnotherProcess(): void
    {
        $file = $this->directory . '/.checkouts.lock';
        $held = (new CheckoutLock())->acquire($file, 0.0);

        $code = sprintf(
            '$h = fopen(%s, "c"); echo flock($h, LOCK_EX | LOCK_NB) ? "acquired" : "blocked";',
            var_export($file, true),
        );
        $result = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
        $held->release();
        $afterRelease = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));

        self::assertSame('blocked', $result);
        self::assertSame('acquired', $afterRelease);
    }
}
