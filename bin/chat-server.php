<?php
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Talk2Me\Chat;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bin/config.php';

if ($cfg['allowPersistentRooms']) {
    $mongo = new Mongo($cfg['mongoHost']);
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Chat($cfg, $mongo)
        )
    ),
    $cfg['webSocketPort']
);

$server->run();
