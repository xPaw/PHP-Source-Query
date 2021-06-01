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
use xPaw\SourceQuery\EngineType;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Socket\Traits\GoldSourcePacketDataTrait;
use xPaw\SourceQuery\Socket\Traits\SourcePacketDataTrait;

final class TestableSocket extends AbstractSocket
{
    use GoldSourcePacketDataTrait {
        GoldSourcePacketDataTrait::readInternalPacketData as readInternalPacketDataGoldSource;
    }

    use SourcePacketDataTrait {
        SourcePacketDataTrait::readInternalPacketData as readInternalPacketDataSource;
    }

    /**
     * @var string[]
     */
    private array $packetQueue;

    private string $type;

    /**
     * TestableSocket constructor.
     */
    public function __construct(string $type = EngineType::SOURCE)
    {
        $this->packetQueue = [];
        $this->type = $type;
    }

    public function getType(): string
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

    protected function readInternalPacketData(
        Buffer $buffer,
        int &$count,
        int &$number,
        bool &$isCompressed,
        ?int &$checksum
    ): void {
        switch ($this->type) {
            case EngineType::GOLDSOURCE:
                $this->readInternalPacketDataGoldSource(
                    $buffer,
                    $count,
                    $number,
                    $isCompressed,
                    $checksum
                );

                break;

            case EngineType::SOURCE:
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
}
