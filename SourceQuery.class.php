<?php
class SourceQueryException extends Exception
{
	// Exception thrown by SourceQuery class
}


class SourceQuery
{
	/**
	 * Class written by xPaw
	 *
	 * Website: http://xpaw.ru
	 * GitHub: https://github.com/xPaw/PHP-Source-Query-Class
	 *
	 * Special thanks to koraktor for his awesome Steam Condenser class,
	 * I used it as a reference at some points.
	 */
	
	/**
	 * Values returned by GetChallenge()
	 *
	 * TODO: Get rid of this? Improve? Do something else?
	 */
	const GETCHALLENGE_FAILED          = 0;
	const GETCHALLENGE_ALL_CLEAR       = 1;
	const GETCHALLENGE_CONTAINS_ANSWER = 2;
	
	/**
	 * Engines
	 */
	const GOLDSOURCE = 0;
	const SOURCE     = 1;
	
	/**
	 * Packets sent
	 */
	const A2S_PING      = 0x69;
	const A2S_INFO      = 0x54;
	const A2S_PLAYER    = 0x55;
	const A2S_RULES     = 0x56;
	
	/**
	 * Packets received
	 */
	const S2A_PING      = 0x6A;
	const S2A_CHALLENGE = 0x41;
	const S2A_INFO      = 0x49;
	const S2A_INFO_OLD  = 0x6D; // Old GoldSource, HLTV uses it
	const S2A_PLAYER    = 0x44;
	const S2A_RULES     = 0x45;
	const S2A_RCON      = 0x6C;
	
	/**
	 * Source rcon sent
	 */
	const SERVERDATA_EXECCOMMAND    = 2;
	const SERVERDATA_AUTH           = 3;
	
	/**
	 * Source rcon received
	 */
	const SERVERDATA_RESPONSE_VALUE = 0;
	const SERVERDATA_AUTH_RESPONSE  = 2;
	
	/**
	 * Points to rcon class
	 * 
	 * @var SourceQueryRcon
	 */
	private $Rcon;
	
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
	
	/**
	 * True if connection is open, false if not
	 * 
	 * @var bool
	 */
	private $Connected;
	
	/**
	 * Contains challenge
	 * 
	 * @var string
	 */
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
	
	/**
	 * Opens connection to server
	 *
	 * @param string $Ip Server ip
	 * @param int $Port Server port
	 * @param int $Timeout Timeout period
	 * @param int $Engine Engine the server runs on (goldsource, source)
	 *
	 * @throws SourceQueryException
	 * @throws InvalidArgumentException If timeout is not an integer
	 */
	public function Connect( $Ip, $Port, $Timeout = 3, $Engine = self :: SOURCE )
	{
		$this->Disconnect( );
		$this->Buffer->Reset( );
		$this->Challenge = 0;
		
		if( !is_int( $Timeout ) || $Timeout < 0 )
		{
			throw new InvalidArgumentException( 'Timeout must be an integer.' );
		}
		
		if( !$this->Socket->Open( $Ip, (int)$Port, $Timeout, (int)$Engine ) )
		{
			throw new SourceQueryException( 'Can\'t connect to the server.' );
		}
		
		$this->Connected = true;
	}
	
	/**
	 * Closes all open connections
	 */
	public function Disconnect( )
	{
		$this->Connected = false;
		
		$this->Socket->Close( );
		$this->Rcon->Close( );
	}
	
	/**
	 * Sends ping packet to the server
	 * NOTE: This may not work on some games (TF2 for example)
	 *
	 * @return bool True on success, false on failure
	 */
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
	
