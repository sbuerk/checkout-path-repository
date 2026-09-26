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

use Composer\Package\Version\VersionParser;
use Composer\Util\Filesystem;
use SBUERK\CheckoutPathRepository\Exception\InvalidConfigurationException;

/**
 * Validates decoded manifest data and turns it into a {@see Manifest}.
 *
 * Validation is strict on purpose: the manifest is shared between machines and
 * drives git operations, so a typo must fail loudly instead of silently
 * dropping a checkout. Top-level keys other than `packages` are ignored, which
 * leaves room for tooling data (e.g. a `line` section) in the same file.
 *
 * Stateless service.
 */
final class ManifestParser
{
    /**
     * Composer's package name rule, restricted to lower case.
     */
    private const NAME_PATTERN = '{^[a-z0-9](?:[_.-]?[a-z0-9]++)*+/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]++)*+$}D';

    private const ALLOWED_KEYS = ['url', 'branch', 'version', 'path', 'require'];

    private Filesystem $filesystem;
    private VersionParser $versionParser;

    public function __construct(?Filesystem $filesystem = null, ?VersionParser $versionParser = null)
    {
        $this->filesystem = $filesystem ?? new Filesystem();
        $this->versionParser = $versionParser ?? new VersionParser();
    }

    /**
     * Parses a complete manifest document (an object with a `packages` key).
     *
     * @param mixed  $data      decoded JSON document
     * @param string $directory absolute directory checkout paths are relative to
     * @param string $source    human readable origin used in error messages
     * @param string|null $file absolute manifest file, if any
     *
     * @throws InvalidConfigurationException
     */
    public function parseDocument(mixed $data, string $directory, string $source, ?string $file = null): Manifest
    {
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw InvalidConfigurationException::fromErrors($source, ['the manifest must be a JSON object']);
        }
        if (!array_key_exists('packages', $data)) {
            throw InvalidConfigurationException::fromErrors($source, ['the manifest has no "packages" object']);
        }

        return $this->parsePackages($data['packages'], $directory, $source, $file);
    }

    /**
     * Parses a `packages` map: package name => definition.
     *
     * @param mixed  $packages
     * @param string $directory absolute directory checkout paths are relative to
     * @param string $source    human readable origin used in error messages
     * @param string|null $file absolute manifest file, if any
     *
     * @throws InvalidConfigurationException
     */
    public function parsePackages(mixed $packages, string $directory, string $source, ?string $file = null): Manifest
    {
        if (!is_array($packages) || ($packages !== [] && array_is_list($packages))) {
            throw InvalidConfigurationException::fromErrors($source, ['"packages" must be an object mapping package names to checkout definitions']);
        }

        $directory = $this->normalizeDirectory($directory, $source);
        $errors = [];
        $checkouts = [];
        $paths = [];
        foreach ($packages as $name => $definition) {
            $name = (string) $name;
            $checkout = $this->parseDefinition($name, $definition, $directory, $errors);
            if ($checkout === null) {
                continue;
            }
            if (isset($paths[$checkout->absolutePath])) {
                $errors[] = sprintf(
                    '"%s": path "%s" is already used by "%s"',
                    $name,
                    $checkout->path,
                    $paths[$checkout->absolutePath],
                );
                continue;
            }
            $paths[$checkout->absolutePath] = $name;
            $checkouts[$checkout->name] = $checkout;
        }

        if ($errors !== []) {
            throw InvalidConfigurationException::fromErrors($source, $errors);
        }

        return new Manifest($directory, $checkouts, $file === null || $file === '' ? null : $file);
    }

    /**
     * @param list<string> $errors
     */
    private function parseDefinition(string $name, mixed $definition, string $directory, array &$errors): ?CheckoutDefinition
    {
        $errorCount = count($errors);
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $errors[] = sprintf('"%s" is not a valid lower-case composer package name (vendor/name)', $name);
        }
        if (!is_array($definition) || ($definition !== [] && array_is_list($definition))) {
            $errors[] = sprintf('"%s": the definition must be an object', $name);
            return null;
        }

        $unknownKeys = array_diff(array_map('strval', array_keys($definition)), self::ALLOWED_KEYS);
        if ($unknownKeys !== []) {
            $errors[] = sprintf(
                '"%s": unknown key(s) "%s", allowed are "%s"',
                $name,
                implode('", "', $unknownKeys),
                implode('", "', self::ALLOWED_KEYS),
            );
        }

        $url = $this->requireString($name, $definition, 'url', $errors);
        $branch = $this->requireString($name, $definition, 'branch', $errors);
        $version = $this->requireString($name, $definition, 'version', $errors);

        if ($url !== null && str_starts_with($url, '-')) {
            $errors[] = sprintf('"%s": "url" must not start with "-"', $name);
        }
        if ($branch !== null && (str_starts_with($branch, '-') || preg_match('/\s/', $branch) === 1)) {
            $errors[] = sprintf('"%s": "branch" must not start with "-" or contain whitespace', $name);
        }
        if ($version !== null) {
            try {
                $this->versionParser->normalize($version);
            } catch (\UnexpectedValueException $e) {
                $errors[] = sprintf('"%s": "version" is not a valid composer version: %s', $name, $e->getMessage());
            }
        }

        // Default: the last segment of the package name.
        $path = substr($name, (int) strrpos($name, '/') + 1);
        if (array_key_exists('path', $definition)) {
            $path = is_string($definition['path']) ? trim($definition['path']) : '';
            if ($path === '') {
                $errors[] = sprintf('"%s": "path" must be a non-empty string', $name);
            } elseif ($this->filesystem->isAbsolutePath($path)) {
                $errors[] = sprintf('"%s": "path" must be relative to the manifest directory, "%s" is absolute', $name, $path);
            }
        }

        $require = true;
        if (array_key_exists('require', $definition)) {
            if (!is_bool($definition['require'])) {
                $errors[] = sprintf('"%s": "require" must be a boolean', $name);
            } else {
                $require = $definition['require'];
            }
        }

        if (count($errors) !== $errorCount || $url === null || $branch === null || $version === null || $path === '' || $name === '') {
            return null;
        }

        $absolutePath = $this->filesystem->normalizePath($directory . '/' . $path);
        if ($absolutePath === '' || $absolutePath === $directory) {
            $errors[] = sprintf('"%s": "path" must point to a sub directory, not to the manifest directory itself', $name);
            return null;
        }

        return new CheckoutDefinition($name, $url, $branch, $version, $path, $absolutePath, $require);
    }

    /**
     * @param array<mixed> $definition
     * @param list<string> $errors
     * @return non-empty-string|null
     */
    private function requireString(string $name, array $definition, string $key, array &$errors): ?string
    {
        if (!array_key_exists($key, $definition)) {
            $errors[] = sprintf('"%s": missing required key "%s"', $name, $key);
            return null;
        }
        $value = is_string($definition[$key]) ? trim($definition[$key]) : '';
        if ($value === '') {
            $errors[] = sprintf('"%s": "%s" must be a non-empty string', $name, $key);
            return null;
        }
        return $value;
    }

    /**
     * @return non-empty-string
     */
    private function normalizeDirectory(string $directory, string $source): string
    {
        $normalized = $this->filesystem->normalizePath($directory);
        if ($normalized === '' || !$this->filesystem->isAbsolutePath($normalized)) {
            throw InvalidConfigurationException::fromErrors($source, [sprintf('the checkout directory "%s" must resolve to an absolute path', $directory)]);
        }
        return $normalized;
    }
}
