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

use Composer\Composer;
use Composer\Installer\InstallationManager;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Filesystem;
use SBUERK\CheckoutPathRepository\Git\GitCheckout;
use SBUERK\CheckoutPathRepository\Manifest\CheckoutDefinition;
use SBUERK\CheckoutPathRepository\Manifest\Manifest;

/**
 * Compares the manifest checkouts with what `composer install` would work on:
 * the installed packages (`vendor/composer/installed.json`) and, if there is
 * one, the lock file.
 *
 * `installed.json` is read from disk on purpose instead of using composer's
 * local repository: `Factory::createComposer()` purges packages whose install
 * path is not readable from that repository - which is exactly the dangling
 * vendor symlink a removed checkout leaves behind. The lock file is consulted
 * because `composer install` works from it: a checkout that is locked from
 * its path but gone fails with "Source path ... is not found", a present and
 * required checkout missing from the lock fails with exit code 4.
 *
 * Packages installed or locked as `path` packages from a directory below the
 * checkout directory of the manifest, but without a manifest entry, are
 * reported as well: `orphaned` when that directory holds no package any more
 * (removed from the manifest and deleted - `composer install` fails then),
 * `unmanaged` otherwise.
 *
 * A package record counts as "from the checkout" when its dist type is `path`
 * and either its install path resolves (following the vendor symlink) to the
 * checkout directory, or its dist url, resolved against the root directory,
 * is the checkout directory (mirrored installs, vanished checkouts, locked
 * packages).
 *
 * Stateless service.
 */
final class StatusResolver
{
    private const ABSENT = 'absent';
    private const FROM_CHECKOUT = 'checkout';
    private const OTHER_SOURCE = 'other';

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
    public function resolveForComposer(Composer $composer, Manifest $manifest, string $rootDirectory): array
    {
        $vendorDirectory = $composer->getConfig()->get('vendor-dir');
        $installed = new InstalledFilesystemRepository(new JsonFile(
            (is_string($vendorDirectory) ? $vendorDirectory : $rootDirectory . '/vendor') . '/composer/installed.json',
        ));
        $locker = $composer->getLocker();
        $locked = $locker->isLocked() ? $locker->getLockedRepository(true) : null;

        return $this->resolve($manifest, $installed, $locked, $composer->getInstallationManager(), $rootDirectory);
    }

    /**
     * @param RepositoryInterface      $installed packages of installed.json, unpurged
     * @param RepositoryInterface|null $locked    packages of the lock file, null without lock file
     * @param string $rootDirectory absolute directory of the root composer.json
     * @return list<CheckoutStatus>
     */
    public function resolve(
        Manifest $manifest,
        RepositoryInterface $installed,
        ?RepositoryInterface $locked,
        InstallationManager $installationManager,
        string $rootDirectory,
    ): array {
        $rootDirectory = $this->filesystem->normalizePath($rootDirectory);
        $statuses = [];
        foreach ($manifest->checkouts as $checkout) {
            $statuses[] = $this->resolveOne($checkout, $installed, $locked, $installationManager, $rootDirectory);
        }
        foreach ($this->resolveUnlisted($manifest, $installed, $locked, $rootDirectory) as $status) {
            $statuses[] = $status;
        }
        return $statuses;
    }

    /**
     * Path packages below the checkout directory without a manifest entry.
     *
     * @return list<CheckoutStatus> sorted by name
     */
    private function resolveUnlisted(
        Manifest $manifest,
        RepositoryInterface $installedRepository,
        ?RepositoryInterface $lockedRepository,
        string $rootDirectory,
    ): array {
        /** @var array<string, array{directory: string, installed: PackageInterface|null}> $unlisted */
        $unlisted = [];
        foreach (['installed' => $installedRepository, 'locked' => $lockedRepository] as $source => $repository) {
            if ($repository === null) {
                continue;
            }
            foreach ($repository->getPackages() as $package) {
                if ($package instanceof AliasPackage || $manifest->has($package->getName()) || $package->getDistType() !== 'path') {
                    continue;
                }
                $directory = $this->distDirectory($package, $rootDirectory);
                if ($directory === null || !str_starts_with($directory, $manifest->directory . '/')) {
                    continue;
                }
                $unlisted[$package->getName()] ??= ['directory' => $directory, 'installed' => null];
                if ($source === 'installed') {
                    $unlisted[$package->getName()]['installed'] = $package;
                }
            }
        }
        ksort($unlisted);

        $statuses = [];
        foreach ($unlisted as $name => $record) {
            $directory = $record['directory'];
            $present = is_file($directory . '/composer.json');
            $isWorkingTree = is_dir($directory);
            $statuses[] = new CheckoutStatus(
                $name,
                $this->filesystem->findShortestPath($rootDirectory, $directory, true),
                $present,
                false,
                null,
                $isWorkingTree ? $this->git->currentBranch($directory) : null,
                $isWorkingTree ? $this->git->isDirty($directory) : null,
                null,
                $record['installed']?->getPrettyVersion(),
                $record['installed'] !== null,
                $present ? CheckoutStatus::STATE_UNMANAGED : CheckoutStatus::STATE_ORPHANED,
            );
        }
        return $statuses;
    }

