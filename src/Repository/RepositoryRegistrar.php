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

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\Version\VersionParser;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use SBUERK\CheckoutPathRepository\Manifest\CheckoutDefinition;
use SBUERK\CheckoutPathRepository\Manifest\Manifest;

/**
 * Registers the present checkouts of a manifest with composer.
 *
 * For every checkout whose directory holds a composer.json:
 *
 * - a `path` repository is prepended (so it wins over packagist.org, all
 *   repositories are canonical in composer 2), with `symlink` and `relative`
 *   enabled and the manifest version pinned through `options.versions`;
 * - the root package gets the `dev` stability flag for the package;
 * - unless the definition opts out (`"require": false`) or the root package
 *   already requires the package itself, a root requirement `name => version`
 *   is added.
 *
 * Missing checkouts are reported (verbose only, see {@see noticeMissing()})
 * and otherwise ignored.
 *
 * Stateless service: all state lives in the passed composer instance.
 */
final class RepositoryRegistrar
{
    public const MESSAGE_PREFIX = 'checkout-path-repository: ';

    private Filesystem $filesystem;
    private VersionParser $versionParser;

    public function __construct(?Filesystem $filesystem = null, ?VersionParser $versionParser = null)
    {
        $this->filesystem = $filesystem ?? new Filesystem();
        $this->versionParser = $versionParser ?? new VersionParser();
    }

    public function register(Composer $composer, IOInterface $io, Manifest $manifest): RegistrationResult
    {
        $present = $manifest->present();
        $missing = $manifest->missing();

        $this->registerRepositories($composer, $io, $present);
        $required = $this->extendRootPackage($composer, $io, $present);
        $this->reportMissing($io, $manifest, $missing);

        return new RegistrationResult(
            array_map(static fn(CheckoutDefinition $checkout): string => $checkout->name, $present),
            $required,
            array_map(static fn(CheckoutDefinition $checkout): string => $checkout->name, $missing),
        );
    }

    /**
     * Relative url for the path repository. Composer resolves path repository
     * urls against the working directory, and a relative url is what makes the
     * vendor symlink relative (and the lock/installed.json entry portable).
     */
    public function repositoryUrl(CheckoutDefinition $checkout): string
    {
        $workingDirectory = $this->filesystem->normalizePath(Platform::getCwd(true));
        return $this->filesystem->findShortestPath($workingDirectory, $checkout->absolutePath, true);
    }

    /**
     * @param list<CheckoutDefinition> $present
     */
    private function registerRepositories(Composer $composer, IOInterface $io, array $present): void
    {
        $repositoryManager = $composer->getRepositoryManager();
        // Prepend in reverse so the final repository order follows the manifest.
        foreach (array_reverse($present) as $checkout) {
            $url = $this->repositoryUrl($checkout);
            $repositoryManager->prependRepository($repositoryManager->createRepository('path', [
                'type' => 'path',
                'url' => $url,
                'options' => [
                    'symlink' => true,
                    'relative' => true,
                    'versions' => [$checkout->name => $checkout->version],
                ],
            ]));
        }
        foreach ($present as $checkout) {
            $io->writeError(
                sprintf('%sregistered %s (%s) from %s', self::MESSAGE_PREFIX, $checkout->name, $checkout->version, $this->repositoryUrl($checkout)),
                true,
                IOInterface::VERBOSE,
            );
        }
    }

    /**
     * Root requirements are added in-memory only. The stability flags are
     * computed by composer when the root package is loaded, which is before
     * plugins are activated, so they have to be extended here as well -
     * otherwise a `x.y.x-dev` requirement is rejected by `minimum-stability`.
     *
     * Stability flags apply per package name, whoever requires the package.
     * Every registered checkout gets the `dev` flag, including the ones opted
     * out of the root requirement: an `@dev` in the constraint of another
     * (non-root) package does not lift `minimum-stability`.
     *
     * @param list<CheckoutDefinition> $present
     * @return list<string> names of the packages that were added
     */
    private function extendRootPackage(Composer $composer, IOInterface $io, array $present): array
    {
        $rootPackage = $composer->getPackage();
        $requires = $rootPackage->getRequires();
        $devRequires = $rootPackage->getDevRequires();
        $stabilityFlags = $rootPackage->getStabilityFlags();
        $added = [];

        foreach ($present as $checkout) {
            $stabilityFlags[$checkout->name] = max(
                $stabilityFlags[$checkout->name] ?? BasePackage::STABILITY_STABLE,
                BasePackage::STABILITY_DEV,
            );
            if (!$checkout->require) {
                continue;
            }
            if (isset($requires[$checkout->name]) || isset($devRequires[$checkout->name])) {
                $io->writeError(
                    sprintf('%s%s is required by the root package already, its constraint is kept', self::MESSAGE_PREFIX, $checkout->name),
                    true,
                    IOInterface::VERBOSE,
                );
                continue;
            }
            $requires[$checkout->name] = new Link(
                $rootPackage->getName(),
                $checkout->name,
                $this->versionParser->parseConstraints($checkout->version),
                Link::TYPE_REQUIRE,
                $checkout->version,
            );
            $added[] = $checkout->name;
        }

        if ($added !== []) {
            $rootPackage->setRequires($requires);
        }
        if ($present !== []) {
            $rootPackage->setStabilityFlags($stabilityFlags);
        }

        return $added;
    }

    /**
     * The one-line notice about checkouts that are not present, shown for
     * commands changing the installed packages. In verbose mode it has been
     * printed with the details during activation already.
     */
    public function noticeMissing(IOInterface $io, Manifest $manifest): void
    {
        $missing = $manifest->missing();
        if ($missing === [] || $io->isVerbose()) {
            return;
        }
        $io->writeError($this->missingSummary($manifest, $missing));
    }

    /**
     * @param list<CheckoutDefinition> $missing
     */
    private function reportMissing(IOInterface $io, Manifest $manifest, array $missing): void
    {
        if ($missing === []) {
            return;
        }
        $io->writeError($this->missingSummary($manifest, $missing), true, IOInterface::VERBOSE);
        foreach ($missing as $checkout) {
            $io->writeError(
                sprintf(
                    '%s%s: %s %s',
                    self::MESSAGE_PREFIX,
                    $checkout->name,
                    $checkout->directoryExists() ? 'no composer.json in' : 'missing directory',
                    $checkout->absolutePath,
                ),
                true,
                IOInterface::VERBOSE,
            );
        }
    }

    /**
     * @param list<CheckoutDefinition> $missing
     */
    private function missingSummary(Manifest $manifest, array $missing): string
    {
        return sprintf(
            '<comment>%s%d of %d checkouts not present, skipped (see "composer checkouts:status", clone with "composer checkouts:clone").</comment>',
            self::MESSAGE_PREFIX,
            count($missing),
            count($manifest->checkouts),
        );
    }
}
