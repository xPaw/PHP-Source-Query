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
use xPaw\SourceQuery\Socket\SocketInterface;
use xPaw\SourceQuery\SourceQuery;

final class GoldSourceRcon extends AbstractRcon
{
    /**
     * Points to socket class.
     */
    private SocketInterface $socket;

    private string $rconPassword = '';

    private string $rconChallenge = '';

    public function __construct(SocketInterface $socket)
    {
        $this->socket = $socket;
    }

    /**
     * Open.
     */
    public function open(): void
    {
    }

    /**
     * Close.
     */
    public function close(): void
    {
        $this->rconChallenge = '';
        $this->rconPassword = '';
    }

    /**
     * @throws AuthenticationException
     */
    public function authorize(string $password): void
    {
        $this->rconPassword = $password;

        $this->write(null, 'challenge rcon');
        $buffer = $this->socket->read();

        if ('challenge rcon' !== $buffer->get(14)) {
            throw new AuthenticationException('Failed to get RCON challenge.', AuthenticationException::BAD_PASSWORD);
        }

        $this->rconChallenge = trim($buffer->get());
    }

    /**
     * @throws AuthenticationException
     * @throws InvalidPacketException
     */
    public function command(string $command): string
    {
        if (!$this->rconChallenge) {
            throw new AuthenticationException('Tried to execute a RCON command before successful authorization.', AuthenticationException::BAD_PASSWORD);
        }

        $this->write(null, 'rcon ' . $this->rconChallenge . ' "' . $this->rconPassword . '" ' . $command . "\0");
        $buffer = $this->read();

        return $buffer->get();
    }

    /**
     * @throws AuthenticationException
     * @throws InvalidPacketException
     */
    protected function read(): Buffer
    {
        // GoldSource RCON has same structure as Query.
        $buffer = $this->socket->read();

        $stringBuffer = '';

        // There is no identifier of the end, so we just need to continue reading.
        do {
            $readMore = !$buffer->isEmpty();

            if ($readMore) {
                if (SourceQuery::S2A_RCON !== $buffer->getByte()) {
                    throw new InvalidPacketException('Invalid rcon response.', InvalidPacketException::PACKET_HEADER_MISMATCH);
                }

                $packet = $buffer->get();
                $stringBuffer .= $packet;
                //$stringBuffer .= SubStr( $packet, 0, -2 );

                // Let's assume if this packet is not long enough, there are no more after this one.
                $readMore = strlen($packet) > 1000; // use 1300?

                if ($readMore) {
                    $buffer = $this->socket->read();
                }
            }
        } while ($readMore);

        $trimmed = trim($stringBuffer);

        if ('Bad rcon_password.' === $trimmed) {
            throw new AuthenticationException($trimmed, AuthenticationException::BAD_PASSWORD);
        }
        if ('You have been banned from this server.' === $trimmed) {
            throw new AuthenticationException($trimmed, AuthenticationException::BANNED);
        }

        $buffer->set($trimmed);

        return $buffer;
    }

    protected function write(?int $header, string $string = ''): bool
    {
        $command = pack('cccca*', 0xFF, 0xFF, 0xFF, 0xFF, $string);
        $length = strlen($command);

        return $length === fwrite($this->socket->getSocket(), $command, $length);
    }
}
