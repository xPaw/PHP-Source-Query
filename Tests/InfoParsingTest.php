<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\Packets;
use xPaw\SourceQuery\Tests\Support\TestableSocketTestCase;

/**
 * Field by field coverage of GetInfo( ) for both reply shapes: S2A_INFO_SRC
 * (0x49) with every extra data flag combination, and the GoldSource
 * S2A_INFO_DETAILED (0x6D) with and without a mod block.
 */
class InfoParsingTest extends TestableSocketTestCase
{
	/** S2A_EXTRA_DATA_HAS_GAME_PORT, next 2 bytes are the game port. */
	private const FLAG_PORT = 0x80;

	/** S2A_EXTRA_DATA_HAS_SPECTATOR_DATA, next 2 bytes plus a string. */
	private const FLAG_SPECTATOR = 0x40;

	/** S2A_EXTRA_DATA_HAS_GAMETAG_DATA, next bytes are a string. */
	private const FLAG_TAGS = 0x20;

	/** S2A_EXTRA_DATA_HAS_STEAMID, next 8 bytes are the server's steamid. */
	private const FLAG_STEAMID = 0x10;

	/** S2A_EXTRA_DATA_GAMEID, next 8 bytes are the 64 bit game id. */
	private const FLAG_GAMEID = 0x01;

	private const GamePort = 27015;
	private const SpecPort = 27020;
	private const SpecName = 'Spectator Proxy';
	private const GameTags = 'alltalk,increased_maxplayers';
	private const GameID   = 440;

	/** The two 32 bit words a server puts on the wire for its steamid, lower first. */
	private const SteamIDLower = 0x1B7A5C04;
	private const SteamIDUpper = 0x01100001;
	private const SteamID      = ( self::SteamIDUpper << 32 ) | self::SteamIDLower;

	private const HostName = 'Constructed Server';

	/**
	 * The keys every S2A_INFO_SRC reply produces, used to isolate the extra data
	 * fields from the fixed part.
	 *
	 * @var array<string, bool>
	 */
	private const BaseKeys =
	[
		'Protocol'   => true,
		'HostName'   => true,
		'Map'        => true,
		'ModDir'     => true,
		'ModDesc'    => true,
		'AppID'      => true,
		'Players'    => true,
		'MaxPlayers' => true,
		'Bots'       => true,
		'Dedicated'  => true,
		'Os'         => true,
		'Password'   => true,
		'Secure'     => true,
		'Version'    => true,
	];

	//
	// S2A_INFO_SRC (0x49)
	//

	public function testFixedFieldsAreDecodedWithTheDocumentedTypes( ) : void
	{
		$this->Socket->Queue( self::InfoReply( ) );

		$Info = $this->SourceQuery->GetInfo( );

		self::assertSame( 17, $Info[ 'Protocol' ] );
		self::assertSame( self::HostName, $Info[ 'HostName' ] );
		self::assertSame( 'de_dust2', $Info[ 'Map' ] );
		self::assertSame( 'cstrike', $Info[ 'ModDir' ] );
		self::assertSame( 'Counter-Strike', $Info[ 'ModDesc' ] );
		self::assertSame( 440, $Info[ 'AppID' ] ?? null );
		self::assertSame( 4, $Info[ 'Players' ] );
		self::assertSame( 32, $Info[ 'MaxPlayers' ] );
		self::assertSame( 2, $Info[ 'Bots' ] );
		self::assertSame( 'd', $Info[ 'Dedicated' ] );
		self::assertSame( 'l', $Info[ 'Os' ] );
		self::assertFalse( $Info[ 'Password' ] );
		self::assertTrue( $Info[ 'Secure' ] );
		self::assertSame( '1.0.0.0', $Info[ 'Version' ] ?? null );
	}

	/** The AppID field is an unsigned 16 bit value. */
	public function testAppIDAboveThirtyTwoThousandIsUnsigned( ) : void
	{
		$this->Socket->Queue( self::InfoReply( AppID: 40000 ) );

		self::assertSame( 40000, $this->SourceQuery->GetInfo( )[ 'AppID' ] ?? null );
	}

