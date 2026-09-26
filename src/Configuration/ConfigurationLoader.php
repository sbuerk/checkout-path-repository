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

namespace SBUERK\CheckoutPathRepository\Configuration;

use Composer\Composer;
use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use SBUERK\CheckoutPathRepository\Exception\InvalidConfigurationException;
use SBUERK\CheckoutPathRepository\Manifest\Manifest;
use SBUERK\CheckoutPathRepository\Manifest\ManifestParser;

/**
 * Resolves the plugin configuration of the root package into a {@see Manifest}.
 *
 * Two mutually exclusive shapes are supported below
 * `extra."sbuerk/checkout-path-repository"`:
 *
 * - `{"manifest": "../packages/checkouts.json"}`: an external manifest file,
 *   relative to the directory of the root composer.json. Checkout paths are
 *   relative to the manifest directory.
 * - `{"directory": "packages", "packages": {...}}`: inline definitions,
 *   checkout paths relative to `directory` (default: the root directory).
 *
 * Stateless service.
 */
final class ConfigurationLoader
{
    public const EXTRA_KEY = 'sbuerk/checkout-path-repository';

    private const ALLOWED_KEYS = ['manifest', 'directory', 'packages'];

    private ManifestParser $manifestParser;
    private Filesystem $filesystem;

    public function __construct(?ManifestParser $manifestParser = null, ?Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?? new Filesystem();
        $this->manifestParser = $manifestParser ?? new ManifestParser($this->filesystem);
    }

    /**
     * @return Manifest|null null when the root package does not configure the plugin
     * @throws InvalidConfigurationException
     */
    public function loadFromComposer(Composer $composer): ?Manifest
    {
        return $this->load($composer->getPackage(), $this->rootDirectory($composer));
    }

    /**
     * @param string $rootDirectory absolute directory of the root composer.json
     * @return Manifest|null null when the root package does not configure the plugin
     * @throws InvalidConfigurationException
     */
    public function load(RootPackageInterface $rootPackage, string $rootDirectory): ?Manifest
    {
        $extra = $rootPackage->getExtra();
        if (!array_key_exists(self::EXTRA_KEY, $extra)) {
            return null;
        }

        $source = sprintf('composer.json extra."%s"', self::EXTRA_KEY);
        $config = $extra[self::EXTRA_KEY];
        if (!is_array($config) || ($config !== [] && array_is_list($config))) {
            throw InvalidConfigurationException::fromErrors($source, ['the configuration must be an object']);
        }

        $errors = [];
        $unknownKeys = array_diff(array_map('strval', array_keys($config)), self::ALLOWED_KEYS);
        if ($unknownKeys !== []) {
            $errors[] = sprintf('unknown key(s) "%s", allowed are "%s"', implode('", "', $unknownKeys), implode('", "', self::ALLOWED_KEYS));
        }
        $hasManifest = array_key_exists('manifest', $config);
        $hasPackages = array_key_exists('packages', $config);
        if ($hasManifest === $hasPackages) {
            $errors[] = 'configure either "manifest" (path to a manifest file) or "packages" (inline definitions), not both or none';
        }
        if ($hasManifest && array_key_exists('directory', $config)) {
            $errors[] = '"directory" is only supported together with inline "packages", a manifest resolves paths relative to its own directory';
        }
        foreach (['manifest', 'directory'] as $key) {
            if (array_key_exists($key, $config) && (!is_string($config[$key]) || trim($config[$key]) === '')) {
                $errors[] = sprintf('"%s" must be a non-empty string', $key);
            }
        }
        if ($errors !== []) {
            throw InvalidConfigurationException::fromErrors($source, $errors);
        }

        if ($hasManifest) {
            /** @var string $manifest */
            $manifest = $config['manifest'];
            return $this->loadManifestFile($this->resolvePath($rootDirectory, $manifest));
        }

        $directory = $config['directory'] ?? '.';
        /** @var string $directory */
        return $this->manifestParser->parsePackages(
            $config['packages'],
            $this->resolvePath($rootDirectory, $directory),
            $source,
        );
    }

    /**
     * The directory of the root composer.json. Composer resolves the file from
     * the working directory (or the COMPOSER environment variable) and records
     * it as the config source; the working directory is the fallback for
     * composer instances created without a file.
     */
    public function rootDirectory(Composer $composer): string
    {
        try {
            $file = $composer->getConfig()->getConfigSource()->getName();
        } catch (\Error) {
            // Config::getConfigSource() has a non-nullable return type but no
            // source is set for composer instances built from an array.
            $file = '';
        }
        $realPath = $file !== '' && is_file($file) ? realpath($file) : false;
        return $realPath !== false ? dirname($realPath) : Platform::getCwd(true);
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function loadManifestFile(string $file): Manifest
    {
        $source = sprintf('manifest file "%s"', $file);
        if (!is_file($file)) {
            throw InvalidConfigurationException::fromErrors($source, ['the file does not exist']);
        }
        try {
            $data = (new JsonFile($file))->read();
        } catch (\Throwable $e) {
            throw InvalidConfigurationException::fromErrors($source, [trim($e->getMessage())]);
        }

        return $this->manifestParser->parseDocument($data, dirname($file), $source, $file);
    }

    private function resolvePath(string $baseDirectory, string $path): string
    {
        if ($this->filesystem->isAbsolutePath($path)) {
            return $this->filesystem->normalizePath($path);
        }
        return $this->filesystem->normalizePath($baseDirectory . '/' . $path);
    }
}
