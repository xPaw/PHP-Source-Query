<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Exception\InvalidArgumentException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\Packets;
use xPaw\SourceQuery\Tests\Support\TestableSocketTestCase;

/**
 * The query flow of SourceQuery: challenge negotiation, the connection
 * lifecycle, Ping( ), and the value handling of the replies.
 */
class QueryFlowTest extends TestableSocketTestCase
{
	protected const bool TimeoutOnEmptyQueue = true;

	private const ChallengeB = "\xAA\xBB\xCC\xDD";

	/** The placeholder the library sends when it has no challenge yet. */
	private const NoChallenge = "\xFF\xFF\xFF\xFF";

	//
	// GetChallenge
	//

	/**
	 * The request with the placeholder is answered with S2C_CHALLENGE, then
	 * repeated with the 4 challenge bytes.
	 */
	public function testChallengeIsNegotiatedThenUsed( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		self::assertCount( 1, $this->SourceQuery->GetPlayers( ) );
		self::assertSame(
		[
			[ 'Header' => SourceQuery::A2S_PLAYER, 'String' => self::NoChallenge ],
			[ 'Header' => SourceQuery::A2S_PLAYER, 'String' => Packets::ChallengeBytes ],
		], $this->Socket->Written );
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

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionMessage( 'GetChallenge: Failed to get challenge.' );

		$this->SourceQuery->GetPlayers( );
	}

