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
use Composer\Json\JsonFile;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\CheckoutPathRepository\Command\CloneCommand;
use SBUERK\CheckoutPathRepository\Command\StatusCommand;
use SBUERK\CheckoutPathRepository\Lock\CheckoutLock;

final class CloneCommandTest extends IntegrationTestCase
{
    #[Test]
    public function clonesMissingCheckoutsAndSkipsInaccessibleOnes(): void
    {
        $this->createFleet(false);
        $this->writeRoot();
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new CloneCommand(), $this->createComposer($io), $io);

        $output = $io->getOutput();
        self::assertSame(CloneCommand::EXIT_SUCCESS, $exitCode, $output);
        self::assertFileExists($this->workspace . '/packages/lib/composer.json');
        self::assertFileExists($this->workspace . '/packages/ext/composer.json');
        self::assertDirectoryDoesNotExist($this->workspace . '/packages/private');
        self::assertSame('main', trim($this->git(['symbolic-ref', '--short', 'HEAD'], $this->workspace . '/packages/lib')));
        self::assertSame('5', trim($this->git(['symbolic-ref', '--short', 'HEAD'], $this->workspace . '/packages/ext')));
        self::assertStringContainsString('fixture/lib: cloned', $output);
        self::assertStringContainsString('fixture/private: skipped, ' . $this->workspace . '/remotes/private (branch "1") is not accessible', $output);
        self::assertStringContainsString('checkouts:clone: 2 cloned, 0 already present, 0 unusable (no composer.json), 1 not accessible, 0 failed.', $output);
        self::assertStringContainsString('Run "composer update" to install the new checkouts.', $output);
        self::assertFileExists($this->workspace . '/packages/.checkouts.lock');

