<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Adapter\Postgres;

use Wolfcharaa\MessageBus\Internal\Runtime\FailurePreservingCleanup;

/**
 *  One immutable package migration plus its verified artifact hash.
 *
 * @api adapter
 */
final readonly class SchemaMigration
{
    public function __construct(
        public string $component,
        public string $migrationId,
        public string $sql,
        public string $checksum,
    ) {}

    public function key(): string
    {
        return $this->component . ':' . $this->migrationId;
    }
}

/**
 *  Loads the ordered append-only packaged migration manifest fail-closed.
 *
 * @api adapter
 */
final readonly class SchemaManifest
{
    private string $resources;

    public function __construct(?string $resources = null)
    {
        $this->resources = $resources ?? __DIR__ . '/../resources';
    }

    /** @return list<SchemaMigration> */
    public function migrations(): array
    {
        $manifestPath = $this->resources . '/schema-manifest.json';
        $json = file_get_contents($manifestPath);
        if (!\is_string($json)) {
            throw new \RuntimeException('Packaged MessageBus schema manifest is missing.');
        }
        $manifest = $this->jsonObject($json);
        if (($manifest['schema'] ?? null) !== 1) {
            throw new \RuntimeException('Unsupported MessageBus schema manifest.');
        }

        $migrations = [];
        $seen = [];
        foreach ($this->entries($manifest) as $entry) {
            $component = $this->string($entry, 'component');
            $migrationId = $this->string($entry, 'migration_id');
            $file = $this->string($entry, 'file');
            $checksum = $this->string($entry, 'checksum');
            if (preg_match('/^[a-z][a-z0-9-]{1,31}$/D', $component) !== 1
                || preg_match('/^[0-9]{4}_[a-z0-9_]+$/D', $migrationId) !== 1
                || preg_match('/^[0-9]{3}_[a-z0-9_]+\.sql$/D', $file) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
                throw new \RuntimeException('Invalid MessageBus migration manifest entry.');
            }
            $key = $component . ':' . $migrationId;
            if (isset($seen[$key])) {
                throw new \RuntimeException('Duplicate MessageBus migration identity: ' . $key);
            }
            $seen[$key] = true;
            $sql = file_get_contents($this->resources . '/migrations/' . $file);
            if (!\is_string($sql)) {
                throw new \RuntimeException('Packaged MessageBus migration is missing: ' . $file);
            }
            if (!hash_equals($checksum, hash('sha256', $sql))) {
                throw new \RuntimeException('Packaged MessageBus migration checksum mismatch: ' . $file);
            }
            $migrations[] = new SchemaMigration($component, $migrationId, $sql, $checksum);
        }

        return $migrations;
    }

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        $manifestJson = file_get_contents($this->resources . '/schema-manifest.json');
        if (!\is_string($manifestJson)) {
            throw new \RuntimeException('Packaged MessageBus schema manifest is missing.');
        }
        $manifest = $this->jsonObject($manifestJson);
        $file = $this->string($manifest, 'catalog_file');
        $checksum = $this->string($manifest, 'catalog_checksum');
        if ($file !== 'schema-catalog.json') {
            throw new \RuntimeException('MessageBus schema catalog_file must be schema-catalog.json.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
            throw new \RuntimeException('MessageBus schema catalog_checksum must be a lowercase SHA-256 digest.');
        }
        $catalogJson = file_get_contents($this->resources . '/' . $file);
        if (!\is_string($catalogJson) || !hash_equals($checksum, hash('sha256', $catalogJson))) {
            throw new \RuntimeException('Packaged MessageBus schema catalog checksum mismatch.');
        }
        $catalog = $this->jsonObject($catalogJson);
        if (($catalog['schema'] ?? null) !== 1 || !\is_array($catalog['tables'] ?? null)) {
            throw new \RuntimeException('Unsupported MessageBus schema catalog.');
        }

        return $catalog;
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($value) || array_is_list($value)) {
            throw new \RuntimeException('MessageBus schema manifest must be a JSON object.');
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new \RuntimeException('MessageBus schema manifest has a non-string key.');
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>>
     */
    private function entries(array $manifest): array
    {
        $value = $manifest['migrations'] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('MessageBus schema migrations must be a JSON list.');
        }
        $entries = [];
        foreach ($value as $entry) {
            if (!\is_array($entry) || array_is_list($entry)) {
                throw new \RuntimeException('MessageBus schema migration entry must be an object.');
            }
            $object = [];
            foreach ($entry as $key => $item) {
                if (!\is_string($key)) {
                    throw new \RuntimeException('MessageBus schema migration entry has a non-string key.');
                }
                $object[$key] = $item;
            }
            $entries[] = $object;
        }

        return $entries;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new \RuntimeException('MessageBus schema manifest field must be a string: ' . $key);
        }

        return $value;
    }
}

