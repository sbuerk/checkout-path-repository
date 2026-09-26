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
use SBUERK\CheckoutPathRepository\Command\StatusCommand;
use SBUERK\CheckoutPathRepository\Status\CheckoutStatus;

final class StatusCommandTest extends IntegrationTestCase
{
    /**
     * @return array{int, array<mixed>} exit code and decoded JSON output
     */
    private function statusAsJson(): array
    {
        $io = new BufferIO();
        [$exitCode, $display] = $this->runCommand(new StatusCommand(), $this->createComposer($io), $io, ['--format' => 'json']);
        $data = JsonFile::parseJson($display);
        self::assertIsArray($data);
        return [$exitCode, $data];
    }

    /**
     * @param array<mixed> $status
     * @return array<mixed>
     */
    private static function checkout(array $status, string $name): array
    {
        self::assertIsArray($status['checkouts'] ?? null);
        foreach ($status['checkouts'] as $checkout) {
            if (is_array($checkout) && ($checkout['name'] ?? null) === $name) {
                return $checkout;
            }
        }
        self::fail(sprintf('No status for %s', $name));
    }

    private function update(): void
    {
        $io = new BufferIO();
        self::assertSame(0, $this->runUpdate($this->createComposer($io), $io), $io->getOutput());
    }

    #[Test]
    public function reportsOutOfSyncUntilComposerUpdateInstalledTheCheckouts(): void
    {
        $this->createFleet();
        $this->writeRoot();

        [$exitCode, $status] = $this->statusAsJson();
        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $exitCode);
        self::assertSame(3, StatusCommand::EXIT_OUT_OF_SYNC);
        self::assertFalse($status['inSync']);
        self::assertSame($this->workspace . '/packages/checkouts.json', $status['manifest']);
        self::assertSame(CheckoutStatus::STATE_NOT_INSTALLED, self::checkout($status, 'fixture/lib')['state']);
        self::assertSame(CheckoutStatus::STATE_NOT_INSTALLED, self::checkout($status, 'fixture/ext')['state']);
        self::assertSame(CheckoutStatus::STATE_MISSING, self::checkout($status, 'fixture/private')['state']);

        $this->update();

