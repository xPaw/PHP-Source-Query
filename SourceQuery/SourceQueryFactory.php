<?php

declare(strict_types=1);

namespace xPaw\SourceQuery;

use xPaw\SourceQuery\Socket\GoldSourceSocket;
use xPaw\SourceQuery\Socket\SourceSocket;

final class SourceQueryFactory
{
    public static function createGoldSourceQuery(): SourceQuery
    {
        return new SourceQuery(new SourceSocket());
    }

    public static function createSourceQuery(): SourceQuery
    {
        return new SourceQuery(new GoldSourceSocket());
    }
}
