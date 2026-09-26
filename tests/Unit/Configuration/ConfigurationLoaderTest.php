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

namespace SBUERK\CheckoutPathRepository\Tests\Unit\Configuration;

use Composer\Composer;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Json\JsonFile;
use Composer\Package\RootPackage;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SBUERK\CheckoutPathRepository\Configuration\ConfigurationLoader;
use SBUERK\CheckoutPathRepository\Exception\InvalidConfigurationException;

final class ConfigurationLoaderTest extends TestCase
{
    private static function fixtures(): string
    {
        return (new Filesystem())->normalizePath(__DIR__ . '/../../Fixtures');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function rootPackage(array $extra): RootPackage
    {
        $package = new RootPackage('root/project', '1.0.0.0', '1.0.0');
        $package->setExtra($extra);
        return $package;
    }

    #[Test]
    public function returnsNullWithoutConfiguration(): void
    {
        self::assertNull((new ConfigurationLoader())->load(self::rootPackage(['other' => []]), self::fixtures()));
    }

    #[Test]
    public function loadsManifestRelativeToRootDirectory(): void
    {
        $rootDirectory = self::fixtures() . '/checkouts/present-a';
        $manifest = (new ConfigurationLoader())->load(
            self::rootPackage([ConfigurationLoader::EXTRA_KEY => ['manifest' => '../checkouts.json']]),
            $rootDirectory,
        );

        self::assertNotNull($manifest);
        self::assertSame(self::fixtures() . '/checkouts/checkouts.json', $manifest->file);
        self::assertSame(self::fixtures() . '/checkouts', $manifest->directory);
        self::assertSame(
            ['fixture/present-a', 'fixture/present-b', 'fixture/missing', 'fixture/no-composer-json'],
            array_keys($manifest->checkouts),
        );
        self::assertSame(['fixture/present-a', 'fixture/present-b'], array_map(static fn($c) => $c->name, $manifest->present()));
        self::assertSame(['fixture/missing', 'fixture/no-composer-json'], array_map(static fn($c) => $c->name, $manifest->missing()));
    }

    #[Test]
    public function loadsInlinePackagesRelativeToDirectory(): void
    {
        $manifest = (new ConfigurationLoader())->load(
            self::rootPackage([ConfigurationLoader::EXTRA_KEY => [
                'directory' => 'checkouts',
                'packages' => [
                    'fixture/present-a' => ['url' => 'u', 'branch' => 'main', 'version' => '1.x-dev'],
                ],
            ]]),
            self::fixtures(),
        );

        self::assertNotNull($manifest);
        self::assertNull($manifest->file);
        self::assertSame(self::fixtures() . '/checkouts', $manifest->directory);
        self::assertTrue($manifest->get('fixture/present-a')?->isPresent());
    }

    #[Test]
    public function inlineDirectoryDefaultsToRootDirectory(): void
    {
        $manifest = (new ConfigurationLoader())->load(
            self::rootPackage([ConfigurationLoader::EXTRA_KEY => ['packages' => []]]),
            self::fixtures(),
        );

        self::assertSame(self::fixtures(), $manifest?->directory);
    }

    /**
     * @return \Generator<string, array{mixed, string}>
     */
    public static function invalidConfigurations(): \Generator
    {
        yield 'not an object' => ['manifest.json', 'the configuration must be an object'];
        yield 'none' => [[], 'configure either "manifest"'];
        yield 'both' => [['manifest' => 'a.json', 'packages' => []], 'configure either "manifest"'];
        yield 'unknown key' => [['manifest' => 'a.json', 'path' => 'x'], 'unknown key(s) "path"'];
        yield 'directory with manifest' => [['manifest' => 'a.json', 'directory' => 'x'], '"directory" is only supported together with inline "packages"'];
        yield 'manifest not a string' => [['manifest' => ['a.json']], '"manifest" must be a non-empty string'];
        yield 'empty directory' => [['directory' => '', 'packages' => []], '"directory" must be a non-empty string'];
        yield 'missing manifest file' => [['manifest' => 'does-not-exist.json'], 'does-not-exist.json":' . PHP_EOL . ' - the file does not exist'];
        yield 'invalid inline packages' => [['packages' => ['x' => []]], '"x" is not a valid lower-case composer package name'];
    }

    #[DataProvider('invalidConfigurations')]
    #[Test]
    public function rejectsInvalidConfiguration(mixed $configuration, string $expectedError): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedError);
        (new ConfigurationLoader())->load(self::rootPackage([ConfigurationLoader::EXTRA_KEY => $configuration]), self::fixtures());
    }

    #[Test]
    public function rejectsManifestWithInvalidJson(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('invalid configuration in manifest file');
        // A PHP file is not JSON.
        (new ConfigurationLoader())->load(
            self::rootPackage([ConfigurationLoader::EXTRA_KEY => ['manifest' => '../Unit/Configuration/ConfigurationLoaderTest.php']]),
            self::fixtures(),
        );
    }

    #[Test]
    public function rootDirectoryIsTheDirectoryOfTheConfigSource(): void
    {
        $config = new Config(false);
        $config->setConfigSource(new JsonConfigSource(new JsonFile(self::fixtures() . '/checkouts/present-a/composer.json')));
        $composer = new Composer();
        $composer->setConfig($config);

        self::assertSame(realpath(self::fixtures() . '/checkouts/present-a'), (new ConfigurationLoader())->rootDirectory($composer));
    }

    #[Test]
    public function rootDirectoryFallsBackToWorkingDirectory(): void
    {
        $composer = new Composer();
        $composer->setConfig(new Config(false));

        self::assertSame(Platform::getCwd(true), (new ConfigurationLoader())->rootDirectory($composer));
    }
}
