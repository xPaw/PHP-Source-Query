<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Exception\InvalidArgumentException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Socket;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\FakeUdpServer;

/**
 * The real Socket (fread/fwrite, stream timeouts, address parsing) against a
 * FakeUdpServer on loopback.
 *
 * The known-bug group asserts the correct behaviour and fails until it is fixed.
 */
class UdpSocketTest extends \PHPUnit\Framework\TestCase
{
	private ?FakeUdpServer $Server = null;
	private ?SourceQuery $Query = null;

	public function tearDown( ) : void
	{
		$this->Query?->Disconnect( );
		$this->Server?->Close( );

		$this->Query  = null;
		$this->Server = null;
	}

	/**
	 * MaxPacketLength promises 65536, so a datagram larger than PHP's 8192 byte
	 * stream chunk size must still be delivered in full.
	 */
	#[Group('known-bug')]
	public function testSingleDatagramLargerThan8192BytesIsRead( ) : void
	{
		$this->StartQuery( );

		$RuleCount = 200;
		$Datagram  = FakeUdpServer::A2SReply( SourceQuery::S2A_RULES, self::RulesPayload( $RuleCount, 30 ) );

		self::assertGreaterThan( 9000, strlen( $Datagram ), 'The datagram must be well past the 8192 byte stream chunk size.' );

		$this->Queue( FakeUdpServer::A2SReply( SourceQuery::S2C_CHALLENGE, "\x11\x22\x33\x44" ) );
		$this->Queue( $Datagram );

		$Rules = [];

		try
		{
			$Rules = $this->Query( )->GetRules( );
		}
		catch( InvalidPacketException $Exception )
		{
			self::fail( 'GetRules( ) threw "' . $Exception->getMessage( ) . '" although the server answered with a single ' . strlen( $Datagram ) . ' byte datagram.' );
		}

		self::assertCount( $RuleCount, $Rules );
		self::assertSame( 'value_000' . str_repeat( 'x', 30 ), $Rules[ 'rule_000' ] );
		self::assertSame( 'value_199' . str_repeat( 'x', 30 ), $Rules[ 'rule_199' ] );
	}

	/**
	 * When the read times out mid reassembly the fragments that did arrive must not
	 * be imploded and returned as if the response were whole.
	 */
	public function testIncompleteSplitReplyThrowsInsteadOfReturningTruncatedRules( ) : void
	{
		$this->StartQuery( );

		$RuleCount = 30;
		$Datagram  = FakeUdpServer::A2SReply( SourceQuery::S2A_RULES, self::RulesPayload( $RuleCount, 10 ) );
		$Fragments = FakeUdpServer::SplitPackets( $Datagram, (int)ceil( strlen( $Datagram ) / 3 ) );

		self::assertCount( 3, $Fragments );

		$this->Queue( FakeUdpServer::A2SReply( SourceQuery::S2C_CHALLENGE, "\x11\x22\x33\x44" ) );

		// The last fragment is dropped, exactly as ordinary UDP loss would do.
		$this->Queue( $Fragments[ 0 ] );
		$this->Queue( $Fragments[ 1 ] );

		$Started = microtime( true );
		$Thrown  = null;
		$Rules   = [];

		try
		{
			$Rules = $this->Query( )->GetRules( );
		}
		catch( InvalidPacketException $Exception )
		{
			$Thrown = $Exception;
		}

		$Elapsed = microtime( true ) - $Started;

		self::assertLessThan( 3.0, $Elapsed, 'The read must give up after roughly the 1 second connect timeout.' );
		self::assertNotNull( $Thrown, 'GetRules( ) returned ' . count( $Rules ) . ' of ' . $RuleCount . ' rules from an incomplete split reply instead of throwing.' );
	}

	/** IPv6 literals are passed in brackets, as fsockopen expects. */
	public function testBracketedIPv6LiteralWorks( ) : void
	{
		$Server = $this->StartIPv6Server( );

		$Socket = new Socket( );
		$Query  = new SourceQuery( $Socket );
		$this->Query = $Query;

		$Query->Connect( '[::1]', $Server->Port( ), 1 );

		$Server->Attach( $Socket );
		$Server->Queue( FakeUdpServer::A2SReply( SourceQuery::S2A_INFO_SRC, FakeUdpServer::InfoPayload( 'IPv6 Server' ) ) );

		$Info = $Query->GetInfo( );

		self::assertSame( 'IPv6 Server', $Info[ 'HostName' ] );

		$Requests = $Server->WaitForRequests( 1 );

		self::assertCount( 1, $Requests );
	}

