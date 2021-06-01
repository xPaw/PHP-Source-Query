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

namespace xPaw\SourceQuery;

abstract class EngineType
{
    public const GOLDSOURCE = 'GoldSource';
    public const SOURCE = 'Source';

    public const ALL_ENGINES = [
        self::GOLDSOURCE,
        self::SOURCE,
    ];
}
