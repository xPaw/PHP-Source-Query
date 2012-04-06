<?php
class SourceQueryException extends Exception
{
	// Exception thrown by SourceQuery class
}

class SourceQuery
{
	/*
	 * Class written by xPaw
	 *
	 * Website: http://xpaw.ru
	 * GitHub: https://github.com/xPaw/PHP-Source-Query-Class
	 *
	 * Special thanks to koraktor for his awesome Steam Condenser class,
	 * I used it as a refference at some points.
	 */
	
	// Engines
	const GOLDSOURCE = 0;
	const SOURCE     = 1;
	
	// Packets Ending
	const A2S_PING         = 0x69;
	const A2S_GETCHALLENGE = 0x57; // Doesn't work
	const A2S_INFO         = 0x54;
	const A2S_PLAYER       = 0x55;
	const A2S_RULES        = 0x56;
	
	// Packets Receiving
	const S2A_PING      = 0x6A;
	const S2A_CHALLENGE = 0x41;
	const S2A_INFO      = 0x49;
	const S2A_INFO_OLD  = 0x6D; // Old GoldSource, HLTV uses it
	const S2A_PLAYER    = 0x44;
	const S2A_RULES     = 0x45;
	const S2A_RCON      = 0x6C;
	
	// Source Rcon Sending
	const SERVERDATA_EXECCOMMAND    = 2;
	const SERVERDATA_AUTH           = 3;
	
	// Source Rcon Receiving
	const SERVERDATA_RESPONSE_VALUE = 0;
	const SERVERDATA_AUTH_RESPONSE  = 2;
	
	private $Rcon;
	private $Buffer;
	private $Socket;
	private $Connected;
	private $Challenge;
	
	public function __construct( )
	{
		$this->Buffer = new SourceQueryBuffer( );
		$this->Socket = new SourceQuerySocket( $this->Buffer );
		$this->Rcon   = new SourceQueryRcon( $this->Buffer, $this->Socket );
	}
	
	public function __destruct( )
	{
		$this->Disconnect( );
	}
	
	public function Connect( $Ip, $Port, $Timeout = 3, $Engine = self :: SOURCE )
	{
		$this->Disconnect( );
		$this->Buffer->Reset( );
		$this->Challenge = 0;
		
		if( !$this->Socket->Open( $Ip, (int)$Port, (int)$Timeout, (int)$Engine ) )
		{
			throw new SourceQueryException( 'Can\'t connect to the server.' );
		}
		
		$this->Connected = true;
	}
	
	public function Disconnect( )
	{
		$this->Connected = false;
		
		$this->Socket->Close( );
		$this->Rcon->Close( );
	}
	
	public function Ping( )
	{
		if( !$this->Connected )
		{
			return false;
		}
		
		$this->Socket->Write( self :: A2S_PING );
		$this->Socket->Read( );
		
		return $this->Buffer->GetByte( ) == self :: S2A_PING;
	}
	
