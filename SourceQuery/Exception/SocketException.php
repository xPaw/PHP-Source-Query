<?php
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

	class SocketException extends SourceQueryException
	{
		const COULD_NOT_CREATE_SOCKET = 1;
		const NOT_CONNECTED = 2;
		const CONNECTION_FAILED = 3;
		const INVALID_ENGINE = 3;
	}
