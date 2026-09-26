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

namespace SBUERK\CheckoutPathRepository\Tests\Unit\Repository;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\RootPackage;
use Composer\Repository\ArrayRepository;
use Composer\Repository\PathRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SBUERK\CheckoutPathRepository\Configuration\ConfigurationLoader;
use SBUERK\CheckoutPathRepository\Exception\InvalidConfigurationException;
use SBUERK\CheckoutPathRepository\Manifest\Manifest;
use SBUERK\CheckoutPathRepository\Plugin;
use SBUERK\CheckoutPathRepository\Repository\RepositoryRegistrar;
use Symfony\Component\Console\Output\OutputInterface;

final class RepositoryRegistrarTest extends TestCase
{
    private string $previousWorkingDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousWorkingDirectory = (string) getcwd();
        chdir(self::fixtures());
    }

    protected function tearDown(): void
    {
        chdir($this->previousWorkingDirectory);
        parent::tearDown();
    }

    private static function fixtures(): string
    {
        return (new Filesystem())->normalizePath(__DIR__ . '/../../Fixtures');
    }

    private static function manifest(): Manifest
    {
        $package = new RootPackage('root/project', '1.0.0.0', '1.0.0');
        $package->setExtra([ConfigurationLoader::EXTRA_KEY => ['manifest' => 'checkouts/checkouts.json']]);
        $manifest = (new ConfigurationLoader())->load($package, self::fixtures());
        self::assertNotNull($manifest);
        return $manifest;
    }

    private static function composer(BufferIO $io): Composer
    {
        $config = new Config(false, self::fixtures());
        $composer = new Composer();
        $composer->setConfig($config);
        $rootPackage = new RootPackage('root/project', '1.0.0.0', '1.0.0');
        $rootPackage->setExtra([ConfigurationLoader::EXTRA_KEY => ['manifest' => 'checkouts/checkouts.json']]);
        $composer->setPackage($rootPackage);
        $repositoryManager = RepositoryFactory::manager($io, $config, Factory::createHttpDownloader($io, $config));
        $repositoryManager->addRepository(new ArrayRepository());
        $composer->setRepositoryManager($repositoryManager);
        $composer->setEventDispatcher(new EventDispatcher($composer, $io));
        return $composer;
    }

    #[Test]
    public function prependsPathRepositoriesForPresentCheckoutsInManifestOrder(): void
    {
        $io = new BufferIO();
        $composer = self::composer($io);

        $result = (new RepositoryRegistrar())->register($composer, $io, self::manifest());

        self::assertSame(['fixture/present-a', 'fixture/present-b'], $result->registered);
        self::assertSame(['fixture/missing', 'fixture/no-composer-json'], $result->missing);

        $repositories = $composer->getRepositoryManager()->getRepositories();
        self::assertCount(3, $repositories);
        self::assertInstanceOf(PathRepository::class, $repositories[0]);
        self::assertInstanceOf(PathRepository::class, $repositories[1]);
        self::assertInstanceOf(ArrayRepository::class, $repositories[2], 'existing repositories come after the checkouts');

        self::assertSame(
            [
                'type' => 'path',
                'url' => 'checkouts/present-a',
                'options' => ['symlink' => true, 'relative' => true, 'versions' => ['fixture/present-a' => '1.19.x-dev']],
            ],
            $repositories[0]->getRepoConfig(),
        );
        self::assertSame('checkouts/present-b', $repositories[1]->getRepoConfig()['url']);

        $packages = $repositories[0]->getPackages();
        self::assertCount(1, $packages);
        self::assertSame('fixture/present-a', $packages[0]->getName());
        self::assertSame('1.19.x-dev', $packages[0]->getPrettyVersion(), 'the manifest version is pinned');
    }

    #[Test]
    public function addsRootRequirementsWithDevStabilityUnlessOptedOut(): void
    {
        $io = new BufferIO();
        $composer = self::composer($io);

        $result = (new RepositoryRegistrar())->register($composer, $io, self::manifest());

        self::assertSame(['fixture/present-a'], $result->required);
        $requires = $composer->getPackage()->getRequires();
        self::assertSame(['fixture/present-a'], array_keys($requires));
        self::assertSame('1.19.x-dev', $requires['fixture/present-a']->getPrettyConstraint());
        self::assertSame(Link::TYPE_REQUIRE, $requires['fixture/present-a']->getDescription());
        self::assertSame('root/project', $requires['fixture/present-a']->getSource());
        self::assertTrue($requires['fixture/present-a']->getConstraint()->matches(new Constraint('==', '1.19.9999999.9999999-dev')));
        self::assertSame(
            [
                'fixture/present-a' => BasePackage::STABILITY_DEV,
                // Opted out of the root requirement, but allowed at dev stability
                // for packages requiring it.
                'fixture/present-b' => BasePackage::STABILITY_DEV,
            ],
            $composer->getPackage()->getStabilityFlags(),
        );
    }

    #[Test]
    public function keepsRootRequirementsDeclaredByTheRootPackage(): void
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);
        $composer = self::composer($io);
        $rootPackage = $composer->getPackage();
        $ownLink = new Link('root/project', 'fixture/present-a', new Constraint('>=', '1.0.0.0'), Link::TYPE_REQUIRE, '>=1.0');
        $rootPackage->setRequires(['fixture/present-a' => $ownLink]);

        $result = (new RepositoryRegistrar())->register($composer, $io, self::manifest());

        self::assertSame([], $result->required);
        self::assertSame(['fixture/present-a' => $ownLink], $rootPackage->getRequires());
        self::assertSame(
            ['fixture/present-a' => BasePackage::STABILITY_DEV, 'fixture/present-b' => BasePackage::STABILITY_DEV],
            $rootPackage->getStabilityFlags(),
            'the flag is still added, it only widens what the kept constraint accepts',
        );
        self::assertStringContainsString('fixture/present-a is required by the root package already', $io->getOutput());
    }

    #[Test]
    public function missingCheckoutsAreQuietDuringRegistration(): void
    {
        $io = new BufferIO();
        (new RepositoryRegistrar())->register(self::composer($io), $io, self::manifest());

        self::assertSame('', $io->getOutput(), 'activate() runs for every command, the notice is verbose there');
    }

    #[Test]
    public function noticeMissingPrintsOneLine(): void
    {
        $io = new BufferIO();
        (new RepositoryRegistrar())->noticeMissing($io, self::manifest());

        $output = $io->getOutput();
        self::assertSame(1, substr_count(trim($output), PHP_EOL) + 1, 'exactly one line: ' . $output);
        self::assertStringContainsString('checkout-path-repository: 2 of 4 checkouts not present, skipped', $output);
    }

    #[Test]
    public function noticeMissingIsSkippedInVerboseModeAsRegistrationPrintedIt(): void
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);
        (new RepositoryRegistrar())->noticeMissing($io, self::manifest());

        self::assertSame('', $io->getOutput());
    }

    #[Test]
    public function listsEachMissingCheckoutInVerboseMode(): void
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);
        (new RepositoryRegistrar())->register(self::composer($io), $io, self::manifest());

        $output = $io->getOutput();
        self::assertStringContainsString('checkout-path-repository: 2 of 4 checkouts not present, skipped', $output);
        self::assertStringContainsString('fixture/missing: missing directory ' . self::fixtures() . '/checkouts/missing', $output);
        self::assertStringContainsString('fixture/no-composer-json: no composer.json in ' . self::fixtures() . '/checkouts/no-composer-json', $output);
        self::assertStringContainsString('registered fixture/present-a (1.19.x-dev) from checkouts/present-a', $output);
    }

    #[Test]
    public function pluginActivationWithoutConfigurationDoesNothing(): void
    {
        $io = new BufferIO();
        $composer = self::composer($io);
        $composer->getPackage()->setExtra([]);

        (new Plugin())->activate($composer, $io);

        self::assertCount(1, $composer->getRepositoryManager()->getRepositories());
        self::assertSame([], $composer->getPackage()->getRequires());
        self::assertSame('', $io->getOutput());
    }

    #[Test]
    public function pluginActivationRegistersConfiguredCheckouts(): void
    {
        $io = new BufferIO();
        $composer = self::composer($io);

        (new Plugin())->activate($composer, $io);

        self::assertCount(3, $composer->getRepositoryManager()->getRepositories());
        self::assertSame(['fixture/present-a'], array_keys($composer->getPackage()->getRequires()));
    }

    #[Test]
    public function pluginPrintsInvalidConfigurationBeforeFailing(): void
    {
        $io = new BufferIO();
        $composer = self::composer($io);
        $composer->getPackage()->setExtra([ConfigurationLoader::EXTRA_KEY => ['manifest' => 'checkouts/does-not-exist.json']]);

        try {
            (new Plugin())->activate($composer, $io);
            self::fail('Expected an InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString($e->getMessage(), $io->getOutput(), 'written, as composer swallows it while collecting plugin commands');
        }
    }
}
