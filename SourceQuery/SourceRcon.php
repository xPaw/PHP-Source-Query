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
		return $this->WriteRaw( self::Pack( ++$this->RconRequestId, $Header, $String ) );
	}

	/**
	 * Packet length, request id, type, the string, and an empty second string
	 */
	private static function Pack( int $RequestID, int $Header, string $String ) : string
	{
		$Packet = pack( 'VV', $RequestID, $Header ) . $String . "\x00\x00";

		return pack( 'V', strlen( $Packet ) ) . $Packet;
	}

	private function WriteRaw( string $Data ) : bool
	{
		if( $this->RconSocket === null )
		{
			throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
		}

		$Length = strlen( $Data );

		return $Length === fwrite( $this->RconSocket, $Data, $Length );
	}

	public function Read( ) : Buffer
	{
		if( $this->RconSocket === null )
		{
			throw new SocketException( 'Not connected.', SocketException::NOT_CONNECTED );
		}

		$Buffer = new Buffer( );
		$Buffer->Set( self::ReadExactly( $this->RconSocket, 4 ) );

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

		$Buffer->Set( self::ReadExactly( $this->RconSocket, $PacketSize ) );

		return $Buffer;
	}

	/**
	 * TCP is a stream, a single read may return fewer bytes than asked for.
	 *
	 * @param resource $Socket
	 */
	private static function ReadExactly( $Socket, int $Length ) : string
	{
		$Data = '';

		while( ( $Remaining = $Length - strlen( $Data ) ) > 0 )
		{
			$Chunk = fread( $Socket, $Remaining );

			if( $Chunk === false || $Chunk === '' )
			{
				throw new InvalidPacketException( 'Rcon read: Read ' . strlen( $Data ) . ' of ' . $Length . ' bytes', InvalidPacketException::BUFFER_EMPTY );
			}

			$Data .= $Chunk;
		}

		return $Data;
	}

	public function Command( string $Command ) : string
	{
		// A response can span several packets and nothing marks the last one.
		// The server answers requests in order, so an empty SERVERDATA_REQUESTVALUE sent right behind the command
		// is answered only once the whole response is out, and its request id tells us where the response ends.
		// See https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Multiple-packet_Responses
		$CommandID  = ++$this->RconRequestId;
		$SentinelID = ++$this->RconRequestId;

		$this->WriteRaw(
			self::Pack( $CommandID, SourceQuery::SERVERDATA_EXECCOMMAND, $Command ) .
			self::Pack( $SentinelID, SourceQuery::SERVERDATA_REQUESTVALUE, '' )
		);

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

			if( $RequestID !== $SentinelID )
			{
				$Data .= $Packet;
			}
		}
		// The Source engine first flushes an empty packet for the sentinel, its real reply follows
		while( $RequestID !== $SentinelID || $Packet === '' );

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
