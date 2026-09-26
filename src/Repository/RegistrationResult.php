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

namespace SBUERK\CheckoutPathRepository\Repository;

/**
 * What {@see RepositoryRegistrar::register()} did, by package name.
 */
final class RegistrationResult
{
    /**
     * @param list<string> $registered checkouts registered as path repository
     * @param list<string> $required   root requirements added
     * @param list<string> $missing    checkouts skipped because they are not present
     */
    public function __construct(
        public readonly array $registered,
        public readonly array $required,
        public readonly array $missing,
    ) {}
}
