<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Contract\Message;

/**
 *  Self-hashed portable compiled-registry artifact.
 *
 * @api tooling
 */
final readonly class RegistryArtifact
{
    private const int SCHEMA = 1;

    /** @param list<array<string, bool|int|string|null>> $bindings */
    public function __construct(
        public string $registryFingerprint,
        public array $bindings,
        public ?string $applicationBuildId,
        public string $contentHash,
    ) {
        if ($this->applicationBuildId !== null
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $this->applicationBuildId) !== 1) {
            throw new \InvalidArgumentException(
                'Registry artifact applicationBuildId must match [a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}.',
            );
        }
    }

    public static function fromRegistry(
        CompiledRegistry $registry,
        ?string $applicationBuildId = null,
    ): self {
        $bindings = [];
        foreach ($registry->bindings() as $messageClass => $messageBindings) {
            foreach ($messageBindings as $binding) {
                $bindings[] = [
                    'id' => $binding->id,
                    'message_class' => $messageClass,
                    'role' => $binding->role->value,
                    'handler_class' => $binding->handlerClass,
                    'handler_method' => $binding->method,
                    'context_class' => $binding->contextClass,
                    'subscription_id' => $binding->subscriptionId,
                ];
            }
        }
        usort($bindings, static fn(array $a, array $b): int => [
            $a['message_class'], $a['id'],
        ] <=> [
            $b['message_class'], $b['id'],
        ]);
        $hash = hash('sha256', self::canonical($registry->fingerprint, $bindings, $applicationBuildId));

        return new self($registry->fingerprint, $bindings, $applicationBuildId, $hash);
    }

    public function toJson(): string
    {
        return json_encode([
            'schema' => self::SCHEMA,
            'registry_fingerprint' => $this->registryFingerprint,
            'bindings' => $this->bindings,
            'application_build_id' => $this->applicationBuildId,
            'content_hash' => $this->contentHash,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new \UnexpectedValueException('Compiled registry artifact has an unsupported shape.');
        }
        $data = self::object($decoded);
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw new \UnexpectedValueException('Compiled registry artifact has an unsupported schema.');
        }
        $artifact = new self(
            self::string($data, 'registry_fingerprint'),
            self::bindings($data),
            self::nullableString($data, 'application_build_id'),
            self::string($data, 'content_hash'),
        );
        $expected = hash('sha256', self::canonical(
            $artifact->registryFingerprint,
            $artifact->bindings,
            $artifact->applicationBuildId,
        ));
        if (!hash_equals($expected, $artifact->contentHash)) {
            throw new \UnexpectedValueException('Compiled registry artifact hash does not match its content.');
        }

        return $artifact;
    }

    public function registry(): CompiledRegistry
    {
        $bindings = [];
        foreach ($this->bindings as $row) {
            foreach ([
                'id', 'message_class', 'role', 'handler_class', 'handler_method',
            ] as $required) {
                if (!\is_string($row[$required] ?? null)) {
                    throw new \UnexpectedValueException('Compiled registry binding is incomplete.');
                }
            }
            $messageClass = self::string($row, 'message_class');
            $handlerClass = self::string($row, 'handler_class');
            $contextClass = self::nullableString($row, 'context_class');
            if (!class_exists($messageClass) || !is_a($messageClass, Message::class, true)
                || !class_exists($handlerClass)
                || ($contextClass !== null && !is_a($contextClass, MessageContext::class, true))) {
                throw new \UnexpectedValueException('Compiled registry artifact references an unavailable symbol.');
            }
            $bindings[$messageClass][] = new HandlerBinding(
                self::string($row, 'id'),
                $messageClass,
                MessageRole::from(self::string($row, 'role')),
                $handlerClass,
                self::string($row, 'handler_method'),
                $contextClass,
                self::nullableString($row, 'subscription_id'),
            );
        }

        return new CompiledRegistry($bindings, $this->registryFingerprint);
    }

    public function assertApplicationBuildId(string $expected): void
    {
        if ($this->applicationBuildId === null || !hash_equals($this->applicationBuildId, $expected)) {
            throw new \UnexpectedValueException('Compiled registry artifact application build ID does not match.');
        }
    }

    /** @param list<array<string, bool|int|string|null>> $bindings */
    private static function canonical(
        string $fingerprint,
        array $bindings,
        ?string $applicationBuildId,
    ): string {
        return json_encode([
            'schema' => self::SCHEMA,
            'registry_fingerprint' => $fingerprint,
            'bindings' => $bindings,
            'application_build_id' => $applicationBuildId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Compiled registry field must be a string: ' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw new \UnexpectedValueException('Compiled registry field must be string|null: ' . $key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, bool|int|string|null>>
     */
    private static function bindings(array $data): array
    {
        $value = $data['bindings'] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Compiled registry bindings must be a list.');
        }
        $bindings = [];
        foreach ($value as $row) {
            if (!\is_array($row) || array_is_list($row)) {
                throw new \UnexpectedValueException('Compiled registry binding must be an object.');
            }
            $binding = [];
            foreach ($row as $key => $field) {
                if (!\is_string($key)
                    || (!\is_bool($field) && !\is_int($field) && !\is_string($field) && $field !== null)) {
                    throw new \UnexpectedValueException('Compiled registry binding contains a non-scalar field.');
                }
                $binding[$key] = $field;
            }
            $bindings[] = $binding;
        }

        return $bindings;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function object(array $value): array
    {
        $object = [];
        foreach ($value as $key => $field) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('Compiled registry object has a non-string key.');
            }
            $object[$key] = $field;
        }

        return $object;
    }
}

