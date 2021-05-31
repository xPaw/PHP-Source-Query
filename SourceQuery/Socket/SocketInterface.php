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

namespace xPaw\SourceQuery\Socket;

use xPaw\SourceQuery\Buffer;

/**
 * Base socket interface.
 */
interface SocketInterface
{
    public function getAddress(): string;

    public function getPort(): int;

    /**
     * @return resource
     */
    public function getSocket();

    public function getTimeout(): int;

    /**
     * Get the socket type (goldsrc/src).
     */
    public function getType(): int;

    public function open(string $address, int $port, int $timeout): void;

    /**
     * Close.
     */
    public function close(): void;

    public function read(int $length = 1400): Buffer;

    public function write(int $header, string $string = ''): bool;
}
