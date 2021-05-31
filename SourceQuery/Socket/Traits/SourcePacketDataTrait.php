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

namespace xPaw\SourceQuery\Socket\Traits;

use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\InvalidPacketException;

trait SourcePacketDataTrait
{
    /**
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