	/**
	 * Get server information
	 *
	 * @throws SourceQueryException
	 *
	 * @return bool|array Returns array with information on success, false on failure
	 */
	public function GetInfo( )
	{
		if( !$this->Connected )
		{
			return false;
		}
		
		$this->Socket->Write( self :: A2S_INFO, "Source Engine Query\0" );
		$this->Socket->Read( );
		
		$Type = $this->Buffer->GetByte( );
		
		if( $Type == 0 )
		{
			return false;
		}
		
		// Old GoldSource protocol, HLTV still uses it
		if( $Type == self :: S2A_INFO_OLD && $this->Socket->Engine == self :: GOLDSOURCE )
		{
			/**
			 * If we try to read data again, and we get the result with type S2A_INFO (0x49)
			 * That means this server is running dproto,
			 * Because it sends answer for both protocols
			 */
			
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
	
	/**
	 * Get players on the server
	 *
	 * @throws SourceQueryException
	 *
	 * @return bool|array Returns array with players on success, false on failure
	 */
	public function GetPlayers( )
	{
		if( !$this->Connected )
		{
			return false;
		}
		
		switch( $this->GetChallenge( self :: A2S_PLAYER, self :: S2A_PLAYER ) )
		{
			case self :: GETCHALLENGE_FAILED:
			{
				return false;
			}
			case self :: GETCHALLENGE_ALL_CLEAR:
			{
				$this->Socket->Write( self :: A2S_PLAYER, $this->Challenge );
				$this->Socket->Read( );
				
				$Type = $this->Buffer->GetByte( );
				
				if( $Type == 0 )
				{
					return false;
				}
				else if( $Type != self :: S2A_PLAYER )
				{
					throw new SourceQueryException( 'GetPlayers: Packet header mismatch. (' . $Type . ')' );
				}
				
				break;
			}
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
	
	/**
	 * Get rules (cvars) from the server
	 *
	 * @throws SourceQueryException
	 *
	 * @return bool|array Returns array with rules on success, false on failure
	 */
	public function GetRules( )
	{
		if( !$this->Connected )
		{
			return false;
		}
		
		switch( $this->GetChallenge( self :: A2S_RULES, self :: S2A_RULES ) )
		{
			case self :: GETCHALLENGE_FAILED:
			{
				return false;
			}
			case self :: GETCHALLENGE_ALL_CLEAR:
			{
				$this->Socket->Write( self :: A2S_RULES, $this->Challenge );
				$this->Socket->Read( );
				
				$Type = $this->Buffer->GetByte( );
				
				if( $Type == 0 )
				{
					return false;
				}
				else if( $Type != self :: S2A_RULES )
				{
					throw new SourceQueryException( 'GetRules: Packet header mismatch. (' . $Type . ')' );
				}
				
				break;
			}
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
	
	/**
	 * Get challenge (used for players/rules packets)
	 *
	 * @return bool True if all went well, false if server uses old GoldSource protocol, and it already contains answer
	 */
	private function GetChallenge( $Header, $ExpectedResult )
	{
		if( $this->Challenge )
		{
			return self :: GETCHALLENGE_ALL_CLEAR;
		}
		
		$this->Socket->Write( $Header, 0xFFFFFFFF );
		$this->Socket->Read( );
		
		$Type = $this->Buffer->GetByte( );
		
		switch( $Type )
		{
			case self :: S2A_CHALLENGE:
			{
				$this->Challenge = $this->Buffer->Get( 4 );
				
				return self :: GETCHALLENGE_ALL_CLEAR;
			}
			case $ExpectedResult:
			{
				// Goldsource (HLTV)
				
				return self :: GETCHALLENGE_CONTAINS_ANSWER;
			}
			case 0:
			{
				return self :: GETCHALLENGE_FAILED;
			}
			default:
			{
				throw new SourceQueryException( 'GetChallenge: Packet header mismatch. (' . $Type . ')' );
			}
		}
	}
	
	/**
	 * Sets rcon password, for future use in Rcon()
	 *
	 * @param string $Password Rcon Password
	 *
	 * @return bool True on success, false on failure
	 */
	public function SetRconPassword( $Password )
	{
		if( !$this->Connected )
		{
			return false;
		}
		
		$this->Rcon->Open( );
		
		return $this->Rcon->Authorize( $Password );
	}
	
	/**
	 * Sets rcon password, for future use in Rcon()
	 *
	 * @param string $Command Command to execute on the server
	 *
	 * @return bool|string Answer from server in string, false on failure
	 */
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
	
	/**
	 * Points to buffer class
	 * 
	 * @var SourceQueryBuffer
	 */
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
		
		$this->Socket = FSockOpen( 'udp://' . $Ip, $Port, $ErrNo, $ErrStr, $Timeout );
		
		if( $ErrNo || !$this->Socket )
		{
			throw new Exception( 'Could not create socket: ' . $ErrStr );
		}
		
		Stream_Set_Timeout( $this->Socket, $Timeout );
		Stream_Set_Blocking( $this->Socket, true );
		
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
				// Let's make sure this function exists, it's not included in PHP by default
				if( !Function_Exists( 'bzdecompress' ) )
				{
					throw new RuntimeException( 'Received compressed packet, PHP doesn\'t have Bzip2 library installed, can\'t decompress.' );
				}
				
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
			$this->RconSocket = FSockOpen( $this->Socket->Ip, $this->Socket->Port, $ErrNo, $ErrStr, $this->Socket->Timeout );
			
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
	/**
	 * Buffer
	 * 
	 * @var string
	 */
	private $Buffer;
	
	/**
	 * Buffer length
	 * 
	 * @var int
	 */
	private $Length;
	
	/**
	 * Current position in buffer
	 * 
	 * @var int
	 */
	private $Position;
	
	/**
	 * Sets buffer
	 *
	 * @param string $Buffer Buffer
	 */
	public function Set( $Buffer )
	{
		$this->Buffer   = $Buffer;
		$this->Length   = StrLen( $Buffer );
		$this->Position = 0;
	}
	
	/**
	 * Resets buffer
	 */
	public function Reset( )
	{
		$this->Buffer   = "";
		$this->Length   = 0;
		$this->Position = 0;
	}
	
	/**
	 * Get remaining bytes
	 *
	 * @return int Remaining bytes in buffer
	 */
	public function Remaining( )
	{
		return $this->Length - $this->Position;
	}
	
	/**
	 * Gets data from buffer
	 *
	 * @param int $Length Bytes to read
	 *
	 * @return string
	 */
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
			return "";
		}
		
		$Data = SubStr( $this->Buffer, $this->Position, $Length );
		
		$this->Position += $Length;
		
		return $Data;
	}
	
	/**
	 * Get byte from buffer
	 *
	 * @return int
	 */
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
	
	/**
	 * Read one string from buffer ending with null byte
	 *
	 * @return string String
	 */
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