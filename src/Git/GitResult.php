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

namespace SBUERK\CheckoutPathRepository\Git;

/**
 * Outcome of one git invocation.
 */
final class GitResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * The error output condensed to one line for notices: everything up to
     * and including the first "fatal:" line. Lines before it carry the cause
     * (e.g. "Permission denied (publickey)." from ssh), lines after it are
     * generic advice. Without a "fatal:" line, the last line is used.
     */
    public function reason(): string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $this->errorOutput) ?: []),
            static fn(string $line): bool => $line !== '',
        ));
        if ($lines === []) {
            return sprintf('git exited with code %d', $this->exitCode);
        }
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, 'fatal:')) {
                return implode(' ', array_slice($lines, 0, $index + 1));
            }
        }
        return $lines[count($lines) - 1];
    }
}
