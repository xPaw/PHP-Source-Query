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

trait GoldSourcePacketDataTrait
{
    protected function readInternalPacketData(
        Buffer $buffer,
        int &$count,
        int &$number,
        bool &$isCompressed,
        ?int &$checksum
    ): void {
        $packetCountAndNumber = $buffer->getByte();
        $count = $packetCountAndNumber & 0xF;
        $number = $packetCountAndNumber >> 4;
        $isCompressed = false;
    }
}
