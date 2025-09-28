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
	use xPaw\SourceQuery\Exception\SocketException;

	/**
	 * Base socket interface
	 *
	 * @package xPaw\SourceQuery
	 *
	 * @uses xPaw\SourceQuery\Exception\InvalidPacketException
	 * @uses xPaw\SourceQuery\Exception\SocketException
	 */
	abstract class BaseSocket
	{
		/** @var ?resource */
		public $Socket;
		public int $Engine;
		
		public string $Address;
		public int $Port;
		public int $Timeout;
		
		public function __destruct( )
		{
			$this->Close( );
		}
		
		abstract public function Close( ) : void;
		abstract public function Open( string $Address, int $Port, int $Timeout, int $Engine ) : void;
		abstract public function Write( int $Header, string $String = '' ) : bool;
		abstract public function Read( int $Length = 1400 ) : Buffer;
		
		protected function ReadInternal( Buffer $Buffer, int $Length, callable $SherlockFunction ) : Buffer
		{
			if( $Buffer->Remaining( ) === 0 )
			{
				throw new InvalidPacketException( 'Failed to read any data from socket', InvalidPacketException::BUFFER_EMPTY );
			}
			
			$Header = $Buffer->GetLong( );
			
			if( $Header === -1 ) // Single packet
			{
				// We don't have to do anything
			}
			else if( $Header === -2 ) // Split packet
			{
				$Packets      = [];
				$IsCompressed = false;
				$ReadMore     = false;
				$PacketChecksum = null;
				
				do
				{
					$RequestID = $Buffer->GetLong( );
					
					switch( $this->Engine )
					{
						case SourceQuery::GOLDSOURCE:
						{
							$PacketCountAndNumber = $Buffer->GetByte( );
							$PacketCount          = $PacketCountAndNumber & 0xF;
							$PacketNumber         = $PacketCountAndNumber >> 4;
							
							break;
						}
						case SourceQuery::SOURCE:
						{
							$IsCompressed         = ( $RequestID & 0x80000000 ) !== 0;
							$PacketCount          = $Buffer->GetByte( );
							$PacketNumber         = $Buffer->GetByte( ) + 1;
							
							if( $IsCompressed )
							{
								$Buffer->GetLong( ); // Split size
								
								$PacketChecksum = $Buffer->GetUnsignedLong( );
							}
							else
							{
								$Buffer->GetShort( ); // Split size
							}
							
							break;
						}
						default:
						{
							throw new SocketException( 'Unknown engine.', SocketException::INVALID_ENGINE );
						}
					}
					
					$Packets[ $PacketNumber ] = $Buffer->Get( );
					
					$ReadMore = $PacketCount > sizeof( $Packets );
				}
				while( $ReadMore && $SherlockFunction( $Buffer, $Length ) );
				
				$Data = implode( $Packets );
				
				// TODO: Test this
				if( $IsCompressed )
				{
					// Let's make sure this function exists, it's not included in PHP by default
					if( !function_exists( 'bzdecompress' ) )
					{
						throw new \RuntimeException( 'Received compressed packet, PHP doesn\'t have Bzip2 library installed, can\'t decompress.' );
					}
					
					$Data = bzdecompress( $Data );
					
					if( !is_string( $Data ) || crc32( $Data ) !== $PacketChecksum )
					{
						throw new InvalidPacketException( 'CRC32 checksum mismatch of uncompressed packet data.', InvalidPacketException::CHECKSUM_MISMATCH );
					}
				}
				
				$Buffer->Set( substr( $Data, 4 ) );
			}
			else
			{
				throw new InvalidPacketException( 'Socket read: Raw packet header mismatch. (0x' . dechex( $Header ) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH );
			}
			
			return $Buffer;
		}
	}
