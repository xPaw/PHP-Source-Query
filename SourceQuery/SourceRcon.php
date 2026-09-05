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

use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;

/**
 * Class SourceRcon
 */
class SourceRcon extends BaseRcon
{
	/**
	 * Points to socket class
	 */
	private BaseSocket $Socket;

	private const MaxPacketSize = 1 << 20;

	/** @var ?resource */
	private $RconSocket;
	private int $RconRequestId = 0;

	public function __construct( BaseSocket $Socket )
	{
		$this->Socket = $Socket;
	}

	public function Close( ) : void
	{
		if( $this->RconSocket )
		{
			fclose( $this->RconSocket );

			$this->RconSocket = null;
		}

		$this->RconRequestId = 0;
	}

	public function Open( ) : void
	{
		if( !$this->RconSocket )
		{
			$RconSocket = @fsockopen( $this->Socket->Address, $this->Socket->Port, $ErrNo, $ErrStr, $this->Socket->Timeout );

			if( $ErrNo || !$RconSocket )
			{
				throw new SocketException( 'Can\'t connect to RCON server: ' . $ErrStr, SocketException::CONNECTION_FAILED );
			}

			$this->RconSocket = $RconSocket;
			stream_set_timeout( $this->RconSocket, $this->Socket->Timeout );
			stream_set_blocking( $this->RconSocket, true );
		}
	}

	public function Write( int $Header, string $String = '' ) : bool
	{
		if( $this->RconSocket === null )
		{
			throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
		}

		// Pack the packet together
		$Command = pack( 'VV', ++$this->RconRequestId, $Header ) . $String . "\x00\x00";

		// Prepend packet length
		$Command = pack( 'V', strlen( $Command ) ) . $Command;
		$Length  = strlen( $Command );

		return $Length === fwrite( $this->RconSocket, $Command, $Length );
	}

	public function Read( ) : Buffer
	{
		if( $this->RconSocket === null )
		{
			throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
		}

		$Buffer = new Buffer( );
		$Buffer->Set( $this->ReadExactly( 4 ) );

		$PacketSize = $Buffer->ReadInt32( );

		if( $PacketSize <= 0 )
		{
			throw new InvalidPacketException( 'Rcon read: Packet size was empty', InvalidPacketException::BUFFER_EMPTY );
		}

		// Source engine packets never exceed a few kilobytes, other implementations stay well under this
		if( $PacketSize > self::MaxPacketSize )
		{
			throw new InvalidPacketException( 'Rcon read: Packet size ' . $PacketSize . ' is too large', InvalidPacketException::PACKET_HEADER_MISMATCH );
		}

		$Buffer->Set( $this->ReadExactly( $PacketSize ) );

		return $Buffer;
	}

	/**
	 * TCP is a stream, a single read may return fewer bytes than asked for.
	 */
	private function ReadExactly( int $Length ) : string
	{
		if( $this->RconSocket === null )
		{
			throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
		}

		$Data = '';

		while( strlen( $Data ) < $Length )
		{
			$Chunk = fread( $this->RconSocket, max( 1, $Length - strlen( $Data ) ) );

			if( $Chunk === false || $Chunk === '' )
			{
				if( $Data === '' )
				{
					throw new InvalidPacketException( 'Rcon read: Failed to read any data from socket', InvalidPacketException::BUFFER_EMPTY );
				}

				throw new InvalidPacketException( 'Read ' . strlen( $Data ) . ' bytes from socket, ' . ( $Length - strlen( $Data ) ) . ' remaining', InvalidPacketException::BUFFER_EMPTY );
			}

			$Data .= $Chunk;
		}

		return $Data;
	}

	public function Command( string $Command ) : string
	{
		$this->Write( SourceQuery::SERVERDATA_EXECCOMMAND, $Command );

		// A response can span several packets and nothing marks the last one.
		// The server answers requests in order, so an empty SERVERDATA_REQUESTVALUE sent right after the command
		// is answered only once the whole response is out, and its request id tells us where the response ends.
		// See https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Multiple-packet_Responses
		$this->Write( SourceQuery::SERVERDATA_REQUESTVALUE );

		$SentinelID = $this->RconRequestId;
		$Data = '';

		do
		{
			$Buffer = $this->Read( );

			$RequestID = $Buffer->ReadInt32( );
			$Type      = $Buffer->ReadInt32( );

			if( $Type === SourceQuery::SERVERDATA_AUTH_RESPONSE )
			{
				throw new AuthenticationException( 'Bad rcon_password.', AuthenticationException::BAD_PASSWORD );
			}
			else if( $Type !== SourceQuery::SERVERDATA_RESPONSE_VALUE )
			{
				throw new InvalidPacketException( 'Invalid rcon response.', InvalidPacketException::PACKET_HEADER_MISMATCH );
			}

			$Packet = $Buffer->Read( );

			// Every packet ends with the string terminator and an empty second string
			if( str_ends_with( $Packet, "\x00\x00" ) )
			{
				$Packet = substr( $Packet, 0, -2 );
			}

			if( $RequestID === $SentinelID )
			{
				// The Source engine first flushes an empty packet for the sentinel, its real reply follows
				if( $Packet === '' )
				{
					continue;
				}

				break;
			}

			$Data .= $Packet;
		}
		while( true );

		return $Data;
	}

	public function Authorize( string $Password ) : void
	{
		$this->Write( SourceQuery::SERVERDATA_AUTH, $Password );
		$Buffer = $this->Read( );

		$RequestID = $Buffer->ReadInt32( );
		$Type      = $Buffer->ReadInt32( );

		// If we receive SERVERDATA_RESPONSE_VALUE, then we need to read again
		// More info: https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Additional_Comments

		if( $Type === SourceQuery::SERVERDATA_RESPONSE_VALUE )
		{
			$Buffer = $this->Read( );

			$RequestID = $Buffer->ReadInt32( );
			$Type      = $Buffer->ReadInt32( );
		}

		if( $RequestID === -1 || $Type !== SourceQuery::SERVERDATA_AUTH_RESPONSE )
		{
			throw new AuthenticationException( 'RCON authorization failed.', AuthenticationException::BAD_PASSWORD );
		}
	}
}
