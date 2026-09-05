<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * GoldSource specifics: the detailed reply's mod block, servers answering an
 * info request with several datagrams, and ban replies.
 *
 * The known-bug group asserts the correct behaviour and fails until it is fixed.
 */
class GoldSourceProtocolTest extends \PHPUnit\Framework\TestCase
{
	private const Challenge = "\xFF\xFF\xFF\xFF\x41\x11\x22\x33\x44";

	private TestableSocket $Socket;
	private SourceQuery $SourceQuery;

	public function setUp( ) : void
	{
		$this->Socket = new TestableSocket( );
		$this->Socket->ThrowOnEmptyQueue = false;

		$this->SourceQuery = new SourceQuery( $this->Socket );
		$this->SourceQuery->Connect( '', 2 );
	}

	public function tearDown( ) : void
	{
		$this->SourceQuery->Disconnect( );

		unset( $this->Socket, $this->SourceQuery );
	}

	/**
	 * The third field of the mod block is the null terminated engine version the
	 * mod was built against, not a single filler byte. A server that fills it in
	 * shifts every field after it.
	 */
	#[Group( 'known-bug' )]
	public function testModBlockHlVersionIsANullTerminatedString( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		$this->Socket->Queue( self::DetailedReply( Mod:
			"https://example.invalid/\0" .
			"https://example.invalid/dl\0" .
			"1.1.2.0\0" .              // hlversion
			pack( 'V', 4808 ) .        // Version
			pack( 'V', 184000000 ) .   // Size
			chr( 1 ) .                 // ServerSide
			chr( 0 )                   // CustomDLL
		) );

		$Info = $this->SourceQuery->GetInfo( );

		self::assertTrue( $Info[ 'IsMod' ] ?? null );
		self::assertSame(
		[
			'Url'        => 'https://example.invalid/',
			'Download'   => 'https://example.invalid/dl',
			'Version'    => 4808,
			'Size'       => 184000000,
			'ServerSide' => true,
			'CustomDLL'  => false,
		], $Info[ 'Mod' ] ?? null );
		self::assertTrue( $Info[ 'Secure' ] );
		self::assertSame( 1, $Info[ 'Bots' ] );
	}

	/**
	 * A repeated A2S_INFO inside the server's de-duplication window is answered
	 * with three datagrams: the detailed reply, an empty S2A_PLAYER, then
	 * S2A_INFO_SRC. All three belong to the info request, so the stale
	 * S2A_PLAYER must not be taken for a challenge-less player reply.
	 */
	#[Group( 'known-bug' )]
	public function testThreeDatagramInfoReplyDoesNotSwallowTheChallenge( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;

		$this->Socket->Queue( self::DetailedReply( ) );
		$this->Socket->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_PLAYER ) . chr( 0 ) );
		$this->Socket->Queue( self::InfoReply( ) );

		self::assertSame( 'Fake Server', $this->SourceQuery->GetInfo( )[ 'HostName' ] );

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue( self::PlayersReply( ) );

		try
		{
			$Players = $this->SourceQuery->GetPlayers( );
		}
		catch( InvalidPacketException $Exception )
		{
			self::fail( 'The extra datagrams of the info reply poisoned GetPlayers: ' . $Exception->getMessage( ) );
		}

		self::assertSame( [ 'Player One' ], array_column( $Players, 'Name' ) );
		self::assertSame( "\x11\x22\x33\x44", $this->Socket->LastWritten( )[ 'String' ] ?? null, 'The player request must carry the challenge that was negotiated for it.' );
		self::assertTrue( $this->Socket->IsQueueEmpty( ) );
	}

	/**
	 * A banned address gets an A2A_PRINT reply saying so instead of data, for
	 * every query it sends. That is not a malformed packet.
	 */
	#[Group( 'known-bug' )]
	public function testBanReplyToInfoIsReportedAsBanned( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		$this->Socket->Queue( self::BanReply( ) );

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BANNED );

		$this->SourceQuery->GetInfo( );
	}

	#[Group( 'known-bug' )]
	public function testBanReplyToPlayersIsReportedAsBanned( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		$this->Socket->Queue( self::BanReply( ) );

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BANNED );

		$this->SourceQuery->GetPlayers( );
	}

	//
	// Helpers
	//

	/** An S2A_INFO_DETAILED datagram. $Mod is the block that follows the IsMod flag. */
	private static function DetailedReply( string $Mod = '' ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_INFO_OLD )
			. "127.0.0.1:27015\0"
			. "Fake Server\0"
			. "crossfire\0"
			. "valve\0"
			. "Half-Life\0"
			. chr( 5 )              // Players
			. chr( 32 )             // MaxPlayers
			. chr( 47 )             // Protocol
			. 'd'                   // Dedicated
			. 'l'                   // Os
			. chr( 0 )              // Password
			. chr( $Mod === '' ? 0 : 1 )
			. $Mod
			. chr( 1 )              // Secure
			. chr( 1 );             // Bots
	}

	private static function InfoReply( ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_INFO_SRC )
			. chr( 47 )
			. "Fake Server\0"
			. "crossfire\0"
			. "valve\0"
			. "Half-Life\0"
			. pack( 'v', 70 )
			. chr( 5 ) . chr( 32 ) . chr( 0 ) . 'd' . 'l' . chr( 0 ) . chr( 0 )
			. "1.1.2.0\0";
	}

	private static function BanReply( ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_RCON ) . "You have been banned from this server.\n\0";
	}

	private static function PlayersReply( ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_PLAYER )
			. chr( 1 )
			. chr( 0 ) . "Player One\0" . pack( 'l', 3 ) . pack( 'f', 60.0 );
	}
}
