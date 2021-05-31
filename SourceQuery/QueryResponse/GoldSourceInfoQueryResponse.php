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

final class GoldSourceInfoQueryResponse
{
    /**
     * @throws InvalidPacketException
     */
    public static function fromBuffer(Buffer $buffer): array
    {
        $server = [];

        /*
         * If we try to read data again, and we get the result with type S2A_INFO (0x49).
         * That means this server is running dproto, because it sends answer for both protocols.
         */
        $server['Address'] = $buffer->getString();
        $server['HostName'] = $buffer->getString();
        $server['Map'] = $buffer->getString();
        $server['ModDir'] = $buffer->getString();
        $server['ModDesc'] = $buffer->getString();
        $server['Players'] = $buffer->getByte();
        $server['MaxPlayers'] = $buffer->getByte();
        $server['Protocol'] = $buffer->getByte();
        $server['Dedicated'] = $buffer->getChar();
        $server['Os'] = $buffer->getChar();
        $server['Password'] = $buffer->getBool();
        $server['IsMod'] = $buffer->getBool();

        if ($server['IsMod']) {
            $mod = [];
            $mod['Url'] = $buffer->getString();
            $mod['Download'] = $buffer->getString();
            $buffer->get(1); // NULL byte
            $mod['Version'] = $buffer->getLong();
            $mod['Size'] = $buffer->getLong();
            $mod['ServerSide'] = $buffer->getBool();
            $mod['CustomDLL'] = $buffer->getBool();

            $server['Mod'] = $mod;
        }

        $server['Secure'] = $buffer->getBool();
        $server['Bots'] = $buffer->getByte();

        return $server;
    }
}
