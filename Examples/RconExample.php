<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Socket\SourceSocket;
use xPaw\SourceQuery\Socket\SocketType;

// For the sake of this example
header('Content-Type: text/plain');
header('X-Content-Type-Options: nosniff');

// Edit this ->
define('SQ_SERVER_ADDR', 'localhost');
define('SQ_SERVER_PORT', 27015);
define('SQ_TIMEOUT', 1);
define('SQ_ENGINE', SocketType::SOURCE);
// Edit this <-

$Query = new SourceQuery(new SourceSocket());

try {
    $Query->connect(SQ_SERVER_ADDR, SQ_SERVER_PORT, SQ_TIMEOUT, SQ_ENGINE);

    $Query->setRconPassword('my_awesome_password');

    var_dump($Query->rcon('say hello'));
} catch (Exception $e) {
    echo $e->getMessage();
} finally {
    $Query->disconnect();
}
