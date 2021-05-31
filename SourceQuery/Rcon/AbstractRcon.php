<?php

declare(strict_types=1);

/**
 * @author Pavel Djundik
 *
 * @see https://xpaw.me
 * @see https://github.com/xPaw/PHP-Source-Query
 *
 * @license GNU Lesser General Public License, version 2.1
 *
 * @internal
 */

namespace xPaw\SourceQuery\Rcon;

use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;

abstract class AbstractRcon implements RconInterface
{
    /**
     * @throws AuthenticationException
     * @throws InvalidPacketException
     */
    abstract protected function read(): Buffer;

    abstract protected function write(?int $header, string $string = ''): bool;
}
