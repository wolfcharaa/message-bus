<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$command = proc_open(
    ['git', 'ls-files', '--cached', '--others', '--exclude-standard'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
);
if (!\is_resource($command)) {
    throw new RuntimeException('Unable to enumerate release source files.');
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($command) !== 0) {
    throw new RuntimeException('Unable to enumerate release source files: ' . $stderr);
}

$scanned = 0;
$bytes = 0;
foreach (preg_split('/\R/', trim($stdout)) ?: [] as $relative) {
    if ($relative === '' || preg_match('~^(?:vendor|var|\.git|\.context)/~', $relative) === 1) {
        continue;
    }
    $path = $root . '/' . $relative;
    if (!is_file($path) || is_link($path)) {
        continue;
    }
    $base = basename($relative);
    if (preg_match('/^(?:\.env(?:\..+)?|auth\.json|id_rsa|id_ed25519)$/i', $base) === 1
        || preg_match('/\.(?:pem|p12|pfx|key)$/i', $base) === 1) {
        throw new RuntimeException('Sensitive filename is present in release source: ' . $relative);
    }
    $size = filesize($path);
    if (!\is_int($size) || $size > 5_000_000) {
        continue;
    }
    $content = file_get_contents($path);
    if (!\is_string($content) || str_contains($content, "\0")) {
        continue;
    }
    foreach ([
        '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\bghp_[A-Za-z0-9]{30,}\b/',
        '/\bgithub_pat_[A-Za-z0-9_]{40,}\b/',
        '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
    ] as $pattern) {
        if (preg_match($pattern, $content) === 1) {
            throw new RuntimeException('Potential secret material is present in release source: ' . $relative);
        }
    }
    if ($relative !== 'tools/source-scan.php'
        && preg_match('~/(?:home|Users|srv/projects)/[^\s"\']+~', $content) === 1) {
        throw new RuntimeException('Machine-local absolute path is present in release source: ' . $relative);
    }
    ++$scanned;
    $bytes += $size;
}

fwrite(STDOUT, sprintf("Release source scan passed: %d text files, %d bytes.\n", $scanned, $bytes));
