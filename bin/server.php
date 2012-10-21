<?php
use Iris\WebSocket\WebSocketServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

    require dirname(__DIR__) . '/vendor/autoload.php';

    $server = IoServer::factory(
        new WsServer(
            new WebSocketServer()
        )
      , 8045
    );

    $server->run();