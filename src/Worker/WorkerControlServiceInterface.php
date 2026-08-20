<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

interface WorkerControlServiceInterface
{
    public function send(WorkerControlCommand $command): WorkerControlCommandReceipt;

    public function pause(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt;

    public function resume(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt;

    public function drain(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt;

    public function stop(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt;

    public function kill(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt;

    public function restart(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt;
}
