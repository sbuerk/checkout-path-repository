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
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Thin wrapper around the git commands the plugin needs, executed through
 * composer's {@see ProcessExecutor} with array commands (no shell involved).
 *
 * Remote operations (`ls-remote`, `clone`) never prompt. For the duration of
 * the call only:
 *
 * - `GIT_TERMINAL_PROMPT=0` disables git's terminal credential prompts;
 * - `LC_ALL=C` keeps git's messages untranslated for {@see GitResult::reason()};
 * - `GIT_ASKPASS` is set empty (unless configured), which makes git skip
 *   `core.askPass` and a graphical `SSH_ASKPASS` for credentials, and
 *   `GCM_INTERACTIVE=never` (unless configured) keeps the Git Credential
 *   Manager from opening a dialog;
 * - unless `GIT_SSH_COMMAND` or `GIT_SSH` is configured,
 *   `ssh -o BatchMode=yes -o ConnectTimeout=15` makes ssh fail instead of
 *   asking for a passphrase or a host key confirmation, and gives up on
 *   unreachable hosts after 15 seconds.
 *
 * The access check additionally runs with a process timeout of its own, so
 * an unresponsive remote (e.g. https without a connect timeout) cannot block
 * for composer's full `process-timeout`. Clones use `process-timeout`.
 *
 * Stateless service.
 */
final class GitCheckout
{
    public const BATCH_SSH_COMMAND = 'ssh -o BatchMode=yes -o ConnectTimeout=15';
    public const DEFAULT_ACCESS_CHECK_TIMEOUT = 60;
    /** Exit code reported when a git process was killed after its timeout. */
    public const EXIT_TIMEOUT = 124;

    public function __construct(
        private readonly ProcessExecutor $processExecutor,
        private readonly int $accessCheckTimeout = self::DEFAULT_ACCESS_CHECK_TIMEOUT,
    ) {}

    /**
     * Whether `$branch` exists on `$url` and the remote is accessible without
     * any interaction.
     */
    public function isAccessible(string $url, string $branch): GitResult
    {
        $previousTimeout = ProcessExecutor::getTimeout();
        // 0 disables composer's process timeout; the access check keeps its own.
        ProcessExecutor::setTimeout($previousTimeout > 0 ? min($previousTimeout, $this->accessCheckTimeout) : $this->accessCheckTimeout);
        try {
            return $this->runNonInteractive(['git', 'ls-remote', '--exit-code', '--heads', $url, 'refs/heads/' . $branch]);
        } finally {
            ProcessExecutor::setTimeout($previousTimeout);
        }
    }

    /**
     * The url git actually uses for `$url`, after `url.<base>.insteadOf`
     * rewriting of the git configuration. Local only, no remote access.
     */
    public function effectiveUrl(string $url): string
    {
        $result = $this->run(['git', 'ls-remote', '--get-url', $url]);
        $effectiveUrl = trim($result->output);
        return $result->isSuccessful() && $effectiveUrl !== '' ? $effectiveUrl : $url;
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
        // LC_ALL=C: English messages, so GitResult::reason() finds the "fatal:" line.
        $environment = ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C'];
        if (Platform::getEnv('GIT_ASKPASS') === false) {
            $environment['GIT_ASKPASS'] = '';
        }
        if (!$this->hasEnv('GCM_INTERACTIVE')) {
            $environment['GCM_INTERACTIVE'] = 'never';
        }
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
        try {
            $exitCode = $this->processExecutor->execute($command, $output, $cwd);
        } catch (ProcessTimedOutException $e) {
            return new GitResult(self::EXIT_TIMEOUT, '', sprintf('fatal: timed out after %d seconds', (int) $e->getExceededTimeout()));
        }
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