	/** Protocol 7 era servers stop after Version and send no extra data flags byte. */
	public function testReplyWithoutAnExtraDataFlagsByte( ) : void
	{
		$this->Socket->Queue( self::InfoReply( Protocol: 7 ) );

		$Info = $this->SourceQuery->GetInfo( );

		self::assertSame( 7, $Info[ 'Protocol' ] );
		self::assertArrayNotHasKey( 'ExtraDataFlags', $Info );
		self::assertSame( [], array_diff_key( $Info, self::BaseKeys ) );
	}

	/**
	 * The extra data fields are always written in flag order: game port, steamid,
	 * spectator, tags, game id, whichever of them are present.
	 *
	 * @param int<0, 255> $Flags
	 */
	#[DataProvider( 'ExtraDataFlagsProvider' )]
	public function testExtraDataFlagCombinations( int $Flags ) : void
	{
		$this->Socket->Queue( self::InfoReply( Extra: self::ExtraData( $Flags ) ) );

		$Info = $this->SourceQuery->GetInfo( );

		self::assertSame( self::ExpectedExtraData( $Flags ), array_diff_key( $Info, self::BaseKeys ) );
	}

	/**
	 * @return array<string, array{int<0, 255>}>
	 */
	public static function ExtraDataFlagsProvider( ) : array
	{
		$Provider = [];

		foreach( [ 0xb1, 0x80, 0x91, 0xf1, 0xa0, 0xa1, 0x71, 0x51, 0xd1, 0xe0, 0xe1 ] as $Flags )
		{
			$Provider[ sprintf( '0x%02x', $Flags ) ] = [ $Flags ];
		}

		return $Provider;
	}

	/**
	 * The spectator port is followed by the spectator server name, and an empty
	 * name is just the terminator.
	 */
	public function testEmptySpectatorNameIsAnEmptyString( ) : void
	{
		$this->Socket->Queue( self::InfoReply( Extra: chr( self::FLAG_SPECTATOR ) . pack( 'v', self::SpecPort ) . "\0" ) );

		$Info = $this->SourceQuery->GetInfo( );

		self::assertSame( self::SpecPort, $Info[ 'SpecPort' ] ?? null );
		self::assertSame( '', $Info[ 'SpecName' ] ?? null );
	}

	/**
	 * Titles whose AppID does not fit the 16 bit field report 0 there and put the
	 * real id in the 64 bit GameID of the extra data.
	 */
	public function testAppIDZeroWithGameIDAboveSixteenBits( ) : void
	{
		$this->Socket->Queue( self::InfoReply( AppID: 0, Extra: chr( self::FLAG_GAMEID ) . pack( 'VV', 107410, 0 ) ) );

		$Info = $this->SourceQuery->GetInfo( );

		self::assertSame( 0, $Info[ 'AppID' ] ?? null );
		self::assertSame( 107410, $Info[ 'GameID' ] ?? null );
	}

	/**
	 * The Ship inserts a game mode, witness count and witness time triple between
	 * Secure and the version string.
	 */
	public function testTheShipTriple( ) : void
	{
		$this->Socket->Queue( self::InfoReply( AppID: 2400, Ship: chr( 1 ) . chr( 3 ) . chr( 45 ) ) );

		$Info = $this->SourceQuery->GetInfo( );

		self::assertSame( 2400, $Info[ 'AppID' ] ?? null );
		self::assertSame( 1, $Info[ 'GameMode' ] ?? null );
		self::assertSame( 3, $Info[ 'WitnessCount' ] ?? null );
		self::assertSame( 45, $Info[ 'WitnessTime' ] ?? null );
		self::assertSame( '1.0.0.0', $Info[ 'Version' ] ?? null );
	}

	/**
	 * Only AppID 2400 gets the triple; any other id reads those bytes as the start
	 * of the version string.
	 */
	public function testTheShipTripleIsNotAppliedToOtherAppIDs( ) : void
	{
		$this->Socket->Queue( self::InfoReply( AppID: 2401 ) );

		$Info = $this->SourceQuery->GetInfo( );

		self::assertArrayNotHasKey( 'GameMode', $Info );
		self::assertSame( '1.0.0.0', $Info[ 'Version' ] ?? null );
	}

