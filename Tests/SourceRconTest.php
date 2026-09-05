<?php
declare(strict_types=1);

use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\SourceRcon;
use xPaw\SourceQuery\Tests\Support\FakeRconServer;
use xPaw\SourceQuery\Tests\Support\FakeUdpServer;
use xPaw\SourceQuery\Tests\Support\RconServerFixture;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * The Source RCON implementation, which runs over TCP.
 */
class SourceRconTest extends \PHPUnit\Framework\TestCase
{
	use RconServerFixture;

	//
	// Guards before a connection exists
	//

	public function testWriteWithoutAConnection( ) : void
	{
		$Rcon = new SourceRcon( new TestableSocket( ) );

		$this->expectException( SocketException::class );
		$this->expectExceptionCode( SocketException::NOT_CONNECTED );
		$this->expectExceptionMessage( 'Not connected.' );

		$Rcon->Write( SourceQuery::SERVERDATA_EXECCOMMAND, 'status' );
	}

	public function testReadWithoutAConnection( ) : void
	{
		$Rcon = new SourceRcon( new TestableSocket( ) );

		$this->expectException( SocketException::class );
		$this->expectExceptionCode( SocketException::NOT_CONNECTED );
		$this->expectExceptionMessage( 'Not connected.' );

		$Rcon->Read( );
	}

	public function testCloseWithoutAConnectionIsHarmless( ) : void
	{
		$Rcon = new SourceRcon( new TestableSocket( ) );

		$Rcon->Close( );
		$Rcon->Close( );

		$this->expectException( SocketException::class );

		$Rcon->Read( );
	}

	/**
	 * RCON runs over TCP on the same port as the UDP query socket, so a server
	 * without an RCON listener refuses the connection. The fake UDP server holds
	 * the port on UDP only.
	 */
	public function testConnectionRefused( ) : void
	{
		$UdpServer = new FakeUdpServer( );

		$Query = new SourceQuery( );
		$Query->Connect( '127.0.0.1', $UdpServer->Port( ), 1 );

		$this->Query = $Query;

		$this->expectException( SocketException::class );
		$this->expectExceptionCode( SocketException::CONNECTION_FAILED );
		$this->expectExceptionMessage( 'Can\'t connect to RCON server:' );

		$Query->SetRconPassword( 'pass' );
	}

	//
	// Authorize
	//

