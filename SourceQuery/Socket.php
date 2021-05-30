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

namespace xPaw\SourceQuery;

use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;

/**
 * Class Socket
 *
 * @package xPaw\SourceQuery
 *
 * @uses InvalidPacketException
 * @uses SocketException
 */
final class Socket extends BaseSocket
{
    /**
     * Close
     */
    public function Close(): void
    {
        if ($this->Socket !== null) {
            fclose($this->Socket);

            $this->Socket = 0;
        }
    }

    /**
     * @param string $Address
     * @param int $Port
     * @param int $Timeout
     * @param int $Engine
     *
     * @throws SocketException
     */
    public function Open(string $Address, int $Port, int $Timeout, int $Engine): void
    {
        $this->Timeout = $Timeout;
        $this->Engine  = $Engine;
        $this->Port    = $Port;
        $this->Address = $Address;

        $Socket = @fsockopen('udp://' . $Address, $Port, $ErrNo, $ErrStr, $Timeout);

        if ($ErrNo || $Socket === false) {
            throw new SocketException('Could not create socket: ' . $ErrStr, SocketException::COULD_NOT_CREATE_SOCKET);
        }

        $this->Socket = $Socket;
        stream_set_timeout($this->Socket, $Timeout);
        stream_set_blocking($this->Socket, true);
    }

    /**
     * @param int $Header
     * @param string $String
     *
     * @return bool
     */
    public function Write(int $Header, string $String = ''): bool
    {
        $Command = pack('ccccca*', 0xFF, 0xFF, 0xFF, 0xFF, $Header, $String);
        $Length  = strlen($Command);

        return $Length === fwrite($this->Socket, $Command, $Length);
    }

    /**
     * Reads from socket and returns Buffer.
     *
     * @param int $Length
     *
     * @return Buffer Buffer
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     */
    public function Read(int $Length = 1400): Buffer
    {
        $Buffer = new Buffer();
        $Buffer->Set(fread($this->Socket, $Length));

        $this->ReadInternal($Buffer, $Length, [ $this, 'Sherlock' ]);

        return $Buffer;
    }

    /**
     * @param Buffer $Buffer
     * @param int $Length
     *
     * @return bool
     *
     * @throws InvalidPacketException
     */
    public function Sherlock(Buffer $Buffer, int $Length): bool
    {
        $Data = fread($this->Socket, $Length);

        if (strlen($Data) < 4) {
            return false;
        }

        $Buffer->Set($Data);

        return $Buffer->GetLong() === -2;
    }
}
