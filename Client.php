<?php

namespace PHPPM;

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
     * @return \React\Socket\Connection
     */
    protected function getConnection()
    {
        if ($this->connection) {
            $this->connection->close();
            unset($this->connection);
        }
        $client = stream_socket_client('tcp://127.0.0.1:5500');
        $this->connection = new Socket($client, $this->loop);
        return $this->connection;
    }

    protected function request($command, $options, $callback)
    {
        $data['cmd'] = $command;
        $data['options'] = $options;
        $connection = $this->getConnection();

        $result = '';
        $connection->on('data', function($data) use ($result) {
            $result .= $data;
        });

        $connection->on('close', function() use ($callback, $result) {
            $callback($result);
        });

        $connection->write(json_encode($data));
    }

    public function getStatus(callable $callback)
    {
        $this->request('status', [], function($result) use ($callback) {
            $callback(json_decode($result, true));
        });
    }

}