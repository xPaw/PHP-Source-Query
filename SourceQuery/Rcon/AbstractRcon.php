<?php

declare(strict_types=1);

/**
 * @author Pavel Djundik
 *
 * @link https://xpaw.me
 * @link https://github.com/xPaw/PHP-Source-Query
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
     *
     * @return Buffer
     */
    abstract protected function read(): Buffer;

    /**
     * @param int|null $header
     * @param string $string
     *
     * @return bool
     */
    abstract protected function write(?int $header, string $string = ''): bool;
}
