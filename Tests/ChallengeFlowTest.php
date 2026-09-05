<?php
declare(strict_types=1);

use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\Socket;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\FakeUdpServer;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * The challenge negotiation used by GetPlayers( )/GetRules( ), the connection
 * lifecycle of SourceQuery, and Ping( ) against every reply shape servers send.
 */
class ChallengeFlowTest extends \PHPUnit\Framework\TestCase
{
	private const Challenge = "\x11\x22\x33\x44";

	/** The placeholder the library sends when it has no challenge yet. */
	private const NoChallenge = "\xFF\xFF\xFF\xFF";

	private TestableSocket $Socket;
	private SourceQuery $SourceQuery;

	private ?FakeUdpServer $UdpServer = null;

	public function setUp( ) : void
	{
		$this->Socket = new TestableSocket( );
		$this->SourceQuery = new SourceQuery( $this->Socket );
		$this->SourceQuery->Connect( '', 2 );
	}

	public function tearDown( ) : void
	{
		$this->SourceQuery->Disconnect( );
		$this->UdpServer?->Close( );

		$this->UdpServer = null;

		unset( $this->Socket, $this->SourceQuery );
	}

	//
	// GetChallenge
	//

	/**
	 * The request with the placeholder is answered with S2C_CHALLENGE, then
	 * repeated with the 4 challenge bytes.
	 */
	public function testChallengeIsNegotiatedThenUsed( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		self::assertCount( 1, $this->SourceQuery->GetPlayers( ) );
		self::assertSame(
		[
			[ 'Header' => SourceQuery::A2S_PLAYER, 'String' => self::NoChallenge ],
			[ 'Header' => SourceQuery::A2S_PLAYER, 'String' => self::Challenge ],
		], $this->Socket->Written );
	}

	/** Proxies pad the challenge reply; only the first 4 bytes are the challenge. */
	public function testOnlyFourChallengeBytesAreTaken( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( ) . str_repeat( "\x99", 32 ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		$this->SourceQuery->GetPlayers( );

		self::assertSame( [ 'Header' => SourceQuery::A2S_PLAYER, 'String' => self::Challenge ], $this->Socket->LastWritten( ) );
	}

	/**
	 * Servers that do not use challenges answer the placeholder request with the
	 * data straight away, so the request is repeated with no challenge bytes.
	 */
	public function testServerAnsweringTheChallengeRequestWithPlayerData( ) : void
	{
		$this->Socket->Queue( self::PlayersReply( 'From the challenge request' ) );
		$this->Socket->Queue( self::PlayersReply( 'From the real request' ) );

		$Players = $this->SourceQuery->GetPlayers( );

		self::assertSame( [ 'From the real request' ], array_column( $Players, 'Name' ) );
		self::assertSame( [ 'Header' => SourceQuery::A2S_PLAYER, 'String' => '' ], $this->Socket->LastWritten( ) );
	}

	public function testServerAnsweringTheChallengeRequestWithRuleData( ) : void
	{
		$this->Socket->Queue( self::RulesReply( 'from_challenge' ) );
		$this->Socket->Queue( self::RulesReply( 'from_request' ) );

		self::assertSame( [ 'from_request' => 'yes' ], $this->SourceQuery->GetRules( ) );
		self::assertSame( [ 'Header' => SourceQuery::A2S_RULES, 'String' => '' ], $this->Socket->LastWritten( ) );
	}

	/** A datagram with nothing after the 0xFFFFFFFF header reads back as type 0. */
	public function testHeaderOnlyDatagramFailsTheChallenge( ) : void
	{
		$this->Socket->Queue( "\xFF\xFF\xFF\xFF" );

		try
		{
			$this->SourceQuery->GetPlayers( );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( 'GetChallenge: Failed to get challenge.', $Exception->getMessage( ) );
		}
	}

	public function testUnexpectedReplyTypeFailsTheChallenge( ) : void
	{
		$this->Socket->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_INFO_SRC ) );