	/**
	 * Bytes after the extra data block are described by no flag, so the reply
	 * cannot be trusted.
	 */
	public function testUndescribedTrailingBytesAreRejected( ) : void
	{
		$this->Socket->Queue( self::InfoReply(
			Extra: chr( self::FLAG_PORT ) . pack( 'v', self::GamePort ) . str_repeat( "\x2A", 16 )
		) );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::BUFFER_NOT_EMPTY );
		$this->expectExceptionMessage( '16 bytes remaining' );

		$this->SourceQuery->GetInfo( );
	}

	public function testUnknownReplyTypeIsRejected( ) : void
	{
		$this->Socket->Queue( Packets::A2SReply( 0x6E ) );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::PACKET_HEADER_MISMATCH );
		$this->expectExceptionMessage( '0x6e' );

		$this->SourceQuery->GetInfo( );
	}

	/**
	 * Once a challenge is known, later A2S_INFO requests carry it straight away
	 * instead of paying for another round trip.
	 */
	public function testChallengeFromAnEarlierQueryIsReusedByGetInfo( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_PLAYER, Packets::PlayersPayload( ) ) );

		self::assertSame( [], $this->SourceQuery->GetPlayers( ) );

		$this->Socket->Queue( self::InfoReply( ) );

		self::assertSame( self::HostName, $this->SourceQuery->GetInfo( )[ 'HostName' ] );
		self::assertSame(
			[ 'Header' => SourceQuery::A2S_INFO, 'String' => "Source Engine Query\0" . Packets::ChallengeBytes ],
			$this->Socket->LastWritten( )
		);
	}

	//
	// S2A_INFO_DETAILED (0x6D)
	//

	public function testDetailedReplyWithoutAModBlock( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_OLD, Packets::DetailedInfoPayload( ) ) );

		self::assertSame(
		[
			'Address'    => '127.0.0.1:27015',
			'HostName'   => 'GoldSource Server',
			'Map'        => 'crossfire',
			'ModDir'     => 'valve',
			'ModDesc'    => 'Half-Life',
			'Players'    => 5,
			'MaxPlayers' => 32,
			'Protocol'   => 47,
			'Dedicated'  => 'd',
			'Os'         => 'l',
			'Password'   => false,
			'IsMod'      => false,
			'Secure'     => true,
			'Bots'       => 1,
		], $this->SourceQuery->GetInfo( ) );
	}

	/**
	 * The mod block sits between the IsMod flag and Secure: the two urls, the null
	 * terminated engine version the mod was built against, the mod version, its
	 * download size, and two flag bytes.
	 */
	#[DataProvider( 'ModEngineVersionProvider' )]
	public function testDetailedReplyWithAModBlock( string $EngineVersion ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_OLD, Packets::DetailedInfoPayload( Mod:
			"https://example.invalid/\0" .
			"https://example.invalid/dl\0" .
			$EngineVersion . "\0" .
			pack( 'V', 4808 ) . pack( 'V', 184000000 ) .
			chr( 1 ) . chr( 0 )
		) ) );

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
	 * @return array<string, array{string}>
	 */
	public static function ModEngineVersionProvider( ) : array
	{
		return
		[
			'empty as most servers send it' => [ '' ],
			'filled in'                     => [ '1.1.2.0' ],
		];
	}

	/** The password byte of the detailed reply is 1 or nothing. */
	public function testDetailedReplyPasswordFlag( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_OLD, Packets::DetailedInfoPayload( Password: true ) ) );

		self::assertTrue( $this->SourceQuery->GetInfo( )[ 'Password' ] );
	}

	/**
	 * A banned address gets an A2A_PRINT reply saying so instead of data, for
	 * every query it sends. That is not a malformed packet.
	 */
	public function testBanReplyToInfoIsReportedAsBanned( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		$this->Socket->Queue( Packets::PrintReply( 'You have been banned from this server.' ) );

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BANNED );

		$this->SourceQuery->GetInfo( );
	}

	public function testBanReplyToPlayersIsReportedAsBanned( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		$this->Socket->Queue( Packets::PrintReply( 'You have been banned from this server.' ) );

		$this->expectException( AuthenticationException::class );
		$this->expectExceptionCode( AuthenticationException::BANNED );

		$this->SourceQuery->GetPlayers( );
	}

	// Known bugs.

	/**
	 * A repeated A2S_INFO inside the server's de-duplication window is answered
	 * with three datagrams: the detailed reply, an empty S2A_PLAYER, then
	 * S2A_INFO_SRC. All three belong to the info request, so neither the stale
	 * S2A_PLAYER nor the duplicate S2A_INFO_SRC may reach the next query.
	 */
	#[Group( 'known-bug' )]
	public function testThreeDatagramInfoReplyDoesNotSwallowTheChallenge( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;

		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_OLD, Packets::DetailedInfoPayload( 'Fake Server' ) ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_PLAYER, Packets::PlayersPayload( ) ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_SRC, Packets::InfoPayload( 'Fake Server', 70, 47 ) ) );

		self::assertSame( 'Fake Server', $this->SourceQuery->GetInfo( )[ 'HostName' ] );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_PLAYER, Packets::PlayersPayload( Packets::PlayerRecord( 0, 'Player One' ) ) ) );

		try
		{
			$Players = $this->SourceQuery->GetPlayers( );
		}
		catch( InvalidPacketException )
		{
			self::fail( 'The extra datagrams of the info reply poisoned GetPlayers.' );
		}

		self::assertSame( [ 'Player One' ], array_column( $Players, 'Name' ) );
		self::assertSame( Packets::ChallengeBytes, $this->Socket->LastWritten( )[ 'String' ] ?? null );
		self::assertSame( 0, $this->Socket->QueuedCount( ) );
	}

	//
	// Helpers
	//

	/**
	 * An S2A_INFO_SRC datagram, with the extra data block appended when there is one.
	 *
	 * @param int<0, 255> $Protocol
	 * @param int<0, 65535> $AppID
	 */
	private static function InfoReply( int $Protocol = 17, int $AppID = 440, string $Ship = '', string $Extra = '' ) : string
	{
		return Packets::A2SReply( SourceQuery::S2A_INFO_SRC,
			Packets::InfoPayload( self::HostName, $AppID, $Protocol, 2, $Ship ) . $Extra );
	}

	/**
	 * The extra data block for the given flags, in protocol order.
	 *
	 * @param int<0, 255> $Flags
	 */
	private static function ExtraData( int $Flags ) : string
	{
		$Data = chr( $Flags );

		if( $Flags & self::FLAG_PORT )
		{
			$Data .= pack( 'v', self::GamePort );
		}

		if( $Flags & self::FLAG_STEAMID )
		{
			$Data .= pack( 'VV', self::SteamIDLower, self::SteamIDUpper );
		}

		if( $Flags & self::FLAG_SPECTATOR )
		{
			$Data .= pack( 'v', self::SpecPort ) . self::SpecName . "\0";
		}

		if( $Flags & self::FLAG_TAGS )
		{
			$Data .= self::GameTags . "\0";
		}

		if( $Flags & self::FLAG_GAMEID )
		{
			$Data .= pack( 'VV', self::GameID, 0 );
		}

		return $Data;
	}

	/**
	 * @return array<string, bool|int|string>
	 */
	private static function ExpectedExtraData( int $Flags ) : array
	{
		$Expected = [ 'ExtraDataFlags' => $Flags ];

		if( $Flags & self::FLAG_PORT )
		{
			$Expected[ 'GamePort' ] = self::GamePort;
		}

		if( $Flags & self::FLAG_STEAMID )
		{
			$Expected[ 'SteamID' ] = self::SteamID;
		}

		if( $Flags & self::FLAG_SPECTATOR )
		{
			$Expected[ 'SpecPort' ] = self::SpecPort;
			$Expected[ 'SpecName' ] = self::SpecName;
		}

		if( $Flags & self::FLAG_TAGS )
		{
			$Expected[ 'GameTags' ] = self::GameTags;
		}

		if( $Flags & self::FLAG_GAMEID )
		{
			$Expected[ 'GameID' ] = self::GameID;
		}

		return $Expected;
	}
}
