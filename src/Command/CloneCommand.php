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

namespace SBUERK\CheckoutPathRepository\Command;

use Composer\Command\BaseCommand;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use SBUERK\CheckoutPathRepository\Configuration\ConfigurationLoader;
use SBUERK\CheckoutPathRepository\Exception\InvalidConfigurationException;
use SBUERK\CheckoutPathRepository\Git\GitCheckout;
use SBUERK\CheckoutPathRepository\Lock\CheckoutLock;
use SBUERK\CheckoutPathRepository\Lock\LockException;
use SBUERK\CheckoutPathRepository\Manifest\CheckoutDefinition;
use SBUERK\CheckoutPathRepository\Manifest\Manifest;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer checkouts:clone [--strict] [<package>...]`
 *
 * Clones missing checkouts of the manifest. Existing directories are never
 * touched. Remotes that are not accessible without interaction (private
 * repository, no ssh agent, missing branch) are skipped with a notice.
 */
final class CloneCommand extends BaseCommand
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_FAILURE = 1;

    private ConfigurationLoader $configurationLoader;
    private CheckoutLock $checkoutLock;
    private Filesystem $filesystem;

    public function __construct(
        ?ConfigurationLoader $configurationLoader = null,
        ?CheckoutLock $checkoutLock = null,
        private readonly ?GitCheckout $git = null,
        private readonly float $lockTimeoutSeconds = CheckoutLock::DEFAULT_TIMEOUT_SECONDS,
    ) {
        $this->configurationLoader = $configurationLoader ?? new ConfigurationLoader();
        $this->checkoutLock = $checkoutLock ?? new CheckoutLock();
        $this->filesystem = new Filesystem();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('checkouts:clone')
            ->setDescription('Clones missing checkouts listed in the checkout manifest.')
            ->addArgument(
                'packages',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Package names to clone, all manifest packages when omitted.',
            )
            ->addOption(
                'strict',
                null,
                InputOption::VALUE_NONE,
                'Fail (exit code 1) when a checkout could not be cloned because its remote is not accessible.',
            )
            ->setHelp(
                <<<'HELP'
                    Clones every checkout of the manifest whose directory does not exist yet:

                        <info>git clone --branch <branch> <url> <path></info>

                    Existing directories are never modified - pull, switch branches or re-clone
                    them yourself. Before cloning, access is checked non-interactively with
                    <info>git ls-remote</info>; an inaccessible remote (e.g. a private repository without
                    access) is skipped with a notice and does not fail the command unless
                    <info>--strict</info> is given.

                    Concurrent runs sharing the same checkout directory are serialised with an
                    exclusive lock on "<manifest directory>/.checkouts.lock".

                    Run <info>composer update</info> afterwards to install new checkouts.
                    HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $composer = $this->requireComposer();

        try {
            $manifest = $this->configurationLoader->loadFromComposer($composer);
        } catch (InvalidConfigurationException $e) {
            $io->writeError('<error>' . $e->getMessage() . '</error>');
            return self::EXIT_FAILURE;
        }
        if ($manifest === null) {
            $io->writeError(sprintf('<error>No checkouts configured: add extra."%s" to the root composer.json.</error>', ConfigurationLoader::EXTRA_KEY));
            return self::EXIT_FAILURE;
        }

        /** @var list<string> $requestedNames */
        $requestedNames = $input->getArgument('packages');
        $selected = $this->select($manifest, $requestedNames, $io);
        if ($selected === null) {
            return self::EXIT_FAILURE;
        }

        try {
            $lock = $this->checkoutLock->acquire(
                $manifest->lockFile(),
                $this->lockTimeoutSeconds,
                static function () use ($io, $manifest): void {
                    $io->writeError(sprintf('Waiting for another checkouts:clone run to finish (lock "%s") ...', $manifest->lockFile()));
                },
            );
        } catch (LockException $e) {
            $io->writeError('<error>' . $e->getMessage() . '</error>');
            return self::EXIT_FAILURE;
        }

        $git = $this->git ?? new GitCheckout(new ProcessExecutor($io));
        $rootDirectory = $this->filesystem->normalizePath($this->configurationLoader->rootDirectory($composer));
        $counts = ['cloned' => 0, 'present' => 0, 'inaccessible' => 0, 'failed' => 0];
        try {
            foreach ($selected as $checkout) {
                $counts[$this->cloneOne($checkout, $git, $io, $rootDirectory)]++;
            }
        } finally {
            $lock->release();
        }

        $io->writeError(sprintf(
            'checkouts:clone: %d cloned, %d already present, %d not accessible, %d failed.',
            $counts['cloned'],
            $counts['present'],
            $counts['inaccessible'],
            $counts['failed'],
        ));
        if ($counts['cloned'] > 0) {
            $io->writeError('<info>Run "composer update" to install the new checkouts.</info>');
        }

        if ($counts['failed'] > 0 || ($counts['inaccessible'] > 0 && $input->getOption('strict') === true)) {
            return self::EXIT_FAILURE;
        }
        return self::EXIT_SUCCESS;
    }

    /**
     * @param list<string> $requestedNames
     * @return list<CheckoutDefinition>|null null on unknown package names
     */
    private function select(Manifest $manifest, array $requestedNames, IOInterface $io): ?array
    {
        if ($requestedNames === []) {
            return array_values($manifest->checkouts);
        }
        $unknown = array_values(array_filter($requestedNames, static fn(string $name): bool => !$manifest->has($name)));
        if ($unknown !== []) {
            $io->writeError(sprintf(
                '<error>Unknown package(s) "%s", the manifest lists "%s".</error>',
                implode('", "', $unknown),
                implode('", "', array_keys($manifest->checkouts)),
            ));
            return null;
        }
        $wanted = array_flip(array_map('strtolower', $requestedNames));
        return array_values(array_filter(
            $manifest->checkouts,
            static fn(CheckoutDefinition $checkout): bool => isset($wanted[$checkout->name]),
        ));
    }

    /**
     * @return 'cloned'|'present'|'inaccessible'|'failed'
     */
    private function cloneOne(CheckoutDefinition $checkout, GitCheckout $git, IOInterface $io, string $rootDirectory): string
    {
        $displayPath = $this->filesystem->findShortestPath($rootDirectory, $checkout->absolutePath, true);

        // Checked after acquiring the lock: a concurrent run may have cloned it meanwhile.
        if ($checkout->directoryExists()) {
            $io->writeError(sprintf('  - %s: %s exists, left untouched', $checkout->name, $displayPath), true, IOInterface::VERBOSE);
            return 'present';
        }

        $access = $git->isAccessible($checkout->url, $checkout->branch);
        if (!$access->isSuccessful()) {
            $io->writeError(sprintf(
                '  - <warning>%s: skipped, %s (branch "%s") is not accessible: %s</warning>',
                $checkout->name,
                $checkout->url,
                $checkout->branch,
                $access->exitCode === 2 ? 'branch not found' : $access->reason(),
            ));
            return 'inaccessible';
        }

        $parent = dirname($checkout->absolutePath);
        try {
            $this->filesystem->ensureDirectoryExists($parent);
        } catch (\RuntimeException $e) {
            $io->writeError(sprintf('  - <error>%s: %s</error>', $checkout->name, $e->getMessage()));
            return 'failed';
        }

        $result = $git->clone($checkout->url, $checkout->branch, $checkout->absolutePath);
        if (!$result->isSuccessful()) {
            $io->writeError(sprintf('  - <error>%s: git clone failed: %s</error>', $checkout->name, $result->reason()));
            return 'failed';
        }
        $io->writeError(sprintf('  - %s: cloned %s (branch "%s") into %s', $checkout->name, $checkout->url, $checkout->branch, $displayPath));
        return 'cloned';
    }
}