	/**
	 * A datagram that arrives after the caller gave up must not be consumed as the
	 * answer to the next request; the socket has to be drained once a read times out.
	 */
	#[Group('known-bug')]
	public function testLateDatagramDoesNotPoisonTheNextQuery( ) : void
	{
		$this->StartQuery( );

		// Nothing queued: the read times out and the request is abandoned.
		try
		{
			$this->Query( )->GetInfo( );

			self::fail( 'Expected the unanswered GetInfo( ) to throw.' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::BUFFER_EMPTY, $Exception->getCode( ) );
		}

		// The late answer, followed by the replies to the next request.
		$this->Queue( FakeUdpServer::A2SReply( SourceQuery::S2A_INFO_SRC, FakeUdpServer::InfoPayload( 'stale' ) ) );
		$this->Queue( FakeUdpServer::A2SReply( SourceQuery::S2C_CHALLENGE, "\x11\x22\x33\x44" ) );
		$this->Queue( FakeUdpServer::A2SReply( SourceQuery::S2A_PLAYER, self::PlayersPayload( ) ) );

		$Players = [];

		try
		{
			$Players = $this->Query( )->GetPlayers( );
		}
		catch( InvalidPacketException $Exception )
		{
			self::fail( 'GetPlayers( ) threw "' . $Exception->getMessage( ) . '"; the stale S2A_INFO datagram was consumed as the answer to a different request.' );
		}

		self::assertCount( 2, $Players );
		self::assertSame( 'Player One', $Players[ 0 ][ 'Name' ] );
		self::assertSame( 12, $Players[ 0 ][ 'Frags' ] );
		self::assertSame( 'Player Two', $Players[ 1 ][ 'Name' ] );
		self::assertSame( 34, $Players[ 1 ][ 'Frags' ] );
	}

	/**
	 * Connect( ) promises a positive integer timeout, so 0 must be rejected;
	 * stream_set_timeout( $Socket, 0 ) breaks every later read.
	 */
	public function testConnectRejectsZeroTimeout( ) : void
	{
		$this->Server = new FakeUdpServer( );

		$Query = new SourceQuery( new Socket( ) );
		$this->Query = $Query;

		$this->expectException( InvalidArgumentException::class );

		$Query->Connect( '127.0.0.1', $this->Server->Port( ), 0 );
	}

	public function testGetInfoBaseline( ) : void
	{
		$this->StartQuery( );

		$this->Queue( FakeUdpServer::A2SReply( SourceQuery::S2A_INFO_SRC, FakeUdpServer::InfoPayload( 'Baseline Server', 440 ) ) );

		$Info = $this->Query( )->GetInfo( );

		self::assertSame( 'Baseline Server', $Info[ 'HostName' ] );
		self::assertSame( 'de_dust2', $Info[ 'Map' ] );
		self::assertSame( 'cstrike', $Info[ 'ModDir' ] );
		self::assertSame( 4, $Info[ 'Players' ] );
		self::assertSame( 32, $Info[ 'MaxPlayers' ] );

		$Requests = $this->Server( )->WaitForRequests( 1 );

		self::assertCount( 1, $Requests );
		self::assertSame( "\xFF\xFF\xFF\xFF\x54Source Engine Query\x00", $Requests[ 0 ] );
	}

	public function testGetPlayersWithChallengeBaseline( ) : void
	{
		$this->StartQuery( );

		$this->Queue( FakeUdpServer::A2SReply( SourceQuery::S2C_CHALLENGE, "\x11\x22\x33\x44" ) );
		$this->Queue( FakeUdpServer::A2SReply( SourceQuery::S2A_PLAYER, self::PlayersPayload( ) ) );

		$Players = $this->Query( )->GetPlayers( );

		self::assertCount( 2, $Players );
		self::assertSame( 'Player One', $Players[ 0 ][ 'Name' ] );
		self::assertSame( 12, $Players[ 0 ][ 'Frags' ] );
		self::assertSame( 90, $Players[ 0 ][ 'Time' ] );
		self::assertSame( '01:30', $Players[ 0 ][ 'TimeF' ] );
		self::assertSame( 'Player Two', $Players[ 1 ][ 'Name' ] );

		$Requests = $this->Server( )->WaitForRequests( 2 );

		self::assertCount( 2, $Requests );
		self::assertSame( "\xFF\xFF\xFF\xFF\x55\xFF\xFF\xFF\xFF", $Requests[ 0 ] );
		self::assertSame( "\xFF\xFF\xFF\xFF\x55\x11\x22\x33\x44", $Requests[ 1 ] );
	}

