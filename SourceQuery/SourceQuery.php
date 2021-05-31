<?php

declare(strict_types=1);

/**
 * This class provides the public interface to the PHP-Source-Query library.
 *
 * @author Pavel Djundik
 *
 * @link https://xpaw.me
 * @link https://github.com/xPaw/PHP-Source-Query
 *
 * @license GNU Lesser General Public License, version 2.1
 */

namespace xPaw\SourceQuery;

use RuntimeException;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidArgumentException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\Rcon\GoldSourceRcon;
use xPaw\SourceQuery\Rcon\RconInterface;
use xPaw\SourceQuery\Rcon\SourceRcon;
use xPaw\SourceQuery\Socket\SocketInterface;
use xPaw\SourceQuery\Socket\SocketType;

final class SourceQuery
{
    /**
     * Packets sent
     */
    private const A2A_PING      = 0x69;
    private const A2S_INFO      = 0x54;
    private const A2S_PLAYER    = 0x55;
    private const A2S_RULES     = 0x56;
    private const A2S_SERVERQUERY_GETCHALLENGE = 0x57;

    /**
     * Packets received
     */
    private const A2A_ACK       = 0x6A;
    private const S2C_CHALLENGE = 0x41;
    private const S2A_INFO_SRC  = 0x49;
    private const S2A_INFO_OLD  = 0x6D; // Old GoldSource, HLTV uses it (actually called S2A_INFO_DETAILED).
    private const S2A_PLAYER    = 0x44;
    private const S2A_RULES     = 0x45;
    public const S2A_RCON      = 0x6C;

    /**
     * Source rcon sent
     */
    public const SERVERDATA_REQUESTVALUE   = 0;
    public const SERVERDATA_EXECCOMMAND    = 2;
    public const SERVERDATA_AUTH           = 3;

    /**
     * Source rcon received
     */
    public const SERVERDATA_RESPONSE_VALUE = 0;
    public const SERVERDATA_AUTH_RESPONSE  = 2;

    /**
     * Points to rcon class
     *
     * @var RconInterface|null
     */
    private ?RconInterface $rcon;

    /**
     * Points to socket class
     */
    private SocketInterface $socket;

    /**
     * True if connection is open, false if not
     */
    private bool $connected = false;

    /**
     * Contains challenge
     */
    private string $challenge = '';

    /**
     * Use old method for getting challenge number
     */
    private bool $useOldGetChallengeMethod = false;

