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
	 * Base socket interface
	 *
	 * @package xPaw\SourceQuery
	 *
	 * @uses xPaw\SourceQuery\Exception\InvalidPacketException
	 */
	abstract class BaseSocket
	{
		public $Socket;
		public $Engine;
		
		public $Address;
		public $Port;
		public $Timeout;
		
		public function __destruct( )
		{
			$this->Close( );
		}
		
		abstract public function Close( );
		abstract public function Open( $Address, $Port, $Timeout, $Engine );
		abstract public function Write( $Header, $String = '' );
		abstract public function Read( $Length = 1400 );
		
		protected function ReadInternal( $Buffer, $Length, $SherlockFunction )
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
					}
					
					$Packets[ $PacketNumber ] = $Buffer->Get( );
					
					$ReadMore = $PacketCount > sizeof( $Packets );
				}
				while( $ReadMore && $SherlockFunction( $Buffer, $Length ) );
				
				$Data = Implode( $Packets );
				
				// TODO: Test this
				if( $IsCompressed )
				{
					// Let's make sure this function exists, it's not included in PHP by default
					if( !Function_Exists( 'bzdecompress' ) )
					{
						throw new \RuntimeException( 'Received compressed packet, PHP doesn\'t have Bzip2 library installed, can\'t decompress.' );
					}
					
					$Data = bzdecompress( $Data );
					
					if( CRC32( $Data ) !== $PacketChecksum )
					{
						throw new InvalidPacketException( 'CRC32 checksum mismatch of uncompressed packet data.', InvalidPacketException::CHECKSUM_MISMATCH );
					}
				}
				
				$Buffer->Set( SubStr( $Data, 4 ) );
			}
			else
			{
				throw new InvalidPacketException( 'Socket read: Raw packet header mismatch. (0x' . DecHex( $Header ) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH );
			}
			
			return $Buffer;
		}
	}
