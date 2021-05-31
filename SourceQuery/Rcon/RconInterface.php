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

use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Socket\SocketInterface;

interface RconInterface
{
    public function __construct(SocketInterface $socket);

    /**
     * Open.
     */
    public function open(): void;

    /**
     * Close.
     */
    public function close(): void;

    /**
     * @throws AuthenticationException
     */
    public function authorize(string $password): void;

    /**
     * @throws AuthenticationException
     * @throws InvalidPacketException
     */
    public function command(string $command): string;
}
