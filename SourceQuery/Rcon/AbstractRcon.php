<?php

declare(strict_types=1);

namespace xPaw\SourceQuery\Rcon;

use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;

abstract class AbstractRcon implements RconInterface
{
    /**
     * @param int|null $header
     * @param string $string
     *
     * @return bool
     */
    abstract protected function write(?int $header, string $string = ''): bool;

    /**
     * @throws AuthenticationException
     * @throws InvalidPacketException
     *
     * @return Buffer
     */
    abstract protected function read(): Buffer;
}
