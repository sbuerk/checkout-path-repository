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

namespace SBUERK\CheckoutPathRepository\Tests\Unit\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SBUERK\CheckoutPathRepository\Exception\InvalidConfigurationException;
use SBUERK\CheckoutPathRepository\Manifest\ManifestParser;

final class ManifestParserTest extends TestCase
{
    private const DIRECTORY = '/work/project/packages';

    /**
     * @return array<string, mixed>
     */
    private static function validDefinition(): array
    {
        return [
            'url' => 'git@github.com:vendor/package.git',
            'branch' => '5',
            'version' => '5.1.x-dev',
        ];
    }

    #[Test]
    public function parsesDefinitionsWithDefaultsInManifestOrder(): void
    {
        $manifest = (new ManifestParser())->parseDocument(
            [
                'line' => ['id' => '1', 'cores' => [12, 13]],
                'packages' => [
                    'vendor/second' => self::validDefinition(),
                    'vendor/first' => self::validDefinition() + ['path' => 'sub/dir', 'require' => false],
                ],
            ],
            self::DIRECTORY,
            'test',
            self::DIRECTORY . '/checkouts.json',
        );

        self::assertSame(['vendor/second', 'vendor/first'], array_keys($manifest->checkouts));
        self::assertSame(self::DIRECTORY, $manifest->directory);
        self::assertSame(self::DIRECTORY . '/checkouts.json', $manifest->file);
        self::assertSame(self::DIRECTORY . '/.checkouts.lock', $manifest->lockFile());

        $second = $manifest->get('vendor/second');
        self::assertNotNull($second);
        self::assertSame('git@github.com:vendor/package.git', $second->url);
        self::assertSame('5', $second->branch);
        self::assertSame('5.1.x-dev', $second->version);
        self::assertSame('second', $second->path, 'path defaults to the last name segment');
        self::assertSame(self::DIRECTORY . '/second', $second->absolutePath);
        self::assertTrue($second->require, 'require defaults to true');

        $first = $manifest->get('VENDOR/First');
        self::assertNotNull($first, 'lookup is case-insensitive');
        self::assertSame('sub/dir', $first->path);
        self::assertSame(self::DIRECTORY . '/sub/dir', $first->absolutePath);
        self::assertFalse($first->require);
    }

    #[Test]
    public function relativePathsOutsideTheManifestDirectoryAreNormalized(): void
    {
        $manifest = (new ManifestParser())->parsePackages(
            ['vendor/package' => self::validDefinition() + ['path' => '../elsewhere/./package']],
            self::DIRECTORY . '/',
            'test',
        );

        self::assertSame('/work/project/elsewhere/package', $manifest->get('vendor/package')?->absolutePath);
        self::assertNull($manifest->file);
    }

    #[Test]
    public function emptyPackagesAreValid(): void
    {
        self::assertSame([], (new ManifestParser())->parseDocument(['packages' => []], self::DIRECTORY, 'test')->checkouts);
    }

    /**
     * @return \Generator<string, array{mixed, string}>
     */
    public static function invalidDocuments(): \Generator
    {
        yield 'not an object' => ['string', 'the manifest must be a JSON object'];
        yield 'a list' => [[1, 2], 'the manifest must be a JSON object'];
        yield 'no packages' => [['line' => []], 'the manifest has no "packages" object'];
        yield 'packages is a list' => [['packages' => [self::validDefinition()]], '"packages" must be an object'];
        yield 'packages is a string' => [['packages' => 'vendor/package'], '"packages" must be an object'];
    }

