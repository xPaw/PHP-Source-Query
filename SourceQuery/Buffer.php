<?php
	/**
	 * @author Pavel Djundik
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
		 */
		private string $Buffer = '';
		
		/**
		 * Buffer length
		 */
		private int $Length = 0;
		
		/**
		 * Current position in buffer
		 */
		private int $Position = 0;
		
		/**
		 * Sets buffer
		 */
		public function Set( string $Buffer ) : void
		{
			$this->Buffer   = $Buffer;
			$this->Length   = strlen( $Buffer );
			$this->Position = 0;
		}
		
		/**
		 * Get remaining bytes
		 *
		 * @return int Remaining bytes in buffer
		 */
		public function Remaining( ) : int
		{
			return $this->Length - $this->Position;
		}
		
		/**
		 * Gets data from buffer
		 *
		 * @param int $Length Bytes to read
		 */
		public function Get( int $Length = -1 ) : string
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
			
			$Data = substr( $this->Buffer, $this->Position, $Length );
			
			$this->Position += $Length;
			
			return $Data;
		}
		
		/**
		 * Get byte from buffer
		 */
		public function GetByte( ) : int
		{
			return ord( $this->Get( 1 ) );
		}
		
		/**
		 * Get short from buffer
		 */
		public function GetShort( ) : int
		{
			if( $this->Remaining( ) < 2 )
			{
				throw new InvalidPacketException( 'Not enough data to unpack a short.', InvalidPacketException::BUFFER_EMPTY );
			}
			
			$Data = unpack( 'v', $this->Get( 2 ) );
			
			return (int)$Data[ 1 ];
		}
		
		/**
		 * Get long from buffer
		 */
		public function GetLong( ) : int
		{
			if( $this->Remaining( ) < 4 )
			{
				throw new InvalidPacketException( 'Not enough data to unpack a long.', InvalidPacketException::BUFFER_EMPTY );
			}
			
			$Data = unpack( 'l', $this->Get( 4 ) );
			
			return (int)$Data[ 1 ];
		}
		
		/**
		 * Get float from buffer
		 */
		public function GetFloat( ) : float
		{
			if( $this->Remaining( ) < 4 )
			{
				throw new InvalidPacketException( 'Not enough data to unpack a float.', InvalidPacketException::BUFFER_EMPTY );
			}
			
			$Data = unpack( 'f', $this->Get( 4 ) );
			
			return (float)$Data[ 1 ];
		}
		
		/**
		 * Get unsigned long from buffer
		 */
		public function GetUnsignedLong( ) : int
		{
			if( $this->Remaining( ) < 4 )
			{
				throw new InvalidPacketException( 'Not enough data to unpack an usigned long.', InvalidPacketException::BUFFER_EMPTY );
			}
			
			$Data = unpack( 'V', $this->Get( 4 ) );
			
			return (int)$Data[ 1 ];
		}
		
		/**
		 * Read one string from buffer ending with null byte
		 */
		public function GetString( ) : string
		{
			$ZeroBytePosition = strpos( $this->Buffer, "\0", $this->Position );
			
			if( $ZeroBytePosition === false )
			{
				return '';
			}
			
			$String = $this->Get( $ZeroBytePosition - $this->Position );
			
			$this->Position++;
			
			return $String;
		}
	}
