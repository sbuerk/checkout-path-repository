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
 * One package entry of the manifest: where its checkout lives, where it is
 * cloned from and which version the path repository reports for it.
 */
final class CheckoutDefinition
{
    /**
     * @param non-empty-string $name     lower-cased composer package name
     * @param non-empty-string $url      git url used by `checkouts:clone`
     * @param non-empty-string $branch   branch cloned by `checkouts:clone`
     * @param non-empty-string $version  version reported by the path repository
     *                                   and root requirement constraint
     * @param non-empty-string $path     checkout path as configured, relative
     *                                   to the manifest directory
     * @param non-empty-string $absolutePath normalized absolute checkout path
     * @param bool             $require  add `name => version` to the root requires
     */
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly string $branch,
        public readonly string $version,
        public readonly string $path,
        public readonly string $absolutePath,
        public readonly bool $require,
    ) {}

    /**
     * A checkout counts as present when its directory holds a composer.json,
     * which is exactly what a `path` repository needs to load it.
     */
    public function isPresent(): bool
    {
        return is_file($this->absolutePath . '/composer.json');
    }

    public function directoryExists(): bool
    {
        return file_exists($this->absolutePath) || is_link($this->absolutePath);
    }
}
