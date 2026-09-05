<?php
declare(strict_types=1);

use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\SourceRcon;
use xPaw\SourceQuery\Tests\Support\FakeRconServer;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * Source RCON framing the happy-path tests do not reach: the guards that fire
 * without a connection, malformed length prefixes, the response types a command
 * can come back with, and the multi-packet drain.
 */
class SourceRconEdgeTest extends \PHPUnit\Framework\TestCase
{
	private ?FakeRconServer $RconServer = null;
	private ?SourceQuery $Query = null;

	public function tearDown( ) : void
	{
		$this->Query?->Disconnect( );
		$this->RconServer?->Stop( );

		$this->Query      = null;
		$this->RconServer = null;
	}

	//
	// Guards before a connection exists
	//

	public function testWriteWithoutAConnection( ) : void
	{
		$Rcon = new SourceRcon( new TestableSocket( ) );

		self::ExpectNotConnected( static fn( ) : bool => $Rcon->Write( SourceQuery::SERVERDATA_EXECCOMMAND, 'status' ) );
	}

	public function testReadWithoutAConnection( ) : void
	{
		$Rcon = new SourceRcon( new TestableSocket( ) );

		self::ExpectNotConnected( static fn( ) : Buffer => $Rcon->Read( ) );
	}

	public function testCloseWithoutAConnectionIsHarmless( ) : void
	{
		$Rcon = new SourceRcon( new TestableSocket( ) );

		$Rcon->Close( );
		$Rcon->Close( );

		self::ExpectNotConnected( static fn( ) : Buffer => $Rcon->Read( ) );
	}

	/**
	 * RCON runs over TCP on the same port as the UDP query socket, so a server
	 * without an RCON listener refuses the connection.
	 */
	public function testConnectionRefused( ) : void
	{
		$Port = self::ClosedPort( );

		$Query = new SourceQuery( );
		$Query->Connect( '127.0.0.1', $Port, 1 );

		$this->Query = $Query;

		try
		{
			$Query->SetRconPassword( 'pass' );

			self::fail( 'Expected SocketException' );
		}
		catch( SocketException $Exception )
		{
			self::assertSame( SocketException::CONNECTION_FAILED, $Exception->getCode( ) );
			self::assertStringStartsWith( 'Can\'t connect to RCON server:', $Exception->getMessage( ) );
		}
	}

	//
	// Authorize
	//

