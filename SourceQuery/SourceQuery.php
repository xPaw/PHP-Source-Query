<?php
	/**
	 * This class provides the public interface to the PHP-Source-Query library.
	 *
	 * @author Pavel Djundik
	 *
	 * @link https://xpaw.me
	 * @link https://github.com/xPaw/PHP-Source-Query
	 *
	 * @license GNU Lesser General Public License, version 2.1
	 */

	namespace xPaw\SourceQuery;

	use xPaw\SourceQuery\Exception\AuthenticationException;
	use xPaw\SourceQuery\Exception\InvalidArgumentException;
	use xPaw\SourceQuery\Exception\InvalidPacketException;
	use xPaw\SourceQuery\Exception\SocketException;

	/**
	 * Class SourceQuery
	 *
	 * @package xPaw\SourceQuery
	 *
	 * @uses xPaw\SourceQuery\Exception\AuthenticationException
	 * @uses xPaw\SourceQuery\Exception\InvalidArgumentException
	 * @uses xPaw\SourceQuery\Exception\InvalidPacketException
	 * @uses xPaw\SourceQuery\Exception\SocketException
	 */
	class SourceQuery
	{
		/**
		 * Engines
		 */
		const GOLDSOURCE = 0;
		const SOURCE     = 1;
		
		/**
		 * Packets sent
		 */
		const A2A_PING      = 0x69;
		const A2S_INFO      = 0x54;
		const A2S_PLAYER    = 0x55;
		const A2S_RULES     = 0x56;
		const A2S_SERVERQUERY_GETCHALLENGE = 0x57;
		
		/**
		 * Packets received
		 */
		const A2A_ACK       = 0x6A;
		const S2C_CHALLENGE = 0x41;
		const S2A_INFO_SRC  = 0x49;
		const S2A_INFO_OLD  = 0x6D; // Old GoldSource, HLTV uses it (actually called S2A_INFO_DETAILED)
		const S2A_PLAYER    = 0x44;
		const S2A_RULES     = 0x45;
		const S2A_RCON      = 0x6C;
		
		/**
		 * Source rcon sent
		 */
		const SERVERDATA_REQUESTVALUE   = 0;
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
		 * @var SourceRcon|GoldSourceRcon|null
		 */
		private $Rcon;
		
		/**
		 * Points to socket class
		 */
		private BaseSocket $Socket;
		
		/**
		 * True if connection is open, false if not
		 */
		private bool $Connected = false;
		
		/**
		 * Contains challenge
		 */
		private string $Challenge = '';
		
		/**
		 * Use old method for getting challenge number
		 */
		private bool $UseOldGetChallengeMethod = false;
		
		public function __construct( BaseSocket $Socket = null )
		{
			$this->Socket = $Socket ?: new Socket( );
		}
		
		public function __destruct( )
		{
			$this->Disconnect( );
		}
		
		/**
		 * Opens connection to server
		 *
		 * @param string $Address Server ip
		 * @param int $Port Server port
		 * @param int $Timeout Timeout period
		 * @param int $Engine Engine the server runs on (goldsource, source)
		 *
		 * @throws InvalidArgumentException
		 * @throws SocketException
		 */
		public function Connect( string $Address, int $Port, int $Timeout = 3, int $Engine = self::SOURCE ) : void
		{
			$this->Disconnect( );
			
			if( $Timeout < 0 )
			{
				throw new InvalidArgumentException( 'Timeout must be a positive integer.', InvalidArgumentException::TIMEOUT_NOT_INTEGER );
			}
			
			$this->Socket->Open( $Address, $Port, $Timeout, $Engine );
			
			$this->Connected = true;
		}
		
		/**
		 * Forces GetChallenge to use old method for challenge retrieval because some games use outdated protocol (e.g Starbound)
		 *
		 * @param bool $Value Set to true to force old method
		 *
		 * @returns bool Previous value
		 */
		public function SetUseOldGetChallengeMethod( bool $Value ) : bool
		{
			$Previous = $this->UseOldGetChallengeMethod;
			
			$this->UseOldGetChallengeMethod = $Value === true;
			
			return $Previous;
		}
		
		/**
		 * Closes all open connections
		 */
		public function Disconnect( ) : void
		{
			$this->Connected = false;
			$this->Challenge = '';
			
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
		 * @throws InvalidPacketException
		 * @throws SocketException
		 *
		 * @return bool True on success, false on failure
		 */
		public function Ping( ) : bool
		{
			if( !$this->Connected )
			{
				throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
			}
			
			$this->Socket->Write( self::A2A_PING );
			$Buffer = $this->Socket->Read( );
			
			return $Buffer->GetByte( ) === self::A2A_ACK;
		}
		
		/**
		 * Get server information
		 *
		 * @throws InvalidPacketException
		 * @throws SocketException
		 *
		 * @return array Returns an array with information on success
		 */
		public function GetInfo( ) : array
		{
			if( !$this->Connected )
			{
				throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
			}
			
			if( $this->Challenge )
			{
				$this->Socket->Write( self::A2S_INFO, "Source Engine Query\0" . $this->Challenge );
			}
			else
			{
				$this->Socket->Write( self::A2S_INFO, "Source Engine Query\0" );
			}

			$Buffer = $this->Socket->Read( );
			$Type = $Buffer->GetByte( );
			$Server = [];
			
			if( $Type === self::S2C_CHALLENGE )
			{
				$this->Challenge = $Buffer->Get( 4 );

				$this->Socket->Write( self::A2S_INFO, "Source Engine Query\0" . $this->Challenge );
				$Buffer = $this->Socket->Read( );
				$Type = $Buffer->GetByte( );
			}

			// Old GoldSource protocol, HLTV still uses it
			if( $Type === self::S2A_INFO_OLD && $this->Socket->Engine === self::GOLDSOURCE )
			{
				/**
				 * If we try to read data again, and we get the result with type S2A_INFO (0x49)
				 * That means this server is running dproto,
				 * Because it sends answer for both protocols
				 */
				
				$Server[ 'Address' ]    = $Buffer->GetString( );
				$Server[ 'HostName' ]   = $Buffer->GetString( );
				$Server[ 'Map' ]        = $Buffer->GetString( );
				$Server[ 'ModDir' ]     = $Buffer->GetString( );
				$Server[ 'ModDesc' ]    = $Buffer->GetString( );
				$Server[ 'Players' ]    = $Buffer->GetByte( );
				$Server[ 'MaxPlayers' ] = $Buffer->GetByte( );
				$Server[ 'Protocol' ]   = $Buffer->GetByte( );
				$Server[ 'Dedicated' ]  = chr( $Buffer->GetByte( ) );
				$Server[ 'Os' ]         = chr( $Buffer->GetByte( ) );
				$Server[ 'Password' ]   = $Buffer->GetByte( ) === 1;
				$Server[ 'IsMod' ]      = $Buffer->GetByte( ) === 1;
				
				if( $Server[ 'IsMod' ] )
				{
					$Mod = [];
					$Mod[ 'Url' ]        = $Buffer->GetString( );
					$Mod[ 'Download' ]   = $Buffer->GetString( );
					$Buffer->Get( 1 ); // NULL byte
					$Mod[ 'Version' ]    = $Buffer->GetLong( );
					$Mod[ 'Size' ]       = $Buffer->GetLong( );
					$Mod[ 'ServerSide' ] = $Buffer->GetByte( ) === 1;
					$Mod[ 'CustomDLL' ]  = $Buffer->GetByte( ) === 1;
					$Server[ 'Mod' ] = $Mod;
				}
				
				$Server[ 'Secure' ]   = $Buffer->GetByte( ) === 1;
				$Server[ 'Bots' ]     = $Buffer->GetByte( );
				
				return $Server;
			}
			
			if( $Type !== self::S2A_INFO_SRC )
			{
				throw new InvalidPacketException( 'GetInfo: Packet header mismatch. (0x' . dechex( $Type ) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH );
			}
			
			$Server[ 'Protocol' ]   = $Buffer->GetByte( );
			$Server[ 'HostName' ]   = $Buffer->GetString( );
			$Server[ 'Map' ]        = $Buffer->GetString( );
			$Server[ 'ModDir' ]     = $Buffer->GetString( );
			$Server[ 'ModDesc' ]    = $Buffer->GetString( );
			$Server[ 'AppID' ]      = $Buffer->GetShort( );
			$Server[ 'Players' ]    = $Buffer->GetByte( );
			$Server[ 'MaxPlayers' ] = $Buffer->GetByte( );
			$Server[ 'Bots' ]       = $Buffer->GetByte( );
			$Server[ 'Dedicated' ]  = chr( $Buffer->GetByte( ) );
			$Server[ 'Os' ]         = chr( $Buffer->GetByte( ) );
			$Server[ 'Password' ]   = $Buffer->GetByte( ) === 1;
			$Server[ 'Secure' ]     = $Buffer->GetByte( ) === 1;
			
			// The Ship (they violate query protocol spec by modifying the response)
			if( $Server[ 'AppID' ] === 2400 )
			{
				$Server[ 'GameMode' ]     = $Buffer->GetByte( );
				$Server[ 'WitnessCount' ] = $Buffer->GetByte( );
				$Server[ 'WitnessTime' ]  = $Buffer->GetByte( );
			}
			
			$Server[ 'Version' ] = $Buffer->GetString( );
			
			// Extra Data Flags
			if( $Buffer->Remaining( ) > 0 )
			{
				$Server[ 'ExtraDataFlags' ] = $Flags = $Buffer->GetByte( );
				
				// S2A_EXTRA_DATA_HAS_GAME_PORT - Next 2 bytes include the game port.
				if( $Flags & 0x80 )
				{
					$Server[ 'GamePort' ] = $Buffer->GetShort( );
				}
				
				// S2A_EXTRA_DATA_HAS_STEAMID - Next 8 bytes are the steamID
				// Want to play around with this?
				// You can use https://github.com/xPaw/SteamID.php
				if( $Flags & 0x10 )
				{
					$SteamIDLower    = $Buffer->GetUnsignedLong( );
					$SteamIDInstance = $Buffer->GetUnsignedLong( ); // This gets shifted by 32 bits, which should be steamid instance
					$SteamID = 0;
					
					if( PHP_INT_SIZE === 4 )
					{
						if( extension_loaded( 'gmp' ) )
						{
							$SteamIDLower    = gmp_abs( $SteamIDLower );
							$SteamIDInstance = gmp_abs( $SteamIDInstance );
							$SteamID         = gmp_strval( gmp_or( $SteamIDLower, gmp_mul( $SteamIDInstance, gmp_pow( 2, 32 ) ) ) );
						}
						else
						{
							throw new \RuntimeException( 'Either 64-bit PHP installation or "gmp" module is required to correctly parse server\'s steamid.' );
						}
					}
					else
					{
						$SteamID = $SteamIDLower | ( $SteamIDInstance << 32 );
					}
					
					$Server[ 'SteamID' ] = $SteamID;
					
					unset( $SteamIDLower, $SteamIDInstance, $SteamID );
				}
				
				// S2A_EXTRA_DATA_HAS_SPECTATOR_DATA - Next 2 bytes include the spectator port, then the spectator server name.
				if( $Flags & 0x40 )
				{
					$Server[ 'SpecPort' ] = $Buffer->GetShort( );
					$Server[ 'SpecName' ] = $Buffer->GetString( );
				}
				
				// S2A_EXTRA_DATA_HAS_GAMETAG_DATA - Next bytes are the game tag string
				if( $Flags & 0x20 )
				{
					$Server[ 'GameTags' ] = $Buffer->GetString( );
				}
				
				// S2A_EXTRA_DATA_GAMEID - Next 8 bytes are the gameID of the server
				if( $Flags & 0x01 )
				{
					$Server[ 'GameID' ] = $Buffer->GetUnsignedLong( ) | ( $Buffer->GetUnsignedLong( ) << 32 ); 
				}
				
				if( $Buffer->Remaining( ) > 0 )
				{
					throw new InvalidPacketException( 'GetInfo: unread data? ' . $Buffer->Remaining( ) . ' bytes remaining in the buffer. Please report it to the library developer.',
						InvalidPacketException::BUFFER_NOT_EMPTY );
				}
			}
			
			return $Server;
		}
		
		/**
		 * Get players on the server
		 *
		 * @throws InvalidPacketException
		 * @throws SocketException
		 * 
		 * @return array Returns an array with players on success
		 */
		public function GetPlayers( ) : array
		{
			if( !$this->Connected )
			{
				throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
			}
			
			$this->GetChallenge( self::A2S_PLAYER, self::S2A_PLAYER );
			
			$this->Socket->Write( self::A2S_PLAYER, $this->Challenge );
			$Buffer = $this->Socket->Read( 14000 ); // Arma 3 developers do not split their packets, so we have to read more data
			// This violates the protocol spec, and they probably should fix it: https://developer.valvesoftware.com/wiki/Server_queries#Protocol
			
			$Type = $Buffer->GetByte( );
			
			if( $Type !== self::S2A_PLAYER )
			{
				throw new InvalidPacketException( 'GetPlayers: Packet header mismatch. (0x' . dechex( $Type ) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH );
			}
			
			$Players = [];
			$Count   = $Buffer->GetByte( );
			
			while( $Count-- > 0 && $Buffer->Remaining( ) > 0 )
			{
				$Player = [];
				$Player[ 'Id' ]    = $Buffer->GetByte( ); // PlayerID, is it just always 0?
				$Player[ 'Name' ]  = $Buffer->GetString( );
				$Player[ 'Frags' ] = $Buffer->GetLong( );
				$Player[ 'Time' ]  = (int)$Buffer->GetFloat( );
				$Player[ 'TimeF' ] = gmdate( ( $Player[ 'Time' ] > 3600 ? 'H:i:s' : 'i:s' ), $Player[ 'Time' ] );
				
				$Players[ ] = $Player;
			}
			
			return $Players;
		}
		
		/**
		 * Get rules (cvars) from the server
		 *
		 * @throws InvalidPacketException
		 * @throws SocketException
		 *
		 * @return array Returns an array with rules on success
		 */
		public function GetRules( ) : array
		{
			if( !$this->Connected )
			{
				throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
			}
			
			$this->GetChallenge( self::A2S_RULES, self::S2A_RULES );
			
			$this->Socket->Write( self::A2S_RULES, $this->Challenge );
			$Buffer = $this->Socket->Read( 14000 ); // fix Rust long desc 
			
			$Type = $Buffer->GetByte( );
			
			if( $Type !== self::S2A_RULES )
			{
				throw new InvalidPacketException( 'GetRules: Packet header mismatch. (0x' . dechex( $Type ) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH );
			}
			
			$Rules = [];
			$Count = $Buffer->GetShort( );
			
			while( $Count-- > 0 && $Buffer->Remaining( ) > 0 )
			{
				$Rule  = $Buffer->GetString( );
				$Value = $Buffer->GetString( );
				
				if( !empty( $Rule ) )
				{
					$Rules[ $Rule ] = $Value;
				}
			}
			
			return $Rules;
		}
		
		/**
		 * Get challenge (used for players/rules packets)
		 *
		 * @throws InvalidPacketException
		 */
		private function GetChallenge( int $Header, int $ExpectedResult ) : void
		{
			if( $this->Challenge )
			{
				return;
			}
			
			if( $this->UseOldGetChallengeMethod )
			{
				$Header = self::A2S_SERVERQUERY_GETCHALLENGE;
			}
			
			$this->Socket->Write( $Header, "\xFF\xFF\xFF\xFF" );
			$Buffer = $this->Socket->Read( );
			
			$Type = $Buffer->GetByte( );
			
			switch( $Type )
			{
				case self::S2C_CHALLENGE:
				{
					$this->Challenge = $Buffer->Get( 4 );
					
					return;
				}
				case $ExpectedResult:
				{
					// Goldsource (HLTV)
					
					return;
				}
				case 0:
				{
					throw new InvalidPacketException( 'GetChallenge: Failed to get challenge.' );
				}
				default:
				{
					throw new InvalidPacketException( 'GetChallenge: Packet header mismatch. (0x' . dechex( $Type ) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH );
				}
			}
		}
		
		/**
		 * Sets rcon password, for future use in Rcon()
		 *
		 * @param string $Password Rcon Password
		 *
		 * @throws AuthenticationException
		 * @throws InvalidPacketException
		 * @throws SocketException
		 */
		public function SetRconPassword( string $Password ) : void
		{
			if( !$this->Connected )
			{
				throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
			}
			
			switch( $this->Socket->Engine )
			{
				case SourceQuery::GOLDSOURCE:
				{
					$this->Rcon = new GoldSourceRcon( $this->Socket );
					
					break;
				}
				case SourceQuery::SOURCE:
				{
					$this->Rcon = new SourceRcon( $this->Socket );
					
					break;
				}
				default:
				{
					throw new SocketException( 'Unknown engine.', SocketException::INVALID_ENGINE );
				}
			}
			
			$this->Rcon->Open( );
			$this->Rcon->Authorize( $Password );
		}
		
		/**
		 * Sends a command to the server for execution.
		 *
		 * @param string $Command Command to execute
		 *
		 * @throws AuthenticationException
		 * @throws InvalidPacketException
		 * @throws SocketException
		 *
		 * @return string Answer from server in string
		 */
		public function Rcon( string $Command ) : string
		{
			if( !$this->Connected )
			{
				throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
			}
			
			if( $this->Rcon === null )
			{
				throw new SocketException( 'You must set a RCON password before trying to execute a RCON command.', SocketException::NOT_CONNECTED );
			}
			
			return $this->Rcon->Command( $Command );
		}
	}
