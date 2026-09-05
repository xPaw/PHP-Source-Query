<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * Field by field coverage of GetInfo( ) for both reply shapes: S2A_INFO_SRC
 * (0x49) with every extra data flag combination, and the GoldSource
 * S2A_INFO_DETAILED (0x6D) with and without a mod block.
 */
class InfoParsingTest extends \PHPUnit\Framework\TestCase
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

	private TestableSocket $Socket;
	private SourceQuery $SourceQuery;

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

	//
	// S2A_INFO_SRC (0x49)
	//

	public function testFixedFieldsAreDecodedWithTheDocumentedTypes( ) : void
	{
		$this->Socket->Queue( self::InfoReply( self::InfoBody( ) ) );

		$Info = $this->SourceQuery->GetInfo( );

		self::assertSame( 17, $Info[ 'Protocol' ] );
		self::assertSame( 'Constructed Server', $Info[ 'HostName' ] );
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
		$this->Socket->Queue( self::InfoReply( self::InfoBody( AppID: 40000 ) ) );

		self::assertSame( 40000, $this->SourceQuery->GetInfo( )[ 'AppID' ] ?? null );
	}

	/** Protocol 7 era servers stop after Version and send no extra data flags byte. */
	public function testReplyWithoutAnExtraDataFlagsByte( ) : void
	{
		$this->Socket->Queue( self::InfoReply( self::InfoBody( Protocol: 7 ) ) );

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
		$this->Socket->Queue( self::InfoReply( self::InfoBody( ) . self::ExtraData( $Flags ) ) );

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

	/** The steamid is two little endian 32 bit words, the lower one first. */
	public function testSteamIDIsComposedFromBothWords( ) : void
	{
		$this->Socket->Queue( self::InfoReply( self::InfoBody( ) . self::ExtraData( self::FLAG_STEAMID ) ) );

		self::assertSame( self::SteamID, $this->SourceQuery->GetInfo( )[ 'SteamID' ] ?? null );
	}

	/**
	 * The spectator port is followed by the spectator server name, and an empty
	 * name is just the terminator.
	 */
	public function testEmptySpectatorNameIsAnEmptyString( ) : void
	{
		$this->Socket->Queue( self::InfoReply(
			self::InfoBody( ) . chr( self::FLAG_SPECTATOR ) . pack( 'v', self::SpecPort ) . "\0"
		) );

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
		$this->Socket->Queue( self::InfoReply(
			self::InfoBody( AppID: 0 ) . chr( self::FLAG_GAMEID ) . pack( 'VV', 107410, 0 )
		) );

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
		$this->Socket->Queue( self::InfoReply(
			self::InfoBody( AppID: 2400, Ship: chr( 1 ) . chr( 3 ) . chr( 45 ) )
		) );

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
		$this->Socket->Queue( self::InfoReply( self::InfoBody( AppID: 2401 ) ) );

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
			self::InfoBody( ) . chr( self::FLAG_PORT ) . pack( 'v', self::GamePort ) . str_repeat( "\x2A", 16 )
		) );

		try
		{
			$this->SourceQuery->GetInfo( );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::BUFFER_NOT_EMPTY, $Exception->getCode( ) );
			self::assertStringContainsString( '16 bytes remaining', $Exception->getMessage( ) );
		}
	}

	public function testUnknownReplyTypeIsRejected( ) : void
	{
		$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x6E" );

		try
		{
			$this->SourceQuery->GetInfo( );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::PACKET_HEADER_MISMATCH, $Exception->getCode( ) );
			self::assertStringContainsString( '0x6e', $Exception->getMessage( ) );
		}
	}

	/**
	 * Once a challenge is known, later A2S_INFO requests carry it straight away
	 * instead of paying for another round trip.
	 */
	public function testChallengeFromAnEarlierQueryIsReusedByGetInfo( ) : void
	{
		$this->Socket->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2C_CHALLENGE ) . "\x11\x22\x33\x44" );
		$this->Socket->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_PLAYER ) . chr( 0 ) );

		self::assertSame( [], $this->SourceQuery->GetPlayers( ) );

		$this->Socket->Queue( self::InfoReply( self::InfoBody( ) ) );

		self::assertSame( 'Constructed Server', $this->SourceQuery->GetInfo( )[ 'HostName' ] );
		self::assertSame(
			[ 'Header' => SourceQuery::A2S_INFO, 'String' => "Source Engine Query\0" . "\x11\x22\x33\x44" ],
			$this->Socket->LastWritten( )
		);
	}

	//
	// S2A_INFO_DETAILED (0x6D)
	//

	public function testDetailedReplyWithoutAModBlock( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		$this->Socket->Queue( self::DetailedReply( self::DetailedBody( ) ) );

		$Info = $this->SourceQuery->GetInfo( );

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
		], $Info );
	}

	/**
	 * The mod block sits between the IsMod flag and Secure: two strings, the mod
	 * version, its download size, and two flag bytes.
	 */
	public function testDetailedReplyWithAModBlock( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		$this->Socket->Queue( self::DetailedReply( self::DetailedBody( Mod:
			"https://example.invalid/\0" .
			"https://example.invalid/dl\0" .
			"\0" .
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

	/** The password byte of the detailed reply is 1 or nothing. */
	public function testDetailedReplyPasswordFlag( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		$this->Socket->Queue( self::DetailedReply( self::DetailedBody( Password: true ) ) );

		self::assertTrue( $this->SourceQuery->GetInfo( )[ 'Password' ] );
	}

	//
	// Helpers
	//

	private static function InfoReply( string $Body ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_INFO_SRC ) . $Body;
	}

	private static function DetailedReply( string $Body ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_INFO_OLD ) . $Body;
	}

	/**
	 * An S2A_INFO_SRC body up to and including the version string.
	 *
	 * @param int<0, 255> $Protocol
	 * @param int<0, 65535> $AppID
	 */
	private static function InfoBody( int $Protocol = 17, int $AppID = 440, string $Ship = '' ) : string
	{
		return chr( $Protocol )
			. "Constructed Server\0"
			. "de_dust2\0"
			. "cstrike\0"
			. "Counter-Strike\0"
			. pack( 'v', $AppID )
			. chr( 4 )      // Players
			. chr( 32 )     // MaxPlayers
			. chr( 2 )      // Bots
			. 'd'           // Dedicated
			. 'l'           // Os
			. chr( 0 )      // Password
			. chr( 1 )      // Secure
			. $Ship
			. "1.0.0.0\0";
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

	/** An S2A_INFO_DETAILED body. $Mod follows the IsMod flag when it is set. */
	private static function DetailedBody( bool $Password = false, string $Mod = '' ) : string
	{
		return "127.0.0.1:27015\0"
			. "GoldSource Server\0"
			. "crossfire\0"
			. "valve\0"
			. "Half-Life\0"
			. chr( 5 )              // Players
			. chr( 32 )             // MaxPlayers
			. chr( 47 )             // Protocol
			. 'd'                   // Dedicated
			. 'l'                   // Os
			. chr( $Password ? 1 : 0 )
			. chr( $Mod === '' ? 0 : 1 )
			. $Mod
			. chr( 1 )              // Secure
			. chr( 1 );             // Bots
	}
}
