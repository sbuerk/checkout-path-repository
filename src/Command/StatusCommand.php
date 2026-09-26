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
use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use SBUERK\CheckoutPathRepository\Configuration\ConfigurationLoader;
use SBUERK\CheckoutPathRepository\Exception\InvalidConfigurationException;
use SBUERK\CheckoutPathRepository\Git\GitCheckout;
use SBUERK\CheckoutPathRepository\Status\CheckoutStatus;
use SBUERK\CheckoutPathRepository\Status\StatusResolver;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer checkouts:status [--format=table|json]`
 *
 * Lists the manifest checkouts and whether the installed packages match them.
 * The exit code tells scripts whether a `composer update` is needed.
 */
final class StatusCommand extends BaseCommand
{
    public const EXIT_IN_SYNC = 0;
    public const EXIT_FAILURE = 1;
    /**
     * A present checkout is not installed from its path (not installed at all,
     * installed from another source, other version) or an installed checkout
     * vanished: run `composer update`.
     */
    public const EXIT_OUT_OF_SYNC = 3;

    private const FORMATS = ['table', 'json'];

    private ConfigurationLoader $configurationLoader;

    public function __construct(
        ?ConfigurationLoader $configurationLoader = null,
        private readonly ?GitCheckout $git = null,
    ) {
        $this->configurationLoader = $configurationLoader ?? new ConfigurationLoader();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('checkouts:status')
            ->setDescription('Shows the checkouts of the manifest and whether they are installed from their path.')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: table or json.', 'table')
            ->setHelp(
                <<<'HELP'
                    Shows every checkout of the manifest: its path, whether it is present, the
                    expected and the current branch, uncommitted changes and the installed
                    version (vendor/composer/installed.json). The state takes the lock file into
                    account, as "composer install" works from it.

                    Exit codes:
                      <info>0</info>  in sync, nothing to do
                      <info>3</info>  out of sync, run "composer update": a present checkout is required but
                         not installed or not locked, installed or locked from another source or
                         with another version, an installed or locked checkout does not exist
                         anymore, or a package removed from the manifest is still installed
                         from its vanished checkout ("orphaned")
                      <info>1</info>  error (e.g. invalid configuration)
                    HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $format = $input->getOption('format');
        if (!is_string($format) || !in_array($format, self::FORMATS, true)) {
            $io->writeError(sprintf('<error>Invalid format "%s", use one of "%s".</error>', is_string($format) ? $format : '', implode('", "', self::FORMATS)));
            return self::EXIT_FAILURE;
        }

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

        $resolver = new StatusResolver($this->git ?? new GitCheckout(new ProcessExecutor($io)));
        $statuses = $resolver->resolveForComposer($composer, $manifest, $this->configurationLoader->rootDirectory($composer));
        $inSync = array_filter($statuses, static fn(CheckoutStatus $status): bool => !$status->isInSync()) === [];

        if ($format === 'json') {
            $output->writeln(JsonFile::encode([
                'inSync' => $inSync,
                'manifest' => $manifest->file,
                'directory' => $manifest->directory,
                'checkouts' => array_map(static fn(CheckoutStatus $status): array => $status->toArray(), $statuses),
            ]));
        } else {
            $this->renderStatusTable($output, $statuses);
            $io->writeError($inSync
                ? '<info>Checkouts and installed packages are in sync.</info>'
                : '<warning>Installed packages do not match the checkouts, run "composer update".</warning>');
        }

        return $inSync ? self::EXIT_IN_SYNC : self::EXIT_OUT_OF_SYNC;
    }

    /**
     * @param list<CheckoutStatus> $statuses
     */
    private function renderStatusTable(OutputInterface $output, array $statuses): void
    {
        $table = new Table($output);
        $table->setHeaders(['Package', 'Path', 'Present', 'Branch', 'Current', 'Dirty', 'Installed', 'State']);
        foreach ($statuses as $status) {
            $table->addRow([
                $status->name . ($status->expectedBranch === null ? ' (not in manifest)' : ($status->required ? '' : ' (optional)')),
                $status->path,
                $status->present ? 'yes' : 'no',
                $status->expectedBranch ?? '-',
                $status->currentBranch ?? '-',
                $status->dirty === null ? '-' : ($status->dirty ? 'yes' : 'no'),
                $status->installedVersion === null
                    ? '-'
                    : $status->installedVersion . ($status->installedFromCheckout ? '' : ' (other source)'),
                $status->isInSync() ? $status->state : sprintf('<comment>%s</comment>', $status->state),
            ]);
        }
        $table->render();
    }
}
