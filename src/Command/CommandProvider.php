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

namespace SBUERK\CheckoutPathRepository\Command;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Registers the `checkouts:*` commands with composer.
 *
 * Composer passes an array with the composer, io and plugin instances to the
 * constructor. The commands fetch composer and io when they are executed
 * instead, so there is no constructor.
 */
final class CommandProvider implements CommandProviderCapability
{
    public function getCommands(): array
    {
        return [
            new CloneCommand(),
            new StatusCommand(),
        ];
    }
}
