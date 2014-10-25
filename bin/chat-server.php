<?php
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Talk2Me\Chat;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bin/config.php';

require_once("lib/Mysqldb.php");
$db = new Mysqldb($mysqlServer, $mysqlPort, $mysqlUsername, $mysqlPassword, $mysqlDatabase);

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Chat($db, $allowPersistentRooms)
        )
    ),
    $webSocketPort
);

$server->run();