	public function testGetRulesOverThreeFragmentSplitReplyBaseline( ) : void
	{
		$this->StartQuery( );

		$RuleCount = 30;
		$Datagram  = FakeUdpServer::A2SReply( SourceQuery::S2A_RULES, self::RulesPayload( $RuleCount, 10 ) );
		$Fragments = FakeUdpServer::SplitPackets( $Datagram, (int)ceil( strlen( $Datagram ) / 3 ) );

		self::assertCount( 3, $Fragments );

		$this->Queue( FakeUdpServer::A2SReply( SourceQuery::S2C_CHALLENGE, "\x11\x22\x33\x44" ) );

		foreach( $Fragments as $Fragment )
		{
			$this->Queue( $Fragment );
		}

		$Rules = $this->Query( )->GetRules( );

		self::assertCount( $RuleCount, $Rules );
		self::assertSame( 'value_000' . str_repeat( 'x', 10 ), $Rules[ 'rule_000' ] );
		self::assertSame( 'value_029' . str_repeat( 'x', 10 ), $Rules[ 'rule_029' ] );
	}

	public function testPingBaseline( ) : void
	{
		$this->StartQuery( );

		$this->Queue( FakeUdpServer::A2SReply( SourceQuery::A2A_ACK, "\x00" ) );

		self::assertTrue( $this->Query( )->Ping( ) );

		$Requests = $this->Server( )->WaitForRequests( 1 );

		self::assertCount( 1, $Requests );
		self::assertSame( "\xFF\xFF\xFF\xFF\x69", $Requests[ 0 ] );
	}

	/**
	 * Binds the fake server on IPv4 loopback, connects the real socket to it and
	 * attaches the server so datagrams can be pushed.
	 */
	private function StartQuery( ) : void
	{
		$Server = new FakeUdpServer( );
		$Socket = new Socket( );
		$Query  = new SourceQuery( $Socket );

		$Query->Connect( $Server->Host( ), $Server->Port( ), 1 );

		$Server->Attach( $Socket );

		$this->Server = $Server;
		$this->Query  = $Query;
	}

	private function StartIPv6Server( ) : FakeUdpServer
	{
		try
		{
			$Server = new FakeUdpServer( '[::1]' );
		}
		catch( \RuntimeException $Exception )
		{
			self::markTestSkipped( 'IPv6 loopback is not available: ' . $Exception->getMessage( ) );
		}

		$this->Server = $Server;

		return $Server;
	}

	private function Server( ) : FakeUdpServer
	{
		$Server = $this->Server;

		if( $Server === null )
		{
			self::fail( 'The fake server was not started.' );
		}

		return $Server;
	}

	private function Query( ) : SourceQuery
	{
		$Query = $this->Query;

		if( $Query === null )
		{
			self::fail( 'The query object was not created.' );
		}

		return $Query;
	}

	private function Queue( string $Datagram ) : void
	{
		$this->Server( )->Queue( $Datagram );
	}

	/** S2A_RULES body: int16 count, then null terminated key/value pairs. */
	private static function RulesPayload( int $Count, int $Padding ) : string
	{
		$Payload = pack( 'v', $Count );

		for( $i = 0; $i < $Count; $i++ )
		{
			$Payload .= sprintf( 'rule_%03d', $i ) . "\0";
			$Payload .= sprintf( 'value_%03d', $i ) . str_repeat( 'x', $Padding ) . "\0";
		}

		return $Payload;
	}

	/** S2A_PLAYER body: byte count, then per player id, name, int32 frags, float time. */
	private static function PlayersPayload( ) : string
	{
		return chr( 2 )
			. chr( 0 ) . "Player One\0" . pack( 'l', 12 ) . pack( 'f', 90.0 )
			. chr( 1 ) . "Player Two\0" . pack( 'l', 34 ) . pack( 'f', 4000.0 );
	}
}
