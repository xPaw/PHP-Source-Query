<?php

declare(strict_types=1);

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
     * Close
     */
    public function close(): void;

    /**
     * Open
     */
    public function open(): void;

    /**
     * @param string $command
     *
     * @return string
     *
     * @throws AuthenticationException
     * @throws InvalidPacketException
     */
    public function command(string $command): string;

    /**
     * @param string $password
     *
     * @throws AuthenticationException
     */
    public function authorize(string $password): void;
}
