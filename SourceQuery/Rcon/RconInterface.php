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

use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Socket\SocketInterface;

interface RconInterface
{
    /**
     * @param SocketInterface $socket
     */
    public function __construct(SocketInterface $socket);

    /**
     * Open
     */
    public function open(): void;

    /**
     * Close
     */
    public function close(): void;

    /**
     * @param string $password
     *
     * @throws AuthenticationException
     */
    public function authorize(string $password): void;

    /**
     * @param string $command
     *
     * @return string
     *
     * @throws AuthenticationException
     * @throws InvalidPacketException
     */
    public function command(string $command): string;
}
