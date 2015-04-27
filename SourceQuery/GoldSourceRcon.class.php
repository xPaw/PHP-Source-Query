<?php
	/**
	 * Class written by xPaw
	 *
	 * Website: https://xpaw.me
	 * GitHub: https://github.com/xPaw/PHP-Source-Query-Class
	 */
	
	use xPaw\SourceQuery\Exception\AuthenticationException;
	
	class SourceQueryGoldSourceRcon
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
		 */
		public function Read( $Length = 1400 )
		{
			// GoldSource RCON has same structure as Query
			$this->Socket->Read( );
			
			if( $this->Buffer->GetByte( ) !== SourceQuery :: S2A_RCON )
			{
				return false;
			}
			
			$Buffer  = $this->Buffer->Get( );
			$Trimmed = Trim( $Buffer );
			
			if( $Trimmed === 'Bad rcon_password.' )
			{
				throw new AuthenticationException( $Trimmed, AuthenticationException::BAD_PASSWORD );
			}
			else if( $Trimmed === 'You have been banned from this server.' )
			{
				throw new AuthenticationException( $Trimmed, AuthenticationException::BANNED );
			}
			
			$ReadMore = false;
			
			// There is no indentifier of the end, so we just need to continue reading
			// TODO: Needs to be looked again, it causes timeouts
			do
			{
				$this->Socket->Read( );
				
				$ReadMore = $this->Buffer->Remaining( ) > 0 && $this->Buffer->GetByte( ) === SourceQuery :: S2A_RCON;
				
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
		}
		
		public function Command( $Command )
		{
			if( !$this->RconChallenge )
			{
				return false;
			}
			
			$this->Write( 0, 'rcon ' . $this->RconChallenge . ' "' . $this->RconPassword . '" ' . $Command . "\0" );
			$this->Read( );
			
			return $this->Buffer->Get( );
		}
		
		public function Authorize( $Password )
		{
			$this->RconPassword = $Password;
			
			$this->Write( 0, 'challenge rcon' );
			$this->Socket->Read( );
			
			if( $this->Buffer->Get( 14 ) !== 'challenge rcon' )
			{
				return false;
			}
			
			$this->RconChallenge = Trim( $this->Buffer->Get( ) );
			
			return true;
		}
	}