    #[DataProvider('invalidDocuments')]
    #[Test]
    public function rejectsInvalidDocuments(mixed $document, string $expectedError): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedError);
        (new ManifestParser())->parseDocument($document, self::DIRECTORY, 'test');
    }

    /**
     * @return \Generator<string, array{array<mixed>, string}>
     */
    public static function invalidDefinitions(): \Generator
    {
        $valid = self::validDefinition();
        yield 'invalid name' => [['not-a-name' => $valid], '"not-a-name" is not a valid lower-case composer package name'];
        yield 'upper-case name' => [['Vendor/Package' => $valid], '"Vendor/Package" is not a valid lower-case composer package name'];
        yield 'definition not an object' => [['vendor/package' => 'x'], '"vendor/package": the definition must be an object'];
        yield 'unknown key' => [['vendor/package' => $valid + ['optional' => true]], '"vendor/package": unknown key(s) "optional"'];
        yield 'missing url' => [['vendor/package' => array_diff_key($valid, ['url' => 1])], '"vendor/package": missing required key "url"'];
        yield 'missing branch' => [['vendor/package' => array_diff_key($valid, ['branch' => 1])], '"vendor/package": missing required key "branch"'];
        yield 'missing version' => [['vendor/package' => array_diff_key($valid, ['version' => 1])], '"vendor/package": missing required key "version"'];
        yield 'empty url' => [['vendor/package' => ['url' => ' '] + $valid], '"vendor/package": "url" must be a non-empty string'];
        yield 'url not a string' => [['vendor/package' => ['url' => 1] + $valid], '"vendor/package": "url" must be a non-empty string'];
        yield 'url option injection' => [['vendor/package' => ['url' => '--upload-pack=x'] + $valid], '"vendor/package": "url" must not start with "-"'];
        yield 'branch option injection' => [['vendor/package' => ['branch' => '-b'] + $valid], '"vendor/package": "branch" must not start with "-" or contain whitespace'];
        yield 'branch with whitespace' => [['vendor/package' => ['branch' => 'a b'] + $valid], '"vendor/package": "branch" must not start with "-" or contain whitespace'];
        yield 'version is a constraint' => [['vendor/package' => ['version' => '^5.1'] + $valid], '"vendor/package": "version" is not a valid composer version'];
        yield 'absolute path' => [['vendor/package' => $valid + ['path' => '/abs/package']], '"vendor/package": "path" must be relative to the manifest directory'];
        yield 'empty path' => [['vendor/package' => $valid + ['path' => '']], '"vendor/package": "path" must be a non-empty string'];
        yield 'path is the manifest directory' => [['vendor/package' => $valid + ['path' => '.']], '"vendor/package": "path" must point to a sub directory'];
        yield 'require not a bool' => [['vendor/package' => $valid + ['require' => 'yes']], '"vendor/package": "require" must be a boolean'];
        yield 'duplicate path' => [
            ['vendor/package' => $valid, 'other/package' => $valid],
            '"other/package": path "package" is already used by "vendor/package"',
        ];
    }

    /**
     * @param array<mixed> $packages
     */
    #[DataProvider('invalidDefinitions')]
    #[Test]
    public function rejectsInvalidDefinitions(array $packages, string $expectedError): void
    {
        try {
            (new ManifestParser())->parsePackages($packages, self::DIRECTORY, 'manifest file "x.json"');
            self::fail('Expected an InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('invalid configuration in manifest file "x.json"', $e->getMessage());
            self::assertStringContainsString($expectedError, $e->getMessage());
        }
    }

    #[Test]
    public function reportsAllErrorsAtOnce(): void
    {
        try {
            (new ManifestParser())->parsePackages(
                [
                    'vendor/one' => ['branch' => '1', 'version' => '1.x-dev'],
                    'vendor/two' => ['url' => 'x', 'branch' => '1', 'version' => '1.x-dev', 'foo' => 1],
                ],
                self::DIRECTORY,
                'test',
            );
            self::fail('Expected an InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('"vendor/one": missing required key "url"', $e->getMessage());
            self::assertStringContainsString('"vendor/two": unknown key(s) "foo"', $e->getMessage());
        }
    }

    #[Test]
    public function rejectsRelativeDirectory(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must resolve to an absolute path');
        (new ManifestParser())->parsePackages([], 'relative/dir', 'test');
    }
}
