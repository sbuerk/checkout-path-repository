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

namespace SBUERK\CheckoutPathRepository\Status;

/**
 * State of one manifest checkout compared to the installed packages - or of a
 * package installed from the checkout directory without a manifest entry.
 */
final class CheckoutStatus
{
    /** Present and installed from the checkout. */
    public const STATE_OK = 'ok';
    /** Not present and not installed from its location: nothing to do. */
    public const STATE_MISSING = 'missing';
    /** Present, opted out of the root requirement and not installed. */
    public const STATE_UNUSED = 'unused';
    /** Present and required, but not installed. */
    public const STATE_NOT_INSTALLED = 'not-installed';
    /** Present, but installed from another source (e.g. packagist.org). */
    public const STATE_OTHER_SOURCE = 'other-source';
    /** Installed from the checkout, but with another version than the manifest pins. */
    public const STATE_VERSION_MISMATCH = 'version-mismatch';
    /** Installed from the checkout location, but the checkout is gone. */
    public const STATE_STALE = 'stale';
    /**
     * Not in the manifest (any more), installed or locked from a directory
     * below the checkout directory that holds no package any more.
     */
    public const STATE_ORPHANED = 'orphaned';
    /**
     * Not in the manifest, installed from a directory below the checkout
     * directory that still exists - e.g. through a path repository of the
     * root composer.json. Informational only.
     */
    public const STATE_UNMANAGED = 'unmanaged';

    private const IN_SYNC_STATES = [self::STATE_OK, self::STATE_MISSING, self::STATE_UNUSED, self::STATE_UNMANAGED];

    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly bool $present,
        public readonly bool $required,
        public readonly ?string $expectedBranch,
        public readonly ?string $currentBranch,
        public readonly ?bool $dirty,
        public readonly ?string $expectedVersion,
        public readonly ?string $installedVersion,
        public readonly bool $installedFromCheckout,
        public readonly string $state,
    ) {}

    /**
     * Whether a `composer update` is needed to bring the installed packages in
     * line with the checkouts on disk.
     */
    public function isInSync(): bool
    {
        return in_array($this->state, self::IN_SYNC_STATES, true);
    }

    /**
     * @return array{name: string, path: string, present: bool, required: bool, expectedBranch: string|null, currentBranch: string|null, dirty: bool|null, expectedVersion: string|null, installedVersion: string|null, installedFromCheckout: bool, state: string, inSync: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'present' => $this->present,
            'required' => $this->required,
            'expectedBranch' => $this->expectedBranch,
            'currentBranch' => $this->currentBranch,
            'dirty' => $this->dirty,
            'expectedVersion' => $this->expectedVersion,
            'installedVersion' => $this->installedVersion,
            'installedFromCheckout' => $this->installedFromCheckout,
            'state' => $this->state,
            'inSync' => $this->isInSync(),
        ];
    }
}
