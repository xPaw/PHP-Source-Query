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
     * Points to socket class.
     */
    private SocketInterface $socket;

    /**
     * @var ?resource
     *
     * @psalm-var null|resource|closed-resource
     */
    private $rconSocket;

    private int $rconRequestId = 0;

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
     * Close.
     */
    public function close(): void
    {
        if (is_resource($this->rconSocket)) {
            fclose($this->rconSocket);

            $this->rconSocket = null;
        }

        $this->rconRequestId = 0;
    }

    /**
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

        if (SourceQuery::SERVERDATA_RESPONSE_VALUE === $type) {
            $buffer = $this->read();

            $requestId = $buffer->getLong();
            $type = $buffer->getLong();
        }

        if (-1 === $requestId || SourceQuery::SERVERDATA_AUTH_RESPONSE !== $type) {
            throw new AuthenticationException('RCON authorization failed.', AuthenticationException::BAD_PASSWORD);
        }
    }

    /**
     * @throws AuthenticationException
     * @throws InvalidPacketException
     */
    public function command(string $command): string
    {
        $this->write(SourceQuery::SERVERDATA_EXECCOMMAND, $command);
        $buffer = $this->read();

        $buffer->getLong(); // RequestID

        $type = $buffer->getLong();

        if (SourceQuery::SERVERDATA_AUTH_RESPONSE === $type) {
            throw new AuthenticationException('Bad rcon_password.', AuthenticationException::BAD_PASSWORD);
        }
        if (SourceQuery::SERVERDATA_RESPONSE_VALUE !== $type) {
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

                if (SourceQuery::SERVERDATA_RESPONSE_VALUE !== $buffer->getLong()) {
                    break;
                }

                $data2 = $buffer->get();

                if ("\x00\x01\x00\x00\x00\x00" === $data2) {
                    break;
                }

                $data .= $data2;
            } while (true);
        }

        return rtrim($data, "\0");
    }

    /**
     * @throws InvalidPacketException
     */
    protected function read(): Buffer
    {
        if (!is_resource($this->rconSocket)) {
            throw new InvalidPacketException('Rcon socket not open.');
        }

        $buffer = new Buffer();
        $socketData = fread($this->rconSocket, 4);

        if (!$socketData) {
            throw new InvalidPacketException('Empty data from packet.');
        }

        $buffer->set($socketData);

        if ($buffer->remaining() < 4) {
            throw new InvalidPacketException('Rcon read: Failed to read any data from socket', InvalidPacketException::BUFFER_EMPTY);
        }

        $packetSize = $buffer->getLong();

        $socketData = fread($this->rconSocket, $packetSize);

        if (!$socketData) {
            throw new InvalidPacketException('Empty data from packet.');
        }

        $buffer->set($socketData);

        $data = $buffer->get();

        $remaining = $packetSize - strlen($data);

        while ($remaining > 0) {
            $data2 = fread($this->rconSocket, $remaining);

            if (!$data2) {
                throw new InvalidPacketException('Empty data from packet.');
            }

            $packetSize = strlen($data2);

            if ($packetSize <= 0) {
                throw new InvalidPacketException('Read ' . strlen($data) . ' bytes from socket, ' . $remaining . ' remaining', InvalidPacketException::BUFFER_EMPTY);
            }

            $data .= $data2;
            $remaining -= $packetSize;
        }

        $buffer->set($data);

        return $buffer;
    }

    /**
     * @throws InvalidPacketException
     */
    protected function write(?int $header, string $string = ''): bool
    {
        if (!is_resource($this->rconSocket)) {
            throw new InvalidPacketException('Rcon socket not open.');
        }

        // Pack the packet together.
        $command = pack('VV', ++$this->rconRequestId, $header) . $string . "\x00\x00";

        // Prepend packet length.
        $command = pack('V', strlen($command)) . $command;
        $length = strlen($command);

        return $length === fwrite($this->rconSocket, $command, $length);
    }
}
