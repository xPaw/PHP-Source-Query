<?php
declare(strict_types=1);

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
	abstract public function Read( ) : Buffer;

	protected function ReadInternal( Buffer $Buffer, callable $SherlockFunction ) : Buffer
	{
		if( $Buffer->Remaining( ) === 0 )
		{
			throw new InvalidPacketException( 'Failed to read any data from socket', InvalidPacketException::BUFFER_EMPTY );
		}

		$Header = $Buffer->ReadInt32( );

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
			$ExpectedRequestID = null;

			do
			{
				$RequestID = $Buffer->ReadInt32( );
				$PacketCount = 0;
				$PacketNumber = 0;

				$ExpectedRequestID ??= $RequestID;

				if( $RequestID !== $ExpectedRequestID )
				{
					throw new InvalidPacketException( 'Split packet request id mismatch.', InvalidPacketException::INVALID_SPLIT_PACKET );
				}

				switch( $this->Engine )
				{
					case SourceQuery::GOLDSOURCE:
					{
						$PacketCountAndNumber = $Buffer->ReadByte( );
						$PacketCount          = $PacketCountAndNumber & 0xF;
						$PacketNumber         = $PacketCountAndNumber >> 4;

						break;
					}
					case SourceQuery::SOURCE:
					{
						$IsCompressed         = ( $RequestID & 0x80000000 ) !== 0;
						$PacketCount          = $Buffer->ReadByte( );
						$PacketNumber         = $Buffer->ReadByte( ) + 1;

						// Only the first packet carries the decompressed size and checksum
						if( $IsCompressed && $PacketNumber === 1 )
						{
							$Buffer->ReadInt32( ); // Decompressed size

							$PacketChecksum = $Buffer->ReadUInt32( );
						}

						break;
					}
					default:
					{
						throw new SocketException( 'Unknown engine.', SocketException::INVALID_ENGINE );
					}
				}

				$Packets[ $PacketNumber ] = $Buffer->Read( );

				$ReadMore = $PacketCount > sizeof( $Packets );
			}
			while( $ReadMore && $SherlockFunction( $Buffer ) );

			if( sizeof( $Packets ) !== $PacketCount )
			{
				throw new InvalidPacketException( 'Received ' . sizeof( $Packets ) . ' of ' . $PacketCount . ' split packets.', InvalidPacketException::INVALID_SPLIT_PACKET );
			}

			// UDP Packets can arrive in wrong order
			ksort($Packets, SORT_NUMERIC);

			// Since the Orange Box the split header also carries the uncompressed packet size (2 bytes), Source 2006 servers omit it.
			// The first packet's payload starts with the 0xFFFFFFFF header, so anything else there is the size field.
			if( $this->Engine === SourceQuery::SOURCE && !$IsCompressed && !str_starts_with( $Packets[ 1 ], "\xFF\xFF\xFF\xFF" ) )
			{
				$Packets = array_map( static fn( string $Packet ) : string => substr( $Packet, 2 ), $Packets );
			}

			$Data = implode( $Packets );

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
