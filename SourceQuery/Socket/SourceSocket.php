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
use xPaw\SourceQuery\Exception\InvalidPacketException;

final class SourceSocket extends AbstractSocket
{
    /**
     * @return int
     */
    public function getType(): int
    {
        return SocketType::SOURCE;
    }

    /**
     * @param Buffer $buffer
     * @param int $count
     * @param int $number
     * @param bool $isCompressed
     * @param int|null $checksum
     *
     * @throws InvalidPacketException
     */
    protected function readInternalPacketData(
        Buffer $buffer,
        int &$count,
        int &$number,
        bool &$isCompressed,
        ?int &$checksum
    ): void {
        $count = $buffer->getByte();
        $number = $buffer->getByte() + 1;

        if ($isCompressed) {
            $buffer->getLong(); // Split size.

            $checksum = $buffer->getUnsignedLong();
        } else {
            $buffer->getShort(); // Split size.
        }
    }
}