	public function GetInfo( )
	{
		if( !$this->Connected )
		{
			return false;
		}
		
		$this->Socket->Write( self :: A2S_INFO, "Source Engine Query\0" );
		$this->Socket->Read( );
		
		$Type = $this->Buffer->GetByte( );
		
		// Old GoldSource protocol, HLTV still uses it
		if( $Type == self :: S2A_INFO_OLD && $this->Socket->Engine == self :: GOLDSOURCE )
		{
			// If we try to read data again, and we get the result with type S2A_INFO (0x49)
			// That means this server is running dproto,
			// Because it sends answer for both protocols
			
			$Server[ 'Address' ]    = $this->Buffer->GetString( );
			$Server[ 'HostName' ]   = $this->Buffer->GetString( );
			$Server[ 'Map' ]        = $this->Buffer->GetString( );
			$Server[ 'ModDir' ]     = $this->Buffer->GetString( );
			$Server[ 'ModDesc' ]    = $this->Buffer->GetString( );
			$Server[ 'Players' ]    = $this->Buffer->GetByte( );
			$Server[ 'MaxPlayers' ] = $this->Buffer->GetByte( );
			$Server[ 'Protocol' ]   = $this->Buffer->GetByte( );
			$Server[ 'Dedicated' ]  = Chr( $this->Buffer->GetByte( ) );
			$Server[ 'Os' ]         = Chr( $this->Buffer->GetByte( ) );
			$Server[ 'Password' ]   = $this->Buffer->GetByte( ) == 1;
			$Server[ 'IsMod' ]      = $this->Buffer->GetByte( ) == 1;
			
			if( $Server[ 'IsMod' ] )
			{
				$Mod[ 'Url' ]        = $this->Buffer->GetString( );
				$Mod[ 'Download' ]   = $this->Buffer->GetString( );
				$this->Buffer->Get( 1 ); // NULL byte
				$Mod[ 'Version' ]    = $this->Buffer->GetLong( );
				$Mod[ 'Size' ]       = $this->Buffer->GetLong( );
				$Mod[ 'ServerSide' ] = $this->Buffer->GetByte( ) == 1;
				$Mod[ 'CustomDLL' ]  = $this->Buffer->GetByte( ) == 1;
			}
			
			$Server[ 'Secure' ]   = $this->Buffer->GetByte( ) == 1;
			$Server[ 'Bots' ]     = $this->Buffer->GetByte( );
			
			if( isset( $Mod ) )
			{
				$Server[ 'Mod' ] = $Mod;
			}
			
			return $Server;
		}
		
		if( $Type != self :: S2A_INFO )
		{
			throw new SourceQueryException( 'GetInfo: Packet header mismatch. (' . $Type . ')' );
		}
		
		$Server[ 'Protocol' ]   = $this->Buffer->GetByte( );
		$Server[ 'HostName' ]   = $this->Buffer->GetString( );
		$Server[ 'Map' ]        = $this->Buffer->GetString( );
		$Server[ 'ModDir' ]     = $this->Buffer->GetString( );
		$Server[ 'ModDesc' ]    = $this->Buffer->GetString( );
		$Server[ 'AppID' ]      = $this->Buffer->GetShort( );
		$Server[ 'Players' ]    = $this->Buffer->GetByte( );
		$Server[ 'MaxPlayers' ] = $this->Buffer->GetByte( );
		$Server[ 'Bots' ]       = $this->Buffer->GetByte( );
		$Server[ 'Dedicated' ]  = Chr( $this->Buffer->GetByte( ) );
		$Server[ 'Os' ]         = Chr( $this->Buffer->GetByte( ) );
		$Server[ 'Password' ]   = $this->Buffer->GetByte( ) == 1;
		$Server[ 'Secure' ]     = $this->Buffer->GetByte( ) == 1;
		
		// The Ship
		if( $Server[ 'AppID' ] == 2400 )
		{
			$Server[ 'GameMode' ]     = $this->Buffer->GetByte( );
			$Server[ 'WitnessCount' ] = $this->Buffer->GetByte( );
			$Server[ 'WitnessTime' ]  = $this->Buffer->GetByte( );
		}
		
		$Server[ 'Version' ] = $this->Buffer->GetString( );
		
		// Extra Data Flags
		if( $this->Buffer->Remaining( ) > 0 )
		{
			$Flags = $this->Buffer->GetByte( );
			
			// The server's game port
			if( $Flags & 0x80 )
			{
				$Server[ 'GamePort' ] = $this->Buffer->GetShort( );
			}
			
			// The server's SteamID - does this serve any purpose?
			if( $Flags & 0x10 )
			{
				$Server[ 'ServerID' ] = $this->Buffer->GetUnsignedLong( ) | ( $this->Buffer->GetUnsignedLong( ) << 32 );
			}
			
			// The spectator port and then the spectator server name
			if( $Flags & 0x40 )
			{
				$Server[ 'SpecPort' ] = $this->Buffer->GetShort( );
				$Server[ 'SpecName' ] = $this->Buffer->GetString( );
			}
			
			// The game tag data string for the server
			if( $Flags & 0x20 )
			{
				$Server[ 'GameTags' ] = $this->Buffer->GetString( );
			}
			
			// 0x01 - The server's 64-bit GameID
		}
		
		return $Server;
	}
	
