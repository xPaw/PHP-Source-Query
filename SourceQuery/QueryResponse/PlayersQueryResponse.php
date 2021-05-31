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

namespace xPaw\SourceQuery\QueryResponse;

use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\InvalidPacketException;

class PlayersQueryResponse
{
    /**
     * @throws InvalidPacketException
     */
    public static function fromBuffer(Buffer $buffer): array
    {
        $players = [];
        $count = $buffer->getByte();

        while ($count-- > 0 && !$buffer->isEmpty()) {
            $player = [];
            $player['Id'] = $buffer->getByte(); // PlayerID, is it just always 0?
            $player['Name'] = $buffer->getString();
            $player['Frags'] = $buffer->getLong();
            $player['Time'] = (int) $buffer->getFloat();
            $player['TimeF'] = gmdate(($player['Time'] > 3600 ? 'H:i:s' : 'i:s'), $player['Time']);

            $players[] = $player;
        }

        return $players;
    }
}
