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

		$Data = fread( $this->RconSocket, 4 );
		$Buffer = new Buffer( );
		$Buffer->Set( $Data === false ? '' : $Data );

		if( $Buffer->Remaining( ) < 4 )
		{
			throw new InvalidPacketException( 'Rcon read: Failed to read any data from socket', InvalidPacketException::BUFFER_EMPTY );
		}

		$PacketSize = $Buffer->ReadInt32( );

		if( $PacketSize <= 0 )
		{
			throw new InvalidPacketException( 'Rcon read: Packet size was empty', InvalidPacketException::BUFFER_EMPTY );
		}

		$Data = fread( $this->RconSocket, $PacketSize );
		$Buffer->Set( $Data === false ? '' : $Data );

		$Data = $Buffer->Read( );

		$Remaining = $PacketSize - strlen( $Data );

		while( $Remaining > 0 )
		{
			$Data2 = fread( $this->RconSocket, $Remaining );

			if( $Data2 === false || strlen( $Data2 ) === 0 )
			{
				throw new InvalidPacketException( 'Read ' . strlen( $Data ) . ' bytes from socket, ' . $Remaining . ' remaining', InvalidPacketException::BUFFER_EMPTY );
			}

			$Data .= $Data2;
			$Remaining -= strlen( $Data2 );
		}

		$Buffer->Set( $Data );

		return $Buffer;
	}

	public function Command( string $Command ) : string
	{
		$this->Write( SourceQuery::SERVERDATA_EXECCOMMAND, $Command );
		$Buffer = $this->Read( );

		$Buffer->ReadInt32( ); // RequestID

		$Type = $Buffer->ReadInt32( );

		if( $Type === SourceQuery::SERVERDATA_AUTH_RESPONSE )
		{
			throw new AuthenticationException( 'Bad rcon_password.', AuthenticationException::BAD_PASSWORD );
		}
		else if( $Type !== SourceQuery::SERVERDATA_RESPONSE_VALUE )
		{
			throw new InvalidPacketException( 'Invalid rcon response.', InvalidPacketException::PACKET_HEADER_MISMATCH );
		}

		$Data = $Buffer->Read( );

		// We do this stupid hack to handle split packets
		// See https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Multiple-packet_Responses
		if( strlen( $Data ) >= 4000 )
		{
			$this->Write( SourceQuery::SERVERDATA_REQUESTVALUE );

			do
			{
				$Buffer = $this->Read( );

				$Buffer->ReadInt32( ); // RequestID

				if( $Buffer->ReadInt32( ) !== SourceQuery::SERVERDATA_RESPONSE_VALUE )
				{
					break;
				}

				$Data2 = $Buffer->Read( );

				if( $Data2 === "\x00\x01\x00\x00\x00\x00" )
				{
					break;
				}

				$Data .= $Data2;
			}
			while( true );
		}

		return rtrim( $Data, "\0" );
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
