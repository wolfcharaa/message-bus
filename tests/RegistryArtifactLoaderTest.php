<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Exception\InvalidMessageBusConfiguration;
use Wolfcharaa\MessageBus\Registry\ComposerDirectoryClassSource;
use Wolfcharaa\MessageBus\Registry\RefreshableRegistryClassDiscoverySource;
use Wolfcharaa\MessageBus\Registry\RegistryArtifactLoader;
use Wolfcharaa\MessageBus\Registry\RegistryClassDiscoveryResources;
use Wolfcharaa\MessageBus\Registry\RegistryClassDiscoveryResult;
use Wolfcharaa\MessageBus\Registry\RegistryRefreshPolicy;

final class RegistryArtifactLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/message-bus-refresh-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/src', 0o777, true);
        mkdir($this->directory . '/var', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testWhenStaleRequiresARefreshableSource(): void
    {
        $artifactPath = $this->directory . '/var/registry.json';

        try {
            (new RegistryArtifactLoader())->load($artifactPath, RegistryRefreshPolicy::WhenStale);
            self::fail('WhenStale accepted an absent discovery source.');
        } catch (\InvalidArgumentException $failure) {
            self::assertSame(
                'WhenStale requires a refreshable registry class discovery source.',
                $failure->getMessage(),
            );
        }
    }

    public function testRefreshLockFailureNamesTheExactUnusablePath(): void
    {
        $namespace = 'RefreshLockFixture' . bin2hex(random_bytes(5));
        $this->write('src/Ping.php', $this->queryAndHandler($namespace, 'Ping'));
        $source = new ComposerDirectoryClassSource([$this->directory . '/src'], [$namespace . '\\']);
        $artifactPath = $this->directory . '/missing/registry.json';

        try {
            (new RegistryArtifactLoader())->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source);
            self::fail('Refresh opened a lock below a missing directory.');
        } catch (\RuntimeException $failure) {
            self::assertSame(
                'Unable to open registry refresh lock: ' . $artifactPath . '.lock',
                $failure->getMessage(),
            );
        }
    }

    public function testWhenStaleRechecksResourcesButCompilesOnlyChangedInputs(): void
    {
        $namespace = 'RefreshFixture' . bin2hex(random_bytes(5));
        $this->write('src/Ping.php', $this->queryAndHandler($namespace, 'Ping'));
        $composer = $this->write('composer.json', json_encode([
            'autoload' => ['classmap' => ['src/']],
        ], JSON_THROW_ON_ERROR));
        $source = new CountingRefreshableSource(ComposerDirectoryClassSource::fromComposerJson($composer));
        $artifactPath = $this->directory . '/var/registry.json';
        $loader = new RegistryArtifactLoader();

        $first = $loader->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source, 'dev-build');
        self::assertCount(1, $first->bindings());
        self::assertSame(1, $source->discoverCalls);
        self::assertFileExists($artifactPath . '.meta.json');
        self::assertFileExists($artifactPath . '.lock');

        $second = $loader->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source, 'dev-build');
        self::assertSame($first->fingerprint, $second->fingerprint);
        self::assertSame(1, $source->discoverCalls, 'A fresh bootstrap must not parse declarations again.');
        self::assertGreaterThanOrEqual(3, $source->resourceCalls);

        $this->write('src/Pong.php', $this->queryAndHandler($namespace, 'Pong'));
        $third = $loader->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source, 'dev-build');
        self::assertCount(2, $third->bindings());
        self::assertNotSame($second->fingerprint, $third->fingerprint);
        self::assertSame(2, $source->discoverCalls);
    }

    public function testFailedRefreshDoesNotReturnOrOverwriteTheLastGoodArtifact(): void
    {
        $namespace = 'RefreshFailureFixture' . bin2hex(random_bytes(5));
        $this->write('src/Ping.php', $this->queryAndHandler($namespace, 'Ping'));
        $composer = $this->write('composer.json', json_encode([
            'autoload' => ['classmap' => ['src/']],
        ], JSON_THROW_ON_ERROR));
        $source = ComposerDirectoryClassSource::fromComposerJson($composer);
        $artifactPath = $this->directory . '/var/registry.json';
        $loader = new RegistryArtifactLoader();
        $good = $loader->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source);
        $goodContents = file_get_contents($artifactPath);

        $this->write('src/Duplicate.php', '<?php namespace ' . $namespace . '; final readonly class Ping implements \Wolfcharaa\MessageBus\Contract\Query {}');

        try {
            $loader->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source);
            self::fail('A duplicate declaration silently fell back to the previous artifact.');
        } catch (InvalidMessageBusConfiguration $failure) {
            self::assertContains('MBR025', array_column($failure->diagnostics, 'code'));
        }

        self::assertSame($goodContents, file_get_contents($artifactPath));
        self::assertSame($good->fingerprint, (new RegistryArtifactLoader())->load($artifactPath)->fingerprint);
    }

    public function testInvalidComposerRefreshPreservesDecodeCauseAndNeverReturnsStaleArtifact(): void
    {
        $namespace = 'RefreshComposerFailureFixture' . bin2hex(random_bytes(5));
        $this->write('src/Ping.php', $this->queryAndHandler($namespace, 'Ping'));
        $composer = $this->write('composer.json', json_encode([
            'autoload' => ['classmap' => ['src/']],
        ], JSON_THROW_ON_ERROR));
        $source = ComposerDirectoryClassSource::fromComposerJson($composer);
        $artifactPath = $this->directory . '/var/registry.json';
        $loader = new RegistryArtifactLoader();
        $loader->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source);
        $goodContents = file_get_contents($artifactPath);
        file_put_contents($composer, '{invalid');

        try {
            $loader->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source);
            self::fail('Invalid composer.json silently fell back to the previous artifact.');
        } catch (InvalidMessageBusConfiguration $failure) {
            self::assertContains('MBR022', array_column($failure->diagnostics, 'code'));
            self::assertInstanceOf(\JsonException::class, $failure->getPrevious());
            self::assertStringContainsString($composer, $failure->getMessage());
        }

        self::assertSame($goodContents, file_get_contents($artifactPath));
    }

    public function testCorruptRefreshMetadataForcesARecompileInsteadOfTrustingTheArtifact(): void
    {
        $namespace = 'RefreshMetadataFixture' . bin2hex(random_bytes(5));
        $this->write('src/Ping.php', $this->queryAndHandler($namespace, 'Ping'));
        $composer = $this->write('composer.json', json_encode([
            'autoload' => ['classmap' => ['src/']],
        ], JSON_THROW_ON_ERROR));
        $source = new CountingRefreshableSource(ComposerDirectoryClassSource::fromComposerJson($composer));
        $artifactPath = $this->directory . '/var/registry.json';
        $loader = new RegistryArtifactLoader();
        $first = $loader->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source);
        self::assertSame(1, $source->discoverCalls);
        file_put_contents($artifactPath . '.meta.json', '{invalid');

        $second = $loader->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source);

        self::assertSame($first->fingerprint, $second->fingerprint);
        self::assertSame(2, $source->discoverCalls);
        self::assertIsArray(json_decode(
            (string) file_get_contents($artifactPath . '.meta.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
    }

    public function testMetadataWriteFailureNamesTheExactArtifactSidecarPath(): void
    {
        $namespace = 'RefreshMetadataFailureFixture' . bin2hex(random_bytes(5));
        $this->write('src/Ping.php', $this->queryAndHandler($namespace, 'Ping'));
        $source = new ComposerDirectoryClassSource([$this->directory . '/src'], [$namespace . '\\']);
        $artifactPath = $this->directory . '/var/registry.json';
        $metaPath = $artifactPath . '.meta.json';
        mkdir($metaPath);
        set_error_handler(static fn(): bool => true);

        try {
            (new RegistryArtifactLoader())->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source);
            self::fail('Refresh metadata replaced an existing directory.');
        } catch (\RuntimeException $failure) {
            self::assertSame(
                'Unable to atomically write registry refresh metadata: ' . $metaPath,
                $failure->getMessage(),
            );
        } finally {
            restore_error_handler();
        }
    }

    public function testPrecompiledOnlyRejectsDiscoveryAndLoadsAfterSourceTreeIsGone(): void
    {
        $namespace = 'PrecompiledFixture' . bin2hex(random_bytes(5));
        $this->write('src/Ping.php', $this->queryAndHandler($namespace, 'Ping'));
        $composer = $this->write('composer.json', json_encode([
            'autoload' => ['classmap' => ['src/']],
        ], JSON_THROW_ON_ERROR));
        $source = ComposerDirectoryClassSource::fromComposerJson($composer);
        $artifactPath = $this->directory . '/var/registry.json';
        $loader = new RegistryArtifactLoader();
        $compiled = $loader->load($artifactPath, RegistryRefreshPolicy::WhenStale, $source, 'release-1');

        try {
            $loader->load($artifactPath, RegistryRefreshPolicy::PrecompiledOnly, $source, 'release-1');
            self::fail('Production refresh policy accepted a discovery source.');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringContainsString('must not scan', $failure->getMessage());
        }

        $this->remove($this->directory . '/src');
        $loaded = $loader->load($artifactPath, applicationBuildId: 'release-1');
        self::assertSame($compiled->fingerprint, $loaded->fingerprint);
    }

    public function testConcurrentRefreshProcessesProduceOneVerifiedArtifact(): void
    {
        $namespace = 'ConcurrentRefreshFixture' . bin2hex(random_bytes(5));
        $this->write('src/Ping.php', $this->queryAndHandler($namespace, 'Ping'));
        $composer = $this->write('composer.json', json_encode([
            'autoload' => ['classmap' => ['src/']],
        ], JSON_THROW_ON_ERROR));
        $artifactPath = $this->directory . '/var/registry.json';
        $root = \dirname(__DIR__);
        $code = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $source = \Wolfcharaa\MessageBus\Registry\ComposerDirectoryClassSource::fromComposerJson($argv[2]);
            $registry = (new \Wolfcharaa\MessageBus\Registry\RegistryArtifactLoader())->load(
                $argv[3],
                \Wolfcharaa\MessageBus\Registry\RegistryRefreshPolicy::WhenStale,
                $source,
                'concurrent-build',
            );
            echo $registry->fingerprint;
            PHP;
        $processes = [];
        for ($index = 0; $index < 2; ++$index) {
            $process = proc_open(
                [PHP_BINARY, '-r', $code, $root, $composer, $artifactPath],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        $fingerprints = [];
        foreach ($processes as [$childProcess, $childPipes]) {
            $fingerprints[] = stream_get_contents($childPipes[1]);
            $stderr = stream_get_contents($childPipes[2]);
            fclose($childPipes[1]);
            fclose($childPipes[2]);
            self::assertSame(0, proc_close($childProcess), $stderr);
        }

        self::assertNotSame('', $fingerprints[0]);
        self::assertSame($fingerprints[0], $fingerprints[1]);
        require_once $this->directory . '/src/Ping.php';
        self::assertSame(
            $fingerprints[0],
            (new RegistryArtifactLoader())->load($artifactPath, applicationBuildId: 'concurrent-build')->fingerprint,
        );
        self::assertIsArray(json_decode(
            (string) file_get_contents($artifactPath . '.meta.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
    }

    private function queryAndHandler(string $namespace, string $name): string
    {
        return '<?php namespace ' . $namespace . '; '
            . 'final readonly class ' . $name . ' implements \Wolfcharaa\MessageBus\Contract\Query {} '
            . 'final readonly class ' . $name . 'Handler { '
            . '#[\Wolfcharaa\MessageBus\Attribute\Handler] '
            . 'public function __invoke(' . $name . ' $message): string { return $message::class; } }';
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->directory . '/' . $relative;
        file_put_contents($path, $contents);

        return str_replace('\\', '/', $path);
    }

    private function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $entry) {
                $this->remove($entry instanceof \SplFileInfo ? $entry->getPathname() : $entry);
            }
            rmdir($path);

            return;
        }
        unlink($path);
    }
}

final class CountingRefreshableSource implements RefreshableRegistryClassDiscoverySource
{
    public int $discoverCalls = 0;

    public int $resourceCalls = 0;

    public function __construct(private readonly ComposerDirectoryClassSource $source) {}

    public function classes(): iterable
    {
        foreach ($this->discover()->candidates as $candidate) {
            yield $candidate->class;
        }
    }

    public function discover(): RegistryClassDiscoveryResult
    {
        ++$this->discoverCalls;

        return $this->source->discover();
    }

    public function resources(): RegistryClassDiscoveryResources
    {
        ++$this->resourceCalls;

        return $this->source->resources();
    }
}
