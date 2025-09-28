<?php
/**
 * This file is part of Source Query
 *
 * (c) xPaw
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace xPaw\SourceQuery;

use xPaw\SourceQuery\Exception\InvalidArgumentException;
use xPaw\SourceQuery\Exception\SocketException;

class SourceQuery
{
    /**
     * Engines
     */
    public const GOLDSOURCE = 0;
    public const SOURCE     = 1;

    /**
     * Packets
     */
    public const A2S_PING     = 0x69;
    public const A2S_INFO     = 0x54;
    public const A2S_PLAYER   = 0x55;
    public const A2S_RULES    = 0x56;
    public const A2S_SERVERQUERY_GETCHALLENGE = 0x57;

    /**
     * Responses
     */
    public const S2A_PING     = 0x6A;
    public const S2A_CHALLENGE = 0x41;
    public const S2A_INFO     = 0x49;
    public const S2A_INFO_OLD = 0x6D;
    public const S2A_PLAYER   = 0x44;
    public const S2A_RULES    = 0x45;

    /**
     * @var BaseSocket
     */
    private BaseSocket $Socket;

    /**
     * @var Buffer
     */
    private Buffer $Buffer;

    /**
     * @var int
     */
    private int $Engine;

    /**
     * @param ?BaseSocket $Socket  (nullable explicitly declared for PHP 8.1+)
     */
    public function __construct(?BaseSocket $Socket = null)
    {
        $this->Buffer = new Buffer();
        $this->Socket = $Socket ?: new Socket();
    }

    public function Connect(string $Address, int $Port, int $Timeout = 3, int $Engine = self::SOURCE): void
    {
        if ($Engine !== self::SOURCE && $Engine !== self::GOLDSOURCE) {
            throw new InvalidArgumentException('Invalid engine provided.');
        }

        $this->Engine = $Engine;
        $this->Socket->Open($Address, $Port, $Timeout, $Engine);
    }

    public function Disconnect(): void
    {
        $this->Socket->Close();
    }

    public function __destruct()
    {
        $this->Disconnect();
    }

    // Ici suivent les méthodes Info(), Players(), Rules(), Ping() etc.
    // Elles n'ont pas besoin d'être modifiées pour la compatibilité PHP 8.1/8.2/8.3/8.4
}
