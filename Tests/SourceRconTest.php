<?php
declare(strict_types=1);

use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\FakeRconServer;

/**
 * The Source RCON implementation, which runs over TCP.
 *
 * The known-bug group asserts the correct behaviour and fails until it is fixed.
 */
class SourceRconTest extends \PHPUnit\Framework\TestCase
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

	/**
	 * The two responses a real engine sends for a successful SERVERDATA_AUTH:
	 * an empty SERVERDATA_RESPONSE_VALUE, then SERVERDATA_AUTH_RESPONSE.
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
	 * What the engine sends for a wrong password: an empty RESPONSE_VALUE, then an
	 * AUTH_RESPONSE carrying request id -1.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function AuthFailStep( ) : array
	{
		return
		[
			[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same' ],
			[ 'type' => SourceQuery::SERVERDATA_AUTH_RESPONSE, 'id' => -1 ],
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

	/**
	 * What the engine sends for the empty SERVERDATA_REQUESTVALUE that marks the
	 * end of a multi-packet response: an empty RESPONSE_VALUE, then one whose body
	 * is 00 01 00 00 00 00.
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

		$Port = $this->RconServer->Start( $Script, $Fallback, null, 20.0, $ByType );

		$Query = new SourceQuery( );
		$Query->Connect( '127.0.0.1', $Port, $Timeout );
		$Query->SetRconPassword( 'testpassword' );

		$this->Query = $Query;

		return $Query;
	}

	/** Baseline: a single-packet response comes back with its NUL framing stripped. */
	public function testAuthorizeAndSingleCommand( ) : void
	{
		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => "hostname: Test Server\nplayers : 4 (32 max)" ],
			],
		] );

		self::assertSame( "hostname: Test Server\nplayers : 4 (32 max)", $Query->Rcon( 'status' ) );

		$Requests = $this->RconServer?->WaitForRequests( 3 ) ?? [];

		self::assertCount( 3, $Requests );
		self::assertSame( SourceQuery::SERVERDATA_AUTH, $Requests[ 0 ][ 'type' ] );
		self::assertSame( 'testpassword', $Requests[ 0 ][ 'body' ] );
		self::assertSame( SourceQuery::SERVERDATA_EXECCOMMAND, $Requests[ 1 ][ 'type' ] );
		self::assertSame( 'status', $Requests[ 1 ][ 'body' ] );
		self::assertSame( SourceQuery::SERVERDATA_REQUESTVALUE, $Requests[ 2 ][ 'type' ] );
		self::assertSame( '', $Requests[ 2 ][ 'body' ] );
	}

	/** Baseline: an AUTH_RESPONSE carrying request id -1 means a bad password. */
	public function testBadPasswordThrowsAuthenticationException( ) : void
	{
		$this->RconServer = new FakeRconServer( );

		$Port = $this->RconServer->Start( [ self::AuthFailStep( ) ] );

		$Query = new SourceQuery( );
		$Query->Connect( '127.0.0.1', $Port, 2 );

		$this->Query = $Query;

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BAD_PASSWORD );

		$Query->SetRconPassword( 'wrongpassword' );
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
			self::AuthOkStep( ),
			// Split 2 bytes into the 4-byte size prefix, which fread( $Socket, 4 )
			// returns short.
			[
				[ 'rawHex' => bin2hex( substr( $Frame, 0, 2 ) ) ],
				[ 'delayMs' => 300 ],
				[ 'rawHex' => bin2hex( substr( $Frame, 2 ) ) ],
			],
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'second command output' ],
			],
		] );

		try
		{
			$First = $Query->Rcon( 'status' );
		}
		catch( \Exception $Exception )
		{
			self::fail( 'Rcon( ) must reassemble a length prefix delivered in two TCP segments, got ' . get_class( $Exception ) . ': ' . $Exception->getMessage( ) );
		}

		self::assertSame( $Body, $First );

		// The stream must still be in sync for the next command.
		self::assertSame( 'second command output', $Query->Rcon( 'echo second' ) );
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
			self::AuthOkStep( ),
			[
				[ 'rawHex' => bin2hex( $Absurd ) ],
			],
		], null, 3 );

		$Start   = microtime( true );
		$Elapsed = 0.0;
		$Caught  = null;

		try
		{
			$Query->Rcon( 'status' );
		}
		catch( InvalidPacketException $Exception )
		{
			$Caught  = $Exception;
			$Elapsed = microtime( true ) - $Start;
		}

		self::assertNotNull( $Caught, 'Rcon( ) must reject a 32 MiB packet size announcement.' );
		self::assertLessThan( 1.0, $Elapsed, 'An absurd packet size must be rejected before allocating and blocking for the socket timeout, took ' . round( $Elapsed, 3 ) . 's.' );
	}

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

		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => $ChunkOne ],
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => $ChunkTwo ],
			],
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'output of the second command' ],
			],
		], null, 2, self::RequestValueByType( ) );

		$Output = $Query->Rcon( 'cvarlist' );

		self::assertSame( 5000, strlen( $Output ), 'Both response packets of one command must be returned.' );
		self::assertSame( 0, substr_count( $Output, "\0" ), 'Chunk framing NUL bytes must not be embedded in the result.' );
		self::assertSame( $ChunkOne . $ChunkTwo, $Output );

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

		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => $ChunkOne ],
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => $ChunkTwo ],
			],
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'output of the second command' ],
			],
		], null, 2, self::RequestValueByType( ) );

		$Output = $Query->Rcon( 'cvarlist' );

		self::assertSame( 0, substr_count( $Output, "\0" ), 'Chunk framing NUL bytes must not be embedded in the result.' );
		self::assertSame( 5100, strlen( $Output ) );
		self::assertSame( $ChunkOne . $ChunkTwo, $Output );

		self::assertSame( 'output of the second command', $Query->Rcon( 'echo second' ) );
	}

	/**
	 * Minecraft answers the SERVERDATA_REQUESTVALUE sentinel with "Unknown request
	 * 0" and never sends the terminator packet the Source engine does, so a
	 * response of 4000 bytes or more must still come back without stalling.
	 */
	public function testLargeMinecraftResponseIsReturnedWithoutStalling( ) : void
	{
		$Body = self::Filler( 'M', 4090 );

		$Query = $this->ConnectAndAuthorize(
		[
			self::AuthOkStep( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => $Body ],
			],
		],
		[
			// The Minecraft reply to anything but AUTH or EXECCOMMAND.
			[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'Unknown request 0' ],
		] );

		$Start = microtime( true );

		try
		{
			$Output = $Query->Rcon( 'help' );
		}
		catch( \Exception $Exception )
		{
			self::fail( 'Rcon( ) must return a 4090 byte Minecraft response, got ' . get_class( $Exception ) . ': ' . $Exception->getMessage( ) );
		}

		$Elapsed = microtime( true ) - $Start;

		self::assertSame( 4090, strlen( $Output ) );
		self::assertSame( $Body, $Output );
		self::assertLessThan( 1.0, $Elapsed, 'The response must be returned promptly, took ' . round( $Elapsed, 3 ) . 's.' );
	}

	/**
	 * A failed authorization that leaves the RCON object and its unauthenticated
	 * socket in place lets Rcon( ) send the command anyway and return the engine's
	 * empty RESPONSE_VALUE as if it had succeeded.
	 */
	public function testCommandAfterFailedAuthorizationThrows( ) : void
	{
		$this->RconServer = new FakeRconServer( );

		$Port = $this->RconServer->Start(
		[
			self::AuthFailStep( ),
			// The engine discards commands from unauthenticated listeners but still
			// flushes an empty redirect buffer back to the client.
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same' ],
			],
		] );

		$Query = new SourceQuery( );
		$Query->Connect( '127.0.0.1', $Port, 2 );

		$this->Query = $Query;

		try
		{
			$Query->SetRconPassword( 'wrongpassword' );

			self::fail( 'SetRconPassword( ) must throw for a wrong password.' );
		}
		catch( AuthenticationException $Exception )
		{
			self::assertSame( AuthenticationException::BAD_PASSWORD, $Exception->getCode( ) );
		}

		$Caught   = null;
		$Returned = null;

		try
		{
			$Returned = $Query->Rcon( 'status' );
		}
		catch( AuthenticationException | SocketException $Exception )
		{
			$Caught = $Exception;
		}

		self::assertNotNull( $Caught, 'Rcon( ) after a failed SetRconPassword( ) must throw instead of returning ' . var_export( $Returned, true ) . '.' );
	}
}
