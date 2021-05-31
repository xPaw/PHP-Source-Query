<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use xPaw\SourceQuery\Socket\SourceSocket;
use xPaw\SourceQuery\SourceQuery;

// For the sake of this example
header('Content-Type: text/plain');
header('X-Content-Type-Options: nosniff');

$query = new SourceQuery(new SourceSocket());

try {
    $query->connect('localhost', 27015, 1);

    print_r($query->getInfo());
    print_r($query->getPlayers());
    print_r($query->getRules());
} catch (Exception $exception) {
    echo $exception->getMessage();
} finally {
    $query->disconnect();
}
