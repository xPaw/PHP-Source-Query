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

namespace xPaw\SourceQuery\Socket;

use xPaw\SourceQuery\Buffer;

/**
 * Base socket interface
 *
 * @package xPaw\SourceQuery\Socket
 */
interface SocketInterface
{
    /**
     * @return string
     */
    public function getAddress(): string;

    /**
     * @return int
     */
    public function getPort(): int;

    /**
     * @return resource
     */
    public function getSocket();

    /**
     * @return int
     */
    public function getTimeout(): int;

    /**
     * Get the socket type (goldsrc/src).
     */
    public function getType(): int;

    /**
     * @param string $address
     * @param int $port
     * @param int $timeout
     */
    public function open(string $address, int $port, int $timeout): void;

    /**
     * Close
     */
    public function close(): void;

    /**
     * @param int $length
     *
     * @return Buffer
     */
    public function read(int $length = 1400): Buffer;

    /**
     * @param int $header
     * @param string $string
     *
     * @return bool
     */
    public function write(int $header, string $string = ''): bool;
}