/**
 *  Public tooling root for atomic artifact writes and verified loads.
 *
 * @api tooling
 */
final readonly class RegistryArtifactStore
{
    public function write(RegistryArtifact $artifact, string $path): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('Registry artifact directory is not writable: ' . $directory);
        }
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $artifact->toJson(), LOCK_EX) === false
            || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException('Unable to atomically write registry artifact.');
        }
    }

    public function load(string $path, ?string $expectedApplicationBuildId = null): RegistryArtifact
    {
        $json = file_get_contents($path);
        if (!\is_string($json)) {
            throw new \RuntimeException('Unable to read compiled registry artifact: ' . $path);
        }

        $artifact = RegistryArtifact::fromJson($json);
        if ($expectedApplicationBuildId !== null) {
            $artifact->assertApplicationBuildId($expectedApplicationBuildId);
        }

        return $artifact;
    }
}

/**
 * Controls whether application bootstrap may rebuild a registry artifact.
 *
 * @api tooling
 */
enum RegistryRefreshPolicy: string
{
    /** Load the committed/deployed artifact without touching discovery inputs. */
    case PrecompiledOnly = 'precompiled_only';

    /** Recompile under a process lock when discovery inputs changed. */
    case WhenStale = 'when_stale';
}

/**
 * Loads production artifacts and performs opt-in development refreshes.
 *
 * Refresh checks happen at application bootstrap, never during dispatch. A
 * failed rebuild is returned as its original exception chain; an older
 * artifact is not used as a silent fallback.
 *
 * @api tooling
 */
