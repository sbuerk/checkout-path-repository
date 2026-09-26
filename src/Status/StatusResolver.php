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

use Composer\Installer\InstallationManager;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Filesystem;
use SBUERK\CheckoutPathRepository\Git\GitCheckout;
use SBUERK\CheckoutPathRepository\Manifest\CheckoutDefinition;
use SBUERK\CheckoutPathRepository\Manifest\Manifest;

/**
 * Compares the manifest checkouts with the installed packages
 * (`vendor/composer/installed.json`, composer's local repository).
 *
 * A package counts as "installed from the checkout" when it was installed by a
 * `path` repository (dist type `path`) and either
 *
 * - its install path resolves (following the vendor symlink) to the checkout
 *   directory, or
 * - its dist url, resolved against the root directory, is the checkout
 *   directory (covers mirrored instead of symlinked installs and checkouts
 *   that disappeared since).
 *
 * Stateless service.
 */
final class StatusResolver
{
    private Filesystem $filesystem;
    private VersionParser $versionParser;

    public function __construct(
        private readonly GitCheckout $git,
        ?Filesystem $filesystem = null,
        ?VersionParser $versionParser = null,
    ) {
        $this->filesystem = $filesystem ?? new Filesystem();
        $this->versionParser = $versionParser ?? new VersionParser();
    }

    /**
     * @param string $rootDirectory absolute directory of the root composer.json
     * @return list<CheckoutStatus>
     */
    public function resolve(
        Manifest $manifest,
        RepositoryInterface $localRepository,
        InstallationManager $installationManager,
        string $rootDirectory,
    ): array {
        $statuses = [];
        foreach ($manifest->checkouts as $checkout) {
            $statuses[] = $this->resolveOne($checkout, $localRepository, $installationManager, $rootDirectory);
        }
        return $statuses;
    }

    private function resolveOne(
        CheckoutDefinition $checkout,
        RepositoryInterface $localRepository,
        InstallationManager $installationManager,
        string $rootDirectory,
    ): CheckoutStatus {
        $present = $checkout->isPresent();
        $installed = $this->findInstalledPackage($localRepository, $checkout->name);
        $fromCheckout = $installed !== null
            && $this->isInstalledFromCheckout($installed, $checkout, $installationManager, $rootDirectory);

        if (!$present) {
            $state = $fromCheckout ? CheckoutStatus::STATE_STALE : CheckoutStatus::STATE_MISSING;
        } elseif ($installed === null) {
            $state = $checkout->require ? CheckoutStatus::STATE_NOT_INSTALLED : CheckoutStatus::STATE_UNUSED;
        } elseif (!$fromCheckout) {
            $state = CheckoutStatus::STATE_OTHER_SOURCE;
        } elseif (!$this->versionMatches($checkout->version, $installed)) {
            $state = CheckoutStatus::STATE_VERSION_MISMATCH;
        } else {
            $state = CheckoutStatus::STATE_OK;
        }

        $isWorkingTree = $checkout->directoryExists() && is_dir($checkout->absolutePath);

        return new CheckoutStatus(
            $checkout->name,
            $this->filesystem->findShortestPath($this->filesystem->normalizePath($rootDirectory), $checkout->absolutePath, true),
            $present,
            $checkout->require,
            $checkout->branch,
            $isWorkingTree ? $this->git->currentBranch($checkout->absolutePath) : null,
            $isWorkingTree ? $this->git->isDirty($checkout->absolutePath) : null,
            $checkout->version,
            $installed?->getPrettyVersion(),
            $fromCheckout,
            $state,
        );
    }

    private function findInstalledPackage(RepositoryInterface $localRepository, string $name): ?PackageInterface
    {
        foreach ($localRepository->findPackages($name) as $package) {
            if (!$package instanceof AliasPackage) {
                return $package;
            }
        }
        return null;
    }

    private function isInstalledFromCheckout(
        PackageInterface $package,
        CheckoutDefinition $checkout,
        InstallationManager $installationManager,
        string $rootDirectory,
    ): bool {
        if ($package->getDistType() !== 'path') {
            return false;
        }
        $checkoutRealPath = realpath($checkout->absolutePath);
        $installPath = $installationManager->getInstallPath($package);
        if ($checkoutRealPath !== false && $installPath !== null && $installPath !== '') {
            $installRealPath = realpath($installPath);
            if ($installRealPath !== false && $installRealPath === $checkoutRealPath) {
                return true;
            }
        }

        $distUrl = (string) $package->getDistUrl();
        if ($distUrl === '') {
            return false;
        }
        $distPath = $this->filesystem->isAbsolutePath($distUrl)
            ? $this->filesystem->normalizePath($distUrl)
            : $this->filesystem->normalizePath($rootDirectory . '/' . $distUrl);
        if ($distPath === $checkout->absolutePath) {
            return true;
        }
        $distRealPath = realpath($distPath);
        return $checkoutRealPath !== false && $distRealPath !== false && $distRealPath === $checkoutRealPath;
    }

    private function versionMatches(string $expectedVersion, PackageInterface $installed): bool
    {
        try {
            return $this->versionParser->parseConstraints($expectedVersion)
                ->matches(new Constraint('==', $installed->getVersion()));
        } catch (\UnexpectedValueException) {
            return false;
        }
    }
}
