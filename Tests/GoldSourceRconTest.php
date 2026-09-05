<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\GoldSourceRcon;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\Packets;
use xPaw\SourceQuery\Tests\Support\TestableSocket;
use xPaw\SourceQuery\Tests\Support\UdpServerFixture;

/**
 * GoldSource RCON, which runs over the shared UDP query socket: the challenge
 * state machine, the console redirect chunks, and the rejection replies.
 *
 * GoldSourceRcon::Write( ) writes straight to the raw resource $Socket->Socket,
 * so every wire test here needs the real Socket plus a FakeUdpServer.
 */
class GoldSourceRconTest extends \PHPUnit\Framework\TestCase
{
	use UdpServerFixture;

	/** The challenge number the fake server hands out. */
	private const Challenge = '2043988477';

	/** The challenge number handed out on a second 'challenge rcon' request. */
	private const NewChallenge = '1174404871';

	private const Password = 'pass';

	//
	// State machine
	//

	/**
	 * The rcon line embeds the challenge, so there is nothing to send before the
	 * 'challenge rcon' handshake completes.
	 */
	public function testCommandBeforeAuthorize( ) : void
	{
		$Rcon = new GoldSourceRcon( new TestableSocket( ) );

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BAD_PASSWORD );
		$this->expectExceptionMessage( 'Tried to execute a RCON command before successful authorization.' );

