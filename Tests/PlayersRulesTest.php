<?php
declare(strict_types=1);

use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\Packets;
use xPaw\SourceQuery\Tests\Support\TestableSocketTestCase;

/**
 * Record level coverage of GetPlayers( ) and GetRules( ): empty lists, declared
 * counts that disagree with the records, and the value ranges a server may send.
 */
class PlayersRulesTest extends TestableSocketTestCase
{
	//
	// A2S_PLAYER
	//

	public function testEmptyServerReturnsNoPlayers( ) : void
	{
		self::assertSame( [], $this->Players( chr( 0 ) ) );
	}

	/**
	 * Players leaving between the count byte and the records are routine, so an
	 * over-declared count must simply stop at the end of the buffer.
	 */
	public function testDeclaredCountAboveTheNumberOfRecords( ) : void
	{
		$Players = $this->Players( chr( 4 ) . Packets::PlayerRecord( 0, 'Only One', 1, 1.0 ) );

		self::assertSame( [ 'Only One' ], array_column( $Players, 'Name' ) );
	}

	/** The declared count bounds the loop, so trailing records are left unparsed. */
	public function testDeclaredCountBelowTheNumberOfRecords( ) : void
	{
		$Players = $this->Players(
			chr( 1 ) .
			Packets::PlayerRecord( 0, 'First', 1, 1.0 ) .
			Packets::PlayerRecord( 1, 'Second', 2, 2.0 )
		);

		self::assertSame( [ 'First' ], array_column( $Players, 'Name' ) );
	}

	public function testEmptyPlayerName( ) : void
	{
		$Players = $this->Players( Packets::PlayersPayload( Packets::PlayerRecord( 0, '', 0, 0.0 ) ) );

		self::assertSame( '', $Players[ 0 ][ 'Name' ] );
		self::assertSame( 0, $Players[ 0 ][ 'Time' ] );
		self::assertSame( '00:00', $Players[ 0 ][ 'TimeF' ] );
	}

	/** The index is whatever the server sent; it need not start at zero. */
	public function testPlayerIndexIsTakenFromTheWire( ) : void
	{
		$Players = $this->Players( Packets::PlayersPayload(
			Packets::PlayerRecord( 128, 'High', 0, 0.0 ),
			Packets::PlayerRecord( 255, 'Higher', 0, 0.0 )
		) );

		self::assertSame( [ 128, 255 ], array_column( $Players, 'Id' ) );
	}

	/** Frags are signed: team damage and suicides push them below zero. */
	public function testNegativeFrags( ) : void
	{
		$Players = $this->Players( Packets::PlayersPayload( Packets::PlayerRecord( 0, 'Negative', -7, 0.0 ) ) );

		self::assertSame( -7, $Players[ 0 ][ 'Frags' ] );
	}

	/**
	 * Servers that have no time for a player yet send a negative one, which is
	 * kept as it is.
	 */
	public function testNegativeTime( ) : void
	{
		$Players = $this->Players( Packets::PlayersPayload( Packets::PlayerRecord( 0, 'Negative', 0, -1.0 ) ) );

		self::assertSame( -1, $Players[ 0 ][ 'Time' ] );
		self::assertSame( '00:00', $Players[ 0 ][ 'TimeF' ] );
	}

	/**
	 * A 32 bit float reaches far beyond the integer range, so a huge time is
	 * clamped instead of overflowing the cast.
	 */
	public function testTimeAboveTheIntegerRangeIsClampedToTheMaximum( ) : void
	{
		$Players = $this->Players( Packets::PlayersPayload( Packets::PlayerRecord( 0, 'Huge', 0, 1.0e30 ) ) );

		self::assertSame( PHP_INT_MAX, $Players[ 0 ][ 'Time' ] );
		self::assertSame( '2562047788015215:30:07', $Players[ 0 ][ 'TimeF' ] );
	}

