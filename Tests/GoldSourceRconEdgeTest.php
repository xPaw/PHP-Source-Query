<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\GoldSourceRcon;
use xPaw\SourceQuery\Socket;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\FakeUdpServer;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * GoldSource RCON beyond the happy path: the challenge state machine, the reply
 * shapes for a rejected command, and the engine's rcon line length limit.
 *
 * GoldSourceRcon writes straight to the raw resource behind BaseSocket, so
 * anything touching the wire needs the real Socket and a FakeUdpServer.
 *
 * The known-bug group asserts the correct behaviour and fails until it is fixed.
 */
class GoldSourceRconEdgeTest extends \PHPUnit\Framework\TestCase
{
	/** The challenge number the fake server hands out. */
	private const Challenge = '2043988477';

	/** The challenge number handed out on a second 'challenge rcon' request. */
	private const NewChallenge = '1174404871';

	private const Password = 'pass';

	private ?FakeUdpServer $UdpServer = null;
	private ?Socket $Socket = null;
	private ?GoldSourceRcon $Rcon = null;

	public function tearDown( ) : void
	{
		$this->Rcon?->Close( );
		$this->Socket?->Close( );
		$this->UdpServer?->Close( );

		$this->Rcon      = null;
		$this->Socket    = null;
		$this->UdpServer = null;
	}

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
		$Rcon->Open( );

