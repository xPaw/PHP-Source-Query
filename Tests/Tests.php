<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use xPaw\SourceQuery\Exception\InvalidArgumentException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\Packets;
use xPaw\SourceQuery\Tests\Support\TestableSocketTestCase;

/**
 * The captured replies of real servers in Info/, Players/ and Rules/, plus the
 * guards that fire when nothing is connected.
 */
class Tests extends TestableSocketTestCase
{
	public function testInvalidTimeout() : void
	{
		$this->expectException( InvalidArgumentException::class );

		$this->SourceQuery->Connect( '', 2, -1 );
	}

	public function testNotConnectedGetInfo() : void
	{
		$this->expectException( SocketException::class );
		$this->SourceQuery->Disconnect();
		$this->SourceQuery->GetInfo();
	}

	public function testNotConnectedPing() : void
	{
		$this->expectException( SocketException::class );
		$this->SourceQuery->Disconnect();
		$this->SourceQuery->Ping();
	}

	public function testNotConnectedGetPlayers() : void
	{
		$this->expectException( SocketException::class );
		$this->SourceQuery->Disconnect();
		$this->SourceQuery->GetPlayers();
	}

	public function testNotConnectedGetRules() : void
	{
		$this->expectException( SocketException::class );
		$this->SourceQuery->Disconnect();
		$this->SourceQuery->GetRules();
	}

	public function testNotConnectedSetRconPassword() : void
	{
		$this->expectException( SocketException::class );
		$this->SourceQuery->Disconnect();
		$this->SourceQuery->SetRconPassword('a');
	}

	public function testNotConnectedRcon() : void
	{
		$this->expectException( SocketException::class );
		$this->SourceQuery->Disconnect();
		$this->SourceQuery->Rcon('a');
	}

	public function testRconWithoutPassword() : void
	{
		$this->expectException( SocketException::class );
		$this->SourceQuery->Rcon('a');
	}

	/** @param array<mixed> $ExpectedOutput */
	#[DataProvider( 'InfoProvider' )]
	public function testGetInfo( string $RawInput, array $ExpectedOutput ) : void
	{
		if( isset( $ExpectedOutput[ 'IsMod' ] ) )
		{
			$this->Socket->Engine = SourceQuery::GOLDSOURCE;
		}

		$this->Socket->Queue( $RawInput );

		$RealOutput = $this->SourceQuery->GetInfo();

		self::assertEquals( $ExpectedOutput, $RealOutput );
	}

	/** @return array<int, array{string, array<mixed>}> */
	public static function InfoProvider() : array
	{
		$DataProvider = [];

		foreach( self::Fixtures( 'Info' ) as $File )
		{
			$Raw = hex2bin( trim( self::Contents( $File ) ) );

			if( $Raw === false )
			{
				throw new Exception( 'Invalid hex in ' . $File );
			}

			$DataProvider[] = [ $Raw, self::ExpectedFor( $File ) ];
		}

		return $DataProvider;
	}

	#[DataProvider( 'BadPacketProvider' )]
	public function testBadGetInfo( string $Data ) : void
	{
		$this->expectException( InvalidPacketException::class );
		$this->Socket->Queue( $Data );

		$this->SourceQuery->GetInfo();
	}

	#[DataProvider( 'BadPacketProvider' )]
	public function testBadGetChallengeViaPlayers( string $Data ) : void
	{
		$this->expectException( InvalidPacketException::class );
		$this->Socket->Queue( $Data );

		$this->SourceQuery->GetPlayers();
	}

	#[DataProvider( 'BadPacketProvider' )]
	public function testBadGetPlayersAfterCorrectChallenge( string $Data ) : void
	{
		$this->expectException( InvalidPacketException::class );
		$this->Socket->Queue( Packets::Challenge() );
		$this->Socket->Queue( $Data );

		$this->SourceQuery->GetPlayers();
	}

	#[DataProvider( 'BadPacketProvider' )]
	public function testBadGetRulesAfterCorrectChallenge( string $Data ) : void
	{
		$this->expectException( InvalidPacketException::class );
		$this->Socket->Queue( Packets::Challenge() );
		$this->Socket->Queue( $Data );

		$this->SourceQuery->GetRules();
	}