	public function GetPlayers( )
	{
		if( !$this->Connected || !$this->GetChallenge( ) )
		{
			return false;
		}
		
		$this->Socket->Write( self :: A2S_PLAYER, $this->GetChallenge( ) );
		$this->Socket->Read( );
		
		$Type = $this->Buffer->GetByte( );
		
		if( $Type != self :: S2A_PLAYER )
		{
			throw new SourceQueryException( 'GetPlayers: Packet header mismatch. (' . $Type . ')' );
		}
		
		$Players = Array( );
		$Count   = $this->Buffer->GetByte( );
		
		while( $Count-- > 0 && $this->Buffer->Remaining( ) > 0 )
		{
			$Player[ 'Id' ]    = $this->Buffer->GetByte( ); // PlayerID, is it just always 0?
			$Player[ 'Name' ]  = $this->Buffer->GetString( );
			$Player[ 'Frags' ] = $this->Buffer->GetLong( );
			$Player[ 'Time' ]  = (int)$this->Buffer->GetFloat( );
			$Player[ 'TimeF' ] = GMDate( ( $Player[ 'Time' ] > 3600 ? "H:i:s" : "i:s" ), $Player[ 'Time' ] );
			
			$Players[ ] = $Player;
		}
		
		return $Players;
	}
	
	public function GetRules( )
	{
		if( !$this->Connected || !$this->GetChallenge( ) )
		{
			return false;
		}
		
		$this->Socket->Write( self :: A2S_RULES, $this->GetChallenge( ) );
		$this->Socket->Read( );
		
		$Type = $this->Buffer->GetByte( );
		
		if( $Type != self :: S2A_RULES )
		{
			throw new SourceQueryException( 'GetRules: Packet header mismatch. (' . $Type . ')' );
		}
		
		$Rules = Array( );
		$Count = $this->Buffer->GetShort( );
		
		while( $Count-- > 0 && $this->Buffer->Remaining( ) > 0 )
		{
			$Rule  = $this->Buffer->GetString( );
			$Value = $this->Buffer->GetString( );
			
			if( !Empty( $Rule ) )
			{
				$Rules[ $Rule ] = $Value;
			}
		}
		
		return $Rules;
	}
	
	private function GetChallenge( )
	{
		if( $this->Challenge )
		{
			return $this->Challenge;
		}
		
		// A2S_GETCHALLENGE is broken
		
		$this->Socket->Write( self :: A2S_PLAYER, 0xFFFFFFFF );
		$this->Socket->Read( );
		
		$Type = $this->Buffer->GetByte( );
		
		if( $Type != self :: S2A_CHALLENGE )
		{
			throw new SourceQueryException( 'GetChallenge: Packet header mismatch. (' . $Type . ')' );
		}
		
		// Let's keep it raw, instead of reading as long
		$this->Challenge = $this->Buffer->Get( 4 );
		
		return $this->Challenge;
	}
	
	public function SetRconPassword( $Password )
	{
		if( !$this->Connected )
		{
			return false;
		}
		
		$this->Rcon->Open( );
		
		return $this->Rcon->Authorize( $Password );
	}
	
	public function Rcon( $Command )
	{
		if( !$this->Connected )
		{
			return false;
		}
		
		return $this->Rcon->Command( $Command );
	}
}

class SourceQuerySocket
{
	public $Socket;
	public $Engine;
	
	public $Ip;
	public $Port;
	public $Timeout;
	
	private $Buffer;
	
