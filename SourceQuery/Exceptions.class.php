<?php
	/**
	 * Class written by xPaw
	 *
	 * Website: https://xpaw.me
	 * GitHub: https://github.com/xPaw/PHP-Source-Query-Class
	 */
	
	namespace xPaw\SourceQuery\Exception;
	
	abstract class SourceQueryException extends \Exception
	{
		// Base exception class
	}
	
	class InvalidArgumentException extends SourceQueryException
	{
		const TIMEOUT_NOT_INTEGER = 1;
	}
	
	class TimeoutException extends SourceQueryException
	{
		const TIMEOUT_CONNECT = 1;
	}
	
	class InvalidPacketException extends SourceQueryException
	{
		const PACKET_HEADER_MISMATCH = 1;
		const BUFFER_EMPTY = 2;
		const BUFFER_NOT_EMPTY = 3;
		const CHECKSUM_MISMATCH = 4;
	}
	
	class AuthenticationException extends SourceQueryException
	{
		const BAD_PASSWORD = 1;
		const BANNED = 2;
	}
	
	class SocketException extends SourceQueryException
	{
		const COULD_NOT_CREATE_SOCKET = 1;
	}
