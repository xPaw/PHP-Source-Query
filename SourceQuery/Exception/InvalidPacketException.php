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

namespace xPaw\SourceQuery\Exception;

final class InvalidPacketException extends SourceQueryException
{
    public const PACKET_HEADER_MISMATCH = 1;
    public const BUFFER_EMPTY = 2;
    public const BUFFER_NOT_EMPTY = 3;
    public const CHECKSUM_MISMATCH = 4;
}
