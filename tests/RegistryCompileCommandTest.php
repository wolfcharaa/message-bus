<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Attribute\QueryHandler;
use Wolfcharaa\MessageBus\Cli\Command\RegistryCompileCommand;
use Wolfcharaa\MessageBus\Cli\RegistryCompileInput;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompilerOptions;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationResult;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationGraphContext;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryValidationRuleInterface;

final class RegistryCompileCommandTest extends TestCase
{
    public function testCompileCommandCanBuildRegistryFromCompileInputBootstrap(): void
    {
        $bootstrap = $this->bootstrap('return \\' . RegistryCompileCommandFactory::class . '::successInput();');
        $target = $this->targetFile();

        try {
            $tester = new CommandTester(new RegistryCompileCommand());
            $exitCode = $tester->execute([
                '--bootstrap' => $bootstrap,
                '--output' => $target,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('compiled=' . $target, $tester->getDisplay());

            $registry = CompiledMessageRegistry::fromFile($target);
            self::assertSame('cli-success', $registry->definition()->sourceHash);
            self::assertSame(MessageRegistryCompiler::LIBRARY_VERSION, $registry->definition()->libraryVersion);
            self::assertCount(1, $registry->bindingsForMessage(RegistryCompileCommandSuccessMessage::class));
        } finally {
            @\unlink($bootstrap);
            @\unlink($target);
        }
    }

    public function testCompileCommandAcceptsPrecompiledDiagnosticsResult(): void
    {
        $bootstrap = $this->bootstrap('return \\' . RegistryCompileCommandFactory::class . '::successResult();');
        $target = $this->targetFile();

        try {
            $tester = new CommandTester(new RegistryCompileCommand());
            $exitCode = $tester->execute([
                '--bootstrap' => $bootstrap,
                '--output' => $target,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertFileExists($target);
        } finally {
            @\unlink($bootstrap);
            @\unlink($target);
        }
    }

    public function testCompileCommandExplainsDiagnosticsWithoutWritingRegistry(): void
    {
        $bootstrap = $this->bootstrap('return \\' . RegistryCompileCommandFactory::class . '::invalidInput();');
        $target = $this->targetFile();

        try {
            $tester = new CommandTester(new RegistryCompileCommand());
            $exitCode = $tester->execute([
                '--bootstrap' => $bootstrap,
                '--output' => $target,
                '--explain' => true,
                '--base-path' => \dirname(__DIR__),
            ]);

            $display = $tester->getDisplay();

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('error registry.handler.invalid_signature:', $display);
            self::assertStringContainsString('origin:', $display);
            self::assertStringContainsString('target:', $display);
            self::assertStringContainsString('file=tests/RegistryCompileCommandTest.php', $display);
            self::assertStringContainsString('explain: message_bus.registry.handler_signature -> registry.handler.invalid_signature', $display);
            self::assertStringContainsString('graph: stage=core_validated', $display);
            self::assertStringContainsString('-> handler ' . RegistryCompileCommandInvalidHandler::class . '::__invoke', $display);
            self::assertStringContainsString('-> flow default mode=sync', $display);
            self::assertStringContainsString('registry.compile_failed errors=1 warnings=0 stage=core_validated', $display);
            self::assertFileDoesNotExist($target);
        } finally {
            @\unlink($bootstrap);
            @\unlink($target);
        }
    }

    public function testCompileCommandExplainsMessageHandlerMismatchGraph(): void
    {
        $bootstrap = $this->bootstrap('return \\' . RegistryCompileCommandFactory::class . '::mismatchInput();');
        $target = $this->targetFile();

        try {
            $tester = new CommandTester(new RegistryCompileCommand());
            $exitCode = $tester->execute([
                '--bootstrap' => $bootstrap,
                '--output' => $target,
                '--explain' => true,
                '--base-path' => \dirname(__DIR__),
            ]);

            $display = $tester->getDisplay();

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('First argument of `' . RegistryCompileCommandMismatchedHandler::class . '::__invoke` must accept `' . RegistryCompileCommandExpectedMessage::class . '`.', $display);
            self::assertStringContainsString('target: bindingId=', $display);
            self::assertStringContainsString('messageClass=' . RegistryCompileCommandExpectedMessage::class, $display);
            self::assertStringContainsString('handlerClass=' . RegistryCompileCommandMismatchedHandler::class, $display);
            self::assertStringContainsString('graph: stage=core_validated', $display);
            self::assertStringContainsString('message ' . RegistryCompileCommandExpectedMessage::class, $display);
            self::assertStringContainsString('-> handler ' . RegistryCompileCommandMismatchedHandler::class . '::__invoke', $display);
            self::assertFileDoesNotExist($target);
        } finally {
            @\unlink($bootstrap);
            @\unlink($target);
        }
    }


    public function testCompileCommandCanFailOnProjectWarning(): void
    {
        $bootstrap = $this->bootstrap('return \\' . RegistryCompileCommandFactory::class . '::warningInput();');
        $target = $this->targetFile();

        try {
            $tester = new CommandTester(new RegistryCompileCommand());
            $exitCode = $tester->execute([
                '--bootstrap' => $bootstrap,
                '--output' => $target,
                '--fail-on-warning' => true,
            ]);

            $display = $tester->getDisplay();

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('warning project.cli.warning: Project CLI warning.', $display);
            self::assertStringContainsString('registry.compile_failed errors=0 warnings=1', $display);
            self::assertFileDoesNotExist($target);
        } finally {
            @\unlink($bootstrap);
            @\unlink($target);
        }
    }

    private function bootstrap(string $body): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-registry-compile-');
        self::assertIsString($file);
        \file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");

        return $file;
    }

    private function targetFile(): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-compiled-registry-');
        self::assertIsString($file);
        @\unlink($file);

        return $file . '.php';
    }
}

final class RegistryCompileCommandFactory
{
    public static function successInput(): RegistryCompileInput
    {
        return new RegistryCompileInput(
            new ClassListProvider([
                RegistryCompileCommandSuccessMessage::class,
                RegistryCompileCommandSuccessHandler::class,
            ]),
            sourceHash: 'cli-success',
        );
    }

    public static function successResult(): RegistryCompilationResult
    {
        $input = self::successInput();

        return $input->compiler()->compileWithDiagnostics(
            $input->provider,
            $input->flows,
            $input->libraryVersion,
            $input->sourceHash,
            $input->options,
        );
    }

    public static function invalidInput(): RegistryCompileInput
    {
        return new RegistryCompileInput(new ClassListProvider([
            RegistryCompileCommandInvalidMessage::class,
            RegistryCompileCommandInvalidHandler::class,
        ]));
    }

    public static function mismatchInput(): RegistryCompileInput
    {
        return new RegistryCompileInput(new ClassListProvider([
            RegistryCompileCommandExpectedMessage::class,
            RegistryCompileCommandWrongMessage::class,
            RegistryCompileCommandMismatchedHandler::class,
        ]));
    }

    public static function warningInput(): RegistryCompileInput
    {
        return new RegistryCompileInput(
            new ClassListProvider([
                RegistryCompileCommandSuccessMessage::class,
                RegistryCompileCommandSuccessHandler::class,
            ]),
            validationRules: [new RegistryCompileCommandWarningRule()],
        );
    }

}

final readonly class RegistryCompileCommandSuccessMessage
{
    public function __construct(public string $value = 'ok')
    {
    }
}

#[QueryHandler(message: RegistryCompileCommandSuccessMessage::class)]
final class RegistryCompileCommandSuccessHandler
{
    public function __invoke(RegistryCompileCommandSuccessMessage $message, MessageContextInterface $context): string
    {
        return $message->value;
    }
}

final class RegistryCompileCommandInvalidMessage
{
}

#[CommandHandler(message: RegistryCompileCommandInvalidMessage::class)]
final class RegistryCompileCommandInvalidHandler
{
    public function __invoke(RegistryCompileCommandInvalidMessage $message): string
    {
        return 'invalid';
    }
}

final class RegistryCompileCommandExpectedMessage
{
}

final class RegistryCompileCommandWrongMessage
{
}

#[CommandHandler(message: RegistryCompileCommandExpectedMessage::class)]
final class RegistryCompileCommandMismatchedHandler
{
    public function __invoke(RegistryCompileCommandWrongMessage $message, MessageContextInterface $context): void
    {
    }
}

final class RegistryCompileCommandWarningRule implements RegistryValidationRuleInterface
{
    public function validate(RegistryCompilationGraphContext $context): iterable
    {
        yield RegistryDiagnostic::warning('project.cli.warning', 'Project CLI warning.');
    }
}
