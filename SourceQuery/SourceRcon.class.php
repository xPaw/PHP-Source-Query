<?php
	/**
	 * Class written by xPaw
	 *
	 * Website: http://xpaw.me
	 * GitHub: https://github.com/xPaw/PHP-Source-Query-Class
	 */
	
	class SourceQuerySourceRcon
	{
		/**
		 * Points to buffer class
		 * 
		 * @var SourceQueryBuffer
		 */
		private $Buffer;
		
		/**
		 * Points to socket class
		 * 
		 * @var SourceQuerySocket
		 */
		private $Socket;
		
		private $RconSocket;
		private $RconRequestId;
		
		public function __construct( $Buffer, $Socket )
		{
			$this->Buffer = $Buffer;
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
				$this->RconSocket = @FSockOpen( $this->Socket->Ip, $this->Socket->Port, $ErrNo, $ErrStr, $this->Socket->Timeout );
				
				if( $ErrNo || !$this->RconSocket )
				{
					throw new SourceQueryException( 'Can\'t connect to RCON server: ' . $ErrStr );
				}
				
				Stream_Set_Timeout( $this->RconSocket, $this->Socket->Timeout );
				Stream_Set_Blocking( $this->RconSocket, true );
			}
		}
		
		public function Write( $Header, $String = '' )
		{
			// Pack the packet together
			$Command = Pack( 'VV', ++$this->RconRequestId, $Header ) . $String . "\x00\x00\x00"; 
			
			// Prepend packet length
			$Command = Pack( 'V', StrLen( $Command ) ) . $Command;
			$Length  = StrLen( $Command );
			
			return $Length === FWrite( $this->RconSocket, $Command, $Length );
		}
		
		public function Read( $Length = 1400 )
		{
			$this->Buffer->Set( FRead( $this->RconSocket, $Length ) );
			
			$PacketSize = $this->Buffer->GetLong( );
			
			$Buffer = $this->Buffer->Get( );
			
			$Remaining = $PacketSize - StrLen( $Buffer );
			
			while( $Remaining > 0 )
			{
				$Buffer2 = FRead( $this->RconSocket, $Length );
				$Buffer .= $Buffer2;
				
				$Remaining -= StrLen( $Buffer2 );
			}
			
			$this->Buffer->Set( $Buffer );
		}
		
		public function Command( $Command )
		{
			$this->Write( SourceQuery :: SERVERDATA_EXECCOMMAND, $Command );
			
			$this->Read( );
			
			$this->Buffer->GetLong( ); // RequestID
			$Type      = $this->Buffer->GetLong( );
			
			if( $Type === SourceQuery :: SERVERDATA_AUTH_RESPONSE )
			{
				throw new SourceQueryException( 'Bad rcon_password.' );
			}
			else if( $Type !== SourceQuery :: SERVERDATA_RESPONSE_VALUE )
			{
				return false;
			}
			
			$Buffer = $this->Buffer->Get( );
			
			// We do this stupid hack to handle split packets
			// See https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Multiple-packet_Responses
			if( StrLen( $Buffer ) >= 4000 )
			{
				do
				{
					$this->Write( SourceQuery :: SERVERDATA_RESPONSE_VALUE );
					
					$this->Read( );
					
					$this->Buffer->GetLong( ); // RequestID
					
					if( $this->Buffer->GetLong( ) !== SourceQuery :: SERVERDATA_RESPONSE_VALUE )
					{
						break;
					}
					
					$Buffer .= $this->Buffer->Get( );
				}
				while( false ); // TODO: This is so broken that we don't even try to read multiple times, needs to be revised
			}
			
			// TODO: It should use GetString, but there are no null bytes at the end, why?
			// $Buffer = $this->Buffer->GetString( );
			return $Buffer;
		}
		
		public function Authorize( $Password )
		{
			$this->Write( SourceQuery :: SERVERDATA_AUTH, $Password );
			$this->Read( );
			
			$RequestID = $this->Buffer->GetLong( );
			$Type      = $this->Buffer->GetLong( );
			
			// If we receive SERVERDATA_RESPONSE_VALUE, then we need to read again
			// More info: https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Additional_Comments
			
			if( $Type === SourceQuery :: SERVERDATA_RESPONSE_VALUE )
			{
				$this->Read( );
				
				$RequestID = $this->Buffer->GetLong( );
				$Type      = $this->Buffer->GetLong( );
			}
			
			if( $RequestID === -1 || $Type !== SourceQuery :: SERVERDATA_AUTH_RESPONSE )
			{
				throw new SourceQueryException( 'RCON authorization failed.' );
			}
			
			return true;
		}
	}
