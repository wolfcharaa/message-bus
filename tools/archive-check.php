<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composerPhp = is_file('/usr/bin/php8.4') ? '/usr/bin/php8.4' : PHP_BINARY;
$temporary = sys_get_temp_dir() . '/message-bus-archive-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0775, true)) {
    throw new RuntimeException('Cannot create archive workspace.');
}

try {
    run([
        $composerPhp,
        '/usr/bin/composer',
        'archive',
        '--format=zip',
        '--file=message-bus-7.0.0-alpha.3',
        '--dir=' . $temporary,
    ], $root);
    $archive = $temporary . '/message-bus-7.0.0-alpha.3.zip';
    if (!is_file($archive)) {
        throw new RuntimeException('Composer did not create the expected ZIP.');
    }

    $members = output(['unzip', '-Z1', $archive], $root);
    $paths = array_values(array_filter(preg_split('/\R/', trim($members)) ?: []));
    $allowedRoots = ['bin', 'docs', 'examples', 'resources', 'src'];
    $allowedFiles = ['CHANGELOG.md', 'LICENSE', 'README.md', 'README.en.md', 'composer.json'];
    foreach ($paths as $path) {
        $normalized = rtrim($path, '/');
        if ($normalized === '') {
            continue;
        }
        $first = explode('/', $normalized, 2)[0];
        if (!in_array($normalized, $allowedFiles, true) && !in_array($first, $allowedRoots, true)) {
            throw new RuntimeException('Unexpected archive member: ' . $path);
        }
    }
    foreach ([
        'composer.json',
        'LICENSE',
        'README.md',
        'README.en.md',
        'bin/message-bus',
        'bin/message-bus-worker',
        'resources/schema-manifest.json',
        'resources/public-api.json',
        'resources/skills/integrate-message-bus/SKILL.md',
        'resources/conformance/run-durable-backend.php',
        'resources/conformance/run-idempotency-boundary.php',
    ] as $required) {
        if (!in_array($required, $paths, true)) {
            throw new RuntimeException('Required archive member is missing: ' . $required);
        }
    }
    $binaryModes = output([
        'zipinfo',
        '-l',
        $archive,
        'bin/message-bus',
        'bin/message-bus-worker',
    ], $root);
    if (substr_count($binaryModes, '-rwxr-xr-x') !== 2) {
        throw new RuntimeException('Archive binaries lost executable permissions.');
    }
    foreach ($paths as $path) {
        if (preg_match('~(^|/)(tests|tools|vendor|var|\.git|\.context|\.agents|\.codex|\.idea)(/|$)~', $path) === 1) {
            throw new RuntimeException('Repository-only path leaked into archive: ' . $path);
        }
    }

    $extract = $temporary . '/extract';
    mkdir($extract);
    run(['unzip', '-qq', $archive, '-d', $extract], $root);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extract, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('Archive contains a symlink: ' . $file->getPathname());
        }
        if ($file->isFile()) {
            $base = $file->getBasename();
            if (preg_match('/^(?:\.env(?:\..+)?|auth\.json|id_rsa|id_ed25519)$/i', $base) === 1
                || preg_match('/\.(?:pem|p12|pfx|key)$/i', $base) === 1) {
                throw new RuntimeException('Archive contains a sensitive filename: ' . $file->getPathname());
            }
            $contents = file_get_contents($file->getPathname());
            if (is_string($contents)) {
                foreach ([
                    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
                    '/\bAKIA[0-9A-Z]{16}\b/',
                    '/\bghp_[A-Za-z0-9]{30,}\b/',
                    '/\bgithub_pat_[A-Za-z0-9_]{40,}\b/',
                ] as $pattern) {
                    if (preg_match($pattern, $contents) === 1) {
                        throw new RuntimeException('Archive contains potential secret material: ' . $file->getPathname());
                    }
                }
            }
        }
    }

    $consumer = $temporary . '/consumer';
    mkdir($consumer);
    $clockArchive = $temporary . '/psr-clock-1.0.0.zip';
    run(['zip', '-qr', $clockArchive, '.'], $root . '/vendor/psr/clock');
    $package = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($package)) {
        throw new RuntimeException('Package composer.json did not decode to an object.');
    }
    $package['version'] = '7.0.0-alpha.3';
    $package['dist'] = ['type' => 'zip', 'url' => 'file://' . $archive];
    $clock = json_decode((string) file_get_contents($root . '/vendor/psr/clock/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($clock)) {
        throw new RuntimeException('PSR Clock composer.json did not decode to an object.');
    }
    $clock['version'] = '1.0.0';
    $clock['dist'] = ['type' => 'zip', 'url' => 'file://' . $clockArchive];
    file_put_contents($consumer . '/composer.json', json_encode([
        'name' => 'message-bus/archive-consumer',
        'require' => ['romanfedorskij/message-bus' => '*'],
        'repositories' => [
            ['type' => 'package', 'package' => $package],
            ['type' => 'package', 'package' => $clock],
            ['packagist.org' => false],
        ],
        'minimum-stability' => 'dev',
        'prefer-stable' => true,
        'config' => ['allow-plugins' => false],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    run([
        $composerPhp,
        '/usr/bin/composer',
        'update',
        '--no-dev',
        '--no-interaction',
        '--no-progress',
        '--no-scripts',
    ], $consumer, ['COMPOSER_CACHE_DIR' => $temporary . '/cache']);
    $example = $consumer . '/vendor/romanfedorskij/message-bus/examples/orchestration/run.php';
    $exampleOutput = trim(output([PHP_BINARY, $example], $consumer));
    if ($exampleOutput !== 'Hello Ada') {
        throw new RuntimeException('Archive-installed orchestration example failed: ' . $exampleOutput);
    }
    $skill = $consumer . '/vendor/romanfedorskij/message-bus/resources/skills/integrate-message-bus';
    run([
        PHP_BINARY,
        $root . '/tools/skill-check.php',
        $skill,
    ], $consumer);

    $durableConsumer = 'requires disposable PostgreSQL';
    $durableDsn = getenv('MESSAGE_BUS_TEST_PGSQL_DSN');
    if (is_string($durableDsn) && $durableDsn !== '') {
        $durable = $consumer . '/vendor/romanfedorskij/message-bus/examples/durable-postgres/run.php';
        $durableOutput = output([PHP_BINARY, $durable], $consumer, [
            'MESSAGE_BUS_EXAMPLE_PGSQL_DSN' => $durableDsn,
            'MESSAGE_BUS_EXAMPLE_PGSQL_USER' => (string) getenv('MESSAGE_BUS_TEST_PGSQL_USER'),
            'MESSAGE_BUS_EXAMPLE_PGSQL_PASSWORD' => (string) getenv('MESSAGE_BUS_TEST_PGSQL_PASSWORD'),
            'MESSAGE_BUS_EXAMPLE_PGSQL_SCHEMA' => 'message_bus_archive_consumer',
        ]);
        $durableResult = json_decode($durableOutput, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($durableResult) || ($durableResult['status'] ?? null) !== 'succeeded'
            || ($durableResult['handler_calls'] ?? null) !== 1) {
            throw new RuntimeException('Archive-installed durable PostgreSQL example failed.');
        }
        $durableConsumer = 'passed';
    }

    fwrite(STDOUT, json_encode([
        'schema' => 1,
        'archive' => basename($archive),
        'sha256' => hash_file('sha256', $archive),
        'members' => count($paths),
        'bytes' => filesize($archive),
        'orchestration_consumer' => 'passed',
        'packaged_skill' => 'passed',
        'durable_postgres_consumer' => $durableConsumer,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    removeTree($temporary);
}

/** @param list<string> $command @param array<string, string> $environment */
function run(array $command, string $workingDirectory, array $environment = []): void
{
    output($command, $workingDirectory, $environment);
}

/** @param list<string> $command @param array<string, string> $environment */
function output(array $command, string $workingDirectory, array $environment = []): string
{
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $inherited = getenv();
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $workingDirectory,
        $environment + (is_array($inherited) ? $inherited : []),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start command: ' . implode(' ', $command));
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException(sprintf(
            "Command failed (%d): %s\n%s\n%s",
            $code,
            implode(' ', $command),
            $stdout,
            $stderr,
        ));
    }

    return $stdout;
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($path);
}
