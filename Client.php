<?php

namespace PHPPM;

use React\Socket\ConnectionException;
use Rephp\LoopEvent\SchedulerLoop;
use Rephp\Socket\Socket;

class Client
{
    /**
     * @var int
     */
    protected $controllerPort = 5100;

    /**
     * @var \React\EventLoop\ExtEventLoop|\React\EventLoop\LibEventLoop|\React\EventLoop\LibEvLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    /**
     * @var \React\Socket\Connection
     */
    protected $connection;

    function __construct($controllerPort = 8080)
    {
        $this->controllerPort = $controllerPort;
        $this->loop = new SchedulerLoop();
    }

    /**
     * @return \React\Socket\Connection|Socket
     * @throws ConnectionException
     */
    protected function getConnection()
    {
        if ($this->connection) {
            $this->connection->close();
            unset($this->connection);
        }

        $client = @stream_socket_client('tcp://127.0.0.1:'.$this->controllerPort);
        if (!$client) {
            throw new ConnectionException('Client stream opening have failed.');
        }

        $this->connection = new Socket($client, $this->loop);

        return $this->connection;
    }

    protected function request($command, $options, $callback)
    {
        $data['cmd'] = $command;
        $data['options'] = $options;
        $connection = $this->getConnection();
        $loop = $this->loop;

        $connection->on( 'data', function ($data) use ($callback) { $callback($data); } );
        $connection->on( 'end', function () use ($loop) { $loop->stop(); } );
        $connection->on( 'error', function () use ($loop) { $loop->stop(); } );
        $connection->write(json_encode($data));

        $this->loop->run();
    }

    public function getStatus(callable $callback)
    {
        $this->request( 'status', [], function ($result) use ($callback) { $callback(json_decode($result, true)); } );
    }

    public function restart(callable $callback)
    {
        $this->request('restart', [], $callback);
    }

    public function stop($callback)
    {
        $this->request('stop', [], $callback);
    }
}