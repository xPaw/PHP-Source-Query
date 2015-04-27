<?php
	/**
	 * Class written by xPaw
	 *
	 * Website: https://xpaw.me
	 * GitHub: https://github.com/xPaw/PHP-Source-Query-Class
	 */
	
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
			if( $Length === 0 )
			{
				return '';
			}
			
			$Remaining = $this->Remaining( );
			
			if( $Length === -1 )
			{
				$Length = $Remaining;
			}
			else if( $Length > $Remaining )
			{
				return '';
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
		
		/**
		 * Get short from buffer
		 *
		 * @return int
		 */
		public function GetShort( )
		{
			$Data = UnPack( 'v', $this->Get( 2 ) );
			
			return $Data[ 1 ];
		}
		
		/**
		 * Get long from buffer
		 *
		 * @return int
		 */
		public function GetLong( )
		{
			$Data = UnPack( 'l', $this->Get( 4 ) );
			
			return $Data[ 1 ];
		}
		
		/**
		 * Get float from buffer
		 *
		 * @return float
		 */
		public function GetFloat( )
		{
			$Data = UnPack( 'f', $this->Get( 4 ) );
			
			return $Data[ 1 ];
		}
		
		/**
		 * Get unsigned long from buffer
		 *
		 * @return int
		 */
		public function GetUnsignedLong( )
		{
			$Data = UnPack( 'V', $this->Get( 4 ) );
			
			return $Data[ 1 ];
		}
		
		/**
		 * Read one string from buffer ending with null byte
		 *
		 * @return string
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
