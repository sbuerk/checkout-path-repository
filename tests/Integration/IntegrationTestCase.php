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
use Composer\Composer;
use Composer\Console\Application;
use Composer\Factory;
use Composer\Installer;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use PHPUnit\Framework\TestCase;
use SBUERK\CheckoutPathRepository\Configuration\ConfigurationLoader;
use SBUERK\CheckoutPathRepository\Plugin;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs real composer operations in a throw-away workspace below the git
 * ignored `.cache/tests/` directory of this package:
 *
 * ```
 * .cache/tests/integration/<test class>/<test>/
 *   remotes/<name>/          git repositories acting as clone sources
 *   packages/checkouts.json  the manifest
 *   packages/<name>/         checkouts
 *   root/composer.json       the consuming root project
 * ```
 *
 * The environment is isolated from the developer machine: an own
 * COMPOSER_HOME and cache, packagist.org disabled, and git without the
 * global/system configuration.
 */
abstract class IntegrationTestCase extends TestCase
{
    private const ISOLATED_ENVIRONMENT = [
        'COMPOSER' => null,
        'COMPOSER_ROOT_VERSION' => null,
        'COMPOSER_NO_INTERACTION' => '1',
        'COMPOSER_ALLOW_SUPERUSER' => '1',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_SSH_COMMAND' => null,
        'GIT_SSH' => null,
        'GIT_TERMINAL_PROMPT' => null,
        'GIT_ASKPASS' => null,
        'GCM_INTERACTIVE' => null,
        'FAKE_SSH_SLEEP' => null,
        'GIT_AUTHOR_NAME' => 'Integration Test',
        'GIT_AUTHOR_EMAIL' => 'integration-test@example.com',
        'GIT_COMMITTER_NAME' => 'Integration Test',
        'GIT_COMMITTER_EMAIL' => 'integration-test@example.com',
    ];

    protected Filesystem $filesystem;
    protected string $workspace = '';
    private string $previousWorkingDirectory = '';

    /**
     * @var array<non-empty-string, string|false>
     */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem();
        $shortClassName = substr(static::class, (int) strrpos(static::class, '\\') + 1);
        $this->workspace = $this->filesystem->normalizePath(
            __DIR__ . '/../../.cache/tests/integration/' . $shortClassName . '/' . $this->name(),
        );
        $this->filesystem->emptyDirectory($this->workspace);
        $this->previousWorkingDirectory = (string) getcwd();

        $environment = self::ISOLATED_ENVIRONMENT + [
            'COMPOSER_HOME' => $this->workspace . '/.composer-home',
            'COMPOSER_CACHE_DIR' => $this->workspace . '/.composer-cache',
            'GIT_CONFIG_GLOBAL' => $this->workspace . '/.gitconfig',
            // The workspace lives inside this package's repository.
            'GIT_CEILING_DIRECTORIES' => dirname($this->workspace),
        ];
        file_put_contents($this->workspace . '/.gitconfig', '');
        foreach ($environment as $name => $value) {
            $this->setEnvironment($name, $value);
        }

