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

namespace SBUERK\CheckoutPathRepository\Tests\Integration;

use Composer\InstalledVersions;
use Composer\Util\ProcessExecutor;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\CheckoutPathRepository\Command\StatusCommand;

/**
 * Runs composer's own binary (`bin/composer` of the composer/composer
 * development dependency) in a separate process, with the plugin installed
 * into the root project - the complete `Application::run()` path, including
 * what an in-process test bypasses: plugin command registration, the purge
 * of packages with unreadable install paths and a fresh stat cache.
 */
final class ComposerBinaryTest extends IntegrationTestCase
{
    private function writeRootRequiringThePlugin(): void
    {
        $this->writeRoot([
            'repositories' => [
                ['packagist.org' => false],
                [
                    'type' => 'path',
                    'url' => $this->filesystem->normalizePath(__DIR__ . '/../..'),
                    'options' => ['symlink' => true, 'versions' => ['sbuerk/checkout-path-repository' => '1.0.x-dev']],
                ],
            ],
            'require' => ['sbuerk/checkout-path-repository' => '1.0.x-dev'],
        ]);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string} exit code and combined output
     */
    private function composer(array $arguments): array
    {
        $process = new ProcessExecutor();
        $output = '';
        $exitCode = $process->execute(
            array_merge(
                [PHP_BINARY, __DIR__ . '/../../vendor/composer/composer/bin/composer', '--no-interaction', '--no-ansi'],
                $arguments,
            ),
            $output,
            $this->workspace . '/root',
        );
        return [$exitCode, (is_string($output) ? $output : '') . $process->getErrorOutput()];
    }

    /**
     * Whether the composer/composer version under test (the development
     * dependency, lowest or highest) is at least `$version`. Only used for
     * expectations about composer's own behaviour, never for the plugin's.
     */
    private static function composerIsAtLeast(string $version): bool
    {
        return version_compare((string) InstalledVersions::getVersion('composer/composer'), $version, '>=');
    }

    /**
     * Plugin installed, fleet cloned and installed.
     */
    private function installedFleet(): void
    {
        $this->createFleet();
        $this->writeRootRequiringThePlugin();
        [$exitCode, $output] = $this->composer(['update']);
        self::assertSame(0, $exitCode, $output);
        [$exitCode, $output] = $this->composer(['update']);
        self::assertSame(0, $exitCode, $output);
        [$exitCode, $output] = $this->composer(['checkouts:status']);
        self::assertSame(StatusCommand::EXIT_IN_SYNC, $exitCode, $output);
    }

    #[Test]
    public function removedCheckoutIsReportedStaleUntilComposerUpdate(): void
    {
        $this->installedFleet();
        $this->filesystem->removeDirectory($this->workspace . '/packages/ext');

        [$exitCode, $output] = $this->composer(['checkouts:status', '--format=json']);
        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $exitCode, $output);
        self::assertStringContainsString('"state": "stale"', $output);

        // What the status predicts: install from the lock cannot work
        // (composer 2.3 still exits with 0 there, 2.4+ fails).
        if (self::composerIsAtLeast('2.4.0')) {
            [$exitCode, $output] = $this->composer(['install']);
            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('Source path "../packages/ext" is not found', $output);
        }

        [$exitCode, $output] = $this->composer(['update']);
        self::assertSame(0, $exitCode, $output);
        [$exitCode, $output] = $this->composer(['checkouts:status']);
        self::assertSame(StatusCommand::EXIT_IN_SYNC, $exitCode, $output);
        self::assertSame(['fixture/lib', 'sbuerk/checkout-path-repository'], array_keys($this->installedPackages()));
    }

    #[Test]
    public function newCheckoutIsReportedNotInstalledAndInstallRejectsTheLock(): void
    {
        $this->installedFleet();
        $this->createCheckout('private', '1', ['name' => 'fixture/private']);

        [$exitCode, $output] = $this->composer(['checkouts:status']);
        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $exitCode, $output);
        self::assertMatchesRegularExpression('/fixture\/private\s+\|.*\|\s+not-installed/', $output);

        // Installer::ERROR_LOCK_FILE_INVALID - enforced since composer 2.5.
        if (self::composerIsAtLeast('2.5.0')) {
            [$exitCode, $output] = $this->composer(['install']);
            self::assertSame(4, $exitCode, $output);
            self::assertStringContainsString('Required package "fixture/private" is not present in the lock file.', $output);
        }

        [$exitCode, $output] = $this->composer(['update']);
        self::assertSame(0, $exitCode, $output);
        [$exitCode, $output] = $this->composer(['checkouts:status']);
        self::assertSame(StatusCommand::EXIT_IN_SYNC, $exitCode, $output);
    }

    #[Test]
    public function packageRemovedFromTheManifestIsReportedOrphanedUntilComposerUpdate(): void
    {
        $this->installedFleet();
        $this->removeManifestPackage('fixture/ext');
        $this->filesystem->removeDirectory($this->workspace . '/packages/ext');

        [$exitCode, $output] = $this->composer(['checkouts:status']);
        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $exitCode, $output);
        self::assertMatchesRegularExpression('/fixture\/ext \(not in manifest\)\s+\|\s+\.\.\/packages\/ext\s+\|\s+no\s+\|.*\|\s+orphaned/', $output);

        if (self::composerIsAtLeast('2.4.0')) {
            [$exitCode, $output] = $this->composer(['install']);
            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('Source path "../packages/ext" is not found', $output);
        }

        [$exitCode, $output] = $this->composer(['update']);
        self::assertSame(0, $exitCode, $output);
        self::assertSame(['fixture/lib', 'sbuerk/checkout-path-repository'], array_keys($this->installedPackages()));
        [$exitCode, $output] = $this->composer(['checkouts:status']);
        self::assertSame(StatusCommand::EXIT_IN_SYNC, $exitCode, $output);
        self::assertStringNotContainsString('fixture/ext', $output);
    }

    #[Test]
    public function missingNoticeIsShownForUpdateButNotForShow(): void
    {
        $this->installedFleet();

        [, $output] = $this->composer(['show']);
        self::assertStringNotContainsString('checkouts not present', $output);

        [, $output] = $this->composer(['update']);
        self::assertStringContainsString('checkout-path-repository: 1 of 3 checkouts not present, skipped', $output);
    }

    #[Test]
    public function invalidManifestIsReportedByTheCheckoutsCommands(): void
    {
        $this->installedFleet();
        $this->changeManifestPackage('fixture/ext', ['brnach' => '5']);

        foreach ([['checkouts:status'], ['checkouts:clone'], ['show']] as $arguments) {
            [$exitCode, $output] = $this->composer($arguments);
            self::assertNotSame(0, $exitCode, $output);
            self::assertStringContainsString('checkout-path-repository: invalid configuration in manifest file', $output, implode(' ', $arguments));
            self::assertStringContainsString('"fixture/ext": unknown key(s) "brnach"', $output, implode(' ', $arguments));
        }
    }
}
