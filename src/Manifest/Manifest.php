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

namespace SBUERK\CheckoutPathRepository\Manifest;

/**
 * The resolved checkout manifest: the directory checkout paths are relative
 * to and the package definitions in manifest order.
 */
final class Manifest
{
    public const LOCK_FILE_NAME = '.checkouts.lock';

    /**
     * @param non-empty-string                        $directory absolute, normalized
     * @param array<non-empty-string, CheckoutDefinition> $checkouts keyed by package name, manifest order
     * @param non-empty-string|null                   $file      absolute manifest file, null for inline configuration
     */
    public function __construct(
        public readonly string $directory,
        public readonly array $checkouts,
        public readonly ?string $file = null,
    ) {}

    public function has(string $name): bool
    {
        return isset($this->checkouts[strtolower($name)]);
    }

    public function get(string $name): ?CheckoutDefinition
    {
        return $this->checkouts[strtolower($name)] ?? null;
    }

    /**
     * @return non-empty-string
     */
    public function lockFile(): string
    {
        return $this->directory . '/' . self::LOCK_FILE_NAME;
    }

    /**
     * @return list<CheckoutDefinition>
     */
    public function present(): array
    {
        return array_values(array_filter(
            $this->checkouts,
            static fn(CheckoutDefinition $checkout): bool => $checkout->isPresent(),
        ));
    }

    /**
     * @return list<CheckoutDefinition>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            $this->checkouts,
            static fn(CheckoutDefinition $checkout): bool => !$checkout->isPresent(),
        ));
    }
}
