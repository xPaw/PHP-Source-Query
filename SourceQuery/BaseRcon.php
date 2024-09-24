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

namespace xPaw\SourceQuery;

/**
 * Base RCON interface
 */
abstract class BaseRcon
{
	abstract public function Close( ) : void;
	abstract public function Open( ) : void;
	abstract public function Write( int $Header, string $String = '' ) : bool;
	abstract public function Read( ) : Buffer;
	abstract public function Command( string $Command ) : string;
	abstract public function Authorize( string $Password ) : void;
}