	public function testUnexpectedReplyTypeFailsTheChallenge( ) : void
	{
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_SRC ) );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::PACKET_HEADER_MISMATCH );
		$this->expectExceptionMessage( 'GetChallenge: Packet header mismatch. (0x49)' );

		$this->SourceQuery->GetPlayers( );
	}

	// A server may forget a challenge (restart, expiry) and answer with a fresh
	// S2C_CHALLENGE instead of the data, which has to be re-negotiated.

	public function testGetPlayersRenegotiatesRejectedChallenge( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( self::PlayersReply( 'Before' ) );

		self::assertSame( [ 'Before' ], array_column( $this->SourceQuery->GetPlayers( ), 'Name' ) );

		$this->Socket->Queue( Packets::Challenge( self::ChallengeB ) );
		$this->Socket->Queue( self::PlayersReply( 'After' ) );

		self::assertSame( [ 'After' ], array_column( $this->SourceQuery->GetPlayers( ), 'Name' ) );
		self::assertSame(
			[ 'Header' => SourceQuery::A2S_PLAYER, 'String' => self::ChallengeB ],
			$this->Socket->LastWritten( )
		);
		self::assertSame( 0, $this->Socket->QueuedCount( ) );
	}

	public function testGetRulesRenegotiatesRejectedChallenge( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( self::RulesReply( 'before' ) );

		self::assertSame( [ 'before' => 'yes' ], $this->SourceQuery->GetRules( ) );

		$this->Socket->Queue( Packets::Challenge( self::ChallengeB ) );
		$this->Socket->Queue( self::RulesReply( 'after' ) );

		self::assertSame( [ 'after' => 'yes' ], $this->SourceQuery->GetRules( ) );
		self::assertSame(
			[ 'Header' => SourceQuery::A2S_RULES, 'String' => self::ChallengeB ],
			$this->Socket->LastWritten( )
		);
		self::assertSame( 0, $this->Socket->QueuedCount( ) );
	}

	/**
	 * HLTV proxies pad their S2C_CHALLENGE reply to 1400 bytes with zeros; only
	 * the first 4 bytes are the challenge.
	 */
	public function testPaddedChallengeReplyIsAccepted( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) . str_repeat( "\0", 1391 ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_SRC, Packets::InfoPayload( 'Padded' ) ) );

		self::assertSame( 'Padded', $this->SourceQuery->GetInfo( )[ 'HostName' ] );
		self::assertSame( "Source Engine Query\0" . Packets::ChallengeBytes, $this->Socket->Written[ 1 ][ 'String' ] );

		$this->Socket->Queue( self::PlayersReply( 'Someone' ) );

		self::assertSame( [ 'Someone' ], array_column( $this->SourceQuery->GetPlayers( ), 'Name' ) );
		self::assertSame( [ 'Header' => SourceQuery::A2S_PLAYER, 'String' => Packets::ChallengeBytes ], $this->Socket->LastWritten( ) );
	}

	/**
	 * Some servers hand out a new challenge on every request and never answer with
	 * data, so re-negotiating has to be bounded instead of looping forever.
	 */
	public function testEndlessChallengeRotationThrows( ) : void
	{
		for( $i = 1; $i <= 6; $i++ )
		{
			$this->Socket->Queue( Packets::Challenge( pack( 'V', 0x7A39DA00 + $i ) ) );
		}

		$this->expectException( InvalidPacketException::class );

		$this->SourceQuery->GetPlayers( );
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

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		$this->SourceQuery->GetPlayers( );

		self::assertSame(
		[
			[ 'Header' => SourceQuery::A2S_SERVERQUERY_GETCHALLENGE, 'String' => self::NoChallenge ],
			[ 'Header' => SourceQuery::A2S_PLAYER, 'String' => Packets::ChallengeBytes ],
		], $this->Socket->Written );
	}

	public function testDefaultChallengeMethodSendsTheQueryHeader( ) : void
	{
		$this->SourceQuery->SetUseOldGetChallengeMethod( true );
		$this->SourceQuery->SetUseOldGetChallengeMethod( false );

		$this->Socket->Queue( Packets::Challenge( ) );
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
			$this->Socket->Queue( Packets::A2SReply( SourceQuery::A2A_ACK, $Filler ) );

			self::assertTrue( $this->SourceQuery->Ping( ), $Name );
		}
	}

	public function testPingIsFalseForAnyOtherReplyType( ) : void
	{
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_SRC ) );

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
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		$this->SourceQuery->GetPlayers( );

		$this->SourceQuery->Disconnect( );
		$this->SourceQuery->Connect( '', 2 );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		$this->SourceQuery->GetPlayers( );

		self::assertSame( self::NoChallenge, $this->Socket->Written[ 2 ][ 'String' ] );
		self::assertCount( 4, $this->Socket->Written );
	}

	/** Connect( ) disconnects first, so it carries no state over. */
	public function testConnectTwice( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		$this->SourceQuery->GetPlayers( );

		$this->SourceQuery->Connect( '', 3, 5, SourceQuery::GOLDSOURCE );

		self::assertSame( 3, $this->Socket->Port );
		self::assertSame( 5, $this->Socket->Timeout );
		self::assertSame( SourceQuery::GOLDSOURCE, $this->Socket->Engine );

		$this->Socket->Queue( Packets::Challenge( ) );
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

	/**
	 * Connect( ) promises a positive integer timeout, so 0 must be rejected;
	 * stream_set_timeout( $Socket, 0 ) breaks every later read.
	 */
	public function testConnectRejectsZeroTimeout( ) : void
	{
		$this->expectException( InvalidArgumentException::class );

		$this->SourceQuery->Connect( '', 2, 0 );
	}

	/**
	 * The engine value picks the RCON implementation, and anything that is neither
	 * GoldSource nor Source has none.
	 */
	public function testSetRconPasswordRejectsAnUnknownEngine( ) : void
	{
		$this->Socket->Engine = 99;

		$this->expectException( SocketException::class );
		$this->expectExceptionCode( SocketException::INVALID_ENGINE );

		$this->SourceQuery->SetRconPassword( 'pass' );
	}

	//
	// Player and rule values
	//

	// The float to int conversion of a player's time must cope with NaN and the
	// exact 2^63 boundary without emitting a PHP warning.

	public function testNanPlayerTimeIsZero( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( self::PlayersReply( 'Nan', "\x00\x00\xC0\x7F" ) ); // 0x7FC00000 = NaN

		self::assertSame( [ 0 ], array_column( $this->SourceQuery->GetPlayers( ), 'Time' ) );
	}

	public function testPlayerTimeOfExactlyTwoToThe63IsClamped( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( self::PlayersReply( 'Overflow', "\x00\x00\x00\x5F" ) ); // 0x5F000000 = 2^63

		self::assertSame( [ PHP_INT_MAX ], array_column( $this->SourceQuery->GetPlayers( ), 'Time' ) );
	}

	// TimeF must show the hour at exactly 3600 seconds and keep counting past 24
	// hours instead of wrapping.

	public function testTimeFAtExactlyOneHour( ) : void
	{
		self::assertSame( [ '01:00:00' ], $this->TimeFFor( 3600.0 ) );
	}

	public function testTimeFDoesNotWrapAtTwentyFourHours( ) : void
	{
		self::assertSame( [ '25:00:00' ], $this->TimeFFor( 90000.0 ) );
	}

	public function testTimeFBelowOneHour( ) : void
	{
		self::assertSame( [ '59:59' ], $this->TimeFFor( 3599.0 ) );
	}

	/**
	 * GoldSource servers cap the A2S_RULES reply at 2800 bytes and stop mid record,
	 * leaving a final key with no value.
	 */
	public function testRulesReplyTruncatedAfterKeyStillReturnsEarlierRules( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_RULES,
			pack( 'v', 3 ) . "decalfrequency\0" . "30\0" . "mp_timelimit\0" . "0\0" . "mp_roundtime\0"
		) );

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
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_PLAYER,
			Packets::PlayersPayload(
				Packets::PlayerRecord( 0, 'One', 3, 60.0 ),
				Packets::PlayerRecord( 1, 'Two', 5, 90.0 )
			) . pack( 'VV', 0, 5230 ) . pack( 'VV', 1, 2050 )
		) );

		self::assertSame( [ 'One', 'Two' ], array_column( $this->SourceQuery->GetPlayers( ), 'Name' ) );
	}

	// Known bugs.

	/** Most modern servers ignore A2A_PING, so an unanswered request is not an error. */
	#[Group( 'known-bug' )]
	public function testPingReturnsFalseWhenServerDoesNotAnswer( ) : void
	{
		try
		{
			$Result = $this->SourceQuery->Ping( );
		}
		catch( InvalidPacketException )
		{
			self::fail( 'Ping( ) threw instead of returning false for an unanswered request.' );
		}

		self::assertFalse( $Result );
	}

	/**
	 * A truncated player name that runs off the end of the reply must not come
	 * back as a player with an empty name and Frags/Time read from leftover bytes.
	 */
	#[Group( 'known-bug' )]
	public function testTruncatedPlayerNameMustNotProduceGarbagePlayer( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_PLAYER,
			chr( 2 ) . Packets::PlayerRecord( 0, 'Bob', 5, 4.0 ) . chr( 0 ) . 'AliceXYZ'
		) );

		$this->expectException( InvalidPacketException::class );

		$this->SourceQuery->GetPlayers( );
	}

	/**
	 * An S2C_CHALLENGE with fewer than 4 challenge bytes must make GetInfo( ) fail,
	 * not store the short challenge as '' and repeat the request with it.
	 */
	#[Group( 'known-bug' )]
	public function testShortChallengeInGetInfoMustThrow( ) : void
	{
		$this->Socket->Queue( self::ShortChallenge( ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_SRC, Packets::InfoPayload( ) ) );

		try
		{
			$this->SourceQuery->GetInfo( );

			self::fail( 'A 2 byte challenge was accepted.' );
		}
		catch( InvalidPacketException )
		{
			// The request must not be repeated with an empty challenge.
			self::assertCount( 1, $this->Socket->Written );
		}
	}

	/**
	 * The same short challenge through the GetChallenge( ) path used by
	 * GetPlayers( ): A2S_PLAYER must not be sent with an empty challenge.
	 */
	#[Group( 'known-bug' )]
	public function testShortChallengeInGetPlayersMustThrow( ) : void
	{
		$this->Socket->Queue( self::ShortChallenge( ) );
		$this->Socket->Queue( self::PlayersReply( ) );

		try
		{
			$this->SourceQuery->GetPlayers( );

			self::fail( 'A 2 byte challenge was accepted.' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertCount( 1, $this->Socket->Written );
		}
	}

	//
	// Helpers
	//

	/**
	 * Runs one A2S_PLAYER exchange with the given player time and returns the
	 * resulting TimeF strings.
	 *
	 * @return array<int, string>
	 */
	private function TimeFFor( float $Time ) : array
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( self::PlayersReply( 'Player', pack( 'f', $Time ) ) );

		return array_column( $this->SourceQuery->GetPlayers( ), 'TimeF' );
	}

	/** S2C_CHALLENGE with only 2 of the 4 challenge bytes. */
	private static function ShortChallenge( ) : string
	{
		return Packets::Challenge( "\x11\x11" );
	}

	private static function PlayersReply( string $Name = 'Player', float|string $Time = 60.0 ) : string
	{
		return Packets::A2SReply( SourceQuery::S2A_PLAYER, Packets::PlayersPayload( Packets::PlayerRecord( 0, $Name, 10, $Time ) ) );
	}

	private static function RulesReply( string $Rule ) : string
	{
		return Packets::A2SReply( SourceQuery::S2A_RULES, Packets::RulesPayload( [ $Rule => 'yes' ] ) );
	}
}
