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

use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\CheckoutPathRepository\Git\GitCheckout;

/**
 * Verifies the non-interactive environment of remote git operations with a
 * fake `ssh` executable that records how it was called.
 */
final class GitCheckoutTest extends IntegrationTestCase
{
    private const SSH_URL = 'ssh://git@example.invalid/vendor/package.git';

    private string $sshLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (Platform::isWindows()) {
            self::markTestSkipped('The fake ssh executable is a shell script.');
        }
        $bin = $this->workspace . '/bin';
        $this->filesystem->ensureDirectoryExists($bin);
        $this->sshLog = $this->workspace . '/ssh.log';
        file_put_contents(
            $bin . '/ssh',
            "#!/bin/sh\necho \"args=\$*|prompt=\$GIT_TERMINAL_PROMPT|askpass=[\${GIT_ASKPASS-unset}]|gcm=\${GCM_INTERACTIVE-unset}|lc=\${LC_ALL-unset}\" >> "
            . escapeshellarg($this->sshLog) . "\nif [ -n \"\$FAKE_SSH_SLEEP\" ]; then sleep \"\$FAKE_SSH_SLEEP\"; fi\nexit 255\n",
        );
        chmod($bin . '/ssh', 0755);
        $this->setEnvironment('PATH', $bin . PATH_SEPARATOR . (string) Platform::getEnv('PATH'));
    }

    #[Test]
    public function remoteOperationsUseBatchModeSshAndNoPrompts(): void
    {
        $result = (new GitCheckout(new ProcessExecutor()))->isAccessible(self::SSH_URL, 'main');

        self::assertFalse($result->isSuccessful());
        $log = (string) file_get_contents($this->sshLog);
        self::assertStringContainsString('args=-o BatchMode=yes -o ConnectTimeout=15 ', $log);
        self::assertStringContainsString('prompt=0', $log);
        self::assertStringContainsString('askpass=[]', $log, 'empty GIT_ASKPASS: no core.askPass, no SSH_ASKPASS dialog');
        self::assertStringContainsString('gcm=never', $log);
        self::assertStringContainsString('lc=C', $log);
        self::assertFalse(Platform::getEnv('GIT_ASKPASS'), 'the environment is restored');
        self::assertFalse(Platform::getEnv('GCM_INTERACTIVE'), 'the environment is restored');
        self::assertFalse(Platform::getEnv('GIT_SSH_COMMAND'), 'the environment is restored');
        self::assertFalse(Platform::getEnv('GIT_TERMINAL_PROMPT'), 'the environment is restored');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function composerProcessTimeouts(): array
    {
        return [
            'composer default timeout' => [300],
            // COMPOSER_PROCESS_TIMEOUT=0, as set by CI setups: no limit at all
            'unlimited process timeout' => [0],
        ];
    }

    #[Test]
    #[DataProvider('composerProcessTimeouts')]
    public function accessCheckGivesUpAfterItsTimeout(int $composerTimeout): void
    {
        $this->setEnvironment('FAKE_SSH_SLEEP', '10');
        $originalTimeout = ProcessExecutor::getTimeout();
        ProcessExecutor::setTimeout($composerTimeout);
        try {
            $started = microtime(true);

            $result = (new GitCheckout(new ProcessExecutor(), 1))->isAccessible(self::SSH_URL, 'main');

            self::assertLessThan(8, microtime(true) - $started);
            self::assertSame(GitCheckout::EXIT_TIMEOUT, $result->exitCode);
            self::assertSame('fatal: timed out after 1 seconds', $result->reason());
            self::assertSame($composerTimeout, ProcessExecutor::getTimeout(), 'composer\'s process timeout is restored');
        } finally {
            ProcessExecutor::setTimeout($originalTimeout);
        }
    }

    #[Test]
    public function configuredSshCommandAndAskpassAreRespected(): void
    {
        $this->setEnvironment('GIT_SSH_COMMAND', 'ssh -o CustomOption=yes');
        $this->setEnvironment('GIT_ASKPASS', '/usr/bin/true');

        (new GitCheckout(new ProcessExecutor()))->clone(self::SSH_URL, 'main', $this->workspace . '/clone');

        $log = (string) file_get_contents($this->sshLog);
        self::assertStringContainsString('args=-o CustomOption=yes', $log);
        self::assertStringNotContainsString('BatchMode', $log);
        self::assertStringContainsString('askpass=[/usr/bin/true]', $log);
        self::assertSame('ssh -o CustomOption=yes', Platform::getEnv('GIT_SSH_COMMAND'));
        self::assertDirectoryDoesNotExist($this->workspace . '/clone');
    }

    #[Test]
    public function reportsBranchAndDirtyStateOfWorkingTrees(): void
    {
        $directory = $this->createCheckout('lib', 'main', ['name' => 'fixture/lib']);
        $git = new GitCheckout(new ProcessExecutor());

        self::assertSame('main', $git->currentBranch($directory));
        self::assertFalse($git->isDirty($directory));

        file_put_contents($directory . '/untracked.txt', 'x');
        self::assertTrue($git->isDirty($directory));

        $this->git(['checkout', '--quiet', '--detach'], $directory);
        self::assertNull($git->currentBranch($directory), 'detached HEAD');

        mkdir($this->workspace . '/plain');
        self::assertNull($git->currentBranch($this->workspace . '/plain'));
        self::assertNull($git->isDirty($this->workspace . '/plain'));
    }
}
