<?php
	/**
	 * @author Pavel Djundik <sourcequery@xpaw.me>
	 *
	 * @link https://xpaw.me
	 * @link https://github.com/xPaw/PHP-Source-Query
	 *
	 * @license GNU Lesser General Public License, version 2.1
	 *
	 * @internal
	 */

	namespace xPaw\SourceQuery\Exception;

	class InvalidPacketException extends SourceQueryException
	{
		const PACKET_HEADER_MISMATCH = 1;
		const BUFFER_EMPTY = 2;
		const BUFFER_NOT_EMPTY = 3;
		const CHECKSUM_MISMATCH = 4;
	}
