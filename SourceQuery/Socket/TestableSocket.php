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

namespace xPaw\SourceQuery\Socket;

use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\InvalidPacketException;

final class TestableSocket extends AbstractSocket
{
    /**
     * @var string[]
     */
    private array $packetQueue;

    private int $type;

    /**
     * TestableSocket constructor.
     */
    public function __construct(int $type)
    {
        $this->packetQueue = [];
        $this->type = $type;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function queue(string $data): void
    {
        $this->packetQueue[] = $data;
    }

    public function open(string $address, int $port, int $timeout): void
    {
        $this->timeout = $timeout;
        $this->port = $port;
        $this->address = $address;
    }

    /**
     * Close.
     */
    public function close(): void
    {
    }

    /**
     * @throws InvalidPacketException
     */
    public function read(int $length = 1400): Buffer
    {
        $buffer = new Buffer();

        $packet = array_shift($this->packetQueue);

        if (!$packet) {
            throw new InvalidPacketException('Empty packet');
        }

        $buffer->set($packet);

        $this->readInternal($buffer, $length, [$this, 'sherlock']);

        return $buffer;
    }

    public function write(int $header, string $string = ''): bool
    {
        return true;
    }

    /**
     * @throws InvalidPacketException
     */
    public function sherlock(Buffer $buffer, int $length): bool
    {
        if (0 === count($this->packetQueue)) {
            return false;
        }

        $buffer->set(array_shift($this->packetQueue));

        return -2 === $buffer->getLong();
    }

    /**
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
