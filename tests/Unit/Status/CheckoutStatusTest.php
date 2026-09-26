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

namespace SBUERK\CheckoutPathRepository\Tests\Unit\Status;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SBUERK\CheckoutPathRepository\Git\GitResult;
use SBUERK\CheckoutPathRepository\Status\CheckoutStatus;

final class CheckoutStatusTest extends TestCase
{
    /**
     * @return \Generator<string, array{string, bool}>
     */
    public static function states(): \Generator
    {
        yield 'ok' => [CheckoutStatus::STATE_OK, true];
        yield 'missing' => [CheckoutStatus::STATE_MISSING, true];
        yield 'unused' => [CheckoutStatus::STATE_UNUSED, true];
        yield 'not installed' => [CheckoutStatus::STATE_NOT_INSTALLED, false];
        yield 'other source' => [CheckoutStatus::STATE_OTHER_SOURCE, false];
        yield 'version mismatch' => [CheckoutStatus::STATE_VERSION_MISMATCH, false];
        yield 'stale' => [CheckoutStatus::STATE_STALE, false];
    }

    #[DataProvider('states')]
    #[Test]
    public function inSyncDependsOnState(string $state, bool $expected): void
    {
        $status = new CheckoutStatus('a/b', 'b', true, true, '1', '1', false, '1.x-dev', null, false, $state);

        self::assertSame($expected, $status->isInSync());
        self::assertSame($expected, $status->toArray()['inSync']);
        self::assertSame($state, $status->toArray()['state']);
    }

    #[Test]
    public function gitResultReasonEndsWithTheFirstFatalLine(): void
    {
        self::assertSame(
            "fatal: '/x' does not appear to be a git repository",
            (new GitResult(128, '', "fatal: '/x' does not appear to be a git repository\nfatal: Could not read from remote repository.\n\nPlease make sure you have the correct access rights\nand the repository exists.\n"))->reason(),
        );
        self::assertSame(
            'git@github.com: Permission denied (publickey). fatal: Could not read from remote repository.',
            (new GitResult(128, '', "git@github.com: Permission denied (publickey).\r\nfatal: Could not read from remote repository.\n\nPlease make sure you have the correct access rights\nand the repository exists.\n"))->reason(),
        );
        self::assertSame('error: last line', (new GitResult(1, '', "warning: first\nerror: last line\n"))->reason());
        self::assertSame('git exited with code 2', (new GitResult(2, '', ''))->reason());
        self::assertTrue((new GitResult(0, 'out', ''))->isSuccessful());
    }
}
