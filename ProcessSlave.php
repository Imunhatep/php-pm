<?php

namespace PHPPM;

use React\Http\Request;
use React\Http\Response;
use React\Socket\ConnectionException;
use Rephp\LoopEvent\SchedulerLoop;
use Rephp\Scheduler\SystemCall;
use Rephp\Scheduler\Task;
use Rephp\Server\Server;
use Rephp\Socket\Socket;

class ProcessSlave
{

    /**
     * @var \React\EventLoop\LibEventLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

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

    /**
     * @var integer
     */
    protected $port;

    function __construct($port, $bridgeName = null, $appBootstrap, $appenv)
    {
        $this->port = $port;
        $this->bridgeName = $bridgeName;

        $this->loop = new SchedulerLoop();
        $this->bootstrap($appBootstrap, $appenv);
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

    protected function connectToMaster()
    {
        //---------------------
        $client = stream_socket_client('tcp://127.0.0.1:5500');
        $this->connection = new Socket($client, $this->loop);

        $this->connection->on(
            'close',
            \Closure::bind(
                function () {
                    $this->shutdown();
                },
                $this
            )
        );

        $this->connection->write(json_encode(['cmd' => 'register', 'pid' => getmypid(), 'port' => $this->port]));
    }

    function listenHttpServer()
    {
        $server = new Server($this->loop);
        $http = new \React\Http\Server($server);

        $http->on('request', array($this, 'onRequest'));

        $maxPort = $this->port + 99;
        while ($this->port < $maxPort) {
            try {
                $server->listen($this->port);
                break;
            }
            catch (ConnectionException $e) {
                $this->port++;
            }
        }

        $this->connectToMaster();
        $this->loop->run();
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