    private function resolveOne(
        CheckoutDefinition $checkout,
        RepositoryInterface $installedRepository,
        ?RepositoryInterface $lockedRepository,
        InstallationManager $installationManager,
        string $rootDirectory,
    ): CheckoutStatus {
        $installed = $this->findPackage($installedRepository, $checkout->name);
        $installedSource = $this->source($installed, $checkout, $installationManager, $rootDirectory);
        $locked = $lockedRepository === null ? null : $this->findPackage($lockedRepository, $checkout->name);
        $lockedSource = $lockedRepository === null
            ? null
            : $this->source($locked, $checkout, $installationManager, $rootDirectory);

        $state = $this->state($checkout, $installed, $installedSource, $locked, $lockedSource);
        $isWorkingTree = is_dir($checkout->absolutePath);

        return new CheckoutStatus(
            $checkout->name,
            $this->filesystem->findShortestPath($rootDirectory, $checkout->absolutePath, true),
            $checkout->isPresent(),
            $checkout->require,
            $checkout->branch,
            $isWorkingTree ? $this->git->currentBranch($checkout->absolutePath) : null,
            $isWorkingTree ? $this->git->isDirty($checkout->absolutePath) : null,
            $checkout->version,
            $installed?->getPrettyVersion(),
            $installedSource === self::FROM_CHECKOUT,
            $state,
        );
    }

    /**
     * @param self::ABSENT|self::FROM_CHECKOUT|self::OTHER_SOURCE      $installedSource
     * @param self::ABSENT|self::FROM_CHECKOUT|self::OTHER_SOURCE|null $lockedSource null without lock file
     */
    private function state(
        CheckoutDefinition $checkout,
        ?PackageInterface $installed,
        string $installedSource,
        ?PackageInterface $locked,
        ?string $lockedSource,
    ): string {
        if (!$checkout->isPresent()) {
            return $installedSource === self::FROM_CHECKOUT || $lockedSource === self::FROM_CHECKOUT
                ? CheckoutStatus::STATE_STALE
                : CheckoutStatus::STATE_MISSING;
        }
        if ($installedSource === self::OTHER_SOURCE || $lockedSource === self::OTHER_SOURCE) {
            return CheckoutStatus::STATE_OTHER_SOURCE;
        }
        if ($checkout->require && ($installedSource === self::ABSENT || $lockedSource === self::ABSENT)) {
            return CheckoutStatus::STATE_NOT_INSTALLED;
        }
        // Also for "require": false - locked (because another package needs
        // it) but not installed, e.g. after removing vendor/.
        if ($installedSource === self::ABSENT && $lockedSource === self::FROM_CHECKOUT) {
            return CheckoutStatus::STATE_NOT_INSTALLED;
        }
        foreach ([$installed, $locked] as $package) {
            if ($package !== null && !$this->versionMatches($checkout->version, $package)) {
                return CheckoutStatus::STATE_VERSION_MISMATCH;
            }
        }
        return $installedSource === self::FROM_CHECKOUT ? CheckoutStatus::STATE_OK : CheckoutStatus::STATE_UNUSED;
    }

    private function findPackage(RepositoryInterface $repository, string $name): ?PackageInterface
    {
        foreach ($repository->findPackages($name) as $package) {
            if (!$package instanceof AliasPackage) {
                return $package;
            }
        }
        return null;
    }

    /**
     * @return self::ABSENT|self::FROM_CHECKOUT|self::OTHER_SOURCE
     */
    private function source(
        ?PackageInterface $package,
        CheckoutDefinition $checkout,
        InstallationManager $installationManager,
        string $rootDirectory,
    ): string {
        if ($package === null) {
            return self::ABSENT;
        }
        return $this->isFromCheckout($package, $checkout, $installationManager, $rootDirectory)
            ? self::FROM_CHECKOUT
            : self::OTHER_SOURCE;
    }

    private function isFromCheckout(
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

        $distPath = $this->distDirectory($package, $rootDirectory);
        if ($distPath === null) {
            return false;
        }
        if ($distPath === $checkout->absolutePath) {
            return true;
        }
        $distRealPath = realpath($distPath);
        return $checkoutRealPath !== false && $distRealPath !== false && $distRealPath === $checkoutRealPath;
    }

    /**
     * The source directory of a `path` package: its dist url, resolved
     * against the root directory (composer writes it relative to the working
     * directory of the run, which is the root directory).
     */
    private function distDirectory(PackageInterface $package, string $rootDirectory): ?string
    {
        $distUrl = (string) $package->getDistUrl();
        if ($distUrl === '') {
            return null;
        }
        return $this->filesystem->isAbsolutePath($distUrl)
            ? $this->filesystem->normalizePath($distUrl)
            : $this->filesystem->normalizePath($rootDirectory . '/' . $distUrl);
    }

    private function versionMatches(string $expectedVersion, PackageInterface $package): bool
    {
        try {
            return $this->versionParser->parseConstraints($expectedVersion)
                ->matches(new Constraint('==', $package->getVersion()));
        } catch (\UnexpectedValueException) {
            return false;
        }
    }
}
