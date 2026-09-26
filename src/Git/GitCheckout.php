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

namespace SBUERK\CheckoutPathRepository\Git;

use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;

/**
 * Thin wrapper around the git commands the plugin needs, executed through
 * composer's {@see ProcessExecutor} with array commands (no shell involved).
 *
 * Remote operations (`ls-remote`, `clone`) never prompt: `GIT_TERMINAL_PROMPT=0`
 * disables credential prompts and, unless the user configured `GIT_SSH_COMMAND`
 * or `GIT_SSH` already, `ssh -o BatchMode=yes` makes ssh fail instead of asking
 * for a passphrase or host key confirmation. Both variables are set only for
 * the duration of the call and restored afterwards.
 *
 * Stateless service.
 */
final class GitCheckout
{
    public const BATCH_SSH_COMMAND = 'ssh -o BatchMode=yes';

    public function __construct(private readonly ProcessExecutor $processExecutor) {}

    /**
     * Whether `$branch` exists on `$url` and the remote is accessible without
     * any interaction.
     */
    public function isAccessible(string $url, string $branch): GitResult
    {
        return $this->runNonInteractive(['git', 'ls-remote', '--exit-code', '--heads', $url, 'refs/heads/' . $branch]);
    }

    public function clone(string $url, string $branch, string $directory): GitResult
    {
        return $this->runNonInteractive(['git', 'clone', '--quiet', '--branch', $branch, '--', $url, $directory]);
    }

    /**
     * Whether the directory is the root of its own git working tree. Checked
     * via `.git` (a directory, or a file for worktrees and submodules) so a
     * plain directory inside another repository - e.g. an ignored checkout
     * directory of a mono repository - does not report that repository.
     */
    public function isWorkingTree(string $directory): bool
    {
        return file_exists($directory . '/.git');
    }

    /**
     * @return string|null the checked out branch, null for a detached HEAD or
     *                     when the directory is not a git working tree
     */
    public function currentBranch(string $directory): ?string
    {
        if (!$this->isWorkingTree($directory)) {
            return null;
        }
        $result = $this->run(['git', 'symbolic-ref', '--quiet', '--short', 'HEAD'], $directory);
        $branch = trim($result->output);
        return $result->isSuccessful() && $branch !== '' ? $branch : null;
    }

    /**
     * @return bool|null null when the directory is not a git working tree
     */
    public function isDirty(string $directory): ?bool
    {
        if (!$this->isWorkingTree($directory)) {
            return null;
        }
        $result = $this->run(['git', 'status', '--porcelain', '--untracked-files=normal'], $directory);
        if (!$result->isSuccessful()) {
            return null;
        }
        return trim($result->output) !== '';
    }

    /**
     * @param non-empty-list<string> $command
     */
    private function runNonInteractive(array $command, ?string $cwd = null): GitResult
    {
        $environment = ['GIT_TERMINAL_PROMPT' => '0'];
        if (!$this->hasEnv('GIT_SSH_COMMAND') && !$this->hasEnv('GIT_SSH')) {
            $environment['GIT_SSH_COMMAND'] = self::BATCH_SSH_COMMAND;
        }

        $previous = [];
        foreach ($environment as $name => $value) {
            $previous[$name] = Platform::getEnv($name);
            Platform::putEnv($name, $value);
        }
        try {
            return $this->run($command, $cwd);
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === false) {
                    Platform::clearEnv($name);
                } else {
                    Platform::putEnv($name, $value);
                }
            }
        }
    }

    /**
     * @param non-empty-list<string> $command
     */
    private function run(array $command, ?string $cwd = null): GitResult
    {
        $output = '';
        $exitCode = $this->processExecutor->execute($command, $output, $cwd);
        return new GitResult($exitCode, is_string($output) ? $output : '', $this->processExecutor->getErrorOutput());
    }

    /**
     * @param non-empty-string $name
     */
    private function hasEnv(string $name): bool
    {
        $value = Platform::getEnv($name);
        return $value !== false && $value !== '';
    }
}
