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

use xPaw\SourceQuery\EngineType;
use xPaw\SourceQuery\Socket\Traits\SourcePacketDataTrait;

final class SourceSocket extends AbstractSocket
{
    use SourcePacketDataTrait;

    public function getType(): string
    {
        return EngineType::SOURCE;
    }
}
