<?php

declare(strict_types=1);

namespace xPaw\SourceQuery\Socket;

use xPaw\SourceQuery\Buffer;

final class GoldSourceSocket extends AbstractSocket
{
    /**
     * @return int
     */
    public function getType(): int
    {
        return SocketType::GOLDSOURCE;
    }

    /**
     * @param Buffer $buffer
     * @param int $count
     * @param int $number
     * @param bool $isCompressed
     * @param int|null $checksum
     */
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
