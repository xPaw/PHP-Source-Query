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
	
	use xPaw\SourceQuery\Exception\AuthenticationException;
	use xPaw\SourceQuery\Exception\InvalidPacketException;
	use xPaw\SourceQuery\Exception\SocketException;

	/**
	 * Class SourceRcon
	 *
	 * @package xPaw\SourceQuery
	 *
	 * @uses xPaw\SourceQuery\Exception\AuthenticationException
	 * @uses xPaw\SourceQuery\Exception\InvalidPacketException
	 * @uses xPaw\SourceQuery\Exception\SocketException
	 */
	class SourceRcon
	{
		/**
		 * Points to socket class
		 * 
		 * @var Socket
		 */
		private $Socket;
		
		private $RconSocket;
		private $RconRequestId;
		
		public function __construct( $Socket )
		{
			$this->Socket = $Socket;
		}
		
		public function Close( )
		{
			if( $this->RconSocket )
			{
				FClose( $this->RconSocket );
				
				$this->RconSocket = null;
			}
			
			$this->RconRequestId = 0;
		}
		
		public function Open( )
		{
			if( !$this->RconSocket )
			{
				$this->RconSocket = @FSockOpen( $this->Socket->Address, $this->Socket->Port, $ErrNo, $ErrStr, $this->Socket->Timeout );
				
				if( $ErrNo || !$this->RconSocket )
				{
					throw new SocketException( 'Can\'t connect to RCON server: ' . $ErrStr, SocketException::CONNECTION_FAILED );
				}
				
				Stream_Set_Timeout( $this->RconSocket, $this->Socket->Timeout );
				Stream_Set_Blocking( $this->RconSocket, true );
			}
		}
		
		public function Write( $Header, $String = '' )
		{
			// Pack the packet together
			$Command = Pack( 'VV', ++$this->RconRequestId, $Header ) . $String . "\x00\x00"; 
			
			// Prepend packet length
			$Command = Pack( 'V', StrLen( $Command ) ) . $Command;
			$Length  = StrLen( $Command );
			
			return $Length === FWrite( $this->RconSocket, $Command, $Length );
		}
		
		public function Read( )
		{
			$Buffer = new Buffer( );
			$Buffer->Set( FRead( $this->RconSocket, 4 ) );
			
			if( $Buffer->Remaining( ) < 4 )
			{
				throw new InvalidPacketException( 'Rcon read: Failed to read any data from socket', InvalidPacketException::BUFFER_EMPTY );
			}
			
			$PacketSize = $Buffer->GetLong( );
			
			$Buffer->Set( FRead( $this->RconSocket, $PacketSize ) );
			
			$Data = $Buffer->Get( );
			
			$Remaining = $PacketSize - StrLen( $Data );
			
			while( $Remaining > 0 )
			{
				$Data2 = FRead( $this->RconSocket, $Remaining );
				
				$PacketSize = StrLen( $Data2 );
				
				if( $PacketSize === 0 )
				{
					throw new InvalidPacketException( 'Read ' . strlen( $Data ) . ' bytes from socket, ' . $Remaining . ' remaining', InvalidPacketException::BUFFER_EMPTY );
					
					break;
				}
				
				$Data .= $Data2;
				$Remaining -= $PacketSize;
			}
			
			$Buffer->Set( $Data );
			
			return $Buffer;
		}
		
		public function Command( $Command )
		{
			$this->Write( SourceQuery::SERVERDATA_EXECCOMMAND, $Command );
			$Buffer = $this->Read( );
			
			$Buffer->GetLong( ); // RequestID
			
			$Type = $Buffer->GetLong( );
			
			if( $Type === SourceQuery::SERVERDATA_AUTH_RESPONSE )
			{
				throw new AuthenticationException( 'Bad rcon_password.', AuthenticationException::BAD_PASSWORD );
			}
			else if( $Type !== SourceQuery::SERVERDATA_RESPONSE_VALUE )
			{
				throw new InvalidPacketException( 'Invalid rcon response.', InvalidPacketException::PACKET_HEADER_MISMATCH );
			}
			
			$Data = $Buffer->Get( );
			
			// We do this stupid hack to handle split packets
			// See https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Multiple-packet_Responses
			if( StrLen( $Data ) >= 4000 )
			{
				do
				{
					$this->Write( SourceQuery::SERVERDATA_RESPONSE_VALUE );
					
					$Buffer = $this->Read( );
					
					$Buffer->GetLong( ); // RequestID
					
					if( $Buffer->GetLong( ) !== SourceQuery::SERVERDATA_RESPONSE_VALUE )
					{
						break;
					}
					
					$Data2 = $Buffer->Get( );
					
					if( $Data2 === "\x00\x01\x00\x00\x00\x00" )
					{
						break;
					}
					
					$Data .= $Data2;
				}
				while( true );
			}
			
			return rtrim( $Data, "\0" );
		}
		
		public function Authorize( $Password )
		{
			$this->Write( SourceQuery::SERVERDATA_AUTH, $Password );
			$Buffer = $this->Read( );
			
			$RequestID = $Buffer->GetLong( );
			$Type      = $Buffer->GetLong( );
			
			// If we receive SERVERDATA_RESPONSE_VALUE, then we need to read again
			// More info: https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Additional_Comments
			
			if( $Type === SourceQuery::SERVERDATA_RESPONSE_VALUE )
			{
				$Buffer = $this->Read( );
				
				$RequestID = $Buffer->GetLong( );
				$Type      = $Buffer->GetLong( );
			}
			
			if( $RequestID === -1 || $Type !== SourceQuery::SERVERDATA_AUTH_RESPONSE )
			{
				throw new AuthenticationException( 'RCON authorization failed.', AuthenticationException::BAD_PASSWORD );
			}
		}
	}
