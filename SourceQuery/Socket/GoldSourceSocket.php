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

use xPaw\SourceQuery\Socket\Traits\GoldSourcePacketDataTrait;

final class GoldSourceSocket extends AbstractSocket
{
    use GoldSourcePacketDataTrait;

    public function getType(): int
    {
        return SocketType::GOLDSOURCE;
    }
}
