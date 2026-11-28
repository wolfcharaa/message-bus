<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Exception\InvalidMessageBusConfiguration;
use Wolfcharaa\MessageBus\Registry\ComposerDirectoryClassSource;
use Wolfcharaa\MessageBus\Registry\DiagnosticSeverity;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;

final class ComposerDirectoryClassSourceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/message-bus-discovery-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    /** @return list<string> */
    public static function diagnosticCodes(): array
    {
        return ['MBR021', 'MBR022', 'MBR023', 'MBR024', 'MBR025', 'MBR026', 'MBR027', 'MBR028'];
    }

    public function testPublicConfigurationRejectsIncompleteOrAmbiguousValuesWithExactMessages(): void
    {
        $reflection = new \ReflectionClass(ComposerDirectoryClassSource::class);
        $invalidCases = [
            'missing roots' => [
                static fn(): object => $reflection->newInstance([], ['Application\\']),
                'Composer directory source requires directories and namespace prefixes.',
            ],
            'empty root' => [
                static fn(): object => $reflection->newInstance([''], ['Application\\']),
                'Every Composer directory source directory must be a non-empty string.',
            ],
            'empty excluded root' => [
                static fn(): object => $reflection->newInstance(['/application/src'], ['Application\\'], ['']),
                'Every Composer directory source excluded directory must be a non-empty string.',
            ],
            'non-string namespace prefix' => [
                static fn(): object => $reflection->newInstance(['/application/src'], [42]),
                'Every Composer directory source namespace prefix must be a string; use an empty string to allow all namespaces.',
            ],
            'empty composer path' => [
                static fn(): object => ComposerDirectoryClassSource::fromComposerJson(''),
                'composer.json path must not be empty.',
            ],
        ];

        foreach ($invalidCases as $case => [$operation, $message]) {
            try {
                $operation();
                self::fail('Invalid discovery configuration was accepted: ' . $case);
            } catch (\InvalidArgumentException $failure) {
                self::assertSame($message, $failure->getMessage(), $case);
            }
        }
    }

    public function testComposerProductionMappingsDiscoverAndCompileHandlersWithoutDevFilesOrExcludedTrees(): void
    {
        $namespace = $this->namespace();
        $this->write('src/Ping.php', '<?php namespace ' . $namespace . '; final readonly class Ping implements \Wolfcharaa\MessageBus\Contract\Query {}');
        $this->write('src/PingHandler.php', '<?php namespace ' . $namespace . '; final readonly class PingHandler { #[\Wolfcharaa\MessageBus\Attribute\Handler] public function __invoke(Ping $message): string { return $message::class; } }');
        $this->write('src/Excluded/Hidden.php', '<?php namespace ' . $namespace . '\Excluded; final class Hidden {}');
        $this->write('dev/DevOnly.php', '<?php namespace ' . $namespace . '; final class DevOnly {}');
        $this->write('boot.php', '<?php namespace ' . $namespace . '; final class FilesOnly {}');
        $composer = $this->composer([
            'psr-4' => [$namespace . '\\' => 'src/'],
            'exclude-from-classmap' => ['/src/Excluded/**'],
            'files' => ['boot.php'],
        ], ['psr-4' => [$namespace . '\Dev\\' => 'dev/']]);

        $source = ComposerDirectoryClassSource::fromComposerJson($composer);
        $discovery = $source->discover();

        self::assertSame([
            $namespace . '\Ping',
            $namespace . '\PingHandler',
        ], array_column($discovery->candidates, 'class'));
        self::assertSame([], $discovery->diagnostics);
        $registry = (new RegistryCompiler())->compile($source)->requireRegistry();
        self::assertCount(1, $registry->bindings()[$namespace . '\Ping']);
    }

    public function testMBR021ReportsMissingOptionalDependencyInAnIsolatedRuntime(): void
    {
        $root = \dirname(__DIR__);
        $code = <<<'PHP'
            $loader = require $argv[1] . '/vendor/autoload.php';
            class_exists(\Wolfcharaa\MessageBus\Registry\ComposerDirectoryClassSource::class);
            class_exists(\Wolfcharaa\MessageBus\Internal\Registry\RegistryDiagnosticFactory::class);
            spl_autoload_unregister([$loader, 'loadClass']);
            $source = new \Wolfcharaa\MessageBus\Registry\ComposerDirectoryClassSource([$argv[1] . '/src'], ['Wolfcharaa\\MessageBus\\']);
            echo json_encode($source->discover()->diagnostics[0]->normalized(), JSON_THROW_ON_ERROR);
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, $root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        $diagnostic = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($diagnostic)) {
            self::fail('Isolated diagnostic output is not a JSON object.');
        }

        self::assertSame('MBR021', $diagnostic['code'] ?? null);
        self::assertSame('composer/class-map-generator', $diagnostic['symbol'] ?? null);
        self::assertSame('dependency', $diagnostic['subject_kind'] ?? null);
        $message = $diagnostic['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('composer require --dev', $message);
    }

    public function testMBR022PreservesComposerJsonDecodeFailureAndSourcePath(): void
    {
        $path = $this->write('composer.json', '{broken');
        $result = ComposerDirectoryClassSource::fromComposerJson($path)->discover();
        $diagnostic = $this->diagnostic($result->diagnostics, 'MBR022');

        self::assertSame($path, $diagnostic->sourcePath);
        self::assertSame(\JsonException::class, $diagnostic->causeClass);
        self::assertInstanceOf(\JsonException::class, $result->primaryCause);
    }

    public function testMBR022NamesAComposerFileThatDisappearedBeforeDiscovery(): void
    {
        $path = $this->write('composer.json', '{"autoload":{"classmap":["src/"]}}');
        $source = ComposerDirectoryClassSource::fromComposerJson($path);
        unlink($path);
        $result = $source->discover();
        $diagnostic = $this->diagnostic($result->diagnostics, 'MBR022');

        self::assertSame($path, $diagnostic->symbol);
        self::assertSame($path, $diagnostic->sourcePath);
        self::assertSame(\RuntimeException::class, $diagnostic->causeClass);
        self::assertSame('read/decode failed: file is missing or unreadable', $diagnostic->actual);
        self::assertInstanceOf(\RuntimeException::class, $result->primaryCause);
    }

    public function testMBR023IdentifiesTheInvalidProductionMapping(): void
    {
        $composer = $this->composer(['psr-4' => ['Broken\\' => 'missing/']]);
        $diagnostic = $this->diagnostic(
            ComposerDirectoryClassSource::fromComposerJson($composer)->discover()->diagnostics,
            'MBR023',
        );

        self::assertSame('autoload.psr-4.Broken\\', $diagnostic->symbol);
        self::assertStringContainsString('/missing', (string) $diagnostic->expected);
        self::assertSame('autoload_mapping', $diagnostic->subjectKind);
    }

    public function testMBR023IdentifiesEveryInvalidProductionMappingShape(): void
    {
        $cases = [
            'autoload is not an object' => [
                ['autoload' => 'src/'],
                'autoload',
                'production autoload must be a JSON object',
            ],
            'psr-4 is not an object' => [
                ['autoload' => ['psr-4' => ['src/']]],
                'autoload.psr-4',
                'mapping must be a JSON object',
            ],
            'psr-4 prefix has no separator' => [
                ['autoload' => ['psr-4' => ['Application' => 'src/']]],
                'autoload.psr-4.Application',
                'a non-empty PSR-4 namespace prefix ending in a namespace separator',
            ],
            'psr-4 value has an invalid type' => [
                ['autoload' => ['psr-4' => ['Application\\' => false]]],
                'autoload.psr-4',
                'every prefix maps to a string or list of strings',
            ],
            'psr-4 path is not a string' => [
                ['autoload' => ['psr-4' => ['Application\\' => [false]]]],
                'autoload.psr-4.Application\\',
                'every mapped path is a non-empty string',
            ],
            'classmap is not a string or list' => [
                ['autoload' => ['classmap' => false]],
                'autoload.classmap',
                'mapping must be a string or list of strings',
            ],
            'classmap entry is empty' => [
                ['autoload' => ['classmap' => ['']]],
                'autoload.classmap',
                'every mapped path is a non-empty string',
            ],
            'classmap path is missing' => [
                ['autoload' => ['classmap' => ['missing/']]],
                'autoload.classmap',
                'mapped path exists: ' . $this->directory . '/missing/',
            ],
            'production roots are absent' => [
                ['autoload' => ['files' => ['bootstrap.php']]],
                'autoload',
                'at least one production psr-4 or classmap root',
            ],
            'exclude mapping is not a string or list' => [
                ['autoload' => ['classmap' => ['src/'], 'exclude-from-classmap' => false]],
                'autoload.exclude-from-classmap',
                'value must be a string or list of strings',
            ],
            'exclude pattern is empty' => [
                ['autoload' => ['classmap' => ['src/'], 'exclude-from-classmap' => ['']]],
                'autoload.exclude-from-classmap',
                'every pattern is a non-empty string',
            ],
        ];
        mkdir($this->directory . '/src');

        foreach ($cases as $case => [$document, $symbol, $expected]) {
            $composer = $this->write(
                'composer-' . hash('sha256', $case) . '.json',
                json_encode($document, JSON_THROW_ON_ERROR),
            );
            $diagnostic = $this->diagnostic(
                ComposerDirectoryClassSource::fromComposerJson($composer)->discover()->diagnostics,
                'MBR023',
            );

            self::assertSame($symbol, $diagnostic->symbol, $case);
            self::assertSame($expected, $diagnostic->expected, $case);
            self::assertSame(
                $case === 'classmap path is missing' ? $this->directory . '/missing/' : $composer,
                $diagnostic->sourcePath,
                $case,
            );
        }
    }

    public function testMBR023NamesAMissingExplicitDiscoveryRoot(): void
    {
        $missing = $this->directory . '/missing-root';
        $diagnostic = $this->diagnostic(
            (new ComposerDirectoryClassSource([$missing], ['Application\\']))->discover()->diagnostics,
            'MBR023',
        );

        self::assertSame($missing, $diagnostic->symbol);
        self::assertSame($missing, $diagnostic->sourcePath);
        self::assertSame('path does not exist', $diagnostic->actual);
    }

    public function testMBR024PreservesScannerFailureAsCompilerPreviousException(): void
    {
        $sourceFile = $this->write('src/Unreadable.php', '<?php final class Unreadable {}');
        chmod($sourceFile, 0o000);
        $source = new ComposerDirectoryClassSource([$this->directory . '/src'], ['']);

        try {
            $result = (new RegistryCompiler())->compile($source);
            $diagnostic = $this->diagnostic($result->diagnostics, 'MBR024');
            self::assertSame(\RuntimeException::class, $diagnostic->causeClass);
            self::assertInstanceOf(\RuntimeException::class, $result->primaryCause);

            try {
                $result->requireRegistry();
                self::fail('Scanner failure did not fail registry compilation.');
            } catch (InvalidMessageBusConfiguration $failure) {
                self::assertSame($result->primaryCause, $failure->getPrevious());
            }
        } finally {
            chmod($sourceFile, 0o600);
        }
    }

    public function testMBR025ListsEveryFileDeclaringTheDuplicateClass(): void
    {
        $namespace = $this->namespace();
        $first = $this->write('src/First.php', '<?php namespace ' . $namespace . '; final class Duplicate {}');
        $second = $this->write('src/Second.php', '<?php namespace ' . $namespace . '; final class Duplicate {}');
        $composer = $this->composer(['classmap' => ['src/']]);
        $diagnostic = $this->diagnostic(
            ComposerDirectoryClassSource::fromComposerJson($composer)->discover()->diagnostics,
            'MBR025',
        );

        self::assertSame($namespace . '\Duplicate', $diagnostic->symbol);
        self::assertStringContainsString($first, (string) $diagnostic->actual);
        self::assertStringContainsString($second, (string) $diagnostic->actual);
    }

    public function testMBR026NamesTheDeclarationAndExpectedPsr4File(): void
    {
        $namespace = $this->namespace();
        $actual = $this->write('src/Wrong.php', '<?php namespace ' . $namespace . '; final class Expected {}');
        $composer = $this->composer(['psr-4' => [$namespace . '\\' => 'src/']]);
        $diagnostic = $this->diagnostic(
            ComposerDirectoryClassSource::fromComposerJson($composer)->discover()->diagnostics,
            'MBR026',
        );

        self::assertSame($namespace . '\Expected', $diagnostic->symbol);
        self::assertSame($actual, $diagnostic->sourcePath);
        self::assertStringContainsString('/src/Expected.php', (string) $diagnostic->expected);
    }

    public function testMBR027ReportsCaseCollidingPathPrefixes(): void
    {
        $namespace = $this->namespace();
        $this->write('src/Foo/One.php', '<?php namespace ' . $namespace . '\Foo; final class One {}');
        $this->write('src/foo/Two.php', '<?php namespace ' . $namespace . '\foo; final class Two {}');
        $composer = $this->composer(['psr-4' => [$namespace . '\\' => 'src/']]);
        $diagnostic = $this->diagnostic(
            ComposerDirectoryClassSource::fromComposerJson($composer)->discover()->diagnostics,
            'MBR027',
        );

        self::assertStringContainsString('/src/Foo', (string) $diagnostic->actual);
        self::assertStringContainsString('/src/foo', (string) $diagnostic->actual);
        self::assertSame('source_paths', $diagnostic->subjectKind);
    }

    public function testMBR028IsAnActionableWarningForAnEmptyScan(): void
    {
        mkdir($this->directory . '/src');
        $composer = $this->composer(['psr-4' => ['Empty\\' => 'src/']]);
        $diagnostic = $this->diagnostic(
            ComposerDirectoryClassSource::fromComposerJson($composer)->discover()->diagnostics,
            'MBR028',
        );

        self::assertSame(DiagnosticSeverity::Warning, $diagnostic->severity);
        self::assertSame('0 eligible declarations were discovered', $diagnostic->actual);
        self::assertSame('discovery_roots', $diagnostic->subjectKind);
    }

    public function testMBR001PreservesAClassLoadingFailureFromTheApplication(): void
    {
        $namespace = $this->namespace();
        $path = $this->write('src/BrokenHandler.php', '<?php namespace ' . $namespace . '; final class BrokenHandler extends MissingDependency {}');
        $source = new ComposerDirectoryClassSource([$this->directory . '/src'], [$namespace . '\\']);
        $result = (new RegistryCompiler())->compile($source);
        $diagnostic = $this->diagnostic($result->diagnostics, 'MBR001');

        self::assertSame($path, $diagnostic->sourcePath);
        self::assertSame(\Error::class, $diagnostic->causeClass);
        self::assertStringContainsString('MissingDependency', (string) $diagnostic->actual);

        try {
            $result->requireRegistry();
            self::fail('Application class loading failure was swallowed.');
        } catch (InvalidMessageBusConfiguration $failure) {
            $previous = $failure->getPrevious();
            self::assertInstanceOf(\Error::class, $previous);
            self::assertStringContainsString('MissingDependency', $previous->getMessage());
        }
    }

    public function testHandlerDiagnosticsCarryTheExactDiscoveredSourcePath(): void
    {
        $namespace = $this->namespace();
        $path = $this->write(
            'src/InvalidHandler.php',
            '<?php namespace ' . $namespace . '; '
            . 'final readonly class BrokenQuery implements \Wolfcharaa\MessageBus\Contract\Query {} '
            . 'final class InvalidHandler { #[\Wolfcharaa\MessageBus\Attribute\Handler] '
            . 'public static function handle(BrokenQuery $query): string { return $query::class; } }',
        );
        $source = new ComposerDirectoryClassSource([$this->directory . '/src'], [$namespace . '\\']);
        $diagnostic = $this->diagnostic(
            (new RegistryCompiler())->compile($source)->diagnostics,
            'MBR002',
        );

        self::assertSame($path, $diagnostic->sourcePath);
        self::assertStringContainsString('Source path: ' . $path, $diagnostic->message);
    }

    public function testMessageWithoutHandlerRemainsVisibleAndPointsMBR014AtItsFile(): void
    {
        $namespace = $this->namespace();
        $path = $this->write(
            'src/Orphan.php',
            '<?php namespace ' . $namespace . '; final readonly class Orphan implements \Wolfcharaa\MessageBus\Contract\Query {}',
        );
        $source = new ComposerDirectoryClassSource([$this->directory . '/src'], [$namespace . '\\']);
        $diagnostic = $this->diagnostic(
            (new RegistryCompiler())->compile($source)->diagnostics,
            'MBR014',
        );

        self::assertSame($namespace . '\Orphan', $diagnostic->symbol);
        self::assertSame($path, $diagnostic->sourcePath);
        self::assertSame('0 compiled handler(s)', $diagnostic->actual);
    }

    public function testNestedSymlinkIsOutsideTheBoundaryButAnExplicitSymlinkRootIsAccepted(): void
    {
        $namespace = $this->namespace();
        mkdir($this->directory . '/root');
        $external = $this->write(
            'external/Visible.php',
            '<?php namespace ' . $namespace . '; final class Visible {}',
        );
        self::assertTrue(symlink($this->directory . '/external', $this->directory . '/root/nested'));

        $bounded = new ComposerDirectoryClassSource([$this->directory . '/root'], [$namespace . '\\']);
        self::assertSame([], $bounded->discover()->candidates);
        self::assertSame(['MBR028'], array_column($bounded->discover()->diagnostics, 'code'));

        self::assertTrue(symlink($this->directory . '/external', $this->directory . '/mapped-root'));
        $mapped = new ComposerDirectoryClassSource([$this->directory . '/mapped-root'], [$namespace . '\\']);
        self::assertSame([$namespace . '\Visible'], array_column($mapped->discover()->candidates, 'class'));
        self::assertSame($external, $mapped->discover()->candidates[0]->sourcePath);
    }

    /**
     * @param array<string, mixed> $autoload
     * @param array<string, mixed>|null $autoloadDev
     */
    private function composer(array $autoload, ?array $autoloadDev = null): string
    {
        $document = ['autoload' => $autoload];
        if ($autoloadDev !== null) {
            $document['autoload-dev'] = $autoloadDev;
        }

        return $this->write('composer.json', json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function namespace(): string
    {
        return 'DiscoveryFixture' . bin2hex(random_bytes(5));
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->directory . '/' . $relative;
        $parent = \dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0o777, true);
        }
        file_put_contents($path, $contents);

        return str_replace('\\', '/', $path);
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function diagnostic(array $diagnostics, string $code): RegistryDiagnostic
    {
        $matches = array_values(array_filter(
            $diagnostics,
            static fn(RegistryDiagnostic $diagnostic): bool => $diagnostic->code === $code,
        ));
        self::assertCount(1, $matches, 'Expected exactly one diagnostic ' . $code . '.');
        $diagnostic = $matches[0];
        self::assertNotNull($diagnostic->symbol);
        self::assertNotNull($diagnostic->expected);
        self::assertNotNull($diagnostic->actual);
        self::assertStringContainsString('[' . $code . ']', $diagnostic->message);
        self::assertStringContainsString((string) $diagnostic->symbol, $diagnostic->message);
        self::assertStringContainsString((string) $diagnostic->expected, $diagnostic->message);
        self::assertStringContainsString((string) $diagnostic->actual, $diagnostic->message);

        return $diagnostic;
    }

    private function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path)) {
            unlink($path);

            return;
        }
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path) as $entry) {
                $this->remove($entry instanceof \SplFileInfo ? $entry->getPathname() : $entry);
            }
            rmdir($path);

            return;
        }
        chmod($path, 0o600);
        unlink($path);
    }
}
