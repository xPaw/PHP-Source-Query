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
 * Base socket interface
 *
 * @package xPaw\SourceQuery
 *
 * @uses InvalidPacketException
 * @uses SocketException
 */
abstract class BaseSocket
{
    /**
     * @var resource|null
     */
    public $Socket;

    /**
     * @var int $Engine
     */
    public int $Engine = SourceQuery::SOURCE;

    /**
     * @var string $Address
     */
    public string $Address = '';

    /**
     * @var int $Port
     */
    public int $Port = 0;

    /**
     * @var int $Timeout
     */
    public int $Timeout = 0;

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->Close();
    }

    /**
     * Close
     */
    abstract public function Close(): void;

    /**
     * @param string $Address
     * @param int $Port
     * @param int $Timeout
     * @param int $Engine
     */
    abstract public function Open(string $Address, int $Port, int $Timeout, int $Engine): void;

    /**
     * @param int $Header
     * @param string $String
     *
     * @return bool
     */
    abstract public function Write(int $Header, string $String = ''): bool;

    /**
     * @param int $Length
     *
     * @return Buffer
     */
    abstract public function Read(int $Length = 1400): Buffer;

    /**
     * @param Buffer $Buffer
     * @param int $Length
     * @param callable $SherlockFunction
     *
     * @return Buffer
     *
     * @throws InvalidPacketException
     * @throws SocketException
     */
    protected function ReadInternal(Buffer $Buffer, int $Length, callable $SherlockFunction): Buffer
    {
        if ($Buffer->Remaining() === 0) {
            throw new InvalidPacketException('Failed to read any data from socket', InvalidPacketException::BUFFER_EMPTY);
        }

        $Header = $Buffer->GetLong();

        // Single packet, do nothing.
        if ($Header === -1) {
            return $Buffer;
        }

        if ($Header === -2) { // Split packet
            $Packets      = [];
            $IsCompressed = false;
            $PacketChecksum = null;

            do {
                $RequestID = $Buffer->GetLong();

                switch ($this->Engine) {
                    case SourceQuery::GOLDSOURCE:
                    {
                        $PacketCountAndNumber = $Buffer->GetByte();
                        $PacketCount          = $PacketCountAndNumber & 0xF;
                        $PacketNumber         = $PacketCountAndNumber >> 4;

                        break;
                    }
                    case SourceQuery::SOURCE:
                    {
                        $IsCompressed         = ($RequestID & 0x80000000) !== 0;
                        $PacketCount          = $Buffer->GetByte();
                        $PacketNumber         = $Buffer->GetByte() + 1;

                        if ($IsCompressed) {
                            $Buffer->GetLong(); // Split size

                            $PacketChecksum = $Buffer->GetUnsignedLong();
                        } else {
                            $Buffer->GetShort(); // Split size
                        }

                        break;
                    }
                    default:
                    {
                        throw new SocketException('Unknown engine.', SocketException::INVALID_ENGINE);
                    }
                }

                $Packets[ $PacketNumber ] = $Buffer->Get();

                $ReadMore = $PacketCount > count($Packets);
            } while ($ReadMore && $SherlockFunction($Buffer, $Length));

            $Data = implode($Packets);

            // TODO: Test this
            if ($IsCompressed) {
                $Data = bzdecompress($Data);

                if (!is_string($Data) || crc32($Data) !== $PacketChecksum) {
                    throw new InvalidPacketException('CRC32 checksum mismatch of uncompressed packet data.', InvalidPacketException::CHECKSUM_MISMATCH);
                }
            }

            $Buffer->Set(substr($Data, 4));
        } else {
            throw new InvalidPacketException('Socket read: Raw packet header mismatch. (0x' . dechex($Header) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH);
        }

        return $Buffer;
    }
}
