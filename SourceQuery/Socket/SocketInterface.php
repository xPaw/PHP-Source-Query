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
     * @return int
     */
    public function getTimeout(): int;

    /**
     * @return resource
     */
    public function getSocket();

    /**
     * Get the socket type (goldsrc/src).
     */
    public function getType(): int;

    /**
     * Close
     */
    public function close(): void;

    /**
     * @param string $address
     * @param int $port
     * @param int $timeout
     * @param int $engine
     */
    public function open(string $address, int $port, int $timeout, int $engine): void;

    /**
     * @param int $header
     * @param string $string
     *
     * @return bool
     */
    public function write(int $header, string $string = ''): bool;

    /**
     * @param int $length
     *
     * @return Buffer
     */
    public function read(int $length = 1400): Buffer;
}
