<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use Composer\ClassMapGenerator\ClassMapGenerator;
use Composer\InstalledVersions;
use Wolfcharaa\MessageBus\Internal\Registry\RegistryDiagnosticFactory;

/**
 * One declaration discovered in a concrete source file.
 *
 * @api tooling
 */
final readonly class RegistryClassCandidate
{
    /** @param class-string $class */
    public function __construct(
        public string $class,
        public string $sourcePath,
    ) {}
}

/**
 * Rich discovery outcome consumed by the registry compiler.
 *
 * @api tooling
 */
final readonly class RegistryClassDiscoveryResult
{
    /**
     * @param list<RegistryClassCandidate> $candidates
     * @param list<RegistryDiagnostic> $diagnostics
     */
    public function __construct(
        public array $candidates,
        public array $diagnostics = [],
        public ?\Throwable $primaryCause = null,
    ) {}
}

/**
 * A class source which preserves source paths and discovery failures.
 *
 * @api spi
 */
interface RegistryClassDiscoverySource extends RegistryClassSource
{
    public function discover(): RegistryClassDiscoveryResult;
}

/**
 * Files and directories whose state determines whether an artifact is stale.
 *
 * @api tooling
 */
final readonly class RegistryClassDiscoveryResources
{
    /**
     * @param list<string> $files
     * @param list<string> $directories
     */
    public function __construct(
        public string $configurationFingerprint,
        public array $files,
        public array $directories,
    ) {}
}

/**
 * Rich source which can describe the inputs used by development refreshes.
 *
 * @api spi
 */
interface RefreshableRegistryClassDiscoverySource extends RegistryClassDiscoverySource
{
    public function resources(): RegistryClassDiscoveryResources;
}

/**
 * Discovers production classes in explicit roots or a root composer.json.
 *
 * The optional `composer/class-map-generator` parser is deliberately a dev
 * dependency. Production can load a precompiled registry artifact without it.
 *
 * @api tooling
 */
final class ComposerDirectoryClassSource implements RefreshableRegistryClassDiscoverySource
{
    /** @var list<string> */
    private readonly array $directories;

    /** @var list<string> */
    private readonly array $namespacePrefixes;

    /** @var list<string> */
    private readonly array $excludedDirectories;

    private ?string $composerJsonPath = null;

    /**
     * @param list<string> $directories
     * @param list<string> $namespacePrefixes
     * @param list<string> $excludedDirectories
     */
    public function __construct(
        array $directories,
        array $namespacePrefixes,
        array $excludedDirectories = [],
    ) {
        if ($directories === [] || $namespacePrefixes === []) {
            throw new \InvalidArgumentException(
                'Composer directory source requires directories and namespace prefixes.',
            );
        }
        foreach (['directory' => $directories, 'excluded directory' => $excludedDirectories] as $kind => $values) {
            foreach ($values as $value) {
                if (!\is_string($value) || $value === '') {
                    throw new \InvalidArgumentException(
                        'Every Composer directory source ' . $kind . ' must be a non-empty string.',
                    );
                }
            }
        }
        foreach ($namespacePrefixes as $prefix) {
            if (!\is_string($prefix)) {
                throw new \InvalidArgumentException(
                    'Every Composer directory source namespace prefix must be a string; use an empty string to allow all namespaces.',
                );
            }
        }

        $this->directories = array_values($directories);
        $this->namespacePrefixes = array_values($namespacePrefixes);
        $this->excludedDirectories = array_values($excludedDirectories);
    }

    /**
     * Uses only root production `autoload.psr-4`, `autoload.classmap`, and
     * `exclude-from-classmap`. `autoload-dev` and `files` are not discovery roots.
     */
    public static function fromComposerJson(string $composerJsonPath): self
    {
        if ($composerJsonPath === '') {
            throw new \InvalidArgumentException('composer.json path must not be empty.');
        }

        $source = new self([$composerJsonPath], ['']);
        $source->composerJsonPath = self::absolutePath($composerJsonPath);

        return $source;
    }