        [$exitCode, $status] = $this->statusAsJson();
        self::assertSame(StatusCommand::EXIT_IN_SYNC, $exitCode);
        self::assertTrue($status['inSync']);
        self::assertSame(
            [
                'name' => 'fixture/lib',
                'path' => '../packages/lib',
                'present' => true,
                'required' => true,
                'expectedBranch' => 'main',
                'currentBranch' => 'main',
                'dirty' => false,
                'expectedVersion' => '1.19.x-dev',
                'installedVersion' => '1.19.x-dev',
                'installedFromCheckout' => true,
                'state' => CheckoutStatus::STATE_OK,
                'inSync' => true,
            ],
            self::checkout($status, 'fixture/lib'),
        );
        self::assertSame(
            [
                'name' => 'fixture/private',
                'path' => '../packages/private',
                'present' => false,
                'required' => true,
                'expectedBranch' => '1',
                'currentBranch' => null,
                'dirty' => null,
                'expectedVersion' => '1.x-dev',
                'installedVersion' => null,
                'installedFromCheckout' => false,
                'state' => CheckoutStatus::STATE_MISSING,
                'inSync' => true,
            ],
            self::checkout($status, 'fixture/private'),
        );
    }

    #[Test]
    public function rendersTableWithBranchAndDirtyState(): void
    {
        $this->createFleet();
        $this->writeRoot();
        $this->update();
        $this->git(['checkout', '--quiet', '-b', 'feature'], $this->workspace . '/packages/ext');
        file_put_contents($this->workspace . '/packages/ext/new-file.txt', 'uncommitted');

        $io = new BufferIO();
        [$exitCode, $display] = $this->runCommand(new StatusCommand(), $this->createComposer($io), $io);

        self::assertSame(StatusCommand::EXIT_IN_SYNC, $exitCode, 'branch and local changes do not affect the sync state');
        self::assertMatchesRegularExpression('/fixture\/ext\s+\|\s+\.\.\/packages\/ext\s+\|\s+yes\s+\|\s+5\s+\|\s+feature\s+\|\s+yes\s+\|\s+5\.1\.x-dev\s+\|\s+ok/', $display);
        self::assertMatchesRegularExpression('/fixture\/private\s+\|\s+\.\.\/packages\/private\s+\|\s+no\s+\|\s+1\s+\|\s+-\s+\|\s+-\s+\|\s+-\s+\|\s+missing/', $display);
        self::assertStringContainsString('Checkouts and installed packages are in sync.', $io->getOutput());
    }

    #[Test]
    public function reportsNewlyClonedCheckoutAsNotInstalled(): void
    {
        $this->createFleet();
        $this->writeRoot();
        $this->update();
        $this->createCheckout('private', '1', ['name' => 'fixture/private']);

        [$exitCode, $status] = $this->statusAsJson();

        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $exitCode);
        self::assertSame(CheckoutStatus::STATE_NOT_INSTALLED, self::checkout($status, 'fixture/private')['state']);
    }

    #[Test]
    public function optionalCheckoutThatIsNotInstalledIsInSync(): void
    {
        $this->createFleet();
        $this->createCheckout('tool', 'main', ['name' => 'fixture/tool']);
        $this->changeManifestPackage('fixture/tool', ['url' => 'unused', 'branch' => 'main', 'version' => '1.x-dev', 'require' => false]);
        $this->writeRoot();
        $this->update();

        [$exitCode, $status] = $this->statusAsJson();

        self::assertSame(StatusCommand::EXIT_IN_SYNC, $exitCode);
        self::assertSame(CheckoutStatus::STATE_UNUSED, self::checkout($status, 'fixture/tool')['state']);
    }

    #[Test]
    public function optionalCheckoutThatIsLockedButNotInstalledIsNotInstalled(): void
    {
        $this->createFleet();
        // Not required by the root package, but by fixture/ext: locked.
        $this->changeManifestPackage('fixture/lib', ['require' => false]);
        $this->writeRoot();
        $this->update();
        $this->filesystem->removeDirectory($this->workspace . '/root/vendor');
        clearstatcache(true);

        [$exitCode, $status] = $this->statusAsJson();

        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $exitCode);
        self::assertSame(CheckoutStatus::STATE_NOT_INSTALLED, self::checkout($status, 'fixture/lib')['state'], 'not "unused": the lock holds it');
    }

    #[Test]
    public function reportsPackageInstalledFromAnotherSource(): void
    {
        $this->createFleet();
        // fixture/lib installed from a copy outside the checkout directory,
        // without the plugin being active.
        $this->createGitRepository($this->workspace . '/elsewhere/lib', 'main', ['name' => 'fixture/lib']);
        $this->writeRoot([
            'repositories' => [['packagist.org' => false], ['type' => 'path', 'url' => '../elsewhere/lib', 'options' => ['versions' => ['fixture/lib' => '1.19.0']]]],
            'require' => ['fixture/lib' => '*'],
        ]);
        $io = new BufferIO();
        self::assertSame(0, $this->runUpdate($this->createComposer($io, false), $io), $io->getOutput());

        [$exitCode, $status] = $this->statusAsJson();

        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $exitCode);
        self::assertSame(CheckoutStatus::STATE_OTHER_SOURCE, self::checkout($status, 'fixture/lib')['state']);
        self::assertSame('1.19.0', self::checkout($status, 'fixture/lib')['installedVersion']);
        self::assertFalse(self::checkout($status, 'fixture/lib')['installedFromCheckout']);
    }

    #[Test]
    public function reportsVersionMismatchAfterTheManifestVersionChanged(): void
    {
        $this->createFleet();
        $this->writeRoot();
        $this->update();
        $this->changeManifestPackage('fixture/ext', ['version' => '5.2.x-dev']);

        [$exitCode, $status] = $this->statusAsJson();

        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $exitCode);
        self::assertSame(CheckoutStatus::STATE_VERSION_MISMATCH, self::checkout($status, 'fixture/ext')['state']);
    }

    #[Test]
    public function reportsInstalledCheckoutThatWasRemoved(): void
    {
        $this->createFleet();
        $this->writeRoot();
        $this->update();
        $this->filesystem->removeDirectory($this->workspace . '/packages/ext');
        // The removal ran in a sub process: without this, PHP's stat cache
        // still resolves the now dangling vendor symlink.
        clearstatcache(true);

        [$exitCode, $status] = $this->statusAsJson();

        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $exitCode);
        self::assertSame(CheckoutStatus::STATE_STALE, self::checkout($status, 'fixture/ext')['state']);
        self::assertTrue(self::checkout($status, 'fixture/ext')['installedFromCheckout']);
    }

    #[Test]
    public function reportsPackageRemovedFromManifestAndDiskAsOrphaned(): void
    {
        $this->createFleet();
        $this->writeRoot();
        $this->update();
        $this->removeManifestPackage('fixture/ext');
        $this->filesystem->removeDirectory($this->workspace . '/packages/ext');
        clearstatcache(true);

        [$exitCode, $status] = $this->statusAsJson();

        self::assertSame(StatusCommand::EXIT_OUT_OF_SYNC, $exitCode);
        self::assertSame(
            [
                'name' => 'fixture/ext',
                'path' => '../packages/ext',
                'present' => false,
                'required' => false,
                'expectedBranch' => null,
                'currentBranch' => null,
                'dirty' => null,
                'expectedVersion' => null,
                'installedVersion' => '5.1.x-dev',
                'installedFromCheckout' => true,
                'state' => CheckoutStatus::STATE_ORPHANED,
                'inSync' => false,
            ],
            self::checkout($status, 'fixture/ext'),
        );
    }

    #[Test]
    public function reportsPackageRemovedFromManifestOnlyAsUnmanaged(): void
    {
        $this->createFleet();
        $this->writeRoot();
        $this->update();
        $this->removeManifestPackage('fixture/ext');

        [$exitCode, $status] = $this->statusAsJson();

        self::assertSame(StatusCommand::EXIT_IN_SYNC, $exitCode, 'composer install still works, nothing is broken');
        self::assertSame(CheckoutStatus::STATE_UNMANAGED, self::checkout($status, 'fixture/ext')['state']);
        self::assertSame('5', self::checkout($status, 'fixture/ext')['currentBranch']);
    }

    #[Test]
    public function ignoresPathPackagesOutsideTheCheckoutDirectory(): void
    {
        $this->createFleet();
        $this->createGitRepository($this->workspace . '/elsewhere/tool', 'main', ['name' => 'fixture/tool']);
        $this->writeRoot([
            'repositories' => [['packagist.org' => false], ['type' => 'path', 'url' => '../elsewhere/tool', 'options' => ['versions' => ['fixture/tool' => '1.0.0']]]],
            'require' => ['fixture/tool' => '*'],
        ]);
        $this->update();

        [$exitCode, $status] = $this->statusAsJson();

        self::assertSame(StatusCommand::EXIT_IN_SYNC, $exitCode);
        self::assertIsArray($status['checkouts']);
        self::assertCount(3, $status['checkouts'], 'only the manifest entries');
    }

    #[Test]
    public function rejectsUnknownFormat(): void
    {
        $this->createFleet();
        $this->writeRoot();
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new StatusCommand(), $this->createComposer($io), $io, ['--format' => 'xml']);

        self::assertSame(StatusCommand::EXIT_FAILURE, $exitCode);
        self::assertStringContainsString('Invalid format "xml"', $io->getOutput());
    }

    #[Test]
    public function failsWithoutConfiguration(): void
    {
        $this->createFleet();
        $this->writeRoot();
        $composerJson = $this->readJson($this->workspace . '/root/composer.json');
        unset($composerJson['extra']);
        $this->writeJson($this->workspace . '/root/composer.json', $composerJson);
        $io = new BufferIO();

        [$exitCode] = $this->runCommand(new StatusCommand(), $this->createComposer($io), $io);

        self::assertSame(StatusCommand::EXIT_FAILURE, $exitCode);
        self::assertStringContainsString('No checkouts configured', $io->getOutput());
    }
}
