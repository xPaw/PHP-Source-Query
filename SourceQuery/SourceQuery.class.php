<?php
	/**
	 * Class written by xPaw
	 *
	 * Website: https://xpaw.me
	 * GitHub: https://github.com/xPaw/PHP-Source-Query-Class
	 *
	 * Special thanks to koraktor for his awesome Steam Condenser class,
	 * I used it as a reference at some points.
	 */
	
	require __DIR__ . '/Exceptions.class.php';
	require __DIR__ . '/Buffer.class.php';
	require __DIR__ . '/Socket.class.php';
	require __DIR__ . '/SourceRcon.class.php';
	require __DIR__ . '/GoldSourceRcon.class.php';
	
	use xPaw\SourceQuery\Exception\InvalidArgumentException;
	use xPaw\SourceQuery\Exception\TimeoutException;
	use xPaw\SourceQuery\Exception\InvalidPacketException;
	
	class SourceQuery
	{
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
		const A2S_SERVERQUERY_GETCHALLENGE = 0x57;
		
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
		
		/**
		 * Use old method for getting challenge number
		 * 
		 * @var bool
		 */
		private $UseOldGetChallengeMethod;
		
		public function __construct( )
		{
			$this->Buffer = new SourceQueryBuffer( );
			$this->Socket = new SourceQuerySocket( $this->Buffer );
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
		 * @throws InvalidArgumentException
		 * @throws TimeoutException
		 */
		public function Connect( $Ip, $Port, $Timeout = 3, $Engine = self :: SOURCE )
		{
			$this->Disconnect( );
			
			if( !is_int( $Timeout ) || $Timeout < 0 )
			{
				throw new InvalidArgumentException( 'Timeout must be an integer.', InvalidArgumentException::TIMEOUT_NOT_INTEGER );
			}
			
			if( !$this->Socket->Open( $Ip, (int)$Port, $Timeout, (int)$Engine ) )
			{
				throw new TimeoutException( 'Could not connect to server.', TimeoutException::TIMEOUT_CONNECT );
			}
			
			$this->Connected = true;
		}
		
		/**
		 * Forces GetChallenge to use old method for challenge retrieval because some games use outdated protocol (e.g Starbound)
		 *
		 * @param bool $Value Set to true to force old method
		 *
		 * @returns bool Previous value
		 */
		public function SetUseOldGetChallengeMethod( $Value )
		{
			$Previous = $this->UseOldGetChallengeMethod;
			
			$this->UseOldGetChallengeMethod = $Value === true;
			
			return $Previous;
		}
		
		/**
		 * Closes all open connections
		 */
		public function Disconnect( )
		{
			$this->Connected = false;
			$this->Challenge = 0;
			
			$this->Buffer->Reset( );
			
			$this->Socket->Close( );
			
			if( $this->Rcon )
			{
				$this->Rcon->Close( );
				
				$this->Rcon = null;
			}
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
			
			return $this->Buffer->GetByte( ) === self :: S2A_PING;
		}
		
		/**
		 * Get server information
		 *
		 * @throws InvalidPacketException
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
			
			if( $Type === 0 )
			{
				return false;
			}
			
			// Old GoldSource protocol, HLTV still uses it
			if( $Type === self :: S2A_INFO_OLD && $this->Socket->Engine === self :: GOLDSOURCE )
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
				$Server[ 'Password' ]   = $this->Buffer->GetByte( ) === 1;
				$Server[ 'IsMod' ]      = $this->Buffer->GetByte( ) === 1;
				
				if( $Server[ 'IsMod' ] )
				{
					$Mod[ 'Url' ]        = $this->Buffer->GetString( );
					$Mod[ 'Download' ]   = $this->Buffer->GetString( );
					$this->Buffer->Get( 1 ); // NULL byte
					$Mod[ 'Version' ]    = $this->Buffer->GetLong( );
					$Mod[ 'Size' ]       = $this->Buffer->GetLong( );
					$Mod[ 'ServerSide' ] = $this->Buffer->GetByte( ) === 1;
					$Mod[ 'CustomDLL' ]  = $this->Buffer->GetByte( ) === 1;
				}
				
				$Server[ 'Secure' ]   = $this->Buffer->GetByte( ) === 1;
				$Server[ 'Bots' ]     = $this->Buffer->GetByte( );
				
				if( isset( $Mod ) )
				{
					$Server[ 'Mod' ] = $Mod;
				}
				
				return $Server;
			}
			
			if( $Type !== self :: S2A_INFO )
			{
				throw new InvalidPacketException( 'GetInfo: Packet header mismatch. (0x' . DecHex( $Type ) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH );
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
			$Server[ 'Password' ]   = $this->Buffer->GetByte( ) === 1;
			$Server[ 'Secure' ]     = $this->Buffer->GetByte( ) === 1;
			
			// The Ship (they violate query protocol spec by modifying the response)
			if( $Server[ 'AppID' ] === 2400 )
			{
				$Server[ 'GameMode' ]     = $this->Buffer->GetByte( );
				$Server[ 'WitnessCount' ] = $this->Buffer->GetByte( );
				$Server[ 'WitnessTime' ]  = $this->Buffer->GetByte( );
			}
			
			$Server[ 'Version' ] = $this->Buffer->GetString( );
			
			// Extra Data Flags
			if( $this->Buffer->Remaining( ) > 0 )
			{
				$Server[ 'ExtraDataFlags' ] = $Flags = $this->Buffer->GetByte( );
				
				// The server's game port
				if( $Flags & 0x80 )
				{
					$Server[ 'GamePort' ] = $this->Buffer->GetShort( );
				}
				
				// The server's SteamID - does this serve any purpose?
				if( $Flags & 0x10 )
				{
					$Server[ 'ServerID' ] = $this->Buffer->GetUnsignedLong( ) | ( $this->Buffer->GetUnsignedLong( ) << 32 ); // TODO: verify this
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
				
				// GameID -- alternative to AppID?
				if( $Flags & 0x01 )
				{
					$Server[ 'GameID' ] = $this->Buffer->GetUnsignedLong( ) | ( $this->Buffer->GetUnsignedLong( ) << 32 ); 
				}
				
				if( $this->Buffer->Remaining( ) > 0 )
				{
					throw new InvalidPacketException( 'GetInfo: unread data? ' . $this->Buffer->Remaining( ) . ' bytes remaining in the buffer. Please report it to the library developer.',
						InvalidPacketException::BUFFER_NOT_EMPTY );
				}
			}
			
			return $Server;
		}
		
		/**
		 * Get players on the server
		 *
		 * @throws InvalidPacketException
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
					$this->Socket->Read( 14000 ); // Moronic Arma 3 developers do not split their packets, so we have to read more data
					// This violates the protocol spec, and they probably should fix it: https://developer.valvesoftware.com/wiki/Server_queries#Protocol
					
					$Type = $this->Buffer->GetByte( );
					
					if( $Type === 0 )
					{
						return false;
					}
					else if( $Type !== self :: S2A_PLAYER )
					{
						throw new InvalidPacketException( 'GetPlayers: Packet header mismatch. (0x' . DecHex( $Type ) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH );
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
		 * @throws InvalidPacketException
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
					
					if( $Type === 0 )
					{
						return false;
					}
					else if( $Type !== self :: S2A_RULES )
					{
						throw new InvalidPacketException( 'GetRules: Packet header mismatch. (0x' . DecHex( $Type ) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH );
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
		 * @param $Header
		 * @param $ExpectedResult
		 * @throws InvalidPacketException
		 * @return bool True if all went well, false if server uses old GoldSource protocol, and it already contains answer
		 */
		private function GetChallenge( $Header, $ExpectedResult )
		{
			if( $this->Challenge )
			{
				return self :: GETCHALLENGE_ALL_CLEAR;
			}
			
			if( $this->UseOldGetChallengeMethod )
			{
				$Header = self :: A2S_SERVERQUERY_GETCHALLENGE;
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
					throw new InvalidPacketException( 'GetChallenge: Packet header mismatch. (0x' . DecHex( $Type ) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH );
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
			
			switch( $this->Socket->Engine )
			{
				case SourceQuery :: GOLDSOURCE:
				{
					$this->Rcon = new SourceQueryGoldSourceRcon( $this->Buffer, $this->Socket );
					
					break;
				}
				case SourceQuery :: SOURCE:
				{
					$this->Rcon = new SourceQuerySourceRcon( $this->Buffer, $this->Socket );
					
					break;
				}
			}
			
			$this->Rcon->Open( );
			
			return $this->Rcon->Authorize( $Password );
		}
		
		/**
		 * Sends a command to the server for execution.
		 *
		 * @param string $Command Command to execute
		 *
		 * @return string|bool Answer from server in string, false on failure
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
