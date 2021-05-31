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

namespace xPaw\SourceQuery\Socket;

use SplQueue;
use SplDoublyLinkedList;
use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\InvalidPacketException;

final class TestableSocket extends AbstractSocket
{
    /**
     * @var SplQueue<string>
     */
    private SplQueue $packetQueue;

    /**
     * @var int
     */
    private int $type;

    /**
     * TestableSocket constructor.
     *
     * @param int $type
     */
    public function __construct(int $type)
    {
        $this->packetQueue = new SplQueue();
        $this->packetQueue->setIteratorMode(SplDoublyLinkedList::IT_MODE_DELETE);
        $this->type = $type;
    }

    /**
     * @return int
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * @param string $data
     */
    public function queue(string $data): void
    {
        $this->packetQueue->push($data);
    }

    /**
     * @param string $address
     * @param int $port
     * @param int $timeout
     */
    public function open(string $address, int $port, int $timeout): void
    {
        $this->timeout = $timeout;
        $this->port    = $port;
        $this->address = $address;
    }

    /**
     * Close.
     */
    public function close(): void
    {
    }

    /**
     * @param int $length
     *
     * @return Buffer
     *
     * @throws InvalidPacketException
     */
    public function read(int $length = 1400): Buffer
    {
        $buffer = new Buffer();
        $buffer->set($this->packetQueue->shift());

        $this->readInternal($buffer, $length, [ $this, 'sherlock' ]);

        return $buffer;
    }

    /**
     * @param int $header
     * @param string $string
     *
     * @return bool
     */
    public function write(int $header, string $string = ''): bool
    {
        return true;
    }

    /**
     * @param Buffer $buffer
     * @param int $length
     *
     * @return bool
     *
     * @throws InvalidPacketException
     */
    public function sherlock(Buffer $buffer, int $length): bool
    {
        if ($this->packetQueue->isEmpty()) {
            return false;
        }

        $buffer->set($this->packetQueue->shift());

        return $buffer->getLong() === -2;
    }

    /**
     * @param Buffer $buffer
     * @param int $count
     * @param int $number
     * @param bool $isCompressed
     * @param int|null $checksum
     *
     * @throws InvalidPacketException
     */
    protected function readInternalPacketData(
        Buffer $buffer,
        int &$count,
        int &$number,
        bool &$isCompressed,
        ?int &$checksum
    ): void {
        switch ($this->type) {
            case SocketType::GOLDSOURCE:
                $this->readInternalPacketDataGoldSource(
                    $buffer,
                    $count,
                    $number,
                    $isCompressed,
                    $checksum
                );

                break;

            case SocketType::SOURCE:
            default:
                $this->readInternalPacketDataSource(
                    $buffer,
                    $count,
                    $number,
                    $isCompressed,
                    $checksum
                );
        }
    }

    /**
     * Same as GoldSourceSocket::readInternalPacketData.
     *
     * @param Buffer $buffer
     * @param int $count
     * @param int $number
     * @param bool $isCompressed
     * @param int|null $checksum
     */
    private function readInternalPacketDataGoldSource(
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

    /**
     * Same as SourceSocket::readInternalPacketData.
     *
     * @param Buffer $buffer
     * @param int $count
     * @param int $number
     * @param bool $isCompressed
     * @param int|null $checksum
     *
     * @throws InvalidPacketException
     */
    private function readInternalPacketDataSource(
        Buffer $buffer,
        int &$count,
        int &$number,
        bool &$isCompressed,
        ?int &$checksum
    ): void {
        $count = $buffer->getByte();
        $number = $buffer->getByte() + 1;

        if ($isCompressed) {
            $buffer->getLong(); // Split size.

            $checksum = $buffer->getUnsignedLong();
        } else {
            $buffer->getShort(); // Split size.
        }
    }
}
