<?php

declare(strict_types=1);

namespace xPaw\SourceQuery;

use xPaw\SourceQuery\Rcon\GoldSourceRcon;
use xPaw\SourceQuery\Rcon\SourceRcon;
use xPaw\SourceQuery\Socket\GoldSourceSocket;
use xPaw\SourceQuery\Socket\SourceSocket;

final class SourceQueryFactory
{
    public static function createGoldSourceQuery(): SourceQuery
    {
        $socket = new SourceSocket();
        $rcon = new SourceRcon($socket);

        return new SourceQuery($socket, $rcon);
    }

    public static function createSourceQuery(): SourceQuery
    {
        $socket = new GoldSourceSocket();
        $rcon = new GoldSourceRcon($socket);

        return new SourceQuery($socket, $rcon);
    }
}
