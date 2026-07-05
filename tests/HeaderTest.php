<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Header;
use Wolfcharaa\MessageBus\Queue\QueueHeader;

final class HeaderTest extends TestCase
{
    public function testWithReplacesHeaderOfSameClass(): void
    {
        $header = (new Header(new QueueHeader()))
            ->with(QueueHeader::started());

        /** @var QueueHeader $queueHeader */
        $queueHeader = $header->get(QueueHeader::class);

        self::assertTrue($queueHeader->isStarted);
    }

    public function testMergeKeepsRuntimeHeaderPriority(): void
    {
        $defaultHeader = new Header(new QueueHeader());
        $runtimeHeader = new Header(QueueHeader::started());

        $header = $defaultHeader->merge($runtimeHeader);

        /** @var QueueHeader $queueHeader */
        $queueHeader = $header->get(QueueHeader::class);

        self::assertTrue($queueHeader->isStarted);
    }
}
