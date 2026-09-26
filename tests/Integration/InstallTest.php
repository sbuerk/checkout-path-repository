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

use Composer\IO\BufferIO;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a real `composer update` (composer's Installer) with the plugin active.
 */
final class InstallTest extends IntegrationTestCase
{
    #[Test]
    public function installsPresentCheckoutsWithPinnedVersionsAndSkipsMissingOnes(): void
    {
        $this->createFleet();
        // No "minimum-stability": the default "stable" has to be lifted by the
        // stability flags the plugin adds for its root requirements.
        $this->writeRoot();
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);

        $exitCode = $this->runUpdate($this->createComposer($io), $io);

        self::assertSame(0, $exitCode, $io->getOutput());
        $installed = $this->installedPackages();
        self::assertSame(['fixture/ext', 'fixture/lib'], array_keys($installed));
        self::assertSame('5.1.x-dev', $installed['fixture/ext']['version']);
        self::assertSame('1.19.x-dev', $installed['fixture/lib']['version'], 'pinned, although the checkout is on branch main');
        $dist = $this->installedDist('fixture/lib');
        self::assertSame('path', $dist['type'] ?? null);
        self::assertSame('../packages/lib', $dist['url'] ?? null, 'relative url, portable between host and container');
        self::assertStringContainsString('checkout-path-repository: 1 of 3 checkouts not present, skipped', $io->getOutput());

        // Relative symlinks keep working when the tree is mounted elsewhere.
        $vendorLib = $this->workspace . '/root/vendor/fixture/lib';
        self::assertTrue(is_link($vendorLib));
        self::assertSame('../../../packages/lib', rtrim((string) readlink($vendorLib), '/'));
        self::assertSame(realpath($this->workspace . '/packages/lib'), realpath($vendorLib));

        $lock = $this->readJson($this->workspace . '/root/composer.lock');
        $stabilityFlags = $lock['stability-flags'] ?? null;
        self::assertIsArray($stabilityFlags);
        ksort($stabilityFlags);
        self::assertSame(['fixture/ext' => 20, 'fixture/lib' => 20], $stabilityFlags);
        // The root requirements exist in memory only.
        self::assertStringNotContainsString('fixture/', (string) file_get_contents($this->workspace . '/root/composer.json'));
    }

    #[Test]
    public function missingNoticeIsShownForCommandsChangingPackagesOnly(): void
    {
        $this->createFleet();
        $this->writeRoot();
        $io = new BufferIO();
        $composer = $this->createComposer($io);
        self::assertStringNotContainsString('checkout-path-repository', $io->getOutput(), 'activation itself is quiet without -v');
        $output = new BufferedOutput();

        foreach (['show', 'dump-autoload', 'why'] as $command) {
            $composer->getEventDispatcher()->dispatch(PluginEvents::COMMAND, new CommandEvent(PluginEvents::COMMAND, $command, new ArrayInput([]), $output));
        }
        self::assertStringNotContainsString('checkout-path-repository', $io->getOutput());

        $composer->getEventDispatcher()->dispatch(PluginEvents::COMMAND, new CommandEvent(PluginEvents::COMMAND, 'update', new ArrayInput([]), $output));
        self::assertStringContainsString('checkout-path-repository: 1 of 3 checkouts not present, skipped', $io->getOutput());
    }

    #[Test]
    public function stockPathRepositoriesCannotResolveTheFleetWithoutPinnedVersions(): void
    {
        // Control experiment for the test above: the same checkouts through a
        // plain path repository. fixture/lib reports "dev-main" (its branch),
        // which does not satisfy "~1.19.0@dev" of fixture/ext.
        $this->createFleet();
        $this->writeRoot([
            'repositories' => [['packagist.org' => false], ['type' => 'path', 'url' => '../packages/*']],
            'require' => ['fixture/ext' => '@dev'],
            'extra' => ['sbuerk/checkout-path-repository' => null],
        ]);
        $io = new BufferIO();

        $exitCode = $this->runUpdate($this->createComposer($io, false), $io);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('fixture/lib', $io->getOutput());
        self::assertSame([], $this->installedPackages());
    }

    #[Test]
    public function optedOutCheckoutIsInstalledOnlyWhenAnotherPackageRequiresIt(): void
    {
        $this->createFleet();
        $this->createCheckout('tool', 'main', ['name' => 'fixture/tool']);
        $this->changeManifestPackage('fixture/lib', ['require' => false]);
        $this->changeManifestPackage('fixture/tool', ['url' => 'unused', 'branch' => 'main', 'version' => '1.x-dev', 'require' => false]);
        $this->writeRoot();
        $io = new BufferIO();

        $composer = $this->createComposer($io);
        self::assertSame(['fixture/ext'], array_keys($composer->getPackage()->getRequires()));
        $exitCode = $this->runUpdate($composer, $io);

        self::assertSame(0, $exitCode, $io->getOutput());
        $installed = $this->installedPackages();
        self::assertSame(['fixture/ext', 'fixture/lib'], array_keys($installed), 'fixture/tool is registered, but nobody requires it');
        self::assertSame('../packages/lib', $this->installedDist('fixture/lib')['url'] ?? null, 'installed from the checkout through the dependency of fixture/ext');
    }

    #[Test]
    public function rootConstraintOfTheProjectIsKept(): void
    {
        $this->createFleet();
        $this->writeRoot(['require' => ['fixture/lib' => '^2.0@dev']]);
        $io = new BufferIO();

        $exitCode = $this->runUpdate($this->createComposer($io), $io);

        self::assertNotSame(0, $exitCode, 'the checkout reports 1.19.x-dev, the root package insists on ^2.0');
        self::assertStringContainsString('fixture/lib', $io->getOutput());
    }
}
