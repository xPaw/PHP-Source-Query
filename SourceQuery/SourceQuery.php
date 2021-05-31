<?php

declare(strict_types=1);

/**
 * This class provides the public interface to the PHP-Source-Query library.
 *
 * @author Pavel Djundik
 *
 * @see https://xpaw.me
 * @see https://github.com/xPaw/PHP-Source-Query
 *
 * @license GNU Lesser General Public License, version 2.1
 */

namespace xPaw\SourceQuery;

use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidArgumentException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\QueryResponse\GoldSourceInfoQueryResponse;
use xPaw\SourceQuery\QueryResponse\PlayersQueryResponse;
use xPaw\SourceQuery\QueryResponse\RulesQueryResponse;
use xPaw\SourceQuery\QueryResponse\SourceInfoQueryResponse;
use xPaw\SourceQuery\Rcon\RconInterface;
use xPaw\SourceQuery\Socket\SocketInterface;
use xPaw\SourceQuery\Socket\SocketType;

final class SourceQuery
{
    /**
     * Packets sent.
     */
    private const A2A_PING = 0x69;
    private const A2S_INFO = 0x54;
    private const A2S_PLAYER = 0x55;
    private const A2S_RULES = 0x56;
    private const A2S_SERVERQUERY_GETCHALLENGE = 0x57;

    /**
     * Packets received.
     */
    private const A2A_ACK = 0x6A;
    private const S2C_CHALLENGE = 0x41;
    private const S2A_INFO_SRC = 0x49;
    private const S2A_INFO_OLD = 0x6D; // Old GoldSource, HLTV uses it (actually called S2A_INFO_DETAILED).
    private const S2A_PLAYER = 0x44;
    private const S2A_RULES = 0x45;
    public const S2A_RCON = 0x6C;

    /**
     * Source rcon sent.
     */
    public const SERVERDATA_REQUESTVALUE = 0;
    public const SERVERDATA_EXECCOMMAND = 2;
    public const SERVERDATA_AUTH = 3;

    /**
     * Source rcon received.
     */
    public const SERVERDATA_RESPONSE_VALUE = 0;
    public const SERVERDATA_AUTH_RESPONSE = 2;

    /**
     * Points to rcon class.
     */
    private RconInterface $rcon;

    /**
     * Points to socket class.
     */
    private SocketInterface $socket;

    /**
     * True if connection is open, false if not.
     */
    private bool $connected = false;

    /**
     * True if we have opened an Rcon connection, false if not.
     */
    private bool $rconConnected = false;

    /**
     * Contains challenge.
     */
    private string $challenge = '';

    /**
     * Use old method for getting challenge number.
     */
    private bool $useOldGetChallengeMethod = false;

    public function __construct(SocketInterface $socket, RconInterface $rcon)
    {
        $this->socket = $socket;
        $this->rcon = $rcon;
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Opens connection to server.
     *
     * @param string $address Server ip
     * @param int    $port    Server port
     * @param int    $timeout Timeout period
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
     * Closes all open connections.
     */
    public function disconnect(): void
    {
        $this->connected = false;
        $this->rconConnected = false;
        $this->challenge = '';

        $this->socket->close();
        $this->rcon->close();
    }

    /**
     * Forces GetChallenge to use old method for challenge retrieval because some games use outdated protocol (e.g Starbound).
     */
    public function setUseOldGetChallengeMethod(bool $value): void
    {
        $this->useOldGetChallengeMethod = $value;
    }

    /**
     * Sends ping packet to the server
     * NOTE: This may not work on some games (TF2 for example).
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

        return self::A2A_ACK === $buffer->getByte();
    }

    /**
     * Get server information.
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

        if (self::S2C_CHALLENGE === $type) {
            $this->challenge = $buffer->get(4);

            $this->socket->write(self::A2S_INFO, "Source Engine Query\0" . $this->challenge);
            $buffer = $this->socket->read();
            $type = $buffer->getByte();
        }

        // Old GoldSource protocol, HLTV still uses it.
        if (self::S2A_INFO_OLD === $type && SocketType::GOLDSOURCE === $this->socket->getType()) {
            return GoldSourceInfoQueryResponse::fromBuffer($buffer);
        }

        if (self::S2A_INFO_SRC !== $type) {
            throw new InvalidPacketException('GetInfo: Packet header mismatch. (0x' . dechex($type) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH);
        }

        return SourceInfoQueryResponse::fromBuffer($buffer);
    }

    /**
     * Get players on the server.
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

        if (self::S2A_PLAYER !== $type) {
            throw new InvalidPacketException('GetPlayers: Packet header mismatch. (0x' . dechex($type) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH);
        }

        return PlayersQueryResponse::fromBuffer($buffer);
    }

    /**
     * Get rules (cvars) from the server.
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

        if (self::S2A_RULES !== $type) {
            throw new InvalidPacketException('GetRules: Packet header mismatch. (0x' . dechex($type) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH);
        }

        return RulesQueryResponse::fromBuffer($buffer);
    }

    /**
     * Sets rcon password, for future use in Rcon().
     *
     * @param string $password Rcon Password
     *
     * @throws AuthenticationException
     * @throws SocketException
     */
    public function setRconPassword(string $password): void
    {
        if (!$this->connected) {
            throw new SocketException('Not connected.', SocketException::NOT_CONNECTED);
        }

        $this->rcon->open();
        $this->rcon->authorize($password);

        $this->rconConnected = true;
    }

    /**
     * Sends a command to the server for execution.
     *
     * @param string $command Command to execute
     *
     * @throws InvalidPacketException
     * @throws SocketException
     * @throws AuthenticationException
     *
     * @return string Answer from server in string
     */
    public function rcon(string $command): string
    {
        if (!$this->connected) {
            throw new SocketException('Not connected.', SocketException::NOT_CONNECTED);
        }

        if (!$this->rconConnected) {
            throw new SocketException('You must set a RCON password before trying to execute a RCON command.', SocketException::NOT_CONNECTED);
        }

        return $this->rcon->command($command);
    }

    /**
     * Get challenge (used for players/rules packets).
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
                $this->challenge = $buffer->get(4);

                return;

            case $expectedResult:
                // Goldsource (HLTV).
                return;

            case 0:
                throw new InvalidPacketException('GetChallenge: Failed to get challenge.');
            default:
                throw new InvalidPacketException('GetChallenge: Packet header mismatch. (0x' . dechex($type) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH);
        }
    }
}
