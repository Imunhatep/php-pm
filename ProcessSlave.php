<?php
namespace PHPPM;

use PHPPM\Bootstraps\BootstrapInterface;
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
     * Memory limit in MB
     *
     * @var int
     */
    protected $memoryLimit;

    /**
     * Time in seconds
     *
     * @var int
     */
    protected $memoryCheckTime;

    /** @var string */
    protected $host;

    /** @var integer */
    protected $port;

    /**
     * @param string             $host
     * @param int                $port
     * @param string             $bridgeName
     * @param string             $appBootstrap
     * @param string             $appEnv
     * @param int                $memoryLimit
     * @param int                $memoryCheckTime
     */
    public function __construct($host, $port, $bridgeName, $appBootstrap, $appEnv, $memoryLimit, $memoryCheckTime)
    {
        $this->host = $host;
        $this->port = $port;
        $this->bridgeName = $bridgeName;
        $this->memoryCheckTime = $memoryCheckTime;
        $this->memoryLimit = $memoryLimit;

        $this->loop = new SchedulerLoop();
        $this->bootstrap($appBootstrap, $appEnv);
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
        $client = @stream_socket_client('tcp://127.0.0.1:5500');
        if (!$client) {
            throw new ConnectionException('Slave process stream opening failed.');
        }

        $this->connection = new Socket($client, $this->loop);
        $this->connection->on(
            'close',
            \Closure::bind( function () { $this->shutdown(); }, $this )
        );

        $this->connection->write(json_encode(['cmd' => 'register', 'pid' => getmypid(), 'port' => $this->port]));
    }

    function listenHttpServer()
    {
        $server = new Server($this->loop);
        $http = new \React\Http\Server($server);
        $http->on('request', [$this, 'onRequest']);

        $maxPort = $this->port + 99;
        while ($this->port < $maxPort) {
            try {
                $server->listen($this->port, $this->host);
                break;
            }
            catch (ConnectionException $e) {
                $this->port++;
            }
        }

        $this->connectToMaster();
        $this->addMemoryChecker();
        $this->loop->run();
    }

    /**
     * if memory is exceeded restart slave
     */
    private function addMemoryChecker()
    {
        if ($this->memoryCheckTime > 0) {
            $this->loop->addPeriodicTimer(
                $this->memoryCheckTime,
                function () {
                    $mb = memory_get_usage(true) / 1024 / 1024;
                    if ($mb >= $this->memoryLimit) {
                        $this->bye();
                    }
                }
            );
        }
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
            $this->connection->write(json_encode(['cmd' => 'unregister', 'pid' => getmypid()]));
            $this->connection->close();
        }
        $this->loop->stop();
    }
}