    /**
     * @param SocketInterface $socket
     */
    public function __construct(SocketInterface $socket)
    {
        $this->socket = $socket;
        $this->rcon = null;
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Opens connection to server
     *
     * @param string $address Server ip
     * @param int $port Server port
     * @param int $timeout Timeout period
     *
     * @throws InvalidArgumentException
     */
    public function connect(string $address, int $port, int $timeout = 3): void
    {
        $this->disconnect();

        if ($timeout < 0) {
            throw new InvalidArgumentException('Timeout must be a positive integer.', InvalidArgumentException::TIMEOUT_NOT_INTEGER);
        }

        $this->socket->open($address, $port, $timeout);

        $this->connected = true;
    }

    /**
     * Forces GetChallenge to use old method for challenge retrieval because some games use outdated protocol (e.g Starbound)
     *
     * @param bool $value Set to true to force old method
     *
     * @return bool Previous value
     */
    public function SetUseOldGetChallengeMethod(bool $value): bool
    {
        $previous = $this->useOldGetChallengeMethod;

        $this->useOldGetChallengeMethod = $value === true;

        return $previous;
    }

    /**
     * Closes all open connections
     */
    public function disconnect(): void
    {
        $this->connected = false;
        $this->challenge = '';

        $this->socket->close();

        if ($this->rcon) {
            $this->rcon->close();

            $this->rcon = null;
        }
    }

    /**
     * Sends ping packet to the server
     * NOTE: This may not work on some games (TF2 for example)
     *
     * @throws SocketException
     *
     * @return bool True on success, false on failure
     */
    public function ping(): bool
    {
        if (!$this->connected) {
            throw new SocketException('Not connected.', SocketException::NOT_CONNECTED);
        }

        $this->socket->write(self::A2A_PING);
        $buffer = $this->socket->read();

        return $buffer->getByte() === self::A2A_ACK;
    }

    /**
     * Get server information
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @return array Returns an array with information on success
     */
    public function getInfo(): array
    {
        if (!$this->connected) {
            throw new SocketException('Not connected.', SocketException::NOT_CONNECTED);
        }

        if ($this->challenge) {
            $this->socket->write(self::A2S_INFO, "Source Engine Query\0" . $this->challenge);
        } else {
            $this->socket->write(self::A2S_INFO, "Source Engine Query\0");
        }

        $buffer = $this->socket->read();
        $type = $buffer->getByte();
        $server = [];

        if ($type === self::S2C_CHALLENGE) {
            $this->challenge = $buffer->get(4);

            $this->socket->write(self::A2S_INFO, "Source Engine Query\0" . $this->challenge);
            $buffer = $this->socket->read();
            $type = $buffer->getByte();
        }

        // Old GoldSource protocol, HLTV still uses it.
        if ($type === self::S2A_INFO_OLD && $this->socket->getType() === SocketType::GOLDSOURCE) {
            /**
             * If we try to read data again, and we get the result with type S2A_INFO (0x49)
             * That means this server is running dproto,
             * Because it sends answer for both protocols
             */

            $server[ 'Address' ]    = $buffer->getString();
            $server[ 'HostName' ]   = $buffer->getString();
            $server[ 'Map' ]        = $buffer->getString();
            $server[ 'ModDir' ]     = $buffer->getString();
            $server[ 'ModDesc' ]    = $buffer->getString();
            $server[ 'Players' ]    = $buffer->getByte();
            $server[ 'MaxPlayers' ] = $buffer->getByte();
            $server[ 'Protocol' ]   = $buffer->getByte();
            $server[ 'Dedicated' ]  = chr($buffer->getByte());
            $server[ 'Os' ]         = chr($buffer->getByte());
            $server[ 'Password' ]   = $buffer->getByte() === 1;
            $server[ 'IsMod' ]      = $buffer->getByte() === 1;

            if ($server[ 'IsMod' ]) {
                $Mod = [];
                $Mod[ 'Url' ]        = $buffer->getString();
                $Mod[ 'Download' ]   = $buffer->getString();
                $buffer->get(1); // NULL byte
                $Mod[ 'Version' ]    = $buffer->getLong();
                $Mod[ 'Size' ]       = $buffer->getLong();
                $Mod[ 'ServerSide' ] = $buffer->getByte() === 1;
                $Mod[ 'CustomDLL' ]  = $buffer->getByte() === 1;
                $server[ 'Mod' ] = $Mod;
            }

            $server[ 'Secure' ]   = $buffer->getByte() === 1;
            $server[ 'Bots' ]     = $buffer->getByte();

            return $server;
        }

        if ($type !== self::S2A_INFO_SRC) {
            throw new InvalidPacketException('GetInfo: Packet header mismatch. (0x' . dechex($type) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH);
        }

        $server[ 'Protocol' ]   = $buffer->getByte();
        $server[ 'HostName' ]   = $buffer->getString();
        $server[ 'Map' ]        = $buffer->getString();
        $server[ 'ModDir' ]     = $buffer->getString();
        $server[ 'ModDesc' ]    = $buffer->getString();
        $server[ 'AppID' ]      = $buffer->getShort();
        $server[ 'Players' ]    = $buffer->getByte();
        $server[ 'MaxPlayers' ] = $buffer->getByte();
        $server[ 'Bots' ]       = $buffer->getByte();
        $server[ 'Dedicated' ]  = chr($buffer->getByte());
        $server[ 'Os' ]         = chr($buffer->getByte());
        $server[ 'Password' ]   = $buffer->getByte() === 1;
        $server[ 'Secure' ]     = $buffer->getByte() === 1;

        // The Ship (they violate query protocol spec by modifying the response)
        if ($server[ 'AppID' ] === 2400) {
            $server[ 'GameMode' ]     = $buffer->getByte();
            $server[ 'WitnessCount' ] = $buffer->getByte();
            $server[ 'WitnessTime' ]  = $buffer->getByte();
        }

        $server[ 'Version' ] = $buffer->getString();

        // Extra Data Flags.
        if (!$buffer->isEmpty()) {
            $server[ 'ExtraDataFlags' ] = $Flags = $buffer->getByte();

            // S2A_EXTRA_DATA_HAS_GAME_PORT - Next 2 bytes include the game port.
            if ($Flags & 0x80) {
                $server[ 'GamePort' ] = $buffer->getShort();
            }

            // S2A_EXTRA_DATA_HAS_STEAMID - Next 8 bytes are the steamID.
            // Want to play around with this?
            // You can use https://github.com/xPaw/SteamID.php
            if ($Flags & 0x10) {
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

                $server[ 'SteamID' ] = $steamId;

                unset($steamIdLower, $steamIdInstance, $steamId);
            }

            // S2A_EXTRA_DATA_HAS_SPECTATOR_DATA - Next 2 bytes include the spectator port, then the spectator server name.
            if ($Flags & 0x40) {
                $server[ 'SpecPort' ] = $buffer->getShort();
                $server[ 'SpecName' ] = $buffer->getString();
            }

            // S2A_EXTRA_DATA_HAS_GAMETAG_DATA - Next bytes are the game tag string.
            if ($Flags & 0x20) {
                $server[ 'GameTags' ] = $buffer->getString();
            }

            // S2A_EXTRA_DATA_GAMEID - Next 8 bytes are the gameID of the server.
            if ($Flags & 0x01) {
                $server[ 'GameID' ] = $buffer->getUnsignedLong() | ($buffer->getUnsignedLong() << 32);
            }

            if (!$buffer->isEmpty()) {
                throw new InvalidPacketException(
                    'GetInfo: unread data? ' . $buffer->remaining() . ' bytes remaining in the buffer. Please report it to the library developer.',
                    InvalidPacketException::BUFFER_NOT_EMPTY
                );
            }
        }

        return $server;
    }

    /**
     * Get players on the server
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @return array Returns an array with players on success
     */
    public function getPlayers(): array
    {
        if (!$this->connected) {
            throw new SocketException('Not connected.', SocketException::NOT_CONNECTED);
        }

        $this->getChallenge(self::A2S_PLAYER, self::S2A_PLAYER);

        $this->socket->write(self::A2S_PLAYER, $this->challenge);
        $buffer = $this->socket->read(14000); // Moronic Arma 3 developers do not split their packets, so we have to read more data.
        // This violates the protocol spec, and they probably should fix it: https://developer.valvesoftware.com/wiki/Server_queries#Protocol

        $type = $buffer->getByte();

        if ($type !== self::S2A_PLAYER) {
            throw new InvalidPacketException('GetPlayers: Packet header mismatch. (0x' . dechex($type) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH);
        }

        $players = [];
        $count = $buffer->getByte();

        while ($count-- > 0 && !$buffer->isEmpty()) {
            $player = [];
            $player[ 'Id' ]    = $buffer->getByte(); // PlayerID, is it just always 0?
            $player[ 'Name' ]  = $buffer->getString();
            $player[ 'Frags' ] = $buffer->getLong();
            $player[ 'Time' ]  = (int)$buffer->getFloat();
            $player[ 'TimeF' ] = gmdate(($player[ 'Time' ] > 3600 ? 'H:i:s' : 'i:s'), $player[ 'Time' ]);

            $players[] = $player;
        }

        return $players;
    }

    /**
     * Get rules (cvars) from the server
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @return array Returns an array with rules on success
     */
    public function getRules(): array
    {
        if (!$this->connected) {
            throw new SocketException('Not connected.', SocketException::NOT_CONNECTED);
        }

        $this->getChallenge(self::A2S_RULES, self::S2A_RULES);

        $this->socket->write(self::A2S_RULES, $this->challenge);
        $buffer = $this->socket->read();

        $type = $buffer->getByte();

        if ($type !== self::S2A_RULES) {
            throw new InvalidPacketException('GetRules: Packet header mismatch. (0x' . dechex($type) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH);
        }

        $rules = [];
        $count = $buffer->getShort();

        while ($count-- > 0 && !$buffer->isEmpty()) {
            $rule = $buffer->getString();
            $value = $buffer->getString();

            if (!empty($rule)) {
                $rules[$rule] = $value;
            }
        }

        return $rules;
    }

    /**
     * Get challenge (used for players/rules packets)
     *
     * @param int $header
     * @param int $expectedResult
     *
     * @throws InvalidPacketException
     */
    private function getChallenge(int $header, int $expectedResult): void
    {
        if ($this->challenge) {
            return;
        }

        if ($this->useOldGetChallengeMethod) {
            $header = self::A2S_SERVERQUERY_GETCHALLENGE;
        }

        $this->socket->write($header, "\xFF\xFF\xFF\xFF");
        $buffer = $this->socket->read();

        $type = $buffer->getByte();

        switch ($type) {
            case self::S2C_CHALLENGE:
            {
                $this->challenge = $buffer->get(4);

                return;
            }
            case $expectedResult:
            {
                // Goldsource (HLTV).
                return;
            }
            case 0:
            {
                throw new InvalidPacketException('GetChallenge: Failed to get challenge.');
            }
            default:
            {
                throw new InvalidPacketException('GetChallenge: Packet header mismatch. (0x' . dechex($type) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH);
            }
        }
    }

    /**
     * Sets rcon password, for future use in Rcon()
     *
     * @param string $password Rcon Password
     *
     * @throws AuthenticationException
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function setRconPassword(string $password): void
    {
        if (!$this->connected) {
            throw new SocketException('Not connected.', SocketException::NOT_CONNECTED);
        }

        switch ($this->socket->getType()) {
            case SocketType::GOLDSOURCE:
            {
                $this->rcon = new GoldSourceRcon($this->socket);

                break;
            }
            case SocketType::SOURCE:
            {
                $this->rcon = new SourceRcon($this->socket);

                break;
            }
            default:
            {
                throw new SocketException('Unknown engine.', SocketException::INVALID_ENGINE);
            }
        }

        $this->rcon->open();
        $this->rcon->authorize($password);
    }

    /**
     * Sends a command to the server for execution.
     *
     * @param string $command Command to execute
     *
     * @return string Answer from server in string
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @throws AuthenticationException
     */
    public function rcon(string $command): string
    {
        if (!$this->connected) {
            throw new SocketException('Not connected.', SocketException::NOT_CONNECTED);
        }

        if ($this->rcon === null) {
            throw new SocketException('You must set a RCON password before trying to execute a RCON command.', SocketException::NOT_CONNECTED);
        }

        return $this->rcon->command($command);
    }
}
