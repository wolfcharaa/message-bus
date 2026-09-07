<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Cli\RegistryCompileInput;
use Wolfcharaa\MessageBus\Dumper\CompiledRegistryFileWriter;
use Wolfcharaa\MessageBus\Registry\DeprecationDiagnosticsMode;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\MessageRegistryDefinition;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompilerOptions;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationGraphContext;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationResult;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticOrigin;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticTarget;

#[AsCommand(name: 'registry:compile')]
final class RegistryCompileCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Compile the message registry and report configuration diagnostics.')
            ->addOption('bootstrap', null, InputOption::VALUE_REQUIRED, 'PHP file returning RegistryCompileInput, RegistryCompilationResult or MessageRegistryDefinition.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Target compiled registry PHP file.')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Print a focused relation graph for every diagnostic.')
            ->addOption('fail-on-warning', null, InputOption::VALUE_NONE, 'Return a failure exit code when warnings are emitted.')
            ->addOption('deprecations', null, InputOption::VALUE_REQUIRED, 'Deprecation diagnostics mode: ignore, warn or fail.')
            ->addOption('base-path', null, InputOption::VALUE_REQUIRED, 'Base path for diagnostic file display.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $value = require $this->bootstrapPath($input);

        if ($value instanceof MessageRegistryDefinition) {
            // TODO(next-major): remove the definition-only bootstrap path after provider/flow bootstraps are widely adopted.
            return $this->writeDefinition($value, $input, $output);
        }

        if ($value instanceof RegistryCompilationResult) {
            $options = $this->options($input, new MessageRegistryCompilerOptions());

            return $this->handleResult($value, $options, $input, $output);
        }

        if ($value instanceof RegistryCompileInput) {
            $options = $this->options($input, $value->options ?? new MessageRegistryCompilerOptions());
            $result = $value->compiler()->compileWithDiagnostics(
                $value->provider,
                $value->flows,
                $value->libraryVersion,
                $value->sourceHash,
                $options,
            );

            return $this->handleResult($result, $options, $input, $output);
        }

        throw new RuntimeException('registry:compile bootstrap must return MessageRegistryDefinition, RegistryCompilationResult or RegistryCompileInput.');
    }

    private function handleResult(
        RegistryCompilationResult $result,
        MessageRegistryCompilerOptions $options,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->renderDiagnostics(
            $result,
            $output,
            (string) ($input->getOption('base-path') ?: \getcwd()),
            (bool) $input->getOption('explain'),
        );

        if ($result->isFailure($options)) {
            $output->writeln(\sprintf(
                'registry.compile_failed errors=%d warnings=%d',
                \count($result->errors()),
                \count($result->warnings()),
            ));

            if (!$input->getOption('explain') && $result->graphContext !== null) {
                $output->writeln('hint: Run registry:compile --explain for affected message/handler graph.');
            }

            return Command::FAILURE;
        }

        if (!$result->hasDefinition()) {
            throw new RuntimeException('registry:compile diagnostics result has no definition and no failure diagnostics.');
        }

        return $this->writeDefinition($result->definition, $input, $output);
    }

    private function renderDiagnostics(RegistryCompilationResult $result, OutputInterface $output, string $basePath, bool $explain): void
    {
        foreach ($result->diagnostics as $diagnostic) {
            $output->writeln(\sprintf('%s %s: %s', $diagnostic->severity->value, $diagnostic->code, $diagnostic->message));

            if ($diagnostic->origin !== null) {
                $output->writeln('  origin: ' . $this->origin($diagnostic->origin, $basePath));
            }

            if ($diagnostic->target !== null) {
                $output->writeln('  target: ' . $this->target($diagnostic->target));
            }

            if ($diagnostic->hint !== null) {
                $output->writeln('  hint: ' . $diagnostic->hint);
            }

            if ($explain) {
                $output->writeln('  explain: ' . $this->explain($diagnostic));
                $this->renderFocusedGraph($diagnostic, $result->graphContext, $output);
            }
        }
    }

    private function renderFocusedGraph(
        RegistryDiagnostic $diagnostic,
        ?RegistryCompilationGraphContext $context,
        OutputInterface $output,
    ): void {
        if ($context === null) {
            return;
        }

        $target = $diagnostic->target;
        $bindings = \array_values(\array_filter(
            $context->bindings,
            static function (HandlerBindingDefinition $binding) use ($target): bool {
                if ($target === null) {
                    return false;
                }

                return ($target->bindingId !== null && $binding->bindingId === $target->bindingId)
                    || ($target->handlerClass !== null && $binding->action === $target->handlerClass)
                    || ($target->messageClass !== null && $binding->message === $target->messageClass);
            },
        ));

        $output->writeln('  graph: stage=' . $context->stage->value);

        if ($bindings === []) {
            $output->writeln('    diagnostic -> ' . ($target !== null ? $this->target($target) : 'registry'));

            return;
        }

        foreach ($bindings as $binding) {
            $messageName = $context->messageNames[$binding->message] ?? null;
            $message = $binding->message . ($messageName !== null ? ' name=' . $messageName : '');
            $flow = $context->flows?->all()[$binding->flow] ?? null;

            $output->writeln('    message ' . $message);
            $output->writeln(\sprintf(
                '      -> handler %s::%s binding=%s',
                $binding->action,
                $binding->method,
                $binding->bindingId ?? 'unresolved',
            ));
            $output->writeln('         -> flow ' . $binding->flow . ($flow !== null ? ' mode=' . $flow->mode->value : ''));

            if ($target?->middlewareClass !== null) {
                $output->writeln('         -> interceptor ' . $target->middlewareClass);
            }
        }
    }

    private function writeDefinition(MessageRegistryDefinition $definition, InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getOption('output');
        if (!\is_string($target) || $target === '') {
            throw new RuntimeException('registry:compile requires --output for successful compilation.');
        }

        $file = (new CompiledRegistryFileWriter())->write($definition, $target);
        $output->writeln('compiled=' . $file);

        return Command::SUCCESS;
    }

    private function options(InputInterface $input, MessageRegistryCompilerOptions $base): MessageRegistryCompilerOptions
    {
        $deprecations = $base->deprecations;
        $value = $input->getOption('deprecations');

        if ($value !== null) {
            $deprecations = DeprecationDiagnosticsMode::tryFrom((string) $value)
                ?? throw new RuntimeException('Invalid --deprecations value. Expected one of: ignore, warn, fail.');
        }

        return new MessageRegistryCompilerOptions(
            deprecations: $deprecations,
            failOnWarning: $base->failOnWarning || (bool) $input->getOption('fail-on-warning'),
        );
    }

    private function bootstrapPath(InputInterface $input): string
    {
        $path = $input->getOption('bootstrap');
        if (!\is_string($path) || $path === '' || !\is_file($path)) {
            throw new RuntimeException('registry:compile bootstrap file was not found. Pass --bootstrap.');
        }

        return $path;
    }

    private function origin(RegistryDiagnosticOrigin $origin, string $basePath): string
    {
        $parts = ['kind=' . $origin->kind->value];

        foreach ([
            'name' => $origin->name,
            'attribute' => $origin->attributeClass,
            'config' => $origin->configKey,
            'sourceRule' => $origin->sourceRule,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        if ($origin->file !== null) {
            $parts[] = 'file=' . $this->displayPath($origin->file, $basePath) . ($origin->line !== null ? ':' . $origin->line : '');
        }

        return \implode(' ', $parts);
    }

    private function target(RegistryDiagnosticTarget $target): string
    {
        $parts = [];
        foreach ([
            'bindingId' => $target->bindingId,
            'messageName' => $target->messageName,
            'messageClass' => $target->messageClass,
            'handlerClass' => $target->handlerClass,
            'method' => $target->method,
            'flow' => $target->flow,
            'interceptorClass' => $target->middlewareClass,
            'alias' => $target->alias,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        return $parts !== [] ? \implode(' ', $parts) : 'registry';
    }

    private function explain(RegistryDiagnostic $diagnostic): string
    {
        $source = $diagnostic->origin?->sourceRule
            ?? $diagnostic->origin?->kind->value
            ?? 'registry';
        $target = $diagnostic->target !== null ? $this->target($diagnostic->target) : 'registry';
        $parts = [$source, $diagnostic->code, $target];

        if ($diagnostic->hint !== null) {
            $parts[] = $diagnostic->hint;
        }

        return \implode(' -> ', $parts);
    }

    private function displayPath(string $file, string $basePath): string
    {
        $realBase = \realpath($basePath);
        $realFile = \realpath($file);

        if ($realBase === false || $realFile === false) {
            return $file;
        }

        $prefix = \rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (\str_starts_with($realFile, $prefix)) {
            return \substr($realFile, \strlen($prefix));
        }

        return $file;
    }
}
