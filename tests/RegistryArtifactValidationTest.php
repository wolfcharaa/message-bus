<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Contract\Query;
use Wolfcharaa\MessageBus\Registry\RegistryArtifact;

final class RegistryArtifactValidationTest extends TestCase
{
    public function testArtifactBuildIdentityErrorsNameTheExactContract(): void
    {
        try {
            new RegistryArtifact('fingerprint', [], ' invalid build', 'hash');
            self::fail('Invalid application build ID was accepted.');
        } catch (\InvalidArgumentException $failure) {
            self::assertSame(
                'Registry artifact applicationBuildId must match [a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}.',
                $failure->getMessage(),
            );
        }

        $artifact = new RegistryArtifact('fingerprint', [], 'release-1', 'hash');

        try {
            $artifact->assertApplicationBuildId('release-2');
            self::fail('Registry artifact accepted another application build ID.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame(
                'Compiled registry artifact application build ID does not match.',
                $failure->getMessage(),
            );
        }
    }

    public function testMalformedJsonStructuresNameTheExactArtifactLocationAndExpectedShape(): void
    {
        $cases = [
            'root list' => [
                '[]',
                'Compiled registry artifact root must be a JSON object; got list.',
            ],
            'schema' => [
                '{"schema":2}',
                'Compiled registry artifact schema must be 1; got 2.',
            ],
            'root key' => [
                '{"0":"invalid","schema":1}',
                'Compiled registry artifact root key must be a string; got 0.',
            ],
            'registry fingerprint' => [
                '{"schema":1,"bindings":[],"application_build_id":null,"content_hash":"hash"}',
                'Compiled registry field must be a string: registry_fingerprint',
            ],
            'application build ID type' => [
                '{"schema":1,"registry_fingerprint":"fingerprint","bindings":[],"application_build_id":42,"content_hash":"hash"}',
                'Compiled registry field must be string|null: application_build_id',
            ],
            'bindings type' => [
                '{"schema":1,"registry_fingerprint":"fingerprint","bindings":"invalid","application_build_id":null,"content_hash":"hash"}',
                'Compiled registry bindings must be a list.',
            ],
            'binding shape' => [
                '{"schema":1,"registry_fingerprint":"fingerprint","bindings":[[]],"application_build_id":null,"content_hash":"hash"}',
                'Compiled registry binding #0 must be a JSON object; got list.',
            ],
            'binding field type' => [
                '{"schema":1,"registry_fingerprint":"fingerprint","bindings":[{"id":[]}],"application_build_id":null,"content_hash":"hash"}',
                'Compiled registry binding #0 field "id" must use a string key and bool|int|string|null value; got array.',
            ],
            'content hash' => [
                '{"schema":1,"registry_fingerprint":"fingerprint","bindings":[],"application_build_id":null,"content_hash":"invalid"}',
                'Compiled registry artifact hash does not match its content.',
            ],
        ];

        foreach ($cases as $case => [$json, $message]) {
            try {
                RegistryArtifact::fromJson($json);
                self::fail('Malformed registry artifact was accepted: ' . $case);
            } catch (\UnexpectedValueException $failure) {
                self::assertSame($message, $failure->getMessage(), $case);
            }
        }
    }

    public function testInvalidBindingReferencesNameTheBindingFieldAndValue(): void
    {
        $base = [
            'id' => ArtifactValidationQuery::class,
            'message_class' => ArtifactValidationQuery::class,
            'role' => 'query',
            'handler_class' => ArtifactValidationHandler::class,
            'handler_method' => '__invoke',
            'context_class' => null,
            'subscription_id' => null,
        ];
        $cases = [
            'missing required field' => [
                ['id' => 'binding'],
                'Compiled registry binding #0 field "message_class" must be a string; got null.',
            ],
            'message class' => [
                [...$base, 'message_class' => 'Application\MissingMessage'],
                'Compiled registry binding #0 message_class "Application\MissingMessage" must be an autoloadable class implementing Wolfcharaa\MessageBus\Contract\Message.',
            ],
            'handler class' => [
                [...$base, 'handler_class' => 'Application\MissingHandler'],
                'Compiled registry binding #0 handler_class "Application\MissingHandler" must be an autoloadable class.',
            ],
            'context class' => [
                [...$base, 'context_class' => \stdClass::class],
                'Compiled registry binding #0 context_class "stdClass" must be an autoloadable class implementing ' . MessageContext::class . '.',
            ],
            'role' => [
                [...$base, 'role' => 'invalid'],
                'Compiled registry binding #0 role "invalid" must be one of: query, request, command, event.',
            ],
        ];

        foreach ($cases as $case => [$binding, $message]) {
            $artifact = new RegistryArtifact('fingerprint', [$binding], null, 'unchecked-in-memory-hash');

            try {
                $artifact->registry();
                self::fail('Invalid registry binding was accepted: ' . $case);
            } catch (\UnexpectedValueException $failure) {
                self::assertSame($message, $failure->getMessage(), $case);
            }
        }
    }
}

/** @implements Query<string> */
final readonly class ArtifactValidationQuery implements Query {}

final readonly class ArtifactValidationHandler {}