		try
		{
			$this->SourceQuery->GetPlayers( );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::PACKET_HEADER_MISMATCH, $Exception->getCode( ) );
			self::assertStringContainsString( 'GetChallenge', $Exception->getMessage( ) );
			self::assertStringContainsString( '0x49', $Exception->getMessage( ) );
		}
	}

	//
	// SetUseOldGetChallengeMethod
	//

	public function testSetUseOldGetChallengeMethodReturnsThePreviousValue( ) : void
	{
		self::assertFalse( $this->SourceQuery->SetUseOldGetChallengeMethod( true ) );
		self::assertTrue( $this->SourceQuery->SetUseOldGetChallengeMethod( true ) );
		self::assertTrue( $this->SourceQuery->SetUseOldGetChallengeMethod( false ) );
		self::assertFalse( $this->SourceQuery->SetUseOldGetChallengeMethod( false ) );
	}

	/**
	 * The old method asks with the dedicated A2S_SERVERQUERY_GETCHALLENGE header
	 * instead of the query's own header.
	 */
	public function testOldChallengeMethodSendsItsOwnHeader( ) : void
	{
		$this->SourceQuery->SetUseOldGetChallengeMethod( true );

		$this->Socket->Queue( self::ChallengeReply( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		$this->SourceQuery->GetPlayers( );

		self::assertSame(
		[
			[ 'Header' => SourceQuery::A2S_SERVERQUERY_GETCHALLENGE, 'String' => self::NoChallenge ],
			[ 'Header' => SourceQuery::A2S_PLAYER, 'String' => self::Challenge ],
		], $this->Socket->Written );
	}

	public function testDefaultChallengeMethodSendsTheQueryHeader( ) : void
	{
		$this->SourceQuery->SetUseOldGetChallengeMethod( true );
		$this->SourceQuery->SetUseOldGetChallengeMethod( false );

		$this->Socket->Queue( self::ChallengeReply( ) );
		$this->Socket->Queue( self::RulesReply( 'sv_gravity' ) );

		$this->SourceQuery->GetRules( );

		self::assertSame( SourceQuery::A2S_RULES, $this->Socket->Written[ 0 ][ 'Header' ] );
	}

	//
	// Ping
	//

	/**
	 * Servers answer A2A_PING with an A2A_ACK byte plus a filler whose length
	 * varies by engine generation; only the first byte counts.
	 */
	public function testPingAcceptsEveryAckShape( ) : void
	{
		$Acks =
		[
			'source 2006, 5 bytes'  => '',
			'goldsource, 6 bytes'   => "\x00",
			'orange box, 20 bytes'  => str_repeat( '0', 14 ) . "\x00",
			'proxy padded to 1400'  => str_repeat( "\x00", 1395 ),
		];

		foreach( $Acks as $Name => $Filler )
		{
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::A2A_ACK ) . $Filler );

			self::assertTrue( $this->SourceQuery->Ping( ), $Name );
		}
	}

	public function testPingIsFalseForAnyOtherReplyType( ) : void
	{
		$this->Socket->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_INFO_SRC ) );

		self::assertFalse( $this->SourceQuery->Ping( ) );
		self::assertSame( [ 'Header' => SourceQuery::A2A_PING, 'String' => '' ], $this->Socket->LastWritten( ) );
	}

	//
	// Connection lifecycle
	//

	/**
	 * The challenge belongs to the connection, so a reconnect has to negotiate a
	 * new one instead of sending the old server's number.
	 */
	public function testDisconnectClearsTheChallenge( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		$this->SourceQuery->GetPlayers( );

		$this->SourceQuery->Disconnect( );
		$this->SourceQuery->Connect( '', 2 );

		$this->Socket->Queue( self::ChallengeReply( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		$this->SourceQuery->GetPlayers( );

		self::assertSame( self::NoChallenge, $this->Socket->Written[ 2 ][ 'String' ] );
		self::assertCount( 4, $this->Socket->Written );
	}

	/** Connect( ) disconnects first, so it carries no state over. */
	public function testConnectTwice( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		$this->SourceQuery->GetPlayers( );

		$this->SourceQuery->Connect( '', 3, 5, SourceQuery::GOLDSOURCE );

		self::assertSame( 3, $this->Socket->Port );
		self::assertSame( 5, $this->Socket->Timeout );
		self::assertSame( SourceQuery::GOLDSOURCE, $this->Socket->Engine );

		$this->Socket->Queue( self::ChallengeReply( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		$this->SourceQuery->GetPlayers( );

		self::assertSame( self::NoChallenge, $this->Socket->Written[ 2 ][ 'String' ] );
	}

	public function testDisconnectTwiceIsHarmless( ) : void
	{
		$this->SourceQuery->Disconnect( );
		$this->SourceQuery->Disconnect( );

		$this->expectException( SocketException::class );
		$this->expectExceptionCode( SocketException::NOT_CONNECTED );

		$this->SourceQuery->GetInfo( );
	}

	public function testDestructorClosesTheSocket( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		$Socket = new Socket( );
		$Query  = new SourceQuery( $Socket );
		$Query->Connect( $this->UdpServer->Host( ), $this->UdpServer->Port( ), 1 );

		self::assertIsResource( $Socket->Socket );

		unset( $Query );

		self::assertNull( $Socket->Socket );
	}

	/**
	 * The engine value picks the RCON implementation, and anything that is neither
	 * GoldSource nor Source has none.
	 */
	public function testSetRconPasswordRejectsAnUnknownEngine( ) : void
	{
		$this->Socket->Engine = 99;

		try
		{
			$this->SourceQuery->SetRconPassword( 'pass' );

			self::fail( 'Expected SocketException' );
		}
		catch( SocketException $Exception )
		{
			self::assertSame( SocketException::INVALID_ENGINE, $Exception->getCode( ) );
		}
	}

	//
	// Helpers
	//

	private static function ChallengeReply( ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2C_CHALLENGE ) . self::Challenge;
	}

	private static function PlayersReply( string $Name = 'Player' ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_PLAYER )
			. chr( 1 )
			. chr( 0 ) . $Name . "\0" . pack( 'l', 1 ) . pack( 'f', 60.0 );
	}

	private static function RulesReply( string $Rule ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_RULES ) . pack( 'v', 1 ) . $Rule . "\0" . "yes\0";
	}
}
