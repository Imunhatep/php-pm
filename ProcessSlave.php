<?php

namespace PHPPM;

use React\Http\Request;
use React\Http\Response;
use React\Socket\ConnectionException;
use Rephp\LoopEvent\SchedulerLoop;
use Rephp\Server\Server;
use Rephp\Socket\Socket;

class ProcessSlave
{

    /**
     * @var \React\EventLoop\LibEventLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    /**
     * @var resource
     */
    protected $client;

    /**
     * @var \React\Socket\Connection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $bridgeName;

    /**
     * @var Bridges\BridgeInterface
     */
    protected $bridge;

    /**
     * @var string|null
     */
    protected $appenv;

    function __construct($bridgeName = null, $appBootstrap, $appenv)
    {
        $this->bridgeName = $bridgeName;
        $this->bootstrap($appBootstrap, $appenv);
        $this->connectToMaster();
        $this->loop->run();
    }

    protected function shutdown()
    {
        echo "SHUTTING SLAVE PROCESS DOWN\n";
        $this->bye();
        exit;
    }

    /**
     * @return Bridges\BridgeInterface
     */
    protected function getBridge()
    {
        if (null === $this->bridge && $this->bridgeName) {
            if (true === class_exists($this->bridgeName)) {
                $bridgeClass = $this->bridgeName;
            }
            else {
                $bridgeClass = sprintf('\\PHPPM\\Bridges\\%s', ucfirst($this->bridgeName));
            }

            $this->bridge = new $bridgeClass;
        }

        return $this->bridge;
    }

    protected function bootstrap($appBootstrap, $appenv)
    {
        if ($bridge = $this->getBridge()) {
            $bridge->bootstrap($appBootstrap, $appenv);
        }
    }

    function connectToMaster()
    {
        $this->loop = new SchedulerLoop();

        //---------------------
        $this->client = stream_socket_client('tcp://127.0.0.1:5500');
        $this->connection = new Socket($this->client, $this->loop);

        $this->connection->on('close', \Closure::bind(function () { $this->shutdown(); }, $this) );

        $socket = new Server($this->loop);
        $http = new \React\Http\Server($socket);

        $http->on('request', array($this, 'onRequest'));

        $port = 5501;
        while ($port < 5600) {
            try {
                $socket->listen($port);
                break;
            }
            catch (ConnectionException $e) {
                $port++;
            }
        }
        $this->loop->run();

        $this->connection->write(json_encode(array('cmd' => 'register', 'pid' => getmypid(), 'port' => $port)));
    }


    function onRequest(Request $request, Response $response)
    {
        if ($bridge = $this->getBridge()) {
            return $bridge->onRequest($request, $response);
        }
        else {
            $response->writeHead('404');
            $response->end('No Bridge Defined.');
        }
    }

    function bye()
    {
        if ($this->connection->isWritable()) {
            $this->connection->write(json_encode(array('cmd' => 'unregister', 'pid' => getmypid())));
            $this->connection->close();
        }
        $this->loop->stop();
    }
}