		$Rcon->Command( 'status' );
	}

	/**
	 * The socket is shared with the query protocol, so writing without one is a
	 * socket error, not an authentication error.
	 */
	public function testWriteWithoutAnOpenSocket( ) : void
	{
		$Rcon = new GoldSourceRcon( new TestableSocket( ) );

		$this->expectException( SocketException::class );
		$this->expectExceptionCode( SocketException::NOT_CONNECTED );

		$Rcon->Write( 0, 'challenge rcon' );
	}

	/** Close( ) forgets the challenge and the password. */
	public function testCloseResetsTheChallenge( ) : void
	{
		$Socket = $this->OpenSocket( SourceQuery::GOLDSOURCE );

		$this->Queue( Packets::RconChallengeReply( self::Challenge ) );

		$Rcon = new GoldSourceRcon( $Socket );
		$Rcon->Authorize( self::Password );
		$Rcon->Close( );

		$this->expectException( AuthenticationException::class );

		$Rcon->Command( 'status' );
	}

	//
	// Authorize
	//

	/** Baseline: a short single-datagram reply round trips over the real socket. */
	public function testAuthorizeAndCommandRoundTrip( ) : void
	{
		$Query = $this->Authorize( );

		$this->Queue( Packets::PrintReply( 'hostname: Fake GoldSource' ) );

		self::assertSame( 'hostname: Fake GoldSource', $Query->Rcon( 'status' ) );

		self::assertSame(
		[
			"\xFF\xFF\xFF\xFF" . 'challenge rcon',
			"\xFF\xFF\xFF\xFF" . 'rcon ' . self::Challenge . ' "' . self::Password . '" status' . "\0",
		], $this->UdpServer->WaitForRequests( 2 ) );
	}

	/** Anything that does not begin with the echoed request is not a challenge. */
	public function testAuthorizeRejectsAReplyThatIsNotAChallenge( ) : void
	{
		$Query = $this->ConnectQuery( SourceQuery::GOLDSOURCE );

		$this->Queue( Packets::PrintReply( 'Bad rcon_password.' ) );

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BAD_PASSWORD );
		$this->expectExceptionMessage( 'Failed to get RCON challenge.' );

		$Query->SetRconPassword( self::Password );
	}

	/**
	 * Challenges expire, are evicted and are reseeded by a restart, so
	 * 'Bad challenge.' is the normal way a long lived connection is told to fetch
	 * a new one. The command must be retried once with the fresh challenge.
	 */
	public function testStaleChallengeIsRenewedAndTheCommandRetried( ) : void
	{
		$Query = $this->Authorize( );

		$this->Queue(
			Packets::PrintReply( "Bad rcon_password.\nBad challenge.\n" ),
			Packets::RconChallengeReply( self::NewChallenge ),
			Packets::PrintReply( 'hostname: Fake Server' )
		);

		try
		{
			$Output = $Query->Rcon( 'status' );
		}
		catch( AuthenticationException $Exception )
		{
			self::fail( 'The command was not retried with a fresh challenge: ' . $Exception->getMessage( ) );
		}

		self::assertSame( 'hostname: Fake Server', $Output );

		$Requests = $this->UdpServer->WaitForRequests( 4 );

		self::assertCount( 4, $Requests );
		self::assertSame( "\xFF\xFF\xFF\xFF" . 'challenge rcon', $Requests[ 2 ] );
		self::assertSame( "\xFF\xFF\xFF\xFF" . 'rcon ' . self::NewChallenge . ' "' . self::Password . '" status' . "\0", $Requests[ 3 ] );
	}

	//
	// Reply handling
	//

	/** Every RCON redirect datagram carries the S2A_RCON type byte. */
	public function testReplyWithTheWrongTypeByte( ) : void
	{
		$Query = $this->Authorize( );

		$this->Queue( Packets::A2SReply( 0x6E, "hostname: Fake Server\0\0" ) );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::PACKET_HEADER_MISMATCH );
		$this->expectExceptionMessage( 'Invalid rcon response.' );

		$Query->Rcon( 'status' );
	}

	/**
	 * Too many failed rcon attempts get the source address banned, and the ban is
	 * reported instead of the command output.
	 */
	public function testBannedReply( ) : void
	{
		$Query = $this->Authorize( );

		$this->Queue( Packets::PrintReply( 'You have been banned from this server.' ) );

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BANNED );
		$this->expectExceptionMessage( 'You have been banned from this server.' );

		$Query->Rcon( 'status' );
	}

	/**
	 * The engine prefixes several distinct rejections with 'Bad rcon_password.'
	 * and puts the reason on the next line. All of them are authentication
	 * failures, not command output. 'Bad challenge.' is handled by fetching a new
	 * challenge instead.
	 */
	#[DataProvider( 'RejectionProvider' )]
	public function testEveryBadPasswordRejectionIsAnAuthenticationFailure( string $Reply ) : void
	{
		$Query = $this->Authorize( );

		$this->Queue( Packets::PrintReply( $Reply ) );

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BAD_PASSWORD );

		$Query->Rcon( 'status' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function RejectionProvider( ) : array
	{
		return
		[
			'bare rejection'  => [ 'Bad rcon_password.' ],
			'no password set' => [ "Bad rcon_password.\nNo password set for this server.\n" ],
			'no privilege'    => [ "Bad rcon_password.\nNo privilege.\n" ],
		];
	}

	/**
	 * The engine flushes its console redirect in chunks, so a long reply arrives as
	 * several datagrams. Each of them ends with the engine's null terminator, which
	 * has to be stripped per chunk instead of ending up inside the returned string.
	 */
	public function testMultipleChunksDoNotLeakNullBytes( ) : void
	{
		$Query = $this->Authorize( );

		$First  = str_repeat( 'A', 1100 );
		$Second = str_repeat( 'B', 500 );

		$this->Queue( Packets::PrintReply( $First ), Packets::PrintReply( $Second ) );

		$Result = $Query->Rcon( 'status' );

		self::assertSame( 0, substr_count( $Result, "\0" ), 'The per-chunk null terminator must be stripped before the chunks are concatenated.' );
		self::assertSame( $First . $Second, $Result );
	}

	/**
	 * Only the protocol's own null trailer may be stripped; trimming whitespace
	 * eats the leading indentation of the command output.
	 */
	public function testLeadingWhitespaceIsPreserved( ) : void
	{
		$Query = $this->Authorize( );

		$this->Queue( Packets::PrintReply( " indented\n\n" ) );

		// A trailing newline may or may not be stripped, so only the leading part
		// is asserted on.
		self::assertStringStartsWith( ' indented', $Query->Rcon( 'status' ) );
	}

	// Known bugs.

	/**
	 * The engine sends its console redirect in chunks of up to ~1400 bytes, so a
	 * final chunk of 1001-1399 bytes is normal and must not make the library wait
	 * for a further chunk that never comes.
	 */
	#[Group( 'known-bug' )]
	public function testLastChunkOverThousandBytesIsNotDiscarded( ) : void
	{
		$Query = $this->Authorize( );

		$First  = str_repeat( 'A', 1300 );
		$Second = str_repeat( 'B', 1200 );

		$this->Queue( Packets::PrintReply( $First ), Packets::PrintReply( $Second ) );

		try
		{
			$Result = $Query->Rcon( 'status' );
		}
		catch( InvalidPacketException )
		{
			self::fail( 'The 1200 byte last chunk is above the > 1000 heuristic, so Rcon( ) blocked on a third read that never comes.' );
		}

		self::assertSame( $First . $Second, str_replace( "\0", '', $Result ) );
	}

	/**
	 * The challenge reply is the echoed request followed by the number. A reply
	 * with no number leaves nothing to build an rcon line from, so it has to be
	 * rejected here rather than surface later as a command sent before authorization.
	 */
	#[Group( 'known-bug' )]
	public function testAuthorizeRejectsAChallengeReplyWithoutANumber( ) : void
	{
		$Query = $this->ConnectQuery( SourceQuery::GOLDSOURCE );

		$this->Queue( "\xFF\xFF\xFF\xFF" . "challenge rcon\0" );

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BAD_PASSWORD );

		$Query->SetRconPassword( self::Password );
	}

	/**
	 * GoldSource RCON must go through the BaseSocket abstraction rather than the
	 * raw $Socket->Socket resource, so that an injected BaseSocket works.
	 */
	#[Group( 'known-bug' )]
	public function testWorksWithAnInjectedBaseSocket( ) : void
	{
		$Socket = new TestableSocket( );
		$Query  = new SourceQuery( $Socket );
		$Query->Connect( '', 2, 1, SourceQuery::GOLDSOURCE );

		$Socket->Queue( Packets::RconChallengeReply( self::Challenge ) );

		try
		{
			$Query->SetRconPassword( self::Password );
		}
		catch( SocketException )
		{
			self::fail( 'GoldSourceRcon writes to the raw $Socket->Socket resource instead of BaseSocket::Write( ).' );
		}

		self::assertNotSame( [], $Socket->Written, 'The challenge request must have gone through the socket abstraction.' );
		self::assertSame( 0, $Socket->QueuedCount( ), 'The challenge reply must have been consumed.' );
	}

	//
	// Helpers
	//

	/** Connects a real GoldSource socket and completes the 'challenge rcon' handshake. */
	private function Authorize( ) : SourceQuery
	{
		$Query = $this->ConnectQuery( SourceQuery::GOLDSOURCE );

		$this->Queue( Packets::RconChallengeReply( self::Challenge ) );

		$Query->SetRconPassword( self::Password );

		return $Query;
	}
}
