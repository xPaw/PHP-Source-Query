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

	class AuthenticationException extends SourceQueryException
	{
		const BAD_PASSWORD = 1;
		const BANNED = 2;
	}