    public function classes(): iterable
    {
        foreach ($this->discover()->candidates as $candidate) {
            yield $candidate->class;
        }
    }

    public function discover(): RegistryClassDiscoveryResult
    {
        $dependencyFailure = $this->dependencyDiagnostic();
        if ($dependencyFailure instanceof RegistryDiagnostic) {
            return new RegistryClassDiscoveryResult([], [$dependencyFailure]);
        }

        [$plan, $diagnostics, $failure] = $this->plan();
        if ($plan === null) {
            return new RegistryClassDiscoveryResult([], $diagnostics, $failure);
        }

        try {
            $files = $this->phpFiles($plan['roots'], $plan['exclude_patterns'], $plan['excluded_directories']);
            $generator = new ClassMapGenerator(['php', 'inc']);
            $generator->scanPaths(array_map(
                static fn(string $file): \SplFileInfo => new \SplFileInfo($file),
                $files,
            ));
            $classMap = $generator->getClassMap();
        } catch (\Throwable $scanFailure) {
            $diagnostics[] = RegistryDiagnosticFactory::error(
                'MBR024',
                'The class scanner or PHP declaration parser failed.',
                implode(', ', $plan['roots']),
                'every configured discovery root can be traversed and every PHP declaration can be parsed',
                'scanner threw ' . $scanFailure::class . ': ' . $scanFailure->getMessage(),
                $scanFailure::class,
                subjectKind: 'discovery_roots',
            );

            return new RegistryClassDiscoveryResult([], $diagnostics, $scanFailure);
        }

        $duplicates = $classMap->getAmbiguousClasses(false);
        $map = $classMap->getMap();
        ksort($map, SORT_STRING);
        foreach ($duplicates as $class => $duplicatePaths) {
            $paths = array_values(array_unique(array_merge([$map[$class]], $duplicatePaths)));
            sort($paths, SORT_STRING);
            $diagnostics[] = RegistryDiagnosticFactory::error(
                'MBR025',
                'The same PHP declaration exists in more than one discovered file.',
                $class,
                'exactly one source file declares this symbol',
                'declaration paths: ' . implode(', ', $paths),
                sourcePath: $paths[0],
                subjectKind: 'class',
            );
        }

        foreach ($this->caseCollisions($files) as $paths) {
            $diagnostics[] = RegistryDiagnosticFactory::error(
                'MBR027',
                'Discovered paths differ only by ASCII letter case.',
                implode(' <-> ', $paths),
                'every discovered path prefix has one portable spelling',
                'case-colliding paths: ' . implode(', ', $paths),
                sourcePath: $paths[0],
                subjectKind: 'source_paths',
            );
        }

        $candidates = [];
        foreach ($map as $class => $sourcePath) {
            if (isset($duplicates[$class])) {
                continue;
            }
            if (!$this->allowedPrefix($class, $plan['namespace_prefixes'])) {
                continue;
            }
            $expectedPath = $this->expectedPsrPath($class, $sourcePath, $plan['psr4'], $plan['classmap_roots']);
            if ($expectedPath !== true) {
                $diagnostics[] = RegistryDiagnosticFactory::error(
                    'MBR026',
                    'A declaration cannot be loaded through its configured production PSR-4 mapping.',
                    $class,
                    $expectedPath,
                    'declaration found at ' . $sourcePath,
                    sourcePath: $sourcePath,
                    subjectKind: 'class',
                );

                continue;
            }
            $candidates[] = new RegistryClassCandidate($class, self::normalizePath($sourcePath));
        }
        usort($candidates, static fn(RegistryClassCandidate $a, RegistryClassCandidate $b): int => [
            $a->class, $a->sourcePath,
        ] <=> [
            $b->class, $b->sourcePath,
        ]);

        if ($candidates === []) {
            $diagnostics[] = RegistryDiagnosticFactory::warning(
                'MBR028',
                'The configured discovery roots contain no eligible PHP declarations.',
                implode(', ', $plan['roots']),
                'at least one declaration under an allowed namespace prefix',
                '0 eligible declarations were discovered',
                subjectKind: 'discovery_roots',
            );
        }

        return new RegistryClassDiscoveryResult($candidates, $diagnostics);
    }