	/**
	 * Not every implementation sends the empty SERVERDATA_RESPONSE_VALUE before
	 * SERVERDATA_AUTH_RESPONSE, so an AUTH_RESPONSE alone is a complete answer.
	 */
	public function testAuthorizeWhenTheServerSkipsTheEmptyResponseValue( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			[
				[ 'type' => SourceQuery::SERVERDATA_AUTH_RESPONSE, 'id' => 'same' ],
			],
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'authorized' ],
			],
		] );

		self::assertSame( 'authorized', $Query->Rcon( 'status' ) );
	}

	//
	// Read framing
	//

	public function testServerClosesWithoutAnswering( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'close' => true ],
			],
		] );

		try
		{
			$Query->Rcon( 'status' );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::BUFFER_EMPTY, $Exception->getCode( ) );
			self::assertSame( 'Rcon read: Failed to read any data from socket', $Exception->getMessage( ) );
		}
	}

	/**
	 * The length prefix counts the bytes after it, so it can never be zero: the id
	 * and type alone are 8 bytes.
	 */
	public function testPacketSizeOfZero( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'size' => 0 ],
			],
		] );

		try
		{
			$Query->Rcon( 'status' );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::BUFFER_EMPTY, $Exception->getCode( ) );
			self::assertSame( 'Rcon read: Packet size was empty', $Exception->getMessage( ) );
		}
	}

	/**
	 * TCP is a byte stream, so a body regularly arrives across several segments and
	 * has to be read until the announced size is complete.
	 */
	public function testBodyDeliveredAcrossTwoSegments( ) : void
	{
		$Body  = str_repeat( 'a', 1500 ) . str_repeat( 'b', 1500 );
		$Frame = FakeRconServer::Frame( 2, SourceQuery::SERVERDATA_RESPONSE_VALUE, $Body );

		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'rawHex' => bin2hex( substr( $Frame, 0, 1200 ) ) ],
				[ 'delayMs' => 150 ],
				[ 'rawHex' => bin2hex( substr( $Frame, 1200 ) ) ],
			],
		] );

		self::assertSame( $Body, $Query->Rcon( 'status' ) );
	}

	/** The error says how many bytes of the body were still outstanding. */
	public function testConnectionClosedInTheMiddleOfABody( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'rawHex' => bin2hex( pack( 'V', 100 ) . pack( 'VV', 2, SourceQuery::SERVERDATA_RESPONSE_VALUE ) . str_repeat( 'x', 12 ) ) ],
				[ 'close' => true ],
			],
		] );

		try
		{
			$Query->Rcon( 'status' );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::BUFFER_EMPTY, $Exception->getCode( ) );
			self::assertSame( 'Read 20 bytes from socket, 80 remaining', $Exception->getMessage( ) );
		}
	}

	//
	// Command response types
	//

	/**
	 * The engine answers a command from a listener it no longer considers
	 * authenticated with an AUTH_RESPONSE.
	 */
	public function testCommandAnsweredWithAnAuthResponse( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_AUTH_RESPONSE, 'id' => 'same' ],
			],
		] );

		try
		{
			$Query->Rcon( 'status' );

			self::fail( 'Expected AuthenticationException' );
		}
		catch( AuthenticationException $Exception )
		{
			self::assertSame( AuthenticationException::BAD_PASSWORD, $Exception->getCode( ) );
			self::assertSame( 'Bad rcon_password.', $Exception->getMessage( ) );
		}
	}

	public function testCommandAnsweredWithAnUnknownType( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'type' => 9, 'id' => 'same', 'body' => 'what is this' ],
			],
		] );

		try
		{
			$Query->Rcon( 'status' );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::PACKET_HEADER_MISMATCH, $Exception->getCode( ) );
			self::assertSame( 'Invalid rcon response.', $Exception->getMessage( ) );
		}
	}

	public function testCommandWithNoOutputReturnsAnEmptyString( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same' ],
			],
		] );

		self::assertSame( '', $Query->Rcon( 'echo' ) );
	}

	//
	// Multi-packet responses
	//

	/**
	 * An empty SERVERDATA_REQUESTVALUE follows every command; everything up to the
	 * server's reply to it belongs to the command.
	 */
	public function testMultiPacketResponseEndsAtTheTerminatorPacket( ) : void
	{
		$ChunkOne = str_repeat( 'A', 4050 );
		$ChunkTwo = str_repeat( 'B', 600 );

		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => $ChunkOne ],
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => $ChunkTwo ],
			],
		], null, 2, self::RequestValueByType( ) );

		$Output = $Query->Rcon( 'cvarlist' );

		self::assertStringStartsWith( $ChunkOne, $Output );
		self::assertStringContainsString( $ChunkTwo, $Output );

		$Requests = $this->RconServer?->WaitForRequests( 3 ) ?? [];

		self::assertCount( 3, $Requests );
		self::assertSame( SourceQuery::SERVERDATA_REQUESTVALUE, $Requests[ 2 ][ 'type' ] );
		self::assertSame( '', $Requests[ 2 ][ 'body' ] );
	}

	/** An AUTH_RESPONSE in the middle of a response means the server no longer trusts us. */
	public function testAuthResponseDuringMultiPacketResponse( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => str_repeat( 'A', 4050 ) ],
			],
		], null, 2,
		[
			SourceQuery::SERVERDATA_REQUESTVALUE =>
			[
				[ 'type' => SourceQuery::SERVERDATA_AUTH_RESPONSE, 'id' => 'same' ],
			],
		] );

		$this->expectException( AuthenticationException::class );

		$Query->Rcon( 'cvarlist' );
	}

	//
	// Disconnect
	//

	/**
	 * Disconnect( ) drops the RCON object with its TCP connection, so the password
	 * has to be set again before another command.
	 */
	public function testDisconnectReleasesTheRconConnection( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
		] );

		$Query->Disconnect( );
		$Query->Connect( '127.0.0.1', $this->RconServer?->Port( ) ?? 0, 1 );

		try
		{
			$Query->Rcon( 'status' );

			self::fail( 'Expected SocketException' );
		}
		catch( SocketException $Exception )
		{
			self::assertSame( SocketException::NOT_CONNECTED, $Exception->getCode( ) );
			self::assertStringStartsWith( 'You must set a RCON password', $Exception->getMessage( ) );
		}
	}

	//
	// Helpers
	//

	/**
	 * The two responses a real engine sends for a successful SERVERDATA_AUTH.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function AuthOkStep( ) : array
	{
		return
		[
			[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same' ],
			[ 'type' => SourceQuery::SERVERDATA_AUTH_RESPONSE, 'id' => 'same' ],
		];
	}

	/**
	 * What the engine sends for the empty SERVERDATA_REQUESTVALUE probe: an empty
	 * RESPONSE_VALUE, then one whose body is the 00 01 00 00 00 00 terminator.
	 *
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private static function RequestValueByType( ) : array
	{
		return
		[
			SourceQuery::SERVERDATA_REQUESTVALUE =>
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same' ],
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'bodyHex' => '000100000000', 'raw' => true ],
			],
		];
	}

	/**
	 * Boots the scripted server and returns an authorized SourceQuery.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $Script
	 * @param ?array<int, array<string, mixed>>            $Fallback
	 * @param ?array<int, array<int, array<string, mixed>>> $ByType
	 */
	private function ConnectAndAuthorize( array $Script, ?array $Fallback = null, int $Timeout = 2, ?array $ByType = null ) : SourceQuery
	{
		// Every command is followed by a SERVERDATA_REQUESTVALUE probe, answer it like the engine unless the test says otherwise
		if( $ByType === null && $Fallback === null )
		{
			$ByType = self::RequestValueByType( );
		}

		$this->RconServer = new FakeRconServer( );

		$Port = $this->RconServer->Start( $Script, $Fallback, null, 15.0, $ByType );

		$Query = new SourceQuery( );
		$Query->Connect( '127.0.0.1', $Port, $Timeout );
		$Query->SetRconPassword( 'testpassword' );

		$this->Query = $Query;

		return $Query;
	}

	/** A TCP port on loopback that nothing listens on. */
	private static function ClosedPort( ) : int
	{
		$ErrNo  = 0;
		$ErrStr = '';
		$Server = @stream_socket_server( 'tcp://127.0.0.1:0', $ErrNo, $ErrStr );

		if( $Server === false )
		{
			self::fail( 'Could not bind a temporary listener: ' . $ErrStr );
		}

		$Name = stream_socket_get_name( $Server, false );

		fclose( $Server );

		if( $Name === false )
		{
			self::fail( 'Could not resolve the temporary listener address.' );
		}

		$Colon = strrpos( $Name, ':' );

		if( $Colon === false )
		{
			self::fail( 'Unexpected listener address "' . $Name . '".' );
		}

		return (int)substr( $Name, $Colon + 1 );
	}

	/**
	 * @param callable( ) : mixed $Action
	 */
	private static function ExpectNotConnected( callable $Action ) : void
	{
		try
		{
			$Action( );

			self::fail( 'Expected SocketException' );
		}
		catch( SocketException $Exception )
		{
			self::assertSame( SocketException::NOT_CONNECTED, $Exception->getCode( ) );
			self::assertSame( 'Not connected.', $Exception->getMessage( ) );
		}
	}
}
