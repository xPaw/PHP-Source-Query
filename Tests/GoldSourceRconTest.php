<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\Socket;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\FakeUdpServer;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * GoldSource RCON, which runs over the shared UDP query socket.
 *
 * GoldSourceRcon::Write( ) writes straight to the raw resource $Socket->Socket,
 * so every wire test here needs the real Socket plus a FakeUdpServer; only the
 * abstraction test uses TestableSocket.
 *
 * The known-bug group asserts the correct behaviour and fails until it is fixed.
 */
class GoldSourceRconTest extends \PHPUnit\Framework\TestCase
{
	/** The challenge number the fake server hands out. */
	private const Challenge = '12345';

	private const Password = 'pass';

	private ?FakeUdpServer $UdpServer = null;
	private ?Socket $Socket = null;
	private ?SourceQuery $Query = null;

	public function tearDown( ) : void
	{
		$this->Query?->Disconnect( );
		$this->UdpServer?->Close( );

		$this->Query     = null;
		$this->Socket    = null;
		$this->UdpServer = null;
	}

	/**
	 * Binds the fake server, connects a real GoldSource socket to it and completes
	 * the 'challenge rcon' handshake.
	 */
	private function Authorize( int $Timeout = 1 ) : void
	{
		$this->UdpServer = new FakeUdpServer( );
		$this->Socket    = new Socket( );
		$this->Query     = new SourceQuery( $this->Socket );

		$this->Query->Connect( $this->UdpServer->Host( ), $this->UdpServer->Port( ), $Timeout, SourceQuery::GOLDSOURCE );
		$this->UdpServer->Attach( $this->Socket );
		$this->UdpServer->Queue( self::ChallengeReply( ) );

		$this->Query->SetRconPassword( self::Password );
	}

	/**
	 * The server answer to 'challenge rcon': 0xFFFFFFFF, the echoed request
	 * and the challenge number, null terminated.
	 */
	private static function ChallengeReply( ) : string
	{
		return "\xFF\xFF\xFF\xFF" . 'challenge rcon ' . self::Challenge . "\0";
	}

	/**
	 * One redirect datagram: 0xFFFFFFFF, the type byte, the console text, then the
	 * two terminating null bytes the engine writes.
	 */
	private static function PrintReply( string $Text ) : string
	{
		return FakeUdpServer::A2SReply( SourceQuery::S2A_RCON, $Text . "\0\0" );
	}

	/** Baseline: a short single-datagram reply round trips over the real socket. */
	public function testAuthorizeAndCommandRoundTrip( ) : void
	{
		$this->Authorize( );

		$this->UdpServer?->Queue( self::PrintReply( 'hostname: Fake GoldSource' ) );

		self::assertSame( 'hostname: Fake GoldSource', $this->Query?->Rcon( 'status' ) );

		$Requests = $this->UdpServer?->WaitForRequests( 2 ) ?? [];

		self::assertCount( 2, $Requests );
		self::assertSame( "\xFF\xFF\xFF\xFF" . 'challenge rcon', $Requests[ 0 ] );
		self::assertSame( "\xFF\xFF\xFF\xFF" . 'rcon ' . self::Challenge . ' "' . self::Password . '" status' . "\0", $Requests[ 1 ] );
	}

	/**
	 * The engine sends its console redirect in chunks of up to ~1400 bytes, so a
	 * final chunk of 1001-1399 bytes is normal and must not make the library wait
	 * for a further chunk that never comes.
	 */
	#[Group('known-bug')]
	public function testLastChunkOverThousandBytesIsNotDiscarded( ) : void
	{
		$this->Authorize( 2 );

		$First  = str_repeat( 'A', 1300 );
		$Second = str_repeat( 'B', 1200 );

		$this->UdpServer?->Queue( self::PrintReply( $First ) );
		$this->UdpServer?->Queue( self::PrintReply( $Second ) );

		$Start = microtime( true );

		try
		{
			$Result = $this->Query?->Rcon( 'status' ) ?? '';
		}
		catch( InvalidPacketException $Exception )
		{
			self::fail( sprintf(
				'Rcon( ) threw %s( %d ): %s after %.2fs instead of returning the 2500 byte response; the 1200 byte last chunk is above the > 1000 heuristic, so it blocked on a third read that never comes.',
				$Exception::class,
				$Exception->getCode( ),
				$Exception->getMessage( ),
				microtime( true ) - $Start
			) );
		}

		$Elapsed = microtime( true ) - $Start;

		self::assertLessThan( 1.0, $Elapsed, 'Rcon( ) must not block waiting for a chunk that will never arrive.' );
		self::assertSame( $First . $Second, str_replace( "\0", '', $Result ) );
	}

