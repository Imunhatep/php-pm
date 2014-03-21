<?php

namespace PHPPM;

use React\Socket\ConnectionInterface;
use Rephp\LoopEvent\SchedulerLoop;
use Rephp\Scheduler\SystemCall;
use Rephp\Scheduler\Task;
use Rephp\Server\Server;

class ProcessManager
{
    /**
     * @var array
     */
    protected $slaves = [];

    /**
     * @var \React\EventLoop\LibEventLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    /**
     * @var \React\Socket\Server
     */
    protected $controller;

    /**
     * @var \React\Socket\Server
     */
    protected $web;

    /**
     * @var int
     */
    protected $slaveCount = 1;

    /**
     * @var bool
     */
    protected $waitForSlaves = true;

    /**
     * Whether the server is up and thus creates new slaves when they die or not.
     *
     * @var bool
     */
    protected $run = false;

    /**
     * @var int
     */
    protected $index = 0;

    /**
     * @var string
     */
    protected $bridge;

    /**
     * @var string
     */
    protected $appBootstrap;

    /**
     * @var string|null
     */
    protected $appenv;

    /**
     * @var int
     */
    protected $port = 8080;

    function __construct($port = 8080, $slaveCount = 8)
    {
        $this->slaveCount = $slaveCount;
        $this->port = $port;
    }

    public function fork()
    {
        if ($this->run) {
            throw new \LogicException('Can not fork when already run.');
        }

        if (!pcntl_fork()) {
            $this->run();
        }
        else {
            die('pcntl_fork function failed!');
        }
    }

    /**
     * @param string $bridge
     */
    public function setBridge($bridge)
    {
        $this->bridge = $bridge;
    }

    /**
     * @return string
     */
    public function getBridge()
    {
        return $this->bridge;
    }

    /**
     * @param string $appBootstrap
     */
    public function setAppBootstrap($appBootstrap)
    {
        $this->appBootstrap = $appBootstrap;
    }

    /**
     * @return string
     */
    public function getAppBootstrap()
    {
        return $this->appBootstrap;
    }

    /**
     * @param string|null $appenv
     */
    public function setAppEnv($appenv)
    {
        $this->appenv = $appenv;
    }

    /**
     * @return string
     */
    public function getAppEnv()
    {
        return $this->appenv;
    }

    // till react 0.4.1, #https://github.com/reactphp/react/issues/275
    public function run()
    {
        $this->loop = new SchedulerLoop();
        $this->controller = new Server($this->loop);
        $this->controller->on('connection', array($this, 'onSlaveConnection'));
        $this->controller->listen(5500);

        $this->loop->add($this->newInstanceTask(), 'MakeInstance');

        $this->run = true;
        $this->loop->run();
    }

    public function run2()
    {
        $this->loop = \React\EventLoop\Factory::create();
        $this->controller = new \React\Socket\Server($this->loop);
        $this->controller->on('connection', array($this, 'onSlaveConnection'));
        $this->controller->listen(5500);

        for ($i = 0; $i < $this->slaveCount; $i++) {
            $this->newInstance();
        }

        $this->run = true;
        $this->loop->run();
    }

    function startWebServer()
    {
        $this->web = new \React\Socket\Server($this->loop);
        $this->web->on('connection', array($this, 'onWeb'));
        $this->web->listen($this->port);
    }

    function onWeb(ConnectionInterface $incoming)
    {
        $slaveId = $this->getNextSlave();
        $port = $this->slaves[$slaveId]['port'];
        $client = stream_socket_client('tcp://localhost:' . $port);
        $redirect = new \React\Stream\Stream($client, $this->loop);

        $redirect->on(
            'close',
            function () use ($incoming) {
                $incoming->end();
            }
        );

        $incoming->on(
            'data',
            function ($data) use ($redirect) {
                $redirect->write($data);
            }
        );

        $redirect->on(
            'data',
            function ($data) use ($incoming) {
                $incoming->write($data);
            }
        );
    }

    /**
     * @return integer
     */
    protected function getNextSlave()
    {
        $count = count($this->slaves);

        $this->index++;
        if ($count === $this->index) {
            //end
            $this->index = 0;
        }

        return $this->index;
    }

    public function onSlaveConnection(ConnectionInterface $conn)
    {
        $conn->on(
            'data',
            \Closure::bind(
                function ($data) use ($conn) {
                    error_log($data);
                    $this->onData($data, $conn);
                },
                $this
            )
        );
        $conn->on(
            'close',
            \Closure::bind(
                function () use ($conn) {
                    foreach ($this->slaves as $idx => $slave) {
                        if ($slave['connection'] === $conn) {
                            unset($this->slaves[$idx]);
                            $this->checkSlaves();
                        }
                    }
                },
                $this
            )
        );
    }

    public function onData($data, $conn)
    {
        $this->processMessage($data, $conn);
    }

    public function processMessage($data, $conn)
    {
        $data = json_decode($data, true);

        $method = 'command' . ucfirst($data['cmd']);
        if (is_callable(array($this, $method))) {
            $this->$method($data, $conn);
        }
    }

    protected function commandStatus($options, $conn)
    {
        $result['activeSlaves'] = count($this->slaves);
        $conn->end(json_encode($result));
    }

    protected function commandRegister(array $data, $conn)
    {
        $pid = (int)$data['pid'];
        $port = (int)$data['port'];
        $this->slaves[] = array(
            'pid' => $pid,
            'port' => $port,
            'connection' => $conn
        );

        if ($this->waitForSlaves && $this->slaveCount === count($this->slaves)) {
            $slaves = array();
            foreach ($this->slaves as $slave) {
                $slaves[] = $slave['port'];
            }

            echo sprintf("%d slaves (%s) up and ready.\n", $this->slaveCount, implode(', ', $slaves));
        }
    }

    protected function commandUnregister(array $data)
    {
        $pid = (int)$data['pid'];
        echo sprintf("Slave died. (pid %d)\n", $pid);
        foreach ($this->slaves as $idx => $slave) {
            if ($slave['pid'] === $pid) {
                unset($this->slaves[$idx]);
                $this->checkSlaves();
            }
        }
        $this->checkSlaves();
    }

    protected function checkSlaves()
    {
        if (!$this->run) {
            return;
        }

        $i = count($this->slaves);
        if ($this->slaveCount !== $i) {
            echo sprintf('Boot %d new slaves ... ', $this->slaveCount - $i);
            $this->waitForSlaves = true;
            for (; $i < $this->slaveCount; $i++) {
                $this->newInstance();
            }
        }
    }

    function newInstanceTask()
    {
        yield;
        for ($i = 1; $i <= $this->slaveCount; $i++) {
            yield $this->newInstance();
        }
    }

    function newInstance()
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            die('could not fork');
        }
        else if ($pid) {
            // we are parent
            //echo "Started slave pid: $pid\n";
        }
        else{
            // we are the child
            $child = new ProcessSlave($this->getBridge(), $this->appBootstrap, $this->appenv);
            $child->listenHttpServer();
            exit;
        }
    }
}
