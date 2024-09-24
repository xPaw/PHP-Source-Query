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
 * Class Socket
 */
class Socket extends BaseSocket
{
	public function Close( ) : void
	{
		if( is_resource( $this->Socket ) )
		{
			fclose( $this->Socket );

			$this->Socket = null;
		}
	}

	public function Open( string $Address, int $Port, int $Timeout, int $Engine ) : void
	{
		$this->Timeout = $Timeout;
		$this->Engine  = $Engine;
		$this->Port    = $Port;
		$this->Address = $Address;

		$Socket = @fsockopen( 'udp://' . $Address, $Port, $ErrNo, $ErrStr, $Timeout );

		if( $ErrNo || $Socket === false )
		{
			throw new SocketException( 'Could not create socket: ' . $ErrStr, SocketException::COULD_NOT_CREATE_SOCKET );
		}

		$this->Socket = $Socket;
		stream_set_timeout( $this->Socket, $Timeout );
		stream_set_blocking( $this->Socket, true );
	}

	public function Write( int $Header, string $String = '' ) : bool
	{
		if( $this->Socket === null )
		{
			throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
		}

		$Command = pack( 'ccccca*', 0xFF, 0xFF, 0xFF, 0xFF, $Header, $String );
		$Length  = strlen( $Command );

		return $Length === fwrite( $this->Socket, $Command, $Length );
	}

	private const MaxPacketLength = 1 << 16;

	/**
	 * Reads from socket and returns Buffer.
	 *
	 * @throws InvalidPacketException
	 */
	public function Read( ) : Buffer
	{
		if( $this->Socket === null )
		{
			throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
		}

		$Data = fread( $this->Socket, self::MaxPacketLength );
		$Buffer = new Buffer( );
		$Buffer->Set( $Data === false ? '' : $Data );

		$this->ReadInternal( $Buffer, [ $this, 'Sherlock' ] );

		return $Buffer;
	}

	public function Sherlock( Buffer $Buffer ) : bool
	{
		if( $this->Socket === null )
		{
			throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
		}

		$Data = fread( $this->Socket, self::MaxPacketLength );

		if( $Data === false || strlen( $Data ) < 4 )
		{
			return false;
		}

		$Buffer->Set( $Data );

		return $Buffer->ReadInt32( ) === -2;
	}
}
