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

use RuntimeException;
use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\InvalidPacketException;

final class SourceInfoQueryResponse
{
    /**
     * @throws InvalidPacketException
     */
    public static function fromBuffer(Buffer $buffer): array
    {
        $server = [];

        $server['Protocol'] = $buffer->getByte();
        $server['HostName'] = $buffer->getString();
        $server['Map'] = $buffer->getString();
        $server['ModDir'] = $buffer->getString();
        $server['ModDesc'] = $buffer->getString();
        $server['AppID'] = $buffer->getShort();
        $server['Players'] = $buffer->getByte();
        $server['MaxPlayers'] = $buffer->getByte();
        $server['Bots'] = $buffer->getByte();
        $server['Dedicated'] = $buffer->getChar();
        $server['Os'] = $buffer->getChar();
        $server['Password'] = $buffer->getBool();
        $server['Secure'] = $buffer->getBool();

        // The Ship (they violate query protocol spec by modifying the response)
        if (2400 === $server['AppID']) {
            $server['GameMode'] = $buffer->getByte();
            $server['WitnessCount'] = $buffer->getByte();
            $server['WitnessTime'] = $buffer->getByte();
        }

        $server['Version'] = $buffer->getString();

        if ($buffer->isEmpty()) {
            return $server;
        }

        $flags = $buffer->getByte();

        $server['ExtraDataFlags'] = $flags;

        // S2A_EXTRA_DATA_HAS_GAME_PORT - Next 2 bytes include the game port.
        if ($flags & 0x80) {
            $server['GamePort'] = $buffer->getShort();
        }

        // S2A_EXTRA_DATA_HAS_STEAMID - Next 8 bytes are the steamID.
        // Want to play around with this?
        // You can use https://github.com/xPaw/SteamID.php
        if ($flags & 0x10) {
            $steamIdLower = $buffer->getUnsignedLong();
            $steamIdInstance = $buffer->getUnsignedLong(); // This gets shifted by 32 bits, which should be steamid instance.

            if (PHP_INT_SIZE === 4) {
                if (extension_loaded('gmp')) {
                    $steamIdLower = gmp_abs($steamIdLower);
                    $steamIdInstance = gmp_abs($steamIdInstance);
                    $steamId = gmp_strval(gmp_or($steamIdLower, gmp_mul($steamIdInstance, gmp_pow(2, 32))));
                } else {
                    throw new RuntimeException('Either 64-bit PHP installation or "gmp" module is required to correctly parse server\'s steamid.');
                }
            } else {
                $steamId = $steamIdLower | ($steamIdInstance << 32);
            }

            $server['SteamID'] = $steamId;

            unset($steamIdLower, $steamIdInstance, $steamId);
        }

        // S2A_EXTRA_DATA_HAS_SPECTATOR_DATA - Next 2 bytes include the spectator port, then the spectator server name.
        if ($flags & 0x40) {
            $server['SpecPort'] = $buffer->getShort();
            $server['SpecName'] = $buffer->getString();
        }

        // S2A_EXTRA_DATA_HAS_GAMETAG_DATA - Next bytes are the game tag string.
        if ($flags & 0x20) {
            $server['GameTags'] = $buffer->getString();
        }

        // S2A_EXTRA_DATA_GAMEID - Next 8 bytes are the gameID of the server.
        if ($flags & 0x01) {
            $server['GameID'] = $buffer->getUnsignedLong() | ($buffer->getUnsignedLong() << 32);
        }

        if (!$buffer->isEmpty()) {
            throw new InvalidPacketException('GetInfo: unread data? ' . $buffer->remaining() . ' bytes remaining in the buffer. Please report it to the library developer.', InvalidPacketException::BUFFER_NOT_EMPTY);
        }

        return $server;
    }
}
