<?php

declare(strict_types=1);

$directory = $argv[1] ?? dirname(__DIR__) . '/resources/skills/integrate-message-bus';
$skill = rtrim($directory, '/') . '/SKILL.md';
$content = file_get_contents($skill);
if (!\is_string($content)) {
    throw new RuntimeException('Packaged MessageBus skill is missing.');
}
if (preg_match('/\A---\Rname: ([a-z0-9-]+)\Rdescription: ([^\r\n]+)\R---\R/', $content, $matches) !== 1) {
    throw new RuntimeException('Packaged MessageBus skill frontmatter is invalid.');
}
if ($matches[1] !== 'integrate-message-bus' || \strlen($matches[2]) < 80 || \strlen($matches[2]) > 1024) {
    throw new RuntimeException('Packaged MessageBus skill identity or description is invalid.');
}
foreach (['vendor/', 'Wolfcharaa\\MessageBus\\Internal', 'examples/orchestration/run.php', 'docs/en/'] as $required) {
    if (!str_contains($content, $required)) {
        throw new RuntimeException('Packaged MessageBus skill is missing a safety or documentation reference: ' . $required);
    }
}
if (preg_match('~/(?:home|Users|srv/projects)/~', $content) === 1) {
    throw new RuntimeException('Packaged MessageBus skill contains a machine-local path.');
}
$agent = rtrim($directory, '/') . '/agents/openai.yaml';
if (!is_file($agent) || filesize($agent) === 0) {
    throw new RuntimeException('Packaged MessageBus skill agent metadata is missing.');
}

fwrite(STDOUT, "Packaged MessageBus integration skill is valid.\n");
