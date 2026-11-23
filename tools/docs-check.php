<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$inventories = [];

foreach (['ru', 'en'] as $locale) {
    $directory = $root . '/docs/' . $locale;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    $documents = [];
    $ids = [];
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'md') {
            continue;
        }
        $path = substr($file->getPathname(), strlen($directory) + 1);
        $contents = file_get_contents($file->getPathname());
        if (!is_string($contents)) {
            throw new RuntimeException('Cannot read ' . $file->getPathname());
        }
        if (!preg_match('/\A---\Rdoc_id: ([a-z0-9._-]+)\Rcontract_revision: ([0-9]+)\R---\R/', $contents, $match)) {
            throw new RuntimeException($path . ' has invalid frontmatter.');
        }
        if (isset($ids[$match[1]])) {
            throw new RuntimeException($locale . ' has duplicate doc_id ' . $match[1]);
        }
        $ids[$match[1]] = $path;
        $documents[$path] = ['id' => $match[1], 'revision' => (int) $match[2]];

        preg_match_all('/\[[^]]*]\(([^)#]+\.md)(?:#[^)]+)?\)/', $contents, $links);
        foreach ($links[1] as $link) {
            $target = realpath(dirname($file->getPathname()) . '/' . $link);
            if ($target === false || !str_starts_with($target, $directory . '/')) {
                throw new RuntimeException($path . ' has broken local link ' . $link);
            }
        }
    }
    ksort($documents);
    $inventories[$locale] = $documents;
}

if ($inventories['ru'] !== $inventories['en']) {
    throw new RuntimeException('RU/EN document inventories, IDs, or revisions differ.');
}

foreach (['README.md', 'README.en.md', 'CHANGELOG.md'] as $document) {
    if (!is_file($root . '/' . $document)) {
        throw new RuntimeException($document . ' is missing.');
    }
}

fwrite(STDOUT, sprintf("Documentation parity and links are valid (%d paired documents).\n", count($inventories['ru'])));
