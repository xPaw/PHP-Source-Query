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

namespace xPaw\SourceQuery\Rcon;

use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\Socket\SocketInterface;
use xPaw\SourceQuery\SourceQuery;

final class SourceRcon extends AbstractRcon
{
    /**
     * Points to socket class
     */
    private SocketInterface $socket;

    /**
     * @var ?resource
     */
    private $rconSocket;

    /**
     * @var int
     */
    private int $rconRequestId = 0;

    /**
     * @param SocketInterface $socket
     */
    public function __construct(SocketInterface $socket)
    {
        $this->socket = $socket;
    }

    /**
     * @throws SocketException
     */
    public function open(): void
    {
        if (!$this->rconSocket) {
            $rconSocket = @fsockopen(
                $this->socket->getAddress(),
                $this->socket->getPort(),
                $errNo,
                $errStr,
                $this->socket->getTimeout()
            );

            if ($errNo || !$rconSocket) {
                throw new SocketException('Can\'t connect to RCON server: ' . $errStr, SocketException::CONNECTION_FAILED);
            }

            $this->rconSocket = $rconSocket;
            stream_set_timeout($this->rconSocket, $this->socket->getTimeout());
            stream_set_blocking($this->rconSocket, true);
        }
    }

    /**
     * Close
     */
    public function close(): void
    {
        if ($this->rconSocket) {
            fclose($this->rconSocket);

            $this->rconSocket = null;
        }

        $this->rconRequestId = 0;
    }

    /**
     * @param string $password
     *
     * @throws AuthenticationException
     * @throws InvalidPacketException
     */
    public function authorize(string $password): void
    {
        $this->write(SourceQuery::SERVERDATA_AUTH, $password);
        $buffer = $this->read();

        $requestId = $buffer->getLong();
        $type = $buffer->getLong();

        // If we receive SERVERDATA_RESPONSE_VALUE, then we need to read again.
        // More info: https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Additional_Comments

        if ($type === SourceQuery::SERVERDATA_RESPONSE_VALUE) {
            $buffer = $this->read();

            $requestId = $buffer->getLong();
            $type = $buffer->getLong();
        }

        if ($requestId === -1 || $type !== SourceQuery::SERVERDATA_AUTH_RESPONSE) {
            throw new AuthenticationException('RCON authorization failed.', AuthenticationException::BAD_PASSWORD);
        }
    }

    /**
     * @param string $command
     *
     * @return string
     *
     * @throws AuthenticationException
     * @throws InvalidPacketException
     */
    public function command(string $command): string
    {
        $this->write(SourceQuery::SERVERDATA_EXECCOMMAND, $command);
        $buffer = $this->read();

        $buffer->getLong(); // RequestID

        $type = $buffer->getLong();

        if ($type === SourceQuery::SERVERDATA_AUTH_RESPONSE) {
            throw new AuthenticationException('Bad rcon_password.', AuthenticationException::BAD_PASSWORD);
        } elseif ($type !== SourceQuery::SERVERDATA_RESPONSE_VALUE) {
            throw new InvalidPacketException('Invalid rcon response.', InvalidPacketException::PACKET_HEADER_MISMATCH);
        }

        $data = $buffer->get();

        // We do this stupid hack to handle split packets.
        // See https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Multiple-packet_Responses
        if (strlen($data) >= 4000) {
            $this->write(SourceQuery::SERVERDATA_REQUESTVALUE);

            do {
                $buffer = $this->read();

                $buffer->getLong(); // RequestID.

                if ($buffer->getLong() !== SourceQuery::SERVERDATA_RESPONSE_VALUE) {
                    break;
                }

                $data2 = $buffer->get();

                if ($data2 === "\x00\x01\x00\x00\x00\x00") {
                    break;
                }

                $data .= $data2;
            } while (true);
        }

        return rtrim($data, "\0");
    }

    /**
     * @return Buffer
     *
     * @throws InvalidPacketException
     */
    protected function read(): Buffer
    {
        $buffer = new Buffer();
        $buffer->set(fread($this->rconSocket, 4));

        if ($buffer->remaining() < 4) {
            throw new InvalidPacketException('Rcon read: Failed to read any data from socket', InvalidPacketException::BUFFER_EMPTY);
        }

        $packetSize = $buffer->getLong();

        $buffer->set(fread($this->rconSocket, $packetSize));

        $data = $buffer->get();

        $remaining = $packetSize - strlen($data);

        while ($remaining > 0) {
            $data2 = fread($this->rconSocket, $remaining);

            $packetSize = strlen($data2);

            if ($packetSize === 0) {
                throw new InvalidPacketException('Read ' . strlen($data) . ' bytes from socket, ' . $remaining . ' remaining', InvalidPacketException::BUFFER_EMPTY);
            }

            $data .= $data2;
            $remaining -= $packetSize;
        }

        $buffer->set($data);

        return $buffer;
    }

    /**
     * @param int|null $header
     * @param string $string
     *
     * @return bool
     */
    protected function write(?int $header, string $string = ''): bool
    {
        // Pack the packet together.
        $command = pack('VV', ++$this->rconRequestId, $header) . $string . "\x00\x00";

        // Prepend packet length.
        $command = pack('V', strlen($command)) . $command;
        $length  = strlen($command);

        return $length === fwrite($this->rconSocket, $command, $length);
    }
}
