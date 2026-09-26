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
            "#!/bin/sh\necho \"args=\$*|prompt=\$GIT_TERMINAL_PROMPT\" >> " . escapeshellarg($this->sshLog) . "\nexit 255\n",
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
        self::assertStringContainsString('args=-o BatchMode=yes', $log);
        self::assertStringContainsString('prompt=0', $log);
        self::assertFalse(Platform::getEnv('GIT_SSH_COMMAND'), 'the environment is restored');
        self::assertFalse(Platform::getEnv('GIT_TERMINAL_PROMPT'), 'the environment is restored');
    }

    #[Test]
    public function configuredSshCommandIsRespected(): void
    {
        $this->setEnvironment('GIT_SSH_COMMAND', 'ssh -o CustomOption=yes');

        (new GitCheckout(new ProcessExecutor()))->clone(self::SSH_URL, 'main', $this->workspace . '/clone');

        $log = (string) file_get_contents($this->sshLog);
        self::assertStringContainsString('args=-o CustomOption=yes', $log);
        self::assertStringNotContainsString('BatchMode', $log);
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