	/**
	 * Each redirect datagram ends with the engine's null terminator, which has to
	 * be stripped per chunk instead of ending up inside the returned string.
	 */
	#[Group('known-bug')]
	public function testMultipleChunksDoNotLeakNullBytes( ) : void
	{
		$this->Authorize( );

		$First  = str_repeat( 'A', 1100 );
		$Second = str_repeat( 'B', 500 );

		$this->UdpServer?->Queue( self::PrintReply( $First ) );
		$this->UdpServer?->Queue( self::PrintReply( $Second ) );

		$Result = $this->Query?->Rcon( 'status' ) ?? '';

		self::assertSame( 0, substr_count( $Result, "\0" ), 'The per-chunk null terminator must be stripped before the chunks are concatenated.' );
		self::assertSame( $First . $Second, $Result );
	}

	/**
	 * Only the protocol's own null trailer may be stripped; trimming whitespace
	 * eats the leading indentation of the command output.
	 */
	#[Group('known-bug')]
	public function testLeadingWhitespaceIsPreserved( ) : void
	{
		$this->Authorize( );

		$this->UdpServer?->Queue( self::PrintReply( " indented\n\n" ) );

		$Result = $this->Query?->Rcon( 'status' ) ?? '';

		// A trailing newline may or may not be stripped, so only the leading part
		// is asserted on.
		self::assertStringStartsWith( ' indented', $Result, 'Leading whitespace of the server output must not be trimmed away.' );
	}

	/**
	 * GoldSource RCON must go through the BaseSocket abstraction rather than the
	 * raw $Socket->Socket resource, so that an injected BaseSocket works.
	 */
	#[Group('known-bug')]
	public function testWorksWithAnInjectedBaseSocket( ) : void
	{
		$Socket = new TestableSocket( );
		$Query  = new SourceQuery( $Socket );
		$Query->Connect( '', 2, 1, SourceQuery::GOLDSOURCE );

		$Socket->Queue( self::ChallengeReply( ) );

		try
		{
			$Query->SetRconPassword( self::Password );
		}
		catch( SocketException $Exception )
		{
			self::fail( sprintf(
				'SetRconPassword( ) threw SocketException( %d ): %s - GoldSourceRcon must go through BaseSocket::Write( ) instead of writing to the raw $Socket->Socket resource.',
				$Exception->getCode( ),
				$Exception->getMessage( )
			) );
		}

		self::assertNotSame( [], $Socket->Written, 'The challenge request must have gone through the socket abstraction.' );
		self::assertTrue( $Socket->IsQueueEmpty( ), 'The challenge reply must have been consumed.' );

		$Query->Disconnect( );
	}

	/**
	 * Baseline: the engine's rejection is turned into an AuthenticationException.
	 */
	public function testBadPasswordThrows( ) : void
	{
		$this->Authorize( );

		$this->UdpServer?->Queue( self::PrintReply( 'Bad rcon_password.' ) );

		try
		{
			$this->Query?->Rcon( 'status' );

			self::fail( 'Expected AuthenticationException' );
		}
		catch( AuthenticationException $Exception )
		{
			self::assertSame( AuthenticationException::BAD_PASSWORD, $Exception->getCode( ) );
			self::assertSame( 'Bad rcon_password.', $Exception->getMessage( ) );
		}
	}
}