		try
		{
			$Rcon->Command( 'status' );

			self::fail( 'Expected AuthenticationException' );
		}
		catch( AuthenticationException $Exception )
		{
			self::assertSame( AuthenticationException::BAD_PASSWORD, $Exception->getCode( ) );
			self::assertSame( 'Tried to execute a RCON command before successful authorization.', $Exception->getMessage( ) );
		}
	}

	/** Close( ) forgets the challenge and the password. */
	public function testCloseResetsTheChallenge( ) : void
	{
		$Rcon = $this->Authorize( );

		$Rcon->Close( );

		$this->expectException( AuthenticationException::class );

		$Rcon->Command( 'status' );
	}

	/**
	 * The socket is shared with the query protocol, so writing without one is a
	 * socket error, not an authentication error.
	 */
	public function testWriteWithoutAnOpenSocket( ) : void
	{
		$Rcon = new GoldSourceRcon( new TestableSocket( ) );

		try
		{
			$Rcon->Write( 0, 'challenge rcon' );

			self::fail( 'Expected SocketException' );
		}
		catch( SocketException $Exception )
		{
			self::assertSame( SocketException::NOT_CONNECTED, $Exception->getCode( ) );
		}
	}

	//
	// Authorize
	//

	public function testAuthorizeSendsTheChallengeRequestAndKeepsTheNumber( ) : void
	{
		$Rcon = $this->Authorize( );

		$this->Queue( self::PrintReply( 'hostname: Fake Server' ) );

		self::assertSame( 'hostname: Fake Server', $Rcon->Command( 'status' ) );

		$Requests = $this->Server( )->WaitForRequests( 2 );

		self::assertSame( "\xFF\xFF\xFF\xFF" . 'challenge rcon', $Requests[ 0 ] );
		self::assertSame( "\xFF\xFF\xFF\xFF" . 'rcon ' . self::Challenge . ' "' . self::Password . '" status' . "\0", $Requests[ 1 ] );
	}

	/** Anything that does not begin with the echoed request is not a challenge. */
	public function testAuthorizeRejectsAReplyThatIsNotAChallenge( ) : void
	{
		$this->Open( );
		$this->Queue( FakeUdpServer::A2SReply( SourceQuery::S2A_RCON, "Bad rcon_password.\0\0" ) );

		$Rcon = new GoldSourceRcon( $this->RealSocket( ) );
		$this->Rcon = $Rcon;
		$Rcon->Open( );

		try
		{
			$Rcon->Authorize( self::Password );

			self::fail( 'Expected AuthenticationException' );
		}
		catch( AuthenticationException $Exception )
		{
			self::assertSame( AuthenticationException::BAD_PASSWORD, $Exception->getCode( ) );
			self::assertSame( 'Failed to get RCON challenge.', $Exception->getMessage( ) );
		}
	}

	//
	// Reply handling
	//

	/** Every RCON redirect datagram carries the S2A_RCON type byte. */
	public function testReplyWithTheWrongTypeByte( ) : void
	{
		$Rcon = $this->Authorize( );

		$this->Queue( FakeUdpServer::A2SReply( 0x6E, "hostname: Fake Server\0\0" ) );

		try
		{
			$Rcon->Command( 'status' );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::PACKET_HEADER_MISMATCH, $Exception->getCode( ) );
			self::assertSame( 'Invalid rcon response.', $Exception->getMessage( ) );
		}
	}

	/**
	 * Too many failed rcon attempts get the source address banned, and the ban is
	 * reported instead of the command output.
	 */
	public function testBannedReply( ) : void
	{
		$Rcon = $this->Authorize( );

		$this->Queue( self::PrintReply( 'You have been banned from this server.' ) );

		try
		{
			$Rcon->Command( 'status' );

			self::fail( 'Expected AuthenticationException' );
		}
		catch( AuthenticationException $Exception )
		{
			self::assertSame( AuthenticationException::BANNED, $Exception->getCode( ) );
			self::assertSame( 'You have been banned from this server.', $Exception->getMessage( ) );
		}
	}

	/**
	 * The engine flushes its console redirect in chunks, so a long reply arrives
	 * as several datagrams and a chunk over 1000 bytes means another one follows.
	 */
	public function testReplyDeliveredInTwoChunks( ) : void
	{
		$Rcon = $this->Authorize( );

		$First  = str_repeat( 'A', 1100 );
		$Second = str_repeat( 'B', 500 );

		$this->Queue( self::PrintReply( $First ) );
		$this->Queue( self::PrintReply( $Second ) );

		$Result = $Rcon->Command( 'status' );

		self::assertStringStartsWith( $First, $Result );
		self::assertStringEndsWith( $Second, $Result );
	}

	// Known bugs

	/**
	 * The engine prefixes several distinct rejections with 'Bad rcon_password.'
	 * and puts the reason on the next line. All of them are authentication
	 * failures, not command output.
	 */
	#[Group( 'known-bug' )]
	#[DataProvider( 'RejectionProvider' )]
	public function testEveryBadPasswordRejectionIsAnAuthenticationFailure( string $Reply ) : void
	{
		$Rcon = $this->Authorize( );

		$this->Queue( self::PrintReply( $Reply ) );

		try
		{
			$Output = $Rcon->Command( 'status' );

			self::fail( 'Expected AuthenticationException for ' . var_export( $Reply, true ) . ', got ' . var_export( $Output, true ) . ' as command output.' );
		}
		catch( AuthenticationException $Exception )
		{
			self::assertSame( AuthenticationException::BAD_PASSWORD, $Exception->getCode( ) );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function RejectionProvider( ) : array
	{
		return
		[
			'bad challenge'   => [ "Bad rcon_password.\nBad challenge.\n" ],
			'no password set' => [ "Bad rcon_password.\nNo password set for this server.\n" ],
			'no privilege'    => [ "Bad rcon_password.\nNo privilege.\n" ],
		];
	}

	/**
	 * Challenges expire, are evicted and are reseeded by a restart, so
	 * 'Bad challenge.' is the normal way a long lived connection is told to fetch
	 * a new one. The command must be retried once with the fresh challenge.
	 */
	#[Group( 'known-bug' )]
	public function testStaleChallengeIsRenewedAndTheCommandRetried( ) : void
	{
		$Rcon = $this->Authorize( );

		$this->Queue( self::PrintReply( "Bad rcon_password.\nBad challenge.\n" ) );
		$this->Queue( self::ChallengeReply( self::NewChallenge ) );
		$this->Queue( self::PrintReply( 'hostname: Fake Server' ) );

		try
		{
			$Output = $Rcon->Command( 'status' );
		}
		catch( AuthenticationException $Exception )
		{
			self::fail( 'The command was not retried with a fresh challenge: ' . $Exception->getMessage( ) );
		}

		self::assertSame( 'hostname: Fake Server', $Output );

		$Requests = $this->Server( )->WaitForRequests( 4 );

		self::assertCount( 4, $Requests );
		self::assertSame( "\xFF\xFF\xFF\xFF" . 'challenge rcon', $Requests[ 2 ] );
		self::assertSame( "\xFF\xFF\xFF\xFF" . 'rcon ' . self::NewChallenge . ' "' . self::Password . '" status' . "\0", $Requests[ 3 ] );
	}

	/**
	 * The challenge reply is the echoed request followed by the number. A reply
	 * with no number leaves nothing to build an rcon line from, so it has to be
	 * rejected here rather than surface later as a command sent before authorization.
	 */
	#[Group( 'known-bug' )]
	public function testAuthorizeRejectsAChallengeReplyWithoutANumber( ) : void
	{
		$this->Open( );
		$this->Queue( "\xFF\xFF\xFF\xFF" . "challenge rcon\0" );

		$Rcon = new GoldSourceRcon( $this->RealSocket( ) );
		$this->Rcon = $Rcon;
		$Rcon->Open( );

		try
		{
			$Rcon->Authorize( self::Password );

			self::fail( 'Authorize( ) accepted a challenge reply that carries no number.' );
		}
		catch( AuthenticationException $Exception )
		{
			self::assertSame( AuthenticationException::BAD_PASSWORD, $Exception->getCode( ) );
		}
	}

	//
	// Helpers
	//

	/** Binds the fake server and opens a real GoldSource socket against it. */
	private function Open( int $Timeout = 1 ) : void
	{
		$Server = new FakeUdpServer( );
		$Socket = new Socket( );

		$Socket->Open( $Server->Host( ), $Server->Port( ), $Timeout, SourceQuery::GOLDSOURCE );
		$Server->Attach( $Socket );

		$this->UdpServer = $Server;
		$this->Socket    = $Socket;
	}

	/** Opens the socket and completes the 'challenge rcon' handshake. */
	private function Authorize( int $Timeout = 1 ) : GoldSourceRcon
	{
		$this->Open( $Timeout );
		$this->Queue( self::ChallengeReply( self::Challenge ) );

		$Rcon = new GoldSourceRcon( $this->RealSocket( ) );
		$this->Rcon = $Rcon;

		$Rcon->Open( );
		$Rcon->Authorize( self::Password );

		return $Rcon;
	}

	private function RealSocket( ) : Socket
	{
		$Socket = $this->Socket;

		if( $Socket === null )
		{
			self::fail( 'The socket was not opened.' );
		}

		return $Socket;
	}

	private function Server( ) : FakeUdpServer
	{
		$Server = $this->UdpServer;

		if( $Server === null )
		{
			self::fail( 'The fake server was not started.' );
		}

		return $Server;
	}

	private function Queue( string $Datagram ) : void
	{
		$this->Server( )->Queue( $Datagram );
	}

	/**
	 * The server answer to 'challenge rcon': the echoed request and the
	 * challenge number, null terminated.
	 */
	private static function ChallengeReply( string $Challenge ) : string
	{
		return "\xFF\xFF\xFF\xFF" . 'challenge rcon ' . $Challenge . "\n\0";
	}

	/**
	 * One console redirect datagram: the S2A_RCON type byte, the console text,
	 * then the two terminating null bytes the engine writes.
	 */
	private static function PrintReply( string $Text ) : string
	{
		return FakeUdpServer::A2SReply( SourceQuery::S2A_RCON, $Text . "\0\0" );
	}
}
