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

	/**
	 * Class Buffer
	 *
	 * @package xPaw\SourceQuery
	 *
	 * @uses xPaw\SourceQuery\Exception\InvalidPacketException
	 */
	class Buffer
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
			if( $this->Remaining( ) < 2 )
			{
				throw new InvalidPacketException( 'Not enough data to unpack a short.', InvalidPacketException::BUFFER_EMPTY );
			}
			
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
			if( $this->Remaining( ) < 4 )
			{
				throw new InvalidPacketException( 'Not enough data to unpack a long.', InvalidPacketException::BUFFER_EMPTY );
			}
			
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
			if( $this->Remaining( ) < 4 )
			{
				throw new InvalidPacketException( 'Not enough data to unpack a float.', InvalidPacketException::BUFFER_EMPTY );
			}
			
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
			if( $this->Remaining( ) < 4 )
			{
				throw new InvalidPacketException( 'Not enough data to unpack an usigned long.', InvalidPacketException::BUFFER_EMPTY );
			}
			
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
				return '';
			}
			
			$String = $this->Get( $ZeroBytePosition - $this->Position );
			
			$this->Position++;
			
			return $String;
		}
	}
