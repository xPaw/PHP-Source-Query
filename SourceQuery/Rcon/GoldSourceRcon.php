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
use xPaw\SourceQuery\Socket\SocketInterface;
use xPaw\SourceQuery\SourceQuery;

final class GoldSourceRcon extends AbstractRcon
{
    /**
     * Points to socket class
     *
     * @var SocketInterface
     */
    private SocketInterface $socket;

    /**
     * @var string
     */
    private string $rconPassword = '';

    /**
     * @var string
     */
    private string $rconChallenge = '';

    /**
     * @param SocketInterface $socket
     */
    public function __construct(SocketInterface $socket)
    {
        $this->socket = $socket;
    }

    /**
     * Open
     */
    public function open(): void
    {
    }

    /**
     * Close
     */
    public function close(): void
    {
        $this->rconChallenge = '';
        $this->rconPassword  = '';
    }

    /**
     * @param string $password
     *
     * @throws AuthenticationException
     */
    public function authorize(string $password): void
    {
        $this->rconPassword = $password;

        $this->write(null, 'challenge rcon');
        $buffer = $this->socket->read();

        if ($buffer->get(14) !== 'challenge rcon') {
            throw new AuthenticationException('Failed to get RCON challenge.', AuthenticationException::BAD_PASSWORD);
        }

        $this->rconChallenge = trim($buffer->get());
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
     *
     * @return Buffer
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
                if ($buffer->getByte() !== SourceQuery::S2A_RCON) {
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

        if ($trimmed === 'Bad rcon_password.') {
            throw new AuthenticationException($trimmed, AuthenticationException::BAD_PASSWORD);
        } elseif ($trimmed === 'You have been banned from this server.') {
            throw new AuthenticationException($trimmed, AuthenticationException::BANNED);
        }

        $buffer->set($trimmed);

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
        $command = pack('cccca*', 0xFF, 0xFF, 0xFF, 0xFF, $string);
        $length = strlen($command);

        return $length === fwrite($this->socket->getSocket(), $command, $length);
    }
}
