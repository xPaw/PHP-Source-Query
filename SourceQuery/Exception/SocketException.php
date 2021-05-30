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

final class SocketException extends SourceQueryException
{
    public const COULD_NOT_CREATE_SOCKET = 1;
    public const NOT_CONNECTED = 2;
    public const CONNECTION_FAILED = 3;
    public const INVALID_ENGINE = 3;
}
