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

namespace SBUERK\CheckoutPathRepository\Exception;

/**
 * Thrown for an invalid plugin configuration or manifest. All problems found
 * are collected and reported at once, so a broken manifest is fixed in one go.
 */
final class InvalidConfigurationException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public static function fromErrors(string $source, array $errors): self
    {
        return new self(sprintf(
            'checkout-path-repository: invalid configuration in %s:%s',
            $source,
            PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $errors),
        ));
    }
}