	public function __construct( $Buffer )
	{
		$this->Buffer = $Buffer;
	}
	
	public function Close( )
	{
		if( $this->Socket )
		{
			FClose( $this->Socket );
			
			$this->Socket = null;
		}
	}
	
	public function Open( $Ip, $Port, $Timeout, $Engine )
	{
		$this->Timeout = $Timeout;
		$this->Engine  = $Engine;
		$this->Port    = $Port;
		$this->Ip      = $Ip;
		
		if( !( $this->Socket = FSockOpen( 'udp://' . $Ip, $Port ) ) )
		{
			return false;
		}
		
		Socket_Set_TimeOut( $this->Socket, $Timeout );
		
		return true;
	}
	
	public function Write( $Header, $String = '' )
	{
		$Command = Pack( 'ccccca*', 0xFF, 0xFF, 0xFF, 0xFF, $Header, $String );
		$Length  = StrLen( $Command );
		
		return $Length === FWrite( $this->Socket, $Command, $Length );
	}
	
	public function Read( $Length = 1400 )
	{
		$this->Buffer->Set( FRead( $this->Socket, $Length ) );
		
		if( $this->Buffer->Remaining( ) > 0 && $this->Buffer->GetLong( ) == -2 )
		{
			$Packets      = Array( );
			$IsCompressed = false;
			$ReadMore     = false;
			
			do
			{
				$RequestID = $this->Buffer->GetLong( );
				
				switch( $this->Engine )
				{
					case SourceQuery :: GOLDSOURCE:
					{
						$PacketCountAndNumber = $this->Buffer->GetByte( );
						$PacketCount          = $PacketCountAndNumber & 0xF;
						$PacketNumber         = $PacketCountAndNumber >> 4;
						
						break;
					}
					case SourceQuery :: SOURCE:
					{
						$IsCompressed         = ( $RequestID & 0x80000000 ) != 0;
						$PacketCount          = $this->Buffer->GetByte( );
						$PacketNumber         = $this->Buffer->GetByte( ) + 1;
						
						if( $IsCompressed )
						{
							$this->Buffer->GetLong( ); // Split size
							
							$PacketChecksum = $this->Buffer->GetUnsignedLong( );
						}
						else
						{
							$this->Buffer->GetShort( ); // Split size
						}
						
						break;
					}
				}
				
				$Packets[ $PacketNumber ] = $this->Buffer->Get( );
				
				$ReadMore = $PacketCount > sizeof( $Packets );
			}
			while( $ReadMore && $this->Sherlock( $Length ) );
			
			$Buffer = Implode( $Packets );
			
			// TODO: Test this
			if( $IsCompressed )
			{
				$Data = bzdecompress( $Data );
				
				if( CRC32( $Data ) != $PacketChecksum )
				{
					throw new SourceQueryException( 'CRC32 checksum mismatch of uncompressed packet data.' );
				}
			}
			
			$this->Buffer->Set( SubStr( $Buffer, 4 ) );
		}
	}
	
	private function Sherlock( $Length )
	{
		$Data = FRead( $this->Socket, $Length );
		
		if( StrLen( $Data ) < 4 )
		{
			return false;
		}
		
		$this->Buffer->Set( $Data );
		
		return $this->Buffer->GetLong( ) == -2;
	}
}

class SourceQueryRcon
{
	private $Buffer;
	private $Socket;
	
	private $RconSocket;
	private $RconPassword;
	private $RconRequestId;
	private $RconChallenge;
	
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
		