    public function resources(): RegistryClassDiscoveryResources
    {
        [$plan] = $this->plan();
        if ($plan === null) {
            $files = $this->composerJsonPath === null ? [] : [$this->composerJsonPath];

            return new RegistryClassDiscoveryResources(
                hash('sha256', json_encode([$this->composerJsonPath, $this->directories, $this->namespacePrefixes], JSON_THROW_ON_ERROR)),
                $files,
                [],
            );
        }

        return new RegistryClassDiscoveryResources(
            hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)),
            $this->composerJsonPath === null ? [] : [$this->composerJsonPath],
            array_values(array_filter($plan['roots'], is_dir(...))),
        );
    }

    private function dependencyDiagnostic(): ?RegistryDiagnostic
    {
        $available = class_exists(ClassMapGenerator::class);
        $version = null;
        if ($available && class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('composer/class-map-generator')) {
            $version = InstalledVersions::getVersion('composer/class-map-generator');
        }
        $supported = $version === null
            ? $available
            : version_compare($version, '1.8.0.0', '>=') && version_compare($version, '2.0.0.0', '<');
        if ($available && $supported) {
            return null;
        }

        return RegistryDiagnosticFactory::error(
            'MBR021',
            'The optional class discovery dependency is missing or unsupported; install it with "composer require --dev composer/class-map-generator:^1.8" before compiling the registry.',
            'composer/class-map-generator',
            'installed version matching ^1.8 with ClassMapGenerator::scanPaths()',
            $available ? 'installed version is ' . ($version ?? 'unknown and incompatible') : 'package is not installed',
            subjectKind: 'dependency',
        );
    }

    /**
     * @return array{0: array{roots: list<string>, namespace_prefixes: list<string>, excluded_directories: list<string>, exclude_patterns: list<string>, psr4: list<array{prefix: string, root: string}>, classmap_roots: list<string>}|null, 1: list<RegistryDiagnostic>, 2: ?\Throwable}
     */
    private function plan(): array
    {
        if ($this->composerJsonPath === null) {
            $roots = array_map(self::absolutePath(...), $this->directories);
            $invalid = array_values(array_filter($roots, static fn(string $path): bool => !file_exists($path)));
            if ($invalid !== []) {
                $diagnostic = RegistryDiagnosticFactory::error(
                    'MBR023',
                    'An explicit discovery root does not exist.',
                    $invalid[0],
                    'every explicit discovery root is a readable file or directory',
                    'path does not exist',
                    sourcePath: $invalid[0],
                    subjectKind: 'autoload_mapping',
                );

                return [null, [$diagnostic], null];
            }

            return [[
                'roots' => $roots,
                'namespace_prefixes' => $this->namespacePrefixes,
                'excluded_directories' => array_map(self::absolutePath(...), $this->excludedDirectories),
                'exclude_patterns' => [],
                'psr4' => [],
                'classmap_roots' => $roots,
            ], [], null];
        }

        try {
            $json = @file_get_contents($this->composerJsonPath);
            if (!\is_string($json)) {
                throw new \RuntimeException('file is missing or unreadable');
            }
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($document) || array_is_list($document)) {
                throw new \UnexpectedValueException('root JSON value is not an object');
            }
        } catch (\Throwable $failure) {
            $diagnostic = RegistryDiagnosticFactory::error(
                'MBR022',
                'The root composer.json cannot be read or decoded.',
                $this->composerJsonPath,
                'a readable JSON object at the explicitly supplied composer.json path',
                'read/decode failed: ' . $failure->getMessage(),
                $failure::class,
                $this->composerJsonPath,
                'composer_json',
            );

            return [null, [$diagnostic], $failure];
        }

        $autoload = $document['autoload'] ?? null;
        if (!\is_array($autoload) || array_is_list($autoload)) {
            return $this->invalidMapping('autoload', 'production autoload must be a JSON object');
        }
        $base = \dirname($this->composerJsonPath);
        $roots = [];
        $psr4 = [];
        $prefixes = [];
        $psrDocument = $autoload['psr-4'] ?? [];
        if (!\is_array($psrDocument) || ($psrDocument !== [] && array_is_list($psrDocument))) {
            return $this->invalidMapping('autoload.psr-4', 'mapping must be a JSON object');
        }
        foreach ($psrDocument as $prefix => $paths) {
            if (!\is_string($prefix) || (!\is_string($paths) && !\is_array($paths))) {
                return $this->invalidMapping('autoload.psr-4', 'every prefix maps to a string or list of strings');
            }
            if ($prefix !== '' && !str_ends_with($prefix, '\\')) {
                return $this->invalidMapping(
                    'autoload.psr-4.' . $prefix,
                    'a non-empty PSR-4 namespace prefix ending in a namespace separator',
                );
            }
            $pathList = \is_string($paths) ? [$paths] : $paths;
            foreach ($pathList as $path) {
                if (!\is_string($path) || $path === '') {
                    return $this->invalidMapping('autoload.psr-4.' . $prefix, 'every mapped path is a non-empty string');
                }
                $root = self::absolutePath($path, $base);
                if (!is_dir($root)) {
                    return $this->invalidMapping('autoload.psr-4.' . $prefix, 'mapped directory exists: ' . $root, $root);
                }
                $roots[] = $root;
                $prefixes[] = $prefix;
                $psr4[] = ['prefix' => $prefix, 'root' => $root];
            }
        }
        $classmapRoots = [];
        $classmapDocument = $autoload['classmap'] ?? [];
        if (!\is_array($classmapDocument) && !\is_string($classmapDocument)) {
            return $this->invalidMapping('autoload.classmap', 'mapping must be a string or list of strings');
        }
        foreach (\is_string($classmapDocument) ? [$classmapDocument] : $classmapDocument as $path) {
            if (!\is_string($path) || $path === '') {
                return $this->invalidMapping('autoload.classmap', 'every mapped path is a non-empty string');
            }
            $root = self::absolutePath($path, $base);
            if (!file_exists($root)) {
                return $this->invalidMapping('autoload.classmap', 'mapped path exists: ' . $root, $root);
            }
            $roots[] = $root;
            $classmapRoots[] = $root;
        }
        if ($roots === []) {
            return $this->invalidMapping('autoload', 'at least one production psr-4 or classmap root');
        }
        $excludeDocument = $autoload['exclude-from-classmap'] ?? [];
        if (!\is_array($excludeDocument) && !\is_string($excludeDocument)) {
            return $this->invalidMapping('autoload.exclude-from-classmap', 'value must be a string or list of strings');
        }
        $excludePatterns = [];
        foreach (\is_string($excludeDocument) ? [$excludeDocument] : $excludeDocument as $pattern) {
            if (!\is_string($pattern) || $pattern === '') {
                return $this->invalidMapping('autoload.exclude-from-classmap', 'every pattern is a non-empty string');
            }
            $excludePatterns[] = self::normalizePath($base . '/' . ltrim($pattern, '/'));
        }

        return [[
            'roots' => array_values(array_unique($roots)),
            'namespace_prefixes' => array_values(array_unique($prefixes === [] ? [''] : $prefixes)),
            'excluded_directories' => [],
            'exclude_patterns' => $excludePatterns,
            'psr4' => $psr4,
            'classmap_roots' => $classmapRoots,
        ], [], null];
    }

    /** @return array{null, list<RegistryDiagnostic>, null} */
    private function invalidMapping(string $symbol, string $expected, ?string $sourcePath = null): array
    {
        return [null, [RegistryDiagnosticFactory::error(
            'MBR023',
            'The root production autoload mapping is missing or invalid.',
            $symbol,
            $expected,
            'composer.json production autoload mapping cannot be scanned',
            sourcePath: $sourcePath ?? $this->composerJsonPath,
            subjectKind: 'autoload_mapping',
        )], null];
    }

    /**
     * @param list<string> $roots
     * @param list<string> $excludePatterns
     * @param list<string> $excludedDirectories
     * @return list<string>
     */
    private function phpFiles(array $roots, array $excludePatterns, array $excludedDirectories): array
    {
        $files = [];
        foreach ($roots as $root) {
            if (is_file($root)) {
                if ($this->isPhpFile($root) && !$this->excluded($root, $excludePatterns, $excludedDirectories)) {
                    $files[] = self::normalizePath((string) realpath($root));
                }

                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                    continue;
                }
                $path = $file->getPathname();
                if ($this->isPhpFile($path) && !$this->excluded($path, $excludePatterns, $excludedDirectories)) {
                    $files[] = self::normalizePath((string) $file->getRealPath());
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    private function isPhpFile(string $path): bool
    {
        return \in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'inc'], true);
    }

    /**
     * @param list<string> $patterns
     * @param list<string> $directories
     */
    private function excluded(string $path, array $patterns, array $directories): bool
    {
        $path = self::normalizePath($path);
        foreach ($directories as $directory) {
            if ($this->pathWithin($path, self::absolutePath($directory))) {
                return true;
            }
        }
        foreach ($patterns as $pattern) {
            $quoted = preg_quote(rtrim($pattern, '/'), '{');
            $quoted = str_replace(['\*\*', '\*'], ['.+?', '[^/]+?'], $quoted);
            if (preg_match('{^' . $quoted . '($|/)}', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $prefixes */
    private function allowedPrefix(string $class, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix === '' || str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{prefix: string, root: string}> $psr4
     * @param list<string> $classmapRoots
     */
    private function expectedPsrPath(string $class, string $path, array $psr4, array $classmapRoots): true|string
    {
        $path = self::normalizePath($path);
        foreach ($classmapRoots as $root) {
            if ($this->pathWithin($path, $root)) {
                return true;
            }
        }
        $expectations = [];
        foreach ($psr4 as $mapping) {
            if (!str_starts_with($class, $mapping['prefix'])) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($class, \strlen($mapping['prefix']))) . '.php';
            $expected = self::normalizePath(rtrim($mapping['root'], '/') . '/' . $relative);
            $expectations[] = $expected;
            if ($path === $expected) {
                return true;
            }
        }

        return $expectations === []
            ? 'a class name covered by a configured production PSR-4 prefix'
            : 'declaration path equals one of: ' . implode(', ', $expectations);
    }

    /**
     * @param list<string> $paths
     * @return list<non-empty-list<string>>
     */
    private function caseCollisions(array $paths): array
    {
        $spellings = [];
        foreach (array_values(array_unique($paths)) as $path) {
            $prefix = '';
            foreach (explode('/', self::normalizePath($path)) as $segment) {
                if ($segment === '') {
                    continue;
                }
                $prefix .= '/' . $segment;
                $spellings[strtolower($prefix)][$prefix] = true;
            }
        }
        $collisions = [];
        foreach ($spellings as $variants) {
            if (\count($variants) > 1) {
                $paths = array_keys($variants);
                sort($paths, SORT_STRING);
                $collisions[] = $paths;
            }
        }

        return $collisions;
    }

    private function pathWithin(string $path, string $root): bool
    {
        $path = self::normalizePath($path);
        $root = rtrim(self::normalizePath($root), '/');

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function absolutePath(string $path, ?string $base = null): string
    {
        if (!str_starts_with($path, '/')) {
            $path = ($base ?? (string) getcwd()) . '/' . $path;
        }
        $real = realpath($path);

        return self::normalizePath($real === false ? $path : $real);
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
