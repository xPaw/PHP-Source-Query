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

	/**
	 * Class GoldSourceRcon
	 *
	 * @package xPaw\SourceQuery
	 *
	 * @uses xPaw\SourceQuery\Exception\AuthenticationException
	 */
	class GoldSourceRcon
	{
		/**
		 * Points to socket class
		 * 
		 * @var Socket
		 */
		private $Socket;
		
		private $RconPassword;
		private $RconRequestId;
		private $RconChallenge;
		
		public function __construct( $Socket )
		{
			$this->Socket = $Socket;
		}
		
		public function Close( )
		{
			$this->RconChallenge = 0;
			$this->RconRequestId = 0;
			$this->RconPassword  = 0;
		}
		
		public function Open( )
		{
			//
		}
		
		public function Write( $Header, $String = '' )
		{
			$Command = Pack( 'cccca*', 0xFF, 0xFF, 0xFF, 0xFF, $String );
			$Length  = StrLen( $Command );
			
			return $Length === FWrite( $this->Socket->Socket, $Command, $Length );
		}
		
		/**
		 * @param int $Length
		 * @throws AuthenticationException
		 * @return bool
		 */
		public function Read( $Length = 1400 )
		{
			// GoldSource RCON has same structure as Query
			$Buffer = $this->Socket->Read( );
			
			$StringBuffer = '';
			$ReadMore = false;
			
			// There is no indentifier of the end, so we just need to continue reading
			do
			{
				$ReadMore = $Buffer->Remaining( ) > 0;
				
				if( $ReadMore )
				{
					if( $Buffer->GetByte( ) !== SourceQuery::S2A_RCON )
					{
						throw new InvalidPacketException( 'Invalid rcon response.', InvalidPacketException::PACKET_HEADER_MISMATCH );
					}
					
					$Packet = $Buffer->Get( );
					$StringBuffer .= $Packet;
					//$StringBuffer .= SubStr( $Packet, 0, -2 );
					
					// Let's assume if this packet is not long enough, there are no more after this one
					$ReadMore = StrLen( $Packet ) > 1000; // use 1300?
					
					if( $ReadMore )
					{
						$Buffer = $this->Socket->Read( );
					}
				}
			}
			while( $ReadMore );
			
			$Trimmed = trim( $StringBuffer );
			
			if( $Trimmed === 'Bad rcon_password.' )
			{
				throw new AuthenticationException( $Trimmed, AuthenticationException::BAD_PASSWORD );
			}
			else if( $Trimmed === 'You have been banned from this server.' )
			{
				throw new AuthenticationException( $Trimmed, AuthenticationException::BANNED );
			}
			
			$Buffer->Set( $Trimmed );
			
			return $Buffer;
		}
		
		public function Command( $Command )
		{
			if( !$this->RconChallenge )
			{
				throw new AuthenticationException( 'Tried to execute a RCON command before successful authorization.', AuthenticationException::BAD_PASSWORD );
			}
			
			$this->Write( 0, 'rcon ' . $this->RconChallenge . ' "' . $this->RconPassword . '" ' . $Command . "\0" );
			$Buffer = $this->Read( );
			
			return $Buffer->Get( );
		}
		
		public function Authorize( $Password )
		{
			$this->RconPassword = $Password;
			
			$this->Write( 0, 'challenge rcon' );
			$Buffer = $this->Socket->Read( );
			
			if( $Buffer->Get( 14 ) !== 'challenge rcon' )
			{
				throw new AuthenticationException( 'Failed to get RCON challenge.', AuthenticationException::BAD_PASSWORD );
			}
			
			$this->RconChallenge = Trim( $Buffer->Get( ) );
		}
	}
