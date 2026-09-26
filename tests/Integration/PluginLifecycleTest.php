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

use Composer\Command\BaseCommand;
use Composer\IO\BufferIO;
use Composer\Plugin\Capability\CommandProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\CheckoutPathRepository\Command\StatusCommand;

/**
 * The plugin installed into the root project from this package (path
 * repository), loaded and activated by composer's plugin manager - the same
 * sequence a developer goes through on a fresh project.
 */
final class PluginLifecycleTest extends IntegrationTestCase
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

    #[Test]
    public function firstRunInstallsThePluginAndTheSecondRunTheCheckouts(): void
    {
        $this->createFleet(false);
        $this->writeRootRequiringThePlugin();

        // 1. Bootstrap: the plugin is not installed yet, so nothing registers
        //    the checkouts during this resolution.
        $io = new BufferIO();
        self::assertSame(0, $this->runUpdate($this->createComposer($io, false), $io), $io->getOutput());
        self::assertSame(['sbuerk/checkout-path-repository'], array_keys($this->installedPackages()));

        // 2. The plugin is installed now: the plugin manager activates it and
        //    provides the commands.
        $io = new BufferIO();
        $composer = $this->createComposer($io, false);
        $commandNames = [];
        foreach ($composer->getPluginManager()->getPluginCapabilities(CommandProvider::class, ['composer' => $composer, 'io' => $io]) as $provider) {
            foreach ($provider->getCommands() as $command) {
                self::assertInstanceOf(BaseCommand::class, $command);
                $commandNames[] = $command->getName();
            }
        }
        self::assertSame(['checkouts:clone', 'checkouts:status'], $commandNames);
        self::assertStringContainsString('3 of 3 checkouts not present', $io->getOutput());

        // 3. Clone, then status asks for an update.
        [$exitCode] = $this->runCommand($this->commandByName($composer, $io, 'checkouts:clone'), $composer, $io);
        self::assertSame(0, $exitCode, $io->getOutput());
        $io = new BufferIO();
        $composer = $this->createComposer($io, false);
        [$exitCode] = $this->runCommand($this->commandByName($composer, $io, 'checkouts:status'), $composer, $io);
        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $exitCode);

        // 4. The update installs the checkouts, status is in sync afterwards.
        $io = new BufferIO();
        self::assertSame(0, $this->runUpdate($this->createComposer($io, false), $io), $io->getOutput());
        self::assertSame(
            ['fixture/ext', 'fixture/lib', 'sbuerk/checkout-path-repository'],
            array_keys($this->installedPackages()),
        );
        $io = new BufferIO();
        $composer = $this->createComposer($io, false);
        [$exitCode] = $this->runCommand($this->commandByName($composer, $io, 'checkouts:status'), $composer, $io);
        self::assertSame(StatusCommand::EXIT_IN_SYNC, $exitCode, $io->getOutput());
    }

    private function commandByName(\Composer\Composer $composer, BufferIO $io, string $name): BaseCommand
    {
        foreach ($composer->getPluginManager()->getPluginCapabilities(CommandProvider::class, ['composer' => $composer, 'io' => $io]) as $provider) {
            foreach ($provider->getCommands() as $command) {
                if ($command->getName() === $name) {
                    return $command;
                }
            }
        }
        self::fail(sprintf('Command "%s" is not provided.', $name));
    }
}
