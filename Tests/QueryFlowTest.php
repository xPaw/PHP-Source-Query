<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Exception\InvalidArgumentException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\FakeUdpServer;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * The query flow and value handling in SourceQuery, driven entirely through
 * TestableSocket so no network is involved.
 *
 * The known-bug group asserts the correct behaviour and fails until it is fixed.
 */
class QueryFlowTest extends \PHPUnit\Framework\TestCase
{
	private const CHALLENGE_A = "\x11\x22\x33\x44";
	private const CHALLENGE_B = "\xAA\xBB\xCC\xDD";

	private TestableSocket $Socket;
	private SourceQuery $SourceQuery;

	/** @var array<int, string> */
	private array $CapturedWarnings = [];

	public function setUp( ) : void
	{
		$this->Socket = new TestableSocket( );
		$this->SourceQuery = new SourceQuery( $this->Socket );
		$this->SourceQuery->Connect( '', 2 );
	}

	public function tearDown( ) : void
	{
		$this->SourceQuery->Disconnect( );

		unset( $this->Socket, $this->SourceQuery );
	}

	// A server may forget a challenge (restart, expiry) and answer with a fresh
	// S2C_CHALLENGE instead of the data, which has to be re-negotiated.

	#[Group( 'known-bug' )]
	public function testGetPlayersRenegotiatesRejectedChallenge( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) );
		$this->Socket->Queue( self::PlayersReply( 'Before' ) );

		self::assertSame( [ 'Before' ], array_column( $this->SourceQuery->GetPlayers( ), 'Name' ) );

		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_B ) );
		$this->Socket->Queue( self::PlayersReply( 'After' ) );

		try
		{
			$Players = $this->SourceQuery->GetPlayers( );
		}
		catch( InvalidPacketException $e )
		{
			self::fail( 'GetPlayers did not re-negotiate the challenge: ' . $e->getMessage( ) );
		}

		self::assertSame( [ 'After' ], array_column( $Players, 'Name' ) );
		self::assertSame(
			[ 'Header' => SourceQuery::A2S_PLAYER, 'String' => self::CHALLENGE_B ],
			$this->Socket->LastWritten( )
		);
		self::assertTrue( $this->Socket->IsQueueEmpty( ) );
	}

	#[Group( 'known-bug' )]
	public function testGetRulesRenegotiatesRejectedChallenge( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) );
		$this->Socket->Queue( self::RulesReply( 'before', 'yes' ) );

		self::assertSame( [ 'before' => 'yes' ], $this->SourceQuery->GetRules( ) );

		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_B ) );
		$this->Socket->Queue( self::RulesReply( 'after', 'yes' ) );

		try
		{
			$Rules = $this->SourceQuery->GetRules( );
		}
		catch( InvalidPacketException $e )
		{
			self::fail( 'GetRules did not re-negotiate the challenge: ' . $e->getMessage( ) );
		}

		self::assertSame( [ 'after' => 'yes' ], $Rules );
		self::assertSame(
			[ 'Header' => SourceQuery::A2S_RULES, 'String' => self::CHALLENGE_B ],
			$this->Socket->LastWritten( )
		);
		self::assertTrue( $this->Socket->IsQueueEmpty( ) );
	}

	// dproto GoldSource servers answer one A2S_INFO with two datagrams, 0x6D and
	// 0x49; the duplicate must be drained, not handed to the next query.

	#[Group( 'known-bug' )]
	public function testDprotoSecondInfoReplyDoesNotPoisonNextQuery( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;

		$this->Socket->Queue( self::DetailedInfoReply( ) );  // 0x6D, answered and returned
		$this->Socket->Queue( self::InfoReply( 'dproto' ) ); // 0x49, the dproto duplicate

		$Info = $this->SourceQuery->GetInfo( );

		self::assertSame( 'Detailed Server', $Info[ 'HostName' ] );
		self::assertSame( '127.0.0.1:27015', $Info[ 'Address' ] ?? null );

		// The next query must see its own replies.
		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) );
		$this->Socket->Queue( self::PlayersReply( 'Someone' ) );

		try
		{
			$Players = $this->SourceQuery->GetPlayers( );
		}
		catch( InvalidPacketException $e )
		{
			self::fail( 'The dproto duplicate S2A_INFO_SRC datagram poisoned GetPlayers: ' . $e->getMessage( ) );
		}

		self::assertSame( [ 'Someone' ], array_column( $Players, 'Name' ) );
		self::assertTrue( $this->Socket->IsQueueEmpty( ) );
	}

	/**
	 * With an advertising plugin the same servers send three datagrams: 0x6D, an
	 * S2A_PLAYER whose "players" are advert lines, then 0x49.
	 */
	#[Group( 'known-bug' )]
	public function testDprotoThreeDatagramInfoReplyDoesNotPoisonNextQuery( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;

		$this->Socket->Queue( self::DetailedInfoReply( ) );
		$this->Socket->Queue( self::PlayersReply( 'Server IP: 127.0.0.1:27015' ) );
		$this->Socket->Queue( self::InfoReply( 'dproto' ) );

		$Info = $this->SourceQuery->GetInfo( );

		self::assertSame( 'Detailed Server', $Info[ 'HostName' ] );

		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) );
		$this->Socket->Queue( self::PlayersReply( 'Someone' ) );

		try
		{
			$Players = $this->SourceQuery->GetPlayers( );
		}
		catch( InvalidPacketException $e )
		{
			self::fail( 'The extra A2S_INFO datagrams poisoned GetPlayers: ' . $e->getMessage( ) );
		}

		self::assertSame( [ 'Someone' ], array_column( $Players, 'Name' ) );
		self::assertTrue( $this->Socket->IsQueueEmpty( ) );
	}

	// Most modern servers ignore A2A_PING altogether, so a read timeout must come
	// back as false rather than as an exception.

	#[Group( 'known-bug' )]
	public function testPingReturnsFalseWhenServerDoesNotAnswer( ) : void
	{
		$this->Socket->ThrowOnEmptyQueue = false;

		try
		{
			$Result = $this->SourceQuery->Ping( );
		}
		catch( InvalidPacketException $e )
		{
			self::fail( 'Ping( ) threw instead of returning false for an unanswered request: ' . $e->getMessage( ) );
		}

		self::assertFalse( $Result );
	}

	// The float to int conversion of a player's time must cope with NaN and the
	// exact 2^63 boundary without emitting a PHP warning.

	#[Group( 'known-bug' )]
	public function testNanPlayerTimeIsZeroAndDoesNotWarn( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) );
		$this->Socket->Queue( self::PlayersReply( 'Nan', "\x00\x00\xC0\x7F" ) ); // 0x7FC00000 = NaN

		$this->CaptureWarnings( );

		try
		{
			$Players = $this->SourceQuery->GetPlayers( );
		}
		finally
		{
			$this->ReleaseWarnings( );
		}

		self::assertSame( [ 0 ], array_column( $Players, 'Time' ) );
		self::assertSame( [], $this->CapturedWarnings, 'A NaN player time must not emit a PHP warning.' );
	}

	#[Group( 'known-bug' )]
	public function testPlayerTimeOfExactlyTwoToThe63IsClamped( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) );
		$this->Socket->Queue( self::PlayersReply( 'Overflow', "\x00\x00\x00\x5F" ) ); // 0x5F000000 = 2^63

		$this->CaptureWarnings( );

		try
		{
			$Players = $this->SourceQuery->GetPlayers( );
		}
		finally
		{
			$this->ReleaseWarnings( );
		}

		self::assertSame( [ PHP_INT_MAX ], array_column( $Players, 'Time' ) );
		self::assertSame( [], $this->CapturedWarnings, 'A player time of 2^63 must not emit a PHP warning.' );
	}

	// TimeF must show the hour at exactly 3600 seconds and keep counting past 24
	// hours instead of wrapping.

	#[Group( 'known-bug' )]
	public function testTimeFAtExactlyOneHour( ) : void
	{
		self::assertSame( [ '01:00:00' ], self::TimeFFor( 3600.0 ) );
	}

	#[Group( 'known-bug' )]
	public function testTimeFDoesNotWrapAtTwentyFourHours( ) : void
	{
		self::assertSame( [ '25:00:00' ], self::TimeFFor( 90000.0 ) );
	}

	public function testTimeFBelowOneHour( ) : void
	{
		// Baseline: passes today.
		self::assertSame( [ '59:59' ], self::TimeFFor( 3599.0 ) );
	}

	// Connect( ) promises a positive integer timeout, so 0 must be rejected;
	// stream_set_timeout( $Socket, 0 ) breaks every later read.

	#[Group( 'known-bug' )]
	public function testConnectRejectsZeroTimeout( ) : void
	{
		$Socket = new TestableSocket( );
		$Query  = new SourceQuery( $Socket );

		$this->expectException( InvalidArgumentException::class );

		$Query->Connect( '', 2, 0 );
	}

	// A reply that turns up after the caller timed out must not be handed to the
	// next call; the socket has to be drained once a request is abandoned.

	#[Group( 'known-bug' )]
	public function testLateReplyAfterTimeoutDoesNotPoisonNextQuery( ) : void
	{
		$this->Socket->ThrowOnEmptyQueue = false;

		try
		{
			$this->SourceQuery->GetInfo( );

			self::fail( 'GetInfo was expected to time out on an empty socket.' );
		}
		catch( InvalidPacketException $e )
		{
			self::assertSame( InvalidPacketException::BUFFER_EMPTY, $e->getCode( ) );
		}

		// The A2S_INFO reply shows up after the caller gave up on it, followed by
		// the replies that belong to the next query.
		$this->Socket->Queue( self::InfoReply( 'stale' ) );
		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) );
		$this->Socket->Queue( self::PlayersReply( 'Fresh' ) );

		try
		{
			$Players = $this->SourceQuery->GetPlayers( );
		}
		catch( InvalidPacketException $e )
		{
			self::fail( 'The late A2S_INFO reply poisoned GetPlayers: ' . $e->getMessage( ) );
		}

		self::assertSame( [ 'Fresh' ], array_column( $Players, 'Name' ) );
		self::assertTrue( $this->Socket->IsQueueEmpty( ) );
	}

	// Baseline: behaviour that must keep working.

	/**
	 * HLTV proxies pad their S2C_CHALLENGE reply to 1400 bytes with zeros; only
	 * the first 4 bytes are the challenge.
	 */
	public function testPaddedChallengeReplyIsAccepted( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) . str_repeat( "\0", 1391 ) );
		$this->Socket->Queue( self::InfoReply( 'Padded' ) );

		self::assertSame( 'Padded', $this->SourceQuery->GetInfo( )[ 'HostName' ] );
		self::assertSame( "Source Engine Query\0" . self::CHALLENGE_A, $this->Socket->Written[ 1 ][ 'String' ] );

		$this->Socket->Queue( self::PlayersReply( 'Someone' ) );

		self::assertSame( [ 'Someone' ], array_column( $this->SourceQuery->GetPlayers( ), 'Name' ) );
		self::assertSame( self::CHALLENGE_A, $this->Socket->LastWritten( )[ 'String' ] ?? null );
	}

	/**
	 * Some servers hand out a new challenge on every request and never answer with
	 * data, so re-negotiating has to be bounded instead of looping forever.
	 */
	public function testEndlessChallengeRotationThrows( ) : void
	{
		$this->Socket->ThrowOnEmptyQueue = false;

		for( $i = 1; $i <= 6; $i++ )
		{
			$this->Socket->Queue( self::ChallengeReply( pack( 'V', 0x7A39DA00 + $i ) ) );
		}

		$this->expectException( InvalidPacketException::class );

		$this->SourceQuery->GetPlayers( );
	}

	/**
	 * GoldSource servers cap the A2S_RULES reply at 2800 bytes and stop mid record,
	 * leaving a final key with no value.
	 */
	public function testRulesReplyTruncatedAfterKeyStillReturnsEarlierRules( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) );
		$this->Socket->Queue(
			"\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_RULES ) . pack( 'v', 3 ) .
			"decalfrequency\0" . "30\0" .
			"mp_timelimit\0" . "0\0" .
			"mp_roundtime\0"
		);

		$Rules = $this->SourceQuery->GetRules( );

		self::assertSame( '30', $Rules[ 'decalfrequency' ] ?? null );
		self::assertSame( '0', $Rules[ 'mp_timelimit' ] ?? null );
	}

	/**
	 * The Ship appends 8 bytes per player (deaths and money) after the player list,
	 * which the declared count keeps out of the loop.
	 */
	public function testTrailingBytesAfterPlayerListAreIgnored( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) );
		$this->Socket->Queue(
			"\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_PLAYER ) . chr( 2 ) .
			chr( 0 ) . "One\0" . pack( 'V', 3 ) . pack( 'g', 60.0 ) .
			chr( 1 ) . "Two\0" . pack( 'V', 5 ) . pack( 'g', 90.0 ) .
			pack( 'VV', 0, 5230 ) . pack( 'VV', 1, 2050 )
		);

		self::assertSame( [ 'One', 'Two' ], array_column( $this->SourceQuery->GetPlayers( ), 'Name' ) );
	}

	// Baseline: unlike GetPlayers/GetRules above, GetInfo already repeats the
	// request with the challenge appended.

	public function testGetInfoChallengeRoundTrip( ) : void
	{
		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) );
		$this->Socket->Queue( self::InfoReply( 'Challenged' ) );

		$Info = $this->SourceQuery->GetInfo( );

		self::assertSame( 'Challenged', $Info[ 'HostName' ] );
		self::assertCount( 2, $this->Socket->Written );
		self::assertSame(
			[ 'Header' => SourceQuery::A2S_INFO, 'String' => "Source Engine Query\0" ],
			$this->Socket->Written[ 0 ]
		);
		self::assertSame(
			[ 'Header' => SourceQuery::A2S_INFO, 'String' => "Source Engine Query\0" . self::CHALLENGE_A ],
			$this->Socket->Written[ 1 ]
		);
		self::assertTrue( $this->Socket->IsQueueEmpty( ) );
	}

	//
	// Helpers
	//

	/**
	 * Runs one A2S_PLAYER exchange with the given raw player time and returns
	 * the resulting TimeF strings.
	 *
	 * @return array<int, string>
	 */
	private function TimeFFor( float $Time ) : array
	{
		$this->Socket->Queue( self::ChallengeReply( self::CHALLENGE_A ) );
		$this->Socket->Queue( self::PlayersReply( 'Player', pack( 'f', $Time ) ) );

		$Players = $this->SourceQuery->GetPlayers( );

		// Sanity: the integer Time is parsed correctly, only TimeF is at stake.
		self::assertSame( [ (int)$Time ], array_column( $Players, 'Time' ) );

		return array_column( $Players, 'TimeF' );
	}

	private static function ChallengeReply( string $Challenge ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2C_CHALLENGE ) . $Challenge;
	}

	private static function InfoReply( string $HostName ) : string
	{
		return FakeUdpServer::A2SReply( SourceQuery::S2A_INFO_SRC, FakeUdpServer::InfoPayload( $HostName ) );
	}

	/** A GoldSource S2A_INFO_DETAILED (0x6D) reply without a mod block. */
	private static function DetailedInfoReply( ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_INFO_OLD )
			. "127.0.0.1:27015\0"
			. "Detailed Server\0"
			. "de_dust2\0"
			. "cstrike\0"
			. "Counter-Strike\0"
			. chr( 2 ) . chr( 32 ) . chr( 47 ) . 'd' . 'l' . chr( 0 ) . chr( 0 ) . chr( 1 ) . chr( 0 );
	}

	private static function PlayersReply( string $Name, string $TimeBytes = "\x00\x00\x00\x00" ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_PLAYER )
			. chr( 1 )              // Player count
			. chr( 0 )              // Index
			. $Name . "\0"
			. pack( 'V', 10 )       // Frags
			. $TimeBytes;
	}

	private static function RulesReply( string $Rule, string $Value ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_RULES )
			. pack( 'v', 1 )
			. $Rule . "\0"
			. $Value . "\0";
	}

	private function CaptureWarnings( ) : void
	{
		$this->CapturedWarnings = [];

		set_error_handler( function( int $Level, string $Message ) : bool
		{
			$this->CapturedWarnings[] = $Level . ': ' . $Message;

			return true;
		} );
	}

	private function ReleaseWarnings( ) : void
	{
		restore_error_handler( );
	}
}
