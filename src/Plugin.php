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

namespace SBUERK\CheckoutPathRepository;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use SBUERK\CheckoutPathRepository\Command\CommandProvider;
use SBUERK\CheckoutPathRepository\Configuration\ConfigurationLoader;
use SBUERK\CheckoutPathRepository\Repository\RepositoryRegistrar;

/**
 * Composer plugin entry point.
 *
 * `activate()` is the earliest point a plugin can influence dependency
 * resolution: root repositories are already instantiated then, but the
 * repository set for `update` (and for `show`, `why`, `outdated`, ...) is built
 * later. The plugin only reads the manifest and registers what is on disk
 * there - network and git operations are left to the explicit
 * `checkouts:clone` command, as activate() runs on every composer call.
 */
final class Plugin implements PluginInterface, Capable
{
    private ConfigurationLoader $configurationLoader;
    private RepositoryRegistrar $repositoryRegistrar;

    public function __construct(
        ?ConfigurationLoader $configurationLoader = null,
        ?RepositoryRegistrar $repositoryRegistrar = null,
    ) {
        $this->configurationLoader = $configurationLoader ?? new ConfigurationLoader();
        $this->repositoryRegistrar = $repositoryRegistrar ?? new RepositoryRegistrar();
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        $manifest = $this->configurationLoader->loadFromComposer($composer);
        if ($manifest === null) {
            $io->writeError(
                sprintf('%sno extra."%s" configuration in the root composer.json, nothing to register', RepositoryRegistrar::MESSAGE_PREFIX, ConfigurationLoader::EXTRA_KEY),
                true,
                IOInterface::VERBOSE,
            );
            return;
        }
        $this->repositoryRegistrar->register($composer, $io, $manifest);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // No persistent state to tear down.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // No persistent state to remove.
    }

    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }
}