/**
 *  Applies and validates immutable package migrations using host-owned tooling.
 *
 * @api adapter
 */
final readonly class PostgresSchemaManager
{
    private const string LIBRARY_VERSION = '7.0.0-beta.1';

    public function __construct(
        private \PDO $pdo,
        private string $schema = 'public',
        private SchemaManifest $manifest = new SchemaManifest(),
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $this->schema) !== 1) {
            throw new \InvalidArgumentException(
                'PostgresSchemaManager schema must match [a-z_][a-z0-9_]{0,62}.',
            );
        }
    }

    public function installationFingerprint(): string
    {
        return hash('sha256', json_encode([
            'schema' => $this->schema,
            'table_prefix' => 'message_bus__',
            'contract' => 1,
        ], JSON_THROW_ON_ERROR));
    }

    public function migrate(): void
    {
        foreach ($this->manifest->migrations() as $migration) {
            $this->pdo->beginTransaction();

            try {
                $existing = $this->applied($migration->component, $migration->migrationId);
                if ($existing === null) {
                    $this->pdo->exec($this->qualify($migration->sql));
                } elseif ($existing['artifact_checksum'] !== $migration->checksum
                    || $existing['installation_fingerprint'] !== $this->installationFingerprint()) {
                    throw new \RuntimeException('Applied MessageBus migration differs from the package installation.');
                }

                if ($this->applied($migration->component, $migration->migrationId) === null) {
                    $statement = $this->pdo->prepare(\sprintf(
                        'INSERT INTO %s.message_bus__schema_migrations '
                        . '(component, migration_id, artifact_checksum, installation_fingerprint, library_version) '
                        . 'VALUES (:component, :migration_id, :checksum, :fingerprint, :library_version)',
                        $this->quote($this->schema),
                    ));
                    $statement->execute([
                        'component' => $migration->component,
                        'migration_id' => $migration->migrationId,
                        'checksum' => $migration->checksum,
                        'fingerprint' => $this->installationFingerprint(),
                        'library_version' => self::LIBRARY_VERSION,
                    ]);
                }
                $this->pdo->commit();
            } catch (\Throwable $failure) {
                FailurePreservingCleanup::rethrowAfter(
                    $failure,
                    'PostgreSQL schema migration rollback',
                    function (): void {
                        if ($this->pdo->inTransaction()) {
                            $this->pdo->rollBack();
                        }
                    },
                );
            }
        }
    }

    /** Lightweight startup check: exact manifest identity, order and installation. */
    public function validate(): void
    {
        $expected = [];
        foreach ($this->manifest->migrations() as $migration) {
            $expected[$migration->key()] = $migration;
        }
        $query = $this->pdo->query(\sprintf(
            'SELECT component, migration_id, artifact_checksum, installation_fingerprint '
            . 'FROM %s.message_bus__schema_migrations ORDER BY applied_at, component, migration_id',
            $this->quote($this->schema),
        ));
        if (!$query instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to read MessageBus schema migrations.');
        }
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        $actualOrder = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new \UnexpectedValueException('PostgreSQL migration row must be an array.');
            }
            $normalized = [];
            foreach ($row as $field => $value) {
                if (!\is_string($field)) {
                    throw new \UnexpectedValueException('PostgreSQL migration row has a non-string key.');
                }
                $normalized[$field] = $value;
            }
            $key = $this->string($normalized, 'component') . ':' . $this->string($normalized, 'migration_id');
            $migration = $expected[$key] ?? null;
            if (!$migration instanceof SchemaMigration
                || $migration->checksum !== $this->string($normalized, 'artifact_checksum')
                || $this->string($normalized, 'installation_fingerprint') !== $this->installationFingerprint()) {
                throw new \RuntimeException('MessageBus database has an unknown or changed migration.');
            }
            $actualOrder[] = $key;
            unset($expected[$key]);
        }
        $manifestOrder = array_map(
            static fn(SchemaMigration $migration): string => $migration->key(),
            $this->manifest->migrations(),
        );
        if ($expected !== [] || $actualOrder !== $manifestOrder) {
            throw new \RuntimeException('MessageBus database migration order differs from the package manifest.');
        }
    }

    /** Full deploy/CI check of exact owned columns, constraints, indexes and ownership. */
    public function validateCatalog(): void
    {
        $catalog = $this->manifest->catalog();
        $tables = $this->catalogTables($catalog);
        $expectedColumns = [];
        $expectedConstraints = [];
        $expectedIndexes = [];
        foreach ($tables as $table => $definition) {
            $expectedColumns[$table] = $definition['columns'];
            $expectedConstraints[$table] = $definition['constraints'];
            $expectedIndexes[$table] = $definition['indexes'];
        }

        $columns = $this->pdo->prepare(<<<'SQL'
            SELECT c.relname AS table_name, a.attname AS column_name,
                   format_type(a.atttypid, a.atttypmod) AS data_type,
                   NOT a.attnotnull AS nullable,
                   (a.atthasdef OR a.attidentity <> '') AS has_default,
                   CASE WHEN a.attidentity <> '' THEN 'identity'
                        ELSE pg_get_expr(ad.adbin, ad.adrelid, true) END AS default_expression
            FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
            LEFT JOIN pg_catalog.pg_attrdef ad
              ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
            WHERE n.nspname = :schema AND c.relkind = 'r'
              AND c.relname LIKE 'message_bus\_\_%' ESCAPE '\'
              AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY c.relname, a.attnum
            SQL);
        $columns->execute(['schema' => $this->schema]);
        $actualColumns = [];
        $actualDefaults = [];
        foreach ($this->rows($columns) as $row) {
            $table = $this->string($row, 'table_name');
            if (!isset($expectedColumns[$table])) {
                continue;
            }
            $actualColumns[$table][] = [
                $this->string($row, 'column_name'),
                $this->string($row, 'data_type'),
                $this->boolean($row, 'nullable'),
                $this->boolean($row, 'has_default'),
            ];
            $default = $this->nullableString($row, 'default_expression');
            if ($default !== null) {
                $actualDefaults[$table . '.' . $this->string($row, 'column_name')] = $this->normalizeDefinition($default);
            }
        }
        ksort($actualColumns);
        if ($actualColumns !== $expectedColumns) {
            throw new \RuntimeException('MessageBus PostgreSQL column catalog differs from the packaged contract.');
        }
        ksort($actualDefaults);
        $expectedDefaults = $this->catalogStringMap($catalog, 'default_expressions');
        if ($actualDefaults !== $this->normalizeDefinitions($expectedDefaults)) {
            throw new \RuntimeException('MessageBus PostgreSQL default expressions differ from the packaged contract.');
        }

        $constraints = $this->pdo->prepare(<<<'SQL'
            SELECT c.relname AS table_name, con.conname AS object_name,
                   pg_get_constraintdef(con.oid, true) AS object_definition
            FROM pg_catalog.pg_constraint con
            JOIN pg_catalog.pg_class c ON c.oid = con.conrelid
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = :schema AND c.relname LIKE 'message_bus\_\_%' ESCAPE '\'
            ORDER BY c.relname, con.conname
            SQL);
        $constraints->execute(['schema' => $this->schema]);
        $actualConstraints = $this->objectsByTable($constraints, $expectedConstraints);
        if ($actualConstraints !== $expectedConstraints) {
            throw new \RuntimeException('MessageBus PostgreSQL constraints differ from the packaged contract.');
        }
        $constraints->execute(['schema' => $this->schema]);
        $this->assertDefinitions(
            $constraints,
            $this->catalogStringMap($catalog, 'constraint_definitions'),
            'constraints',
        );

        $indexes = $this->pdo->prepare(<<<'SQL'
            SELECT table_class.relname AS table_name, index_class.relname AS object_name,
                   index_data.indisunique AS is_unique,
                   COALESCE(pg_get_expr(index_data.indpred, index_data.indrelid, true), '') AS predicate,
                   array_to_json(ARRAY(
                       SELECT pg_get_indexdef(index_data.indexrelid, position, true)
                           || CASE
                               WHEN (index_data.indoption[position - 1] & 1) <> 0
                                   THEN ' DESC'
                               ELSE ''
                           END
                           || CASE
                               WHEN (index_data.indoption[position - 1] & 1) <> 0
                                   AND (index_data.indoption[position - 1] & 2) = 0
                                   THEN ' NULLS LAST'
                               WHEN (index_data.indoption[position - 1] & 1) = 0
                                   AND (index_data.indoption[position - 1] & 2) <> 0
                                   THEN ' NULLS FIRST'
                               ELSE ''
                           END
                       FROM generate_series(1, index_data.indnkeyatts) AS position
                       ORDER BY position
                   ))::text AS columns_json
            FROM pg_catalog.pg_index index_data
            JOIN pg_catalog.pg_class index_class ON index_class.oid = index_data.indexrelid
            JOIN pg_catalog.pg_class table_class ON table_class.oid = index_data.indrelid
            JOIN pg_catalog.pg_namespace ns ON ns.oid = table_class.relnamespace
            WHERE ns.nspname = :schema
              AND table_class.relname LIKE 'message_bus\_\_%' ESCAPE '\'
            ORDER BY table_class.relname, index_class.relname
            SQL);
        $indexes->execute(['schema' => $this->schema]);
        $actualIndexes = $this->objectsByTable($indexes, $expectedIndexes);
        if ($actualIndexes !== $expectedIndexes) {
            throw new \RuntimeException('MessageBus PostgreSQL indexes differ from the packaged contract.');
        }
        $indexes->execute(['schema' => $this->schema]);
        $this->assertIndexDefinitions($indexes, $this->catalogIndexDefinitions($catalog));

        $owners = [];
        foreach (array_keys($tables) as $table) {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT pg_get_userbyid(c.relowner) AS owner,
                       EXISTS (
                           SELECT 1
                           FROM aclexplode(COALESCE(c.relacl, acldefault('r', c.relowner))) acl
                           WHERE acl.grantee = 0
                             AND acl.privilege_type IN (
                                 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'
                             )
                       ) AS public_writable
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = :schema AND c.relname = :table AND c.relkind = 'r'
                SQL);
            $statement->execute(['schema' => $this->schema, 'table' => $table]);
            $row = $this->row($statement)
                ?? throw new \RuntimeException('MessageBus PostgreSQL owned table is missing: ' . $table);
            $owners[$this->string($row, 'owner')] = true;
            if ($this->boolean($row, 'public_writable')) {
                throw new \RuntimeException('PUBLIC has write privileges on MessageBus table: ' . $table);
            }
            $privilege = $this->pdo->prepare(<<<'SQL'
                SELECT has_table_privilege(current_user,
                           quote_ident(n.nspname) || '.' || quote_ident(c.relname),
                           'SELECT,INSERT,UPDATE,DELETE') AS runtime_access
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = :schema AND c.relname = :table AND c.relkind = 'r'
                SQL);
            $privilege->execute(['schema' => $this->schema, 'table' => $table]);
            $privilegeRow = $this->row($privilege)
                ?? throw new \RuntimeException('MessageBus PostgreSQL privilege target is missing: ' . $table);
            if (!$this->boolean($privilegeRow, 'runtime_access')) {
                throw new \RuntimeException('Current role lacks minimum MessageBus runtime grants: ' . $table);
            }
        }
        if (\count($owners) !== 1) {
            throw new \RuntimeException('MessageBus PostgreSQL tables do not share one owner.');
        }
        $this->assertLegacyObjectsReadOnly($this->catalogStringList($catalog, 'legacy_objects'));
    }

    /** @param array<string, string> $expected */
    private function assertDefinitions(\PDOStatement $statement, array $expected, string $kind): void
    {
        $actual = [];
        foreach ($this->rows($statement) as $row) {
            $key = $this->string($row, 'table_name') . '.' . $this->string($row, 'object_name');
            if (isset($expected[$key])) {
                $actual[$key] = $this->normalizeDefinition($this->string($row, 'object_definition'));
            }
        }
        ksort($actual);
        if ($actual !== $this->normalizeDefinitions($expected)) {
            throw new \RuntimeException('MessageBus PostgreSQL ' . $kind . ' definitions differ from the packaged contract.');
        }
    }

    /** @param array<string, array{unique: bool, columns: list<string>, predicate: string}> $expected */
    private function assertIndexDefinitions(\PDOStatement $statement, array $expected): void
    {
        $actual = [];
        foreach ($this->rows($statement) as $row) {
            $key = $this->string($row, 'table_name') . '.' . $this->string($row, 'object_name');
            if (!isset($expected[$key])) {
                continue;
            }
            $columns = json_decode($this->string($row, 'columns_json'), true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($columns) || !array_is_list($columns)) {
                throw new \UnexpectedValueException('PostgreSQL index columns must be a JSON list.');
            }
            $normalizedColumns = [];
            foreach ($columns as $column) {
                if (!\is_string($column)) {
                    throw new \UnexpectedValueException('PostgreSQL index column must be a string.');
                }
                $normalizedColumns[] = $this->normalizeDefinition($column);
            }
            $actual[$key] = [
                'unique' => $this->boolean($row, 'is_unique'),
                'columns' => $normalizedColumns,
                'predicate' => $this->normalizeDefinition($this->string($row, 'predicate')),
            ];
        }
        ksort($actual);
        foreach ($expected as &$definition) {
            $definition['columns'] = array_map($this->normalizeDefinition(...), $definition['columns']);
            $definition['predicate'] = $this->normalizeDefinition($definition['predicate']);
        }
        unset($definition);
        if ($actual !== $expected) {
            throw new \RuntimeException('MessageBus PostgreSQL index definitions differ from the packaged contract.');
        }
    }

    /** @param list<string> $legacyObjects */
    private function assertLegacyObjectsReadOnly(array $legacyObjects): void
    {
        foreach ($legacyObjects as $object) {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT has_table_privilege(current_user,
                           quote_ident(n.nspname) || '.' || quote_ident(c.relname),
                           'INSERT,UPDATE,DELETE,TRUNCATE') AS current_user_writable,
                       EXISTS (
                           SELECT 1
                           FROM aclexplode(COALESCE(c.relacl, acldefault('r', c.relowner))) acl
                           WHERE acl.grantee = 0
                             AND acl.privilege_type IN ('INSERT', 'UPDATE', 'DELETE', 'TRUNCATE')
                       ) AS public_writable
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = :schema AND c.relname = :object
                  AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
                SQL);
            $statement->execute(['schema' => $this->schema, 'object' => $object]);
            $row = $this->row($statement);
            if ($row !== null && ($this->boolean($row, 'current_user_writable')
                || $this->boolean($row, 'public_writable'))) {
                throw new \RuntimeException('Writable legacy MessageBus object bypasses v7 transitions: ' . $object);
            }
        }
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, array{columns: list<array{string, string, bool, bool}>, constraints: list<string>, indexes: list<string>}>
     */
    private function catalogTables(array $catalog): array
    {
        $rawTables = $catalog['tables'] ?? null;
        if (!\is_array($rawTables) || array_is_list($rawTables)) {
            throw new \RuntimeException('MessageBus schema catalog tables must be an object.');
        }
        $tables = [];
        foreach ($rawTables as $table => $rawDefinition) {
            if (!\is_string($table)) {
                throw new \RuntimeException('MessageBus schema catalog table key must be a string.');
            }
            if (!\is_array($rawDefinition) || array_is_list($rawDefinition)) {
                throw new \RuntimeException(
                    'MessageBus schema catalog table ' . $table . ' definition must be an object.',
                );
            }
            $rawColumns = $rawDefinition['columns'] ?? null;
            $rawDefaults = $rawDefinition['defaults'] ?? null;
            $rawConstraints = $rawDefinition['constraints'] ?? null;
            $rawIndexes = $rawDefinition['indexes'] ?? null;
            if (!\is_array($rawColumns) || !array_is_list($rawColumns)
                || !\is_array($rawDefaults) || !array_is_list($rawDefaults)
                || !\is_array($rawConstraints) || !array_is_list($rawConstraints)
                || !\is_array($rawIndexes) || !array_is_list($rawIndexes)) {
                throw new \RuntimeException(
                    'MessageBus schema catalog table ' . $table
                        . ' requires list members columns, defaults, constraints and indexes.',
                );
            }
            $defaults = $this->stringList($rawDefaults, 'default column');
            $columns = [];
            foreach ($rawColumns as $index => $column) {
                if (!\is_array($column) || !array_is_list($column) || \count($column) !== 3
                    || !\is_string($column[0]) || !\is_string($column[1]) || !\is_bool($column[2])) {
                    throw new \RuntimeException(
                        'MessageBus schema catalog table ' . $table . ' column at index ' . $index
                            . ' must be [string name, string type, bool nullable].',
                    );
                }
                $columns[] = [$column[0], $column[1], $column[2], \in_array($column[0], $defaults, true)];
            }
            $constraints = $this->stringList($rawConstraints, 'constraint');
            $indexes = $this->stringList($rawIndexes, 'index');
            $tables[$table] = [
                'columns' => $columns,
                'constraints' => $constraints,
                'indexes' => $indexes,
            ];
        }
        ksort($tables);

        return $tables;
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, string>
     */
    private function catalogStringMap(array $catalog, string $key): array
    {
        $raw = $catalog[$key] ?? null;
        if (!\is_array($raw) || array_is_list($raw)) {
            throw new \RuntimeException('MessageBus schema catalog ' . $key . ' must be an object.');
        }
        $result = [];
        foreach ($raw as $name => $value) {
            if (!\is_string($name)) {
                throw new \RuntimeException('MessageBus schema catalog ' . $key . ' entry key must be a string.');
            }
            if (!\is_string($value)) {
                throw new \RuntimeException(
                    'MessageBus schema catalog ' . $key . ' entry ' . $name . ' must be a string.',
                );
            }
            $result[$name] = $value;
        }
        ksort($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $catalog
     * @return list<string>
     */
    private function catalogStringList(array $catalog, string $key): array
    {
        $raw = $catalog[$key] ?? null;
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new \RuntimeException('MessageBus schema catalog ' . $key . ' must be a list.');
        }

        return $this->stringList($raw, $key);
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, array{unique: bool, columns: list<string>, predicate: string}>
     */
    private function catalogIndexDefinitions(array $catalog): array
    {
        $raw = $catalog['index_definitions'] ?? null;
        if (!\is_array($raw) || array_is_list($raw)) {
            throw new \RuntimeException('MessageBus schema catalog index definitions must be an object.');
        }
        $definitions = [];
        foreach ($raw as $name => $definition) {
            if (!\is_string($name)) {
                throw new \RuntimeException('MessageBus schema catalog index definition key must be a string.');
            }
            if (!\is_array($definition) || array_is_list($definition)
                || !\is_bool($definition['unique'] ?? null)
                || !\is_array($definition['columns'] ?? null)
                || !array_is_list($definition['columns'])
                || !\is_string($definition['predicate'] ?? null)) {
                throw new \RuntimeException(
                    'MessageBus schema catalog index ' . $name
                        . ' requires bool unique, list columns and string predicate.',
                );
            }
            $definitions[$name] = [
                'unique' => $definition['unique'],
                'columns' => $this->stringList($definition['columns'], 'index column'),
                'predicate' => $definition['predicate'],
            ];
        }
        ksort($definitions);

        return $definitions;
    }

    /**
     * @param array<string, string> $definitions
     * @return array<string, string>
     */
    private function normalizeDefinitions(array $definitions): array
    {
        foreach ($definitions as &$definition) {
            $definition = $this->normalizeDefinition($definition);
        }
        unset($definition);
        ksort($definitions);

        return $definitions;
    }

    private function normalizeDefinition(string $definition): string
    {
        $normalized = strtolower($definition);
        $normalized = str_replace([
            '"' . strtolower($this->schema) . '".',
            strtolower($this->schema) . '.',
            '"',
            '::text',
            '(',
            ')',
        ], '', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized));
        if (!\is_string($normalized)) {
            throw new \RuntimeException('Unable to normalize PostgreSQL catalog definition.');
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private function stringList(array $values, string $kind): array
    {
        $strings = [];
        foreach ($values as $index => $value) {
            if (!\is_string($value)) {
                throw new \RuntimeException(
                    'MessageBus schema catalog ' . $kind . ' at index ' . $index . ' must be a string.',
                );
            }
            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @param array<string, list<string>> $expected
     * @return array<string, list<string>>
     */
    private function objectsByTable(\PDOStatement $statement, array $expected): array
    {
        $actual = array_fill_keys(array_keys($expected), []);
        foreach ($this->rows($statement) as $row) {
            $table = $this->string($row, 'table_name');
            if (isset($actual[$table])) {
                $actual[$table][] = $this->string($row, 'object_name');
            }
        }

        return $actual;
    }

    /** @return list<array<string, mixed>> */
    private function rows(\PDOStatement $statement): array
    {
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $value) {
            if (!\is_array($value)) {
                throw new \UnexpectedValueException('PostgreSQL catalog row must be an array.');
            }
            $row = [];
            foreach ($value as $key => $field) {
                if (!\is_string($key)) {
                    throw new \UnexpectedValueException('PostgreSQL catalog row has a non-string key.');
                }
                $row[$key] = $field;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function row(\PDOStatement $statement): ?array
    {
        $value = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($value === false) {
            return null;
        }
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('PostgreSQL catalog row must be an array.');
        }
        $row = [];
        foreach ($value as $key => $field) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('PostgreSQL catalog row has a non-string key.');
            }
            $row[$key] = $field;
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function boolean(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (\is_bool($value)) {
            return $value;
        }
        if ($value === 't' || $value === '1') {
            return true;
        }
        if ($value === 'f' || $value === '0') {
            return false;
        }

        throw new \UnexpectedValueException('PostgreSQL catalog field must be boolean: ' . $key);
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw new \UnexpectedValueException('PostgreSQL catalog field must be string|null: ' . $key);
        }

        return $value;
    }

    /** @return array{artifact_checksum: string, installation_fingerprint: string}|null */
    private function applied(string $component, string $migrationId): ?array
    {
        $qualified = $this->schema . '.message_bus__schema_migrations';
        $table = $this->pdo->prepare('SELECT to_regclass(:table_name)');
        $table->execute(['table_name' => $qualified]);
        if ($table->fetchColumn() === null) {
            return null;
        }

        $statement = $this->pdo->prepare(\sprintf(
            'SELECT artifact_checksum, installation_fingerprint '
            . 'FROM %s.message_bus__schema_migrations '
            . 'WHERE component = :component AND migration_id = :migration_id',
            $this->quote($this->schema),
        ));
        $statement->execute(['component' => $component, 'migration_id' => $migrationId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            return null;
        }

        return [
            'artifact_checksum' => $this->string($row, 'artifact_checksum'),
            'installation_fingerprint' => $this->string($row, 'installation_fingerprint'),
        ];
    }

    private function qualify(string $sql): string
    {
        return str_replace('__SCHEMA__', $this->quote($this->schema), $sql);
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('PostgreSQL row field must be a string: ' . $key);
        }

        return $value;
    }
}