        // The clones are not registered in the running process: a new composer
        // run (as after the command ends) picks them up.
        $io = new BufferIO();
        [$statusExitCode] = $this->runCommand(new StatusCommand(), $this->createComposer($io), $io);
        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $statusExitCode);
        $io = new BufferIO();
        self::assertSame(0, $this->runUpdate($this->createComposer($io), $io), $io->getOutput());
        self::assertSame(['fixture/ext', 'fixture/lib'], array_keys($this->installedPackages()));
    }

    #[Test]
    public function strictModeFailsForInaccessibleRemotes(): void
    {
        $this->createFleet(false);
        $this->writeRoot();
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new CloneCommand(), $this->createComposer($io), $io, ['--strict' => true]);

        self::assertSame(CloneCommand::EXIT_FAILURE, $exitCode);
        self::assertFileExists($this->workspace . '/packages/lib/composer.json', 'accessible ones are still cloned');
    }

    #[Test]
    public function neverTouchesExistingDirectories(): void
    {
        $this->createFleet(false);
        // Same package, but another branch and local content.
        $this->createCheckout('lib', 'feature', ['name' => 'fixture/lib', 'description' => 'local work']);
        mkdir($this->workspace . '/packages/ext');
        file_put_contents($this->workspace . '/packages/ext/notes.txt', 'not a checkout');
        $this->writeRoot();
        $io = new BufferIO('', \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);

        [$exitCode] = $this->runCommand(new CloneCommand(), $this->createComposer($io), $io, ['packages' => ['fixture/lib', 'fixture/ext']]);

        self::assertSame(CloneCommand::EXIT_SUCCESS, $exitCode, $io->getOutput());
        self::assertSame('feature', trim($this->git(['symbolic-ref', '--short', 'HEAD'], $this->workspace . '/packages/lib')));
        $composerJson = (new JsonFile($this->workspace . '/packages/lib/composer.json'))->read();
        self::assertIsArray($composerJson);
        self::assertSame('local work', $composerJson['description'] ?? null);
        self::assertSame(['notes.txt'], array_values(array_diff((array) scandir($this->workspace . '/packages/ext'), ['.', '..'])));
        self::assertStringContainsString('fixture/lib: ../packages/lib exists, left untouched', $io->getOutput());
        self::assertStringContainsString('fixture/ext: ../packages/ext exists but has no composer.json, left untouched - remove it to clone again', $io->getOutput());
        self::assertStringContainsString('checkouts:clone: 0 cloned, 1 already present, 1 unusable (no composer.json), 0 not accessible, 0 failed.', $io->getOutput());
    }

    #[Test]
    public function warnsAboutDirectoryWithoutComposerJsonWithoutVerbosity(): void
    {
        $this->createFleet(false);
        mkdir($this->workspace . '/packages/ext');
        file_put_contents($this->workspace . '/packages/ext/partial', 'left over');
        $this->writeRoot();
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new CloneCommand(), $this->createComposer($io), $io, ['packages' => ['fixture/ext']]);

        self::assertSame(CloneCommand::EXIT_SUCCESS, $exitCode);
        self::assertStringContainsString('fixture/ext: ../packages/ext exists but has no composer.json, left untouched', $io->getOutput());
    }

    #[Test]
    public function namesTheUrlGitUsesWhenTheConfigurationRewritesIt(): void
    {
        $lib = $this->createRemote('lib', 'main', ['name' => 'fixture/lib']);
        // Like CI rewriting "git@github.com:" to https.
        file_put_contents($this->workspace . '/.gitconfig', sprintf("[url \"%s/\"]\n\tinsteadOf = rewritten:\n", $this->workspace . '/remotes'));
        $this->writeManifest([
            'fixture/lib' => ['url' => 'rewritten:lib', 'branch' => 'main', 'version' => '1.x-dev'],
            'fixture/other' => ['url' => 'rewritten:other', 'branch' => 'main', 'version' => '1.x-dev'],
        ]);
        $this->writeRoot();
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new CloneCommand(), $this->createComposer($io), $io);

        self::assertSame(CloneCommand::EXIT_SUCCESS, $exitCode, $io->getOutput());
        self::assertStringContainsString('fixture/lib: cloned rewritten:lib (rewritten to ' . $lib . ' by git config) (branch "main")', $io->getOutput());
        self::assertStringContainsString('fixture/other: skipped, rewritten:other (rewritten to ' . $this->workspace . '/remotes/other by git config) (branch "main") is not accessible', $io->getOutput());
    }

    #[Test]
    public function clonesIntoAnEmptyDirectory(): void
    {
        $this->createFleet(false);
        mkdir($this->workspace . '/packages/lib');
        $this->writeRoot();
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new CloneCommand(), $this->createComposer($io), $io, ['packages' => ['fixture/lib']]);

        self::assertSame(CloneCommand::EXIT_SUCCESS, $exitCode, $io->getOutput());
        self::assertFileExists($this->workspace . '/packages/lib/composer.json');
        self::assertStringContainsString('fixture/lib: cloned', $io->getOutput());
    }

    #[Test]
    public function clonesThroughATemporaryDirectoryAndRemovesLeftovers(): void
    {
        $this->createFleet(false);
        // Leftover of a clone that was killed (e.g. a container stopped).
        mkdir($this->workspace . '/packages/.lib.clone-999999/.git', 0777, true);
        $this->writeRoot();
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new CloneCommand(), $this->createComposer($io), $io, ['packages' => ['fixture/lib']]);

        self::assertSame(CloneCommand::EXIT_SUCCESS, $exitCode, $io->getOutput());
        self::assertFileExists($this->workspace . '/packages/lib/composer.json');
        self::assertSame('main', trim($this->git(['symbolic-ref', '--short', 'HEAD'], $this->workspace . '/packages/lib')));
        self::assertSame([], glob($this->workspace . '/packages/.*.clone-*'), 'no temporary clone directory is left');
    }

    #[Test]
    public function clonesOnlyTheRequestedPackages(): void
    {
        $this->createFleet(false);
        $this->writeRoot();
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new CloneCommand(), $this->createComposer($io), $io, ['packages' => ['Fixture/Ext']]);

        self::assertSame(CloneCommand::EXIT_SUCCESS, $exitCode, $io->getOutput());
        self::assertDirectoryExists($this->workspace . '/packages/ext');
        self::assertDirectoryDoesNotExist($this->workspace . '/packages/lib');
    }

    #[Test]
    public function rejectsUnknownPackages(): void
    {
        $this->createFleet(false);
        $this->writeRoot();
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new CloneCommand(), $this->createComposer($io), $io, ['packages' => ['fixture/lib', 'fixture/unknown']]);

        self::assertSame(CloneCommand::EXIT_FAILURE, $exitCode);
        self::assertStringContainsString('Unknown package(s) "fixture/unknown"', $io->getOutput());
        self::assertDirectoryDoesNotExist($this->workspace . '/packages/lib', 'nothing is cloned on invalid input');
    }

    #[Test]
    public function skipsMissingBranch(): void
    {
        $lib = $this->createRemote('lib', 'main', ['name' => 'fixture/lib']);
        $this->writeManifest(['fixture/lib' => ['url' => $lib, 'branch' => 'does-not-exist', 'version' => '1.x-dev']]);
        $this->writeRoot();
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new CloneCommand(), $this->createComposer($io), $io);

        self::assertSame(CloneCommand::EXIT_SUCCESS, $exitCode);
        self::assertStringContainsString('fixture/lib: skipped, ' . $lib . ' (branch "does-not-exist") is not accessible: branch not found', $io->getOutput());
        self::assertDirectoryDoesNotExist($this->workspace . '/packages/lib');
    }

    #[Test]
    public function createsMissingParentDirectories(): void
    {
        $lib = $this->createRemote('lib', 'main', ['name' => 'fixture/lib']);
        $this->writeManifest(['fixture/lib' => ['url' => 'file://' . $lib, 'branch' => 'main', 'version' => '1.x-dev', 'path' => 'nested/deeper/lib']]);
        $this->writeRoot();
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new CloneCommand(), $this->createComposer($io), $io);

        self::assertSame(CloneCommand::EXIT_SUCCESS, $exitCode, $io->getOutput());
        self::assertFileExists($this->workspace . '/packages/nested/deeper/lib/composer.json');
    }

    #[Test]
    public function waitsForTheLockAndGivesUpAfterTheTimeout(): void
    {
        $this->createFleet(false);
        $this->writeRoot();
        $held = (new CheckoutLock())->acquire($this->workspace . '/packages/.checkouts.lock', 0.0);
        $io = new BufferIO();

        try {
            [$exitCode] = $this->runCommand(new CloneCommand(null, null, null, 0.3), $this->createComposer($io), $io);
        } finally {
            $held->release();
        }

        self::assertSame(CloneCommand::EXIT_FAILURE, $exitCode);
        self::assertStringContainsString('Waiting for another checkouts:clone run to finish', $io->getOutput());
        self::assertStringContainsString('Timed out after 0.3 seconds waiting for the lock', $io->getOutput());
        self::assertDirectoryDoesNotExist($this->workspace . '/packages/lib', 'nothing is cloned without the lock');
    }
}
