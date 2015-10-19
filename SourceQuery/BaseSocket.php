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
	
	namespace xPaw\SourceQuery;
	
	/**
	 * Base socket interface
	 *
	 * @package xPaw\SourceQuery
	 */
	abstract class BaseSocket
	{
		public $Socket;
		public $Engine;
		
		public $Ip;
		public $Port;
		public $Timeout;
		
		public function __destruct( )
		{
			$this->Close( );
		}
		
		abstract public function Close( );
		abstract public function Open( $Ip, $Port, $Timeout, $Engine );
		abstract public function Write( $Header, $String = '' );
		abstract public function Read( $Length = 1400 );
	}
