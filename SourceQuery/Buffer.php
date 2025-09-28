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

/**
 * Class Buffer
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
	 *
	 * @phpstan-impure
	 */
	public function Remaining( ) : int
	{
		return $this->Length - $this->Position;
	}

	/**
	 * Reads the specified number of bytes.
	 *
	 * @param int $Length Bytes to read
	 */
	public function Read( int $Length = -1 ) : string
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
	 * Reads the next byte.
	 */
	public function ReadByte( ) : int
	{
		return ord( $this->Read( 1 ) );
	}

	/**
	 * Reads a 2-byte signed integer.
	 */
	public function ReadInt16( ) : int
	{
		if( $this->Remaining( ) < 2 )
		{
			throw new InvalidPacketException( 'Not enough data to unpack.', InvalidPacketException::BUFFER_EMPTY );
		}

		$Data = unpack( 'v', $this->Read( 2 ) );

		if( $Data === false )
		{
			throw new InvalidPacketException( 'Failed to unpack.', InvalidPacketException::UNPACK_FAILED );
		}

		return (int)$Data[ 1 ];
	}

	/**
	 * Reads a 4-byte signed integer.
	 */
	public function ReadInt32( ) : int
	{
		if( $this->Remaining( ) < 4 )
		{
			throw new InvalidPacketException( 'Not enough data to unpack.', InvalidPacketException::BUFFER_EMPTY );
		}

		$Data = unpack( 'l', $this->Read( 4 ) );

		if( $Data === false )
		{
			throw new InvalidPacketException( 'Failed to unpack.', InvalidPacketException::UNPACK_FAILED );
		}

		return (int)$Data[ 1 ];
	}

	/**
	 * Reads a 4-byte floating point value.
	 */
	public function ReadFloat32( ) : float
	{
		if( $this->Remaining( ) < 4 )
		{
			throw new InvalidPacketException( 'Not enough data to unpack.', InvalidPacketException::BUFFER_EMPTY );
		}

		$Data = unpack( 'f', $this->Read( 4 ) );

		if( $Data === false )
		{
			throw new InvalidPacketException( 'Failed to unpack.', InvalidPacketException::UNPACK_FAILED );
		}

		return (float)$Data[ 1 ];
	}

	/**
	 * Reads a 4-byte unsigned integer.
	 */
	public function ReadUInt32( ) : int
	{
		if( $this->Remaining( ) < 4 )
		{
			throw new InvalidPacketException( 'Not enough data to unpack.', InvalidPacketException::BUFFER_EMPTY );
		}

		$Data = unpack( 'V', $this->Read( 4 ) );

		if( $Data === false )
		{
			throw new InvalidPacketException( 'Failed to unpack.', InvalidPacketException::UNPACK_FAILED );
		}

		return (int)$Data[ 1 ];
	}

	/**
	 * Read a null-terminated string.
	 */
	public function ReadNullTermString( ) : string
	{
		$ZeroBytePosition = strpos( $this->Buffer, "\0", $this->Position );

		if( $ZeroBytePosition === false )
		{
			return '';
		}

		$String = $this->Read( $ZeroBytePosition - $this->Position );

		$this->Position++;

		return $String;
	}
}