	/** Baseline: a single-packet response comes back with its NUL framing stripped. */
	public function testAuthorizeAndSingleCommand( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			FakeRconServer::AuthOk( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => "hostname: Test Server\nplayers : 4 (32 max)" ],
			],
		] );

		self::assertSame( "hostname: Test Server\nplayers : 4 (32 max)", $Query->Rcon( 'status' ) );

		$Requests = $this->RconServer->WaitForRequests( 3 );

		self::assertCount( 3, $Requests );
		self::assertSame( SourceQuery::SERVERDATA_AUTH, $Requests[ 0 ][ 'type' ] );
		self::assertSame( 'testpassword', $Requests[ 0 ][ 'body' ] );
		self::assertSame( SourceQuery::SERVERDATA_EXECCOMMAND, $Requests[ 1 ][ 'type' ] );
		self::assertSame( 'status', $Requests[ 1 ][ 'body' ] );
		self::assertSame( SourceQuery::SERVERDATA_REQUESTVALUE, $Requests[ 2 ][ 'type' ] );
		self::assertSame( '', $Requests[ 2 ][ 'body' ] );
	}

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

	/** An AUTH_RESPONSE carrying request id -1 means a bad password. */
	public function testBadPasswordThrowsAuthenticationException( ) : void
	{
		$this->ConnectQuery( [ FakeRconServer::AuthFail( ) ] );

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BAD_PASSWORD );

		$this->Query->SetRconPassword( 'wrongpassword' );
	}

	/**
	 * A failed authorization that leaves the RCON object and its unauthenticated
	 * socket in place lets Rcon( ) send the command anyway and return the engine's
	 * empty RESPONSE_VALUE as if it had succeeded.
	 */
	public function testCommandAfterFailedAuthorizationThrows( ) : void
	{
		$Query = $this->ConnectQuery(
		[
			FakeRconServer::AuthFail( ),
			// The engine discards commands from unauthenticated listeners but still
			// flushes an empty redirect buffer back to the client.
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same' ],
			],
		] );

		try
		{
			$Query->SetRconPassword( 'wrongpassword' );

			self::fail( 'SetRconPassword( ) must throw for a wrong password.' );
		}
		catch( AuthenticationException $Exception )
		{
			self::assertSame( AuthenticationException::BAD_PASSWORD, $Exception->getCode( ) );
		}

		$this->expectException( SocketException::class );

		$Query->Rcon( 'status' );
	}

	//
	// Read framing
	//

	public function testServerClosesWithoutAnswering( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			FakeRconServer::AuthOk( ),
			[
				[ 'close' => true ],
			],
		] );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::BUFFER_EMPTY );
		$this->expectExceptionMessage( 'Rcon read: Read 0 of 4 bytes' );

		$Query->Rcon( 'status' );
	}

	/**
	 * The length prefix counts the bytes after it, so it can never be zero: the id
	 * and type alone are 8 bytes.
	 */
	public function testPacketSizeOfZero( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			FakeRconServer::AuthOk( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'size' => 0 ],
			],
		] );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::BUFFER_EMPTY );
		$this->expectExceptionMessage( 'Rcon read: Packet size was empty' );

		$Query->Rcon( 'status' );
	}

	/**
	 * TCP is a byte stream, so the 4-byte length prefix can arrive across two
	 * segments. A short read of it must be reassembled instead of being treated as
	 * end-of-stream, which desynchronises every following command.
	 */
	public function testShortReadOfLengthPrefixIsReassembled( ) : void
	{
		$Body  = 'hostname: Split Prefix Server';
		$Frame = FakeRconServer::Frame( 2, SourceQuery::SERVERDATA_RESPONSE_VALUE, $Body );

		$Query = $this->ConnectAndAuthorize(
		[
			FakeRconServer::AuthOk( ),
			// Split 2 bytes into the 4-byte size prefix, which fread( $Socket, 4 )
			// returns short.
			[
				[ 'rawHex' => bin2hex( substr( $Frame, 0, 2 ) ) ],
				[ 'delayMs' => 50 ],
				[ 'rawHex' => bin2hex( substr( $Frame, 2 ) ) ],
			],
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'second command output' ],
			],
		] );

		self::assertSame( $Body, $Query->Rcon( 'status' ) );

		// The stream must still be in sync for the next command.
		self::assertSame( 'second command output', $Query->Rcon( 'echo second' ) );
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
			FakeRconServer::AuthOk( ),
			[
				[ 'rawHex' => bin2hex( substr( $Frame, 0, 1200 ) ) ],
				[ 'delayMs' => 50 ],
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
			FakeRconServer::AuthOk( ),
			[
				[ 'rawHex' => bin2hex( pack( 'V', 100 ) . pack( 'VV', 2, SourceQuery::SERVERDATA_RESPONSE_VALUE ) . str_repeat( 'x', 12 ) ) ],
				[ 'close' => true ],
			],
		] );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::BUFFER_EMPTY );
		$this->expectExceptionMessage( 'Rcon read: Read 20 of 100 bytes' );

		$Query->Rcon( 'status' );
	}

	/**
	 * Real engine packets never exceed ~4106 bytes, so an announced size has to be
	 * bounded before it is allocated and waited for.
	 */
	public function testAbsurdPacketSizeIsRejectedWithoutWaiting( ) : void
	{
		// 32 MiB announced, 3 bytes of body actually delivered, connection kept open.
		$Absurd = pack( 'V', 0x02000000 ) . pack( 'VV', 2, SourceQuery::SERVERDATA_RESPONSE_VALUE ) . 'abc';

		$Query = $this->ConnectAndAuthorize(
		[
			FakeRconServer::AuthOk( ),
			[
				[ 'rawHex' => bin2hex( $Absurd ) ],
			],
		] );

		$Start  = microtime( true );
		$Caught = null;

		try
		{
			$Query->Rcon( 'status' );
		}
		catch( InvalidPacketException $Exception )
		{
			$Caught = $Exception;
		}

		$Elapsed = microtime( true ) - $Start;

		self::assertNotNull( $Caught, 'A 32 MiB packet size announcement was accepted.' );
		self::assertLessThan( 1.0, $Elapsed, 'An absurd packet size must be rejected before allocating and blocking for the socket timeout, took ' . round( $Elapsed, 3 ) . 's.' );
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
			FakeRconServer::AuthOk( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_AUTH_RESPONSE, 'id' => 'same' ],
			],
		] );

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BAD_PASSWORD );
		$this->expectExceptionMessage( 'Bad rcon_password.' );

		$Query->Rcon( 'status' );
	}

	public function testCommandAnsweredWithAnUnknownType( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			FakeRconServer::AuthOk( ),
			[
				[ 'type' => 9, 'id' => 'same', 'body' => 'what is this' ],
			],
		] );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::PACKET_HEADER_MISMATCH );
		$this->expectExceptionMessage( 'Invalid rcon response.' );

		$Query->Rcon( 'status' );
	}

	public function testCommandWithNoOutputReturnsAnEmptyString( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			FakeRconServer::AuthOk( ),
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
	 * The engine flushes its 4096-byte redirect buffer before a print that would
	 * overflow it, so a non-final chunk can be well under 4000 bytes. A
	 * "first body >= 4000 bytes" heuristic then leaves follow-up packets in the
	 * stream, where they come back as the answer to the next command.
	 */
	public function testMultiPacketResponseBelowHeuristicIsFullyDrained( ) : void
	{
		$ChunkOne = self::Filler( 'A', 3000 );
		$ChunkTwo = self::Filler( 'B', 2000 );

		$Query = $this->ConnectAndAuthorize( self::TwoChunkScript( $ChunkOne, $ChunkTwo ) );

		self::assertSame( $ChunkOne . $ChunkTwo, $Query->Rcon( 'cvarlist' ) );

		// Nothing of the previous response may be left for the next command.
		self::assertSame( 'output of the second command', $Query->Rcon( 'echo second' ) );
	}

	/**
	 * Each chunk's two trailing NUL framing bytes must be stripped rather than end
	 * up in the middle of the returned string.
	 */
	public function testMultiPacketResponseDoesNotEmbedNullBytes( ) : void
	{
		$ChunkOne = self::Filler( 'C', 4100 );
		$ChunkTwo = self::Filler( 'D', 1000 );

		$Query = $this->ConnectAndAuthorize( self::TwoChunkScript( $ChunkOne, $ChunkTwo ) );

		$Output = $Query->Rcon( 'cvarlist' );

		self::assertSame( 0, substr_count( $Output, "\0" ), 'Chunk framing NUL bytes must not be embedded in the result.' );
		self::assertSame( $ChunkOne . $ChunkTwo, $Output );

		self::assertSame( 'output of the second command', $Query->Rcon( 'echo second' ) );
	}

	/** An AUTH_RESPONSE in the middle of a response means the server no longer trusts us. */
	public function testAuthResponseDuringMultiPacketResponse( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			FakeRconServer::AuthOk( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => str_repeat( 'A', 4050 ) ],
			],
		],
		[
			SourceQuery::SERVERDATA_REQUESTVALUE =>
			[
				[ 'type' => SourceQuery::SERVERDATA_AUTH_RESPONSE, 'id' => 'same' ],
			],
		] );

		$this->expectException( AuthenticationException::class );

		$Query->Rcon( 'cvarlist' );
	}

	/**
	 * Minecraft answers the SERVERDATA_REQUESTVALUE sentinel with "Unknown request
	 * 0" and never sends the terminator packet the Source engine does, so a
	 * response of 4000 bytes or more must still come back without stalling.
	 */
	public function testLargeMinecraftResponseIsReturned( ) : void
	{
		$Body = self::Filler( 'M', 4090 );

		$Query = $this->ConnectAndAuthorize(
		[
			FakeRconServer::AuthOk( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => $Body ],
			],
		], null,
		[
			// The Minecraft reply to anything but AUTH or EXECCOMMAND.
			[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'Unknown request 0' ],
		] );

		self::assertSame( $Body, $Query->Rcon( 'help' ) );
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
		$Query = $this->ConnectAndAuthorize( [ FakeRconServer::AuthOk( ) ] );

		$Query->Disconnect( );
		$Query->Connect( '127.0.0.1', $this->RconServer->Port( ), 1 );

		$this->expectException( SocketException::class );
		$this->expectExceptionCode( SocketException::NOT_CONNECTED );
		$this->expectExceptionMessage( 'You must set a RCON password' );

		$Query->Rcon( 'status' );
	}

	//
	// Helpers
	//

	/**
	 * A command answered with two response packets, followed by a second command.
	 *
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private static function TwoChunkScript( string $ChunkOne, string $ChunkTwo ) : array
	{
		return
		[
			FakeRconServer::AuthOk( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => $ChunkOne ],
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => $ChunkTwo ],
			],
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'output of the second command' ],
			],
		];
	}

	/**
	 * Readable, NUL-free filler of an exact byte length so chunk boundaries stay
	 * recognisable in assertion diffs.
	 */
	private static function Filler( string $Marker, int $Length ) : string
	{
		$Line = str_repeat( $Marker, 63 ) . "\n";
		$Text = str_repeat( $Line, intdiv( $Length, 64 ) + 1 );

		return substr( $Text, 0, $Length );
	}
}