        // Composer silences expected warnings (e.g. probing optional files) by
        // lowering error_reporting() instead of using "@", relying on its own
        // error handler (Composer\Util\ErrorHandler) to honour that. Mirror it,
        // and pass everything else on to PHPUnit.
        $previousHandler = null;
        $previousHandler = set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$previousHandler): bool {
            if ((error_reporting() & $level) === 0) {
                return true;
            }
            return is_callable($previousHandler) && (bool) $previousHandler($level, $message, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        chdir($this->previousWorkingDirectory);
        foreach (array_reverse($this->previousEnvironment, true) as $name => $value) {
            if ($value === false) {
                Platform::clearEnv($name);
            } else {
                Platform::putEnv($name, $value);
            }
        }
        $this->previousEnvironment = [];
        parent::tearDown();
    }

    /**
     * Sets (or with null: removes) an environment variable until tearDown().
     *
     * @param non-empty-string $name
     */
    protected function setEnvironment(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->previousEnvironment)) {
            $this->previousEnvironment[$name] = Platform::getEnv($name);
        }
        if ($value === null) {
            Platform::clearEnv($name);
        } else {
            Platform::putEnv($name, $value);
        }
    }

    /**
     * Creates a git repository with one commit on `$branch` holding the given
     * composer.json.
     *
     * @param array<string, mixed> $composerJson
     */
    protected function createGitRepository(string $directory, string $branch, array $composerJson): string
    {
        $this->filesystem->ensureDirectoryExists($directory);
        $this->git(['init', '--quiet'], $directory);
        $this->git(['checkout', '--quiet', '-b', $branch], $directory);
        $this->writeJson($directory . '/composer.json', $composerJson);
        $this->git(['add', 'composer.json'], $directory);
        $this->git(['-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Initial commit'], $directory);
        return $directory;
    }

    /**
     * @param array<string, mixed> $composerJson
     */
    protected function createRemote(string $name, string $branch, array $composerJson): string
    {
        return $this->createGitRepository($this->workspace . '/remotes/' . $name, $branch, $composerJson);
    }

    /**
     * @param array<string, mixed> $composerJson
     */
    protected function createCheckout(string $path, string $branch, array $composerJson): string
    {
        return $this->createGitRepository($this->workspace . '/packages/' . $path, $branch, $composerJson);
    }

    /**
     * The standard fixture, modelled after a real extension fleet:
     *
     * - `fixture/lib` on branch `main` - stock version guessing would report
     *   `dev-main`, the manifest pins `1.19.x-dev`;
     * - `fixture/ext` on branch `5`, pinned to `5.1.x-dev`, requiring
     *   `fixture/lib: ~1.19.0@dev` (satisfiable only through the pin);
     * - `fixture/private`, whose url does not exist (an inaccessible remote).
     *
     * @param bool $cloneCheckouts clone lib and ext into packages/
     */
    protected function createFleet(bool $cloneCheckouts = true): void
    {
        $lib = $this->createRemote('lib', 'main', ['name' => 'fixture/lib', 'description' => 'library']);
        $ext = $this->createRemote('ext', '5', [
            'name' => 'fixture/ext',
            'description' => 'extension',
            'require' => ['fixture/lib' => '~1.19.0@dev'],
        ]);
        $this->writeManifest([
            'fixture/lib' => ['url' => $lib, 'branch' => 'main', 'version' => '1.19.x-dev'],
            'fixture/ext' => ['url' => $ext, 'branch' => '5', 'version' => '5.1.x-dev'],
            'fixture/private' => ['url' => $this->workspace . '/remotes/private', 'branch' => '1', 'version' => '1.x-dev'],
        ]);
        if ($cloneCheckouts) {
            $this->git(['clone', '--quiet', '--branch', 'main', $lib, $this->workspace . '/packages/lib'], $this->workspace);
            $this->git(['clone', '--quiet', '--branch', '5', $ext, $this->workspace . '/packages/ext'], $this->workspace);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $packages
     */
    protected function writeManifest(array $packages): void
    {
        $this->writeJson($this->workspace . '/packages/checkouts.json', [
            'line' => ['id' => '1', 'cores' => [12, 13]],
            'packages' => $packages,
        ]);
    }

    /**
     * Writes root/composer.json: packagist.org disabled and the plugin
     * configured with the manifest, merged with `$overrides`.
     *
     * @param array<string, mixed> $overrides
     */
    protected function writeRoot(array $overrides = []): string
    {
        $composerJson = array_replace_recursive(
            [
                'name' => 'test/root',
                'description' => 'integration test root project',
                'repositories' => [['packagist.org' => false]],
                'extra' => [ConfigurationLoader::EXTRA_KEY => ['manifest' => '../packages/checkouts.json']],
                'config' => ['allow-plugins' => ['sbuerk/checkout-path-repository' => true]],
            ],
            $overrides,
        );
        $this->writeJson($this->workspace . '/root/composer.json', $composerJson);
        return $this->workspace . '/root';
    }

    /**
     * Creates the composer instance of the root project like the composer
     * binary does. With `$activatePlugin`, the plugin is activated like the
     * plugin manager does for an installed plugin.
     */
    protected function createComposer(IOInterface $io, bool $activatePlugin = true): Composer
    {
        chdir($this->workspace . '/root');
        $composer = Factory::create($io, null, false);
        if ($activatePlugin) {
            (new Plugin())->activate($composer, $io);
        }
        return $composer;
    }

    protected function runUpdate(Composer $composer, IOInterface $io): int
    {
        chdir($this->workspace . '/root');
        $composer->getInstallationManager()->setOutputProgress(false);
        $installer = Installer::create($io, $composer);
        $installer
            ->setUpdate(true)
            ->setDevMode(true)
            ->setPreferDist(true)
            ->setDumpAutoloader(true);
        return $installer->run();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{int, string} exit code and standard output
     */
    protected function runCommand(BaseCommand $command, Composer $composer, BufferIO $io, array $input = []): array
    {
        chdir($this->workspace . '/root');
        // Composer commands insist on composer's application, which is only
        // attached here - its run() (error handler, signal handling, working
        // directory switching) is bypassed by the CommandTester.
        $application = new Application();
        $application->setAutoExit(false);
        $command->setApplication($application);
        $command->setComposer($composer);
        $command->setIO($io);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input, ['interactive' => false]);
        return [$exitCode, $tester->getDisplay()];
    }

    /**
     * Installed packages by name, from vendor/composer/installed.json.
     *
     * @return array<string, array<mixed>>
     */
    protected function installedPackages(): array
    {
        $file = $this->workspace . '/root/vendor/composer/installed.json';
        if (!is_file($file)) {
            return [];
        }
        $data = $this->readJson($file);
        self::assertIsArray($data['packages'] ?? null);
        $packages = [];
        foreach ($data['packages'] as $package) {
            self::assertIsArray($package);
            self::assertIsString($package['name'] ?? null);
            $packages[$package['name']] = $package;
        }
        return $packages;
    }

    /**
     * The `dist` section of an installed package.
     *
     * @return array<mixed>
     */
    protected function installedDist(string $name): array
    {
        $dist = $this->installedPackages()[$name]['dist'] ?? null;
        self::assertIsArray($dist, sprintf('%s is not installed or has no dist', $name));
        return $dist;
    }

    /**
     * Replaces keys of one manifest package definition (or adds the package).
     *
     * @param array<string, mixed> $changes
     */
    protected function changeManifestPackage(string $name, array $changes): void
    {
        $file = $this->workspace . '/packages/checkouts.json';
        $manifest = $this->readJson($file);
        $packages = $manifest['packages'] ?? [];
        self::assertIsArray($packages);
        $definition = $packages[$name] ?? [];
        self::assertIsArray($definition);
        $packages[$name] = array_replace($definition, $changes);
        $manifest['packages'] = $packages;
        $this->writeJson($file, $manifest);
    }

    protected function removeManifestPackage(string $name): void
    {
        $file = $this->workspace . '/packages/checkouts.json';
        $manifest = $this->readJson($file);
        $packages = $manifest['packages'] ?? [];
        self::assertIsArray($packages);
        self::assertArrayHasKey($name, $packages);
        unset($packages[$name]);
        $manifest['packages'] = $packages;
        $this->writeJson($file, $manifest);
    }

    /**
     * @return array<mixed>
     */
    protected function readJson(string $file): array
    {
        $data = (new JsonFile($file))->read();
        self::assertIsArray($data);
        return $data;
    }

    /**
     * @param list<string> $arguments
     */
    protected function git(array $arguments, string $cwd): string
    {
        $output = '';
        $process = new ProcessExecutor();
        $exitCode = $process->execute(array_merge(['git'], $arguments), $output, $cwd);
        self::assertSame(0, $exitCode, sprintf('git %s failed: %s', implode(' ', $arguments), $process->getErrorOutput()));
        return is_string($output) ? $output : '';
    }

    /**
     * @param array<mixed> $data
     */
    protected function writeJson(string $file, array $data): void
    {
        $this->filesystem->ensureDirectoryExists(dirname($file));
        file_put_contents($file, JsonFile::encode($data) . "\n");
    }
}
