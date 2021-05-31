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

namespace xPaw\SourceQuery\QueryResponse;

use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\InvalidPacketException;

class RulesQueryResponse
{
    /**
     * @throws InvalidPacketException
     */
    public static function fromBuffer(Buffer $buffer): array
    {
        $rules = [];
        $count = $buffer->getShort();

        while ($count-- > 0 && !$buffer->isEmpty()) {
            $rule = $buffer->getString();
            $value = $buffer->getString();

            if (!empty($rule)) {
                $rules[$rule] = $value;
            }
        }

        return $rules;
    }
}