		$this->RconChallenge = 0;
		$this->RconRequestId = 0;
		$this->RconPassword  = 0;
	}
	
	public function Open( )
	{
		if( !$this->RconSocket && $this->Socket->Engine == SourceQuery :: SOURCE )
		{
			if( !( $this->RconSocket = FSockOpen( $this->Socket->Ip, $this->Socket->Port, $ErrNo, $ErrStr, $this->Socket->Timeout ) ) )
			{
				throw new SourceQueryException( 'Can\'t connect to RCON server: ' . $ErrStr );
			}
			
			Socket_Set_TimeOut( $this->RconSocket, $this->Socket->Timeout );
		}
	}
	
	public function Write( $Header, $String = '' )
	{
		switch( $this->Socket->Engine )
		{
			case SourceQuery :: GOLDSOURCE:
			{
				$Command = Pack( 'cccca*', 0xFF, 0xFF, 0xFF, 0xFF, $String );
				$Length  = StrLen( $Command );
				
				return $Length === FWrite( $this->Socket->Socket, $Command, $Length );
			}
			case SourceQuery :: SOURCE:
			{
				// Pack the packet together
				$Command = Pack( 'VV', ++$this->RequestId, $Header ) . $String . "\x00\x00\x00"; 
				
				// Prepend packet length
				$Command = Pack( 'V', StrLen( $Command ) ) . $Command;
				$Length  = StrLen( $Command );
				
				return $Length === FWrite( $this->RconSocket, $Command, $Length );
			}
		}
	}
	
	public function Read( $Length = 1400 )
	{
		switch( $this->Socket->Engine )
		{
			case SourceQuery :: GOLDSOURCE:
			{
				// GoldSource RCON has same structure as Query
				$this->Socket->Read( );
				
				if( $this->Buffer->GetByte( ) != SourceQuery :: S2A_RCON )
				{
					return false;
				}
				
				$Buffer  = $this->Buffer->Get( );
				$Trimmed = Trim( $Buffer );
				
				if( $Trimmed == 'Bad rcon_password.'
				||  $Trimmed == 'You have been banned from this server.' )
				{
					throw new SourceQueryException( $Trimmed );
				}
				
				$ReadMore = false;
				
				// There is no indentifier of the end, so we just need to continue reading
				// TODO: Needs to be looked again, it causes timeouts
				do
				{
					$this->Socket->Read( );
					
					$ReadMore = $this->Buffer->Remaining( ) > 0 && $this->Buffer->GetByte( ) == SourceQuery :: S2A_RCON;
					
					if( $ReadMore )
					{
						$Packet  = $this->Buffer->Get( );
						$Buffer .= SubStr( $Packet, 0, -2 );
						
						// Let's assume if this packet is not long enough, there are no more after this one
						$ReadMore = StrLen( $Packet ) > 1000; // use 1300?
					}
				}
				while( $ReadMore );
				
				$this->Buffer->Set( Trim( $Buffer ) );
				
				break;
			}
			case SourceQuery :: SOURCE:
			{
				$this->Buffer->Set( FRead( $this->RconSocket, $Length ) );
				
				$Buffer = "";
				
				$PacketSize = $this->Buffer->GetLong( );
				
				$Buffer .= $this->Buffer->Get( );
				
				// TODO: multi packet reading
				
				$this->Buffer->Set( $Buffer );
				
				break;
			}
		}
	}
	
	public function Command( $Command )
	{
		if( !$this->RconChallenge )
		{
			return false;
		}
		
		$Buffer = false;
		
		switch( $this->Socket->Engine )
		{
			case SourceQuery :: GOLDSOURCE:
			{
				$this->Write( 0, 'rcon ' . $this->RconChallenge . ' "' . $this->RconPassword . '" ' . $Command . "\0" );
				$this->Read( );
				
				$Buffer = $this->Buffer->Get( );
				
				break;
			}
			case SourceQuery :: SOURCE:
			{
				$this->Write( SourceQuery :: SERVERDATA_EXECCOMMAND, $Command );
				$this->Read( );
				
				$RequestID = $this->Buffer->GetLong( );
				$Type      = $this->Buffer->GetLong( );
				
				if( $Type == SourceQuery :: SERVERDATA_AUTH_RESPONSE )
				{
					throw new SourceQueryException( 'Bad rcon_password.' );
				}
				else if( $Type != SourceQuery :: SERVERDATA_RESPONSE_VALUE )
				{
					return false;
				}
				
				// TODO: It should use GetString, but there are no null bytes at the end, why?
				// $Buffer = $this->Buffer->GetString( );
				$Buffer = $this->Buffer->Get( );
				
				break;
			}
		}
		
		return $Buffer;
	}
	
	public function Authorize( $Password )
	{
		$this->RconPassword = $Password;
		
		switch( $this->Socket->Engine )
		{
			case SourceQuery :: GOLDSOURCE:
			{
				$this->Write( 0, 'challenge rcon' );
				$this->Socket->Read( );
				
				if( $this->Buffer->Get( 14 ) != 'challenge rcon' )
				{
					return false;
				}
				
				$this->RconChallenge = Trim( $this->Buffer->Get( ) );
				
				break;
			}	
			case SourceQuery :: SOURCE:
			{
				$this->Write( SourceQuery :: SERVERDATA_AUTH, $Password );
				$this->Read( );
				
				$RequestID = $this->Buffer->GetLong( );
				$Type      = $this->Buffer->GetLong( );
				
				// If we receive SERVERDATA_RESPONSE_VALUE, then we need to read again
				// More info: https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Additional_Comments
				
				if( $Type == SourceQuery :: SERVERDATA_RESPONSE_VALUE )
				{
					$this->Read( );
					
					$RequestID = $this->Buffer->GetLong( );
					$Type      = $this->Buffer->GetLong( );
				}
				
				if( $RequestID == -1 || $Type != SourceQuery :: SERVERDATA_AUTH_RESPONSE )
				{
					throw new SourceQueryException( 'RCON authorization failed.' );
				}
				
				$this->RconChallenge = 1;
				
				break;
			}
		}
		
		return true;
	}
}

