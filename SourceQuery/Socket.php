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
	
	use xPaw\SourceQuery\Exception\InvalidPacketException;
	use xPaw\SourceQuery\Exception\SocketException;

	/**
	 * Class Socket
	 *
	 * @package xPaw\SourceQuery
	 *
	 * @uses xPaw\SourceQuery\Exception\InvalidPacketException
	 * @uses xPaw\SourceQuery\Exception\SocketException
	 */
	class Socket extends BaseSocket
	{
		public function Close( )
		{
			if( $this->Socket )
			{
				FClose( $this->Socket );
				
				$this->Socket = null;
			}
		}
		
		public function Open( $Address, $Port, $Timeout, $Engine )
		{
			$this->Timeout = $Timeout;
			$this->Engine  = $Engine;
			$this->Port    = $Port;
			$this->Address = $Address;
			
			$this->Socket = @FSockOpen( 'udp://' . $Address, $Port, $ErrNo, $ErrStr, $Timeout );
			
			if( $ErrNo || $this->Socket === false )
			{
				throw new SocketException( 'Could not create socket: ' . $ErrStr, SocketException::COULD_NOT_CREATE_SOCKET );
			}
			
			Stream_Set_Timeout( $this->Socket, $Timeout );
			Stream_Set_Blocking( $this->Socket, true );
		}
		
		public function Write( $Header, $String = '' )
		{
			$Command = Pack( 'ccccca*', 0xFF, 0xFF, 0xFF, 0xFF, $Header, $String );
			$Length  = StrLen( $Command );
			
			return $Length === FWrite( $this->Socket, $Command, $Length );
		}
		
		/**
		 * Reads from socket and returns Buffer.
		 *
		 * @throws InvalidPacketException
		 *
		 * @return Buffer Buffer
		 */
		public function Read( $Length = 1400 )
		{
			$Buffer = new Buffer( );
			$Buffer->Set( FRead( $this->Socket, $Length ) );
			
			$this->ReadInternal( $Buffer, $Length, [ $this, 'Sherlock' ] );
			
			return $Buffer;
		}
		
		public function Sherlock( $Buffer, $Length )
		{
			$Data = FRead( $this->Socket, $Length );
			
			if( StrLen( $Data ) < 4 )
			{
				return false;
			}
			
			$Buffer->Set( $Data );
			
			return $Buffer->GetLong( ) === -2;
		}
	}