final readonly class RegistryArtifactLoader
{
    private const int META_SCHEMA = 1;

    public function __construct(
        private RegistryCompiler $compiler = new RegistryCompiler(),
        private RegistryArtifactStore $store = new RegistryArtifactStore(),
    ) {}

    public function load(
        string $artifactPath,
        RegistryRefreshPolicy $policy = RegistryRefreshPolicy::PrecompiledOnly,
        ?RefreshableRegistryClassDiscoverySource $source = null,
        ?string $applicationBuildId = null,
    ): CompiledRegistry {
        if ($policy === RegistryRefreshPolicy::PrecompiledOnly) {
            if ($source instanceof RefreshableRegistryClassDiscoverySource) {
                throw new \InvalidArgumentException(
                    'PrecompiledOnly does not accept a discovery source; production loading must not scan source files.',
                );
            }

            return $this->store->load($artifactPath, $applicationBuildId)->registry();
        }
        if (!$source instanceof RefreshableRegistryClassDiscoverySource) {
            throw new \InvalidArgumentException('WhenStale requires a refreshable registry class discovery source.');
        }

        $resources = $source->resources();
        $resourceFingerprint = $this->resourceFingerprint($resources);
        $fresh = $this->loadFresh($artifactPath, $resources, $resourceFingerprint, $applicationBuildId);
        if ($fresh instanceof CompiledRegistry) {
            return $fresh;
        }

        $lockPath = $artifactPath . '.lock';
        $lock = @fopen($lockPath, 'c+');
        if (!\is_resource($lock)) {
            throw new \RuntimeException('Unable to open registry refresh lock: ' . $lockPath);
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire registry refresh lock: ' . $lockPath);
            }

            $resources = $source->resources();
            $resourceFingerprint = $this->resourceFingerprint($resources);
            $fresh = $this->loadFresh($artifactPath, $resources, $resourceFingerprint, $applicationBuildId);
            if ($fresh instanceof CompiledRegistry) {
                return $fresh;
            }

            $registry = $this->compiler->compile($source)->requireRegistry();
            $artifact = RegistryArtifact::fromRegistry($registry, $applicationBuildId);
            $this->store->write($artifact, $artifactPath);
            $this->writeMeta($artifactPath . '.meta.json', [
                'schema' => self::META_SCHEMA,
                'artifact_content_hash' => $artifact->contentHash,
                'configuration_fingerprint' => $resources->configurationFingerprint,
                'resource_fingerprint' => $resourceFingerprint,
                'application_build_id' => $applicationBuildId,
            ]);

            return $registry;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function loadFresh(
        string $artifactPath,
        RegistryClassDiscoveryResources $resources,
        string $resourceFingerprint,
        ?string $applicationBuildId,
    ): ?CompiledRegistry {
        $metaPath = $artifactPath . '.meta.json';
        if (!is_file($artifactPath) || !is_file($metaPath)) {
            return null;
        }

        try {
            $metaJson = file_get_contents($metaPath);
            if (!\is_string($metaJson)) {
                return null;
            }
            $meta = json_decode($metaJson, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($meta) || array_is_list($meta)
                || ($meta['schema'] ?? null) !== self::META_SCHEMA
                || ($meta['configuration_fingerprint'] ?? null) !== $resources->configurationFingerprint
                || ($meta['resource_fingerprint'] ?? null) !== $resourceFingerprint
                || ($meta['application_build_id'] ?? null) !== $applicationBuildId) {
                return null;
            }
            $artifact = $this->store->load($artifactPath, $applicationBuildId);
            if (($meta['artifact_content_hash'] ?? null) !== $artifact->contentHash) {
                return null;
            }

            return $artifact->registry();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resourceFingerprint(RegistryClassDiscoveryResources $resources): string
    {
        $entries = [];
        foreach ($resources->files as $file) {
            $entries[] = $this->fileEntry($file);
        }
        foreach ($resources->directories as $directory) {
            if (!is_dir($directory)) {
                $entries[] = ['missing_directory', $directory];

                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iterator as $file) {
                    if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                        continue;
                    }
                    if (!\in_array(strtolower($file->getExtension()), ['php', 'inc'], true)) {
                        continue;
                    }
                    $entries[] = $this->fileEntry($file->getPathname());
                }
            } catch (\Throwable $failure) {
                $entries[] = ['unreadable_directory', $directory, $failure::class];
            }
        }
        sort($entries, SORT_REGULAR);

        return hash('sha256', json_encode([
            'configuration' => $resources->configurationFingerprint,
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{string, string, string|null} */
    private function fileEntry(string $path): array
    {
        if (!is_file($path)) {
            return ['missing_file', $path, null];
        }
        $hash = @hash_file('sha256', $path);

        return ['file', str_replace('\\', '/', $path), $hash === false ? null : $hash];
    }

    /** @param array<string, int|string|null> $meta */
    private function writeMeta(string $path, array $meta): void
    {
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        $json = json_encode($meta, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException('Unable to atomically write registry refresh metadata: ' . $path);
        }
    }
}
