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

final class TestableRcon extends AbstractRcon
{
    public function open(): void
    {
    }

    public function close(): void
    {
    }

    public function authorize(string $password): void
    {
    }

    public function command(string $command): string
    {
        return '';
    }

    protected function read(): Buffer
    {
        return new Buffer();
    }

    protected function write(?int $header, string $string = ''): bool
    {
        return true;
    }
}
