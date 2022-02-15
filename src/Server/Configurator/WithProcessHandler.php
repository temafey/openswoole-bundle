<?php

declare(strict_types=1);

namespace K911\Swoole\Server\Configurator;

use K911\Swoole\Server\Configurator\ConfiguratorInterface;
use K911\Swoole\Server\HttpServer;
use K911\Swoole\Server\Process\PipeHandlerInterface;
use Swoole\Event;
use Swoole\Http\Server;
use Swoole\Process;
use Swoole\Timer;

class WithProcessHandler implements ConfiguratorInterface
{
    private $httpServer;
    private $processes;
    private $handler;

    public function __construct(iterable $processes, PipeHandlerInterface $handler, HttpServer $httpServer) {
        $this->httpServer = $httpServer;
        $this->processes = $processes;
        $this->handler = $handler;
    }

    public function configure(Server $server): void
    {
        foreach($this->processes as $processDefinitionInstance) {
            $processDefinitionName = get_class($processDefinitionInstance);

            $userWorker = new Process(function(Process $userWorker) use ($processDefinitionInstance, $server) {
                go(function() use ($processDefinitionInstance) {
                    $processDefinitionInstance->run();
                });

                // run sidecar coroutine to process incoming data from pipe
                go(function() use ($server, $userWorker) {
                    while ($data = $userWorker->read()) {
                        $data = unserialize($data);
                        $this->handler->handle($server, $data[0], $data[1]);
                    }
                });
            });

            $userWorker->name($processDefinitionName);

            $server->addProcess($userWorker);

            $this->httpServer->addUserWorker($userWorker);
        }
    }
}