	/** @return array<int, array{string}> */
	public static function BadPacketProvider( ) : array
	{
		return
		[
			[ "" ],
			[ "\xff\xff\xff\xff" ], // No type
			[ "\xff\xff\xff\xff\x49" ], // Correct type, but no data after
			[ "\xff\xff\xff\xff\x6D" ], // Old info packet, but tests are done for source
			[ "\xff\xff\xff\xff\x11" ], // Wrong type
			[ "\x11\x11\x11\x11" ], // Wrong header
			[ "\xff" ], // Should be 4 bytes, but it's 1
		];
	}

	public function testGetChallengeTwice( ) : void
	{
		$this->Socket->Queue( Packets::Challenge() );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_RULES, Packets::RulesPayload( [ 'ayy' => 'lmao' ] ) ) );
		self::assertEquals( [ 'ayy' => 'lmao' ], $this->SourceQuery->GetRules() );

		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_RULES, Packets::RulesPayload( [ 'wow' => 'much' ] ) ) );
		self::assertEquals( [ 'wow' => 'much' ], $this->SourceQuery->GetRules() );
	}

	/**
	 * @param array<string> $RawInput
	 * @param array<mixed> $ExpectedOutput
	 */
	#[DataProvider( 'RulesProvider' )]
	public function testGetRules( array $RawInput, array $ExpectedOutput ) : void
	{
		$this->Socket->Queue( Packets::Challenge( "\x04\xfc\xe2\x0e" ) );

		foreach( $RawInput as $Packet )
		{
			$this->Socket->Queue( (string)hex2bin( $Packet ) );
		}

		$RealOutput = $this->SourceQuery->GetRules();

		self::assertEquals( $ExpectedOutput, $RealOutput );
	}

	/** @return array<int, array{array<string>, array<mixed>}> */
	public static function RulesProvider() : array
	{
		return self::PacketListProvider( 'Rules' );
	}

	/**
	 * @param array<string> $RawInput
	 * @param array<mixed> $ExpectedOutput
	 */
	#[DataProvider( 'PlayersProvider' )]
	public function testGetPlayers( array $RawInput, array $ExpectedOutput ) : void
	{
		$this->Socket->Queue( Packets::Challenge( "\x04\xfc\xe2\x0e" ) );

		foreach( $RawInput as $Packet )
		{
			$this->Socket->Queue( (string)hex2bin( $Packet ) );
		}

		$RealOutput = $this->SourceQuery->GetPlayers();

		self::assertEquals( $ExpectedOutput, $RealOutput );
	}

	/** @return array<int, array{array<string>, array<mixed>}> */
	public static function PlayersProvider() : array
	{
		return self::PacketListProvider( 'Players' );
	}

	/**
	 * Every fixture in the directory as hex encoded packets plus the result
	 * they must decode to.
	 *
	 * @return array<int, array{array<string>, array<mixed>}>
	 */
	private static function PacketListProvider( string $Directory ) : array
	{
		$DataProvider = [];

		foreach( self::Fixtures( $Directory ) as $File )
		{
			$Packets = file( $File, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES );

			if( $Packets === false )
			{
				throw new Exception( 'Could not read ' . $File );
			}

			$DataProvider[] = [ $Packets, self::ExpectedFor( $File ) ];
		}

		return $DataProvider;
	}

	/**
	 * The .raw fixtures of one directory.
	 *
	 * @return array<int, string>
	 */
	private static function Fixtures( string $Directory ) : array
	{
		$Files = glob( __DIR__ . '/' . $Directory . '/*.raw', GLOB_ERR );

		if( $Files === false )
		{
			throw new Exception( 'Could not list the ' . $Directory . ' fixtures.' );
		}

		return $Files;
	}

	/**
	 * The .json result that belongs to a .raw fixture.
	 *
	 * @return array<mixed>
	 */
	private static function ExpectedFor( string $File ) : array
	{
		$Json    = str_replace( '.raw', '.json', $File );
		$Decoded = json_decode( self::Contents( $Json ), true );

		if( !is_array( $Decoded ) )
		{
			throw new Exception( 'Could not decode ' . $Json );
		}

		return $Decoded;
	}

	private static function Contents( string $File ) : string
	{
		$Contents = file_get_contents( $File );

		if( $Contents === false )
		{
			throw new Exception( 'Could not read ' . $File );
		}

		return $Contents;
	}
}
