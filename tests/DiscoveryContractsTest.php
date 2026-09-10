<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Discovery\ChainClassProvider;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Discovery\ComposerClassMapProvider;
use Wolfcharaa\MessageBus\Discovery\Psr4DirectoryClassProvider;

final class DiscoveryContractsTest extends TestCase
{
    public function testChainClassProviderYieldsUniqueClassesInProviderOrder(): void
    {
        $provider = new ChainClassProvider(
            new ClassListProvider([DiscoveryFirstFixture::class, DiscoverySecondFixture::class]),
            new ClassListProvider([DiscoveryFirstFixture::class, DiscoveryThirdFixture::class]),
        );

        self::assertSame([
            DiscoveryFirstFixture::class,
            DiscoverySecondFixture::class,
            DiscoveryThirdFixture::class,
        ], \iterator_to_array($provider->classes(), false));
    }

    public function testComposerClassMapProviderFiltersByNamespacePrefix(): void
    {
        $classMap = $this->tempFile('classmap-', '<?php return ' . \var_export([
            DiscoveryFirstFixture::class => __FILE__,
            'Other\\Namespace\\Skipped' => __FILE__,
            DiscoverySecondFixture::class => __FILE__,
        ], true) . ';');

        try {
            $provider = new ComposerClassMapProvider($classMap, [__NAMESPACE__ . '\\Discovery']);

            self::assertSame([
                DiscoveryFirstFixture::class,
                DiscoverySecondFixture::class,
            ], \iterator_to_array($provider->classes(), false));
        } finally {
            @\unlink($classMap);
        }
    }

    public function testComposerClassMapProviderIgnoresMissingAndInvalidFiles(): void
    {
        $invalidMap = $this->tempFile('classmap-invalid-', '<?php return "bad";');

        try {
            self::assertSame([], \iterator_to_array((new ComposerClassMapProvider('/tmp/message-bus-missing-map.php'))->classes(), false));
            self::assertSame([], \iterator_to_array((new ComposerClassMapProvider($invalidMap))->classes(), false));
        } finally {
            @\unlink($invalidMap);
        }
    }

    public function testPsr4DirectoryClassProviderYieldsExistingClassesOnly(): void
    {
        $directory = \sys_get_temp_dir() . '/message-bus-psr4-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($directory));

        $fixture = $directory . '/Psr4Fixture.php';
        $ignored = $directory . '/Ignored.txt';
        \file_put_contents($fixture, "<?php\n\nnamespace Wolfcharaa\\MessageBus\\Tests\\Psr4Fixture;\n\nfinal class Psr4Fixture {}\n");
        \file_put_contents($ignored, 'ignored');
        require_once $fixture;

        try {
            $provider = new Psr4DirectoryClassProvider([
                __NAMESPACE__ . '\\Psr4Fixture\\' => $directory,
                __NAMESPACE__ . '\\Missing\\' => $directory . '/missing',
            ]);

            self::assertSame([
                __NAMESPACE__ . '\\Psr4Fixture\\Psr4Fixture',
            ], \iterator_to_array($provider->classes(), false));
        } finally {
            @\unlink($ignored);
            @\unlink($fixture);
            @\rmdir($directory);
        }
    }

    private function tempFile(string $prefix, string $contents): string
    {
        $file = \tempnam(\sys_get_temp_dir(), $prefix);
        self::assertIsString($file);
        \file_put_contents($file, $contents);

        return $file;
    }
}

final class DiscoveryFirstFixture
{
}

final class DiscoverySecondFixture
{
}

final class DiscoveryThirdFixture
{
}