	public function testTimeBelowTheIntegerRangeIsClampedToTheMinimum( ) : void
	{
		$Players = $this->Players( Packets::PlayersPayload( Packets::PlayerRecord( 0, 'Huge Negative', 0, -1.0e30 ) ) );

		self::assertSame( PHP_INT_MIN, $Players[ 0 ][ 'Time' ] );
		self::assertSame( '00:00', $Players[ 0 ][ 'TimeF' ] );
	}

	/** Names are raw bytes, so multi byte text has to survive untouched. */
	public function testUtf8PlayerName( ) : void
	{
		$Name = "Игрок ✔";

		$Players = $this->Players( Packets::PlayersPayload( Packets::PlayerRecord( 0, $Name, 0, 0.0 ) ) );

		self::assertSame( $Name, $Players[ 0 ][ 'Name' ] );
	}

	public function testPlayersReplyWithWrongTypeIsRejected( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_RULES, Packets::RulesPayload( [] ) ) );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::PACKET_HEADER_MISMATCH );
		$this->expectExceptionMessage( 'GetPlayers' );

		$this->SourceQuery->GetPlayers( );
	}

	//
	// A2S_RULES
	//

	public function testServerWithNoRules( ) : void
	{
		self::assertSame( [], $this->Rules( Packets::RulesPayload( [] ) ) );
	}

	public function testDeclaredRuleCountAboveTheNumberOfRecords( ) : void
	{
		$Rules = $this->Rules( pack( 'v', 9 ) . "mp_timelimit\0" . "35\0" );

		self::assertSame( [ 'mp_timelimit' => '35' ], $Rules );
	}

	public function testDeclaredRuleCountBelowTheNumberOfRecords( ) : void
	{
		$Rules = $this->Rules( pack( 'v', 1 ) . "mp_timelimit\0" . "35\0" . "mp_friendlyfire\0" . "1\0" );

		self::assertSame( [ 'mp_timelimit' => '35' ], $Rules );
	}

	/** A rule with an empty name is dropped, the rules around it are kept. */
	public function testRuleWithAnEmptyNameIsSkipped( ) : void
	{
		$Rules = $this->Rules( pack( 'v', 3 ) . "\0" . "orphan\0" . "sv_gravity\0" . "800\0" . "\0" . "\0" );

		self::assertSame( [ 'sv_gravity' => '800' ], $Rules );
	}

	public function testRuleWithAnEmptyValue( ) : void
	{
		self::assertSame( [ 'sv_downloadurl' => '' ], $this->Rules( Packets::RulesPayload( [ 'sv_downloadurl' => '' ] ) ) );
	}

	/** Values are raw bytes up to the terminator, newlines included. */
	public function testRuleValuesKeepNewlinesAndUtf8( ) : void
	{
		$Value = "first line\nsecond line\r\nтретья ✔";

		self::assertSame( [ 'sv_motd' => $Value ], $this->Rules( Packets::RulesPayload( [ 'sv_motd' => $Value ] ) ) );
	}

	/** The rule count is an unsigned 16 bit value. */
	public function testRuleCountIsUnsigned( ) : void
	{
		$Rules = $this->Rules( pack( 'v', 40000 ) . "sv_gravity\0" . "800\0" );

		self::assertSame( [ 'sv_gravity' => '800' ], $Rules );
	}

	public function testRulesReplyWithWrongTypeIsRejected( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_PLAYER, Packets::PlayersPayload( ) ) );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::PACKET_HEADER_MISMATCH );
		$this->expectExceptionMessage( 'GetRules' );

		$this->SourceQuery->GetRules( );
	}

	//
	// Helpers
	//

	/**
	 * Answers the challenge round trip, then the given S2A_PLAYER body.
	 *
	 * @return array<int, array{Id: int, Name: string, Frags: int, Time: int, TimeF: string}>
	 */
	private function Players( string $Body ) : array
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_PLAYER, $Body ) );

		return $this->SourceQuery->GetPlayers( );
	}

	/**
	 * Answers the challenge round trip, then the given S2A_RULES body.
	 *
	 * @return array<string, string>
	 */
	private function Rules( string $Body ) : array
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_RULES, $Body ) );

		return $this->SourceQuery->GetRules( );
	}
}