class SourceQueryBuffer
{
	private $Buffer;
	private $Length;
	private $Position;
	
	public function Set( $Buffer )
	{
		$this->Buffer   = $Buffer;
		$this->Length   = StrLen( $Buffer );
		$this->Position = 0;
		
		return true;
	}
	
	public function Reset( )
	{
		$this->Buffer   = "";
		$this->Length   = 0;
		$this->Position = 0;
	}
	
	public function Remaining( )
	{
		return $this->Length - $this->Position;
	}
	
	public function Get( $Length = -1 )
	{
		if( $Length == 0 )
		{
			// Why even bother
			return "";
		}
		
		$Remaining = $this->Remaining( );
		
		if( $Length == -1 )
		{
			$Length = $Remaining;
		}
		else if( $Length > $Remaining )
		{
			return false;
		}
		
		$Data = SubStr( $this->Buffer, $this->Position, $Length );
		
		$this->Position += $Length;
		
		return $Data;
	}
	
	public function GetByte( )
	{
		return Ord( $this->Get( 1 ) );
	}
	
	public function GetShort( )
	{
		$Data = UnPack( 'v', $this->Get( 2 ) );
		
		return $Data[ 1 ];
	}
	
	public function GetLong( )
	{
		$Data = UnPack( 'l', $this->Get( 4 ) );
		
		return $Data[ 1 ];
	}
	
	public function GetFloat( )
	{
		$Data = UnPack( 'f', $this->Get( 4 ) );
		
		return $Data[ 1 ];
	}
	
	public function GetUnsignedLong( )
	{
		$Data = UnPack( 'V', $this->Get( 4 ) );
		
		return $Data[ 1 ];
	}
	
	public function GetString( )
	{
		$ZeroBytePosition = StrPos( $this->Buffer, "\0", $this->Position );
		
		if( $ZeroBytePosition === false )
		{
			$String = "";
		}
		else
		{
			$String = $this->Get( $ZeroBytePosition - $this->Position );
			
			$this->Position++;
		}
		
		return $String;
	}
}