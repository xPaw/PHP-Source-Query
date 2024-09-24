<?php
declare(strict_types=1);

	use xPaw\SourceQuery\BaseSocket;
	use xPaw\SourceQuery\SourceQuery;
	use xPaw\SourceQuery\Buffer;

	class TestableSocket extends BaseSocket
	{
		/** @var \SplQueue<string> */
		private \SplQueue $PacketQueue;

		public function __construct( )
		{
			$this->PacketQueue = new \SplQueue();
			$this->PacketQueue->setIteratorMode( \SplDoublyLinkedList::IT_MODE_DELETE );

		}

		public function Queue( string $Data ) : void
		{
			$this->PacketQueue->push( $Data );
		}

		public function Close( ) : void
		{
			//
		}

		public function Open( string $Address, int $Port, int $Timeout, int $Engine ) : void
		{
			$this->Timeout = $Timeout;
			$this->Engine  = $Engine;
			$this->Port    = $Port;
			$this->Address = $Address;
		}

		public function Write( int $Header, string $String = '' ) : bool
		{
			return true;
		}

		public function Read( ) : Buffer
		{
			$Buffer = new Buffer( );
			$Buffer->Set( $this->PacketQueue->shift() );

			$this->ReadInternal( $Buffer, [ $this, 'Sherlock' ] );

			return $Buffer;
		}

		public function Sherlock( Buffer $Buffer ) : bool
		{
			if( $this->PacketQueue->isEmpty() )
			{
				return false;
			}

			$Buffer->Set( $this->PacketQueue->shift() );

			return $Buffer->ReadInt32( ) === -2;
		}
	}

	class Tests extends \PHPUnit\Framework\TestCase
	{
		private TestableSocket $Socket;
		private SourceQuery $SourceQuery;

		public function setUp() : void
		{
			$this->Socket = new TestableSocket();
			$this->SourceQuery = new SourceQuery( $this->Socket );
			$this->SourceQuery->Connect( '', 2 );
		}

		public function tearDown() : void
		{
			$this->SourceQuery->Disconnect();

			unset( $this->Socket, $this->SourceQuery );
		}

		public function testInvalidTimeout() : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\InvalidArgumentException::class );
			$SourceQuery = new SourceQuery( );
			$SourceQuery->Connect( '', 2, -1 );
		}

		public function testNotConnectedGetInfo() : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\SocketException::class );
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->GetInfo();
		}

		public function testNotConnectedPing() : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\SocketException::class );
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->Ping();
		}

		public function testNotConnectedGetPlayers() : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\SocketException::class );
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->GetPlayers();
		}

		/**
		 * @expectedException xPaw\SourceQuery\Exception\SocketException
		 */
		public function testNotConnectedGetRules() : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\SocketException::class );
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->GetRules();
		}

		public function testNotConnectedSetRconPassword() : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\SocketException::class );
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->SetRconPassword('a');
		}

		public function testNotConnectedRcon() : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\SocketException::class );
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->Rcon('a');
		}

		public function testRconWithoutPassword() : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\SocketException::class );
			$this->SourceQuery->Rcon('a');
		}

		/**
		 * @dataProvider InfoProvider
		 */
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

		public static function InfoProvider() : array
		{
			$DataProvider = [];

			$Files = glob( __DIR__ . '/Info/*.raw', GLOB_ERR );

			if( $Files === false )
			{
				throw new Exception();
			}

			foreach( $Files as $File )
			{
				$DataProvider[] =
				[
					hex2bin( trim( (string)file_get_contents( $File ) ) ),
					json_decode( (string)file_get_contents( str_replace( '.raw', '.json', $File ) ), true )
				];
			}

			return $DataProvider;
		}

		/**
		 * @dataProvider BadPacketProvider
		 */
		public function testBadGetInfo( string $Data ) : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\InvalidPacketException::class );
			$this->Socket->Queue( $Data );

			$this->SourceQuery->GetInfo();
		}

		/**
		 * @dataProvider BadPacketProvider
		 */
		public function testBadGetChallengeViaPlayers( string $Data ) : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\InvalidPacketException::class );
			$this->Socket->Queue( $Data );

			$this->SourceQuery->GetPlayers();
		}

		/**
		 * @dataProvider BadPacketProvider
		 */
		public function testBadGetPlayersAfterCorrectChallenge( string $Data ) : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\InvalidPacketException::class );
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11" );
			$this->Socket->Queue( $Data );

			$this->SourceQuery->GetPlayers();
		}

		/**
		 * @dataProvider BadPacketProvider
		 */
		public function testBadGetRulesAfterCorrectChallenge( string $Data ) : void
		{
			$this->expectException( xPaw\SourceQuery\Exception\InvalidPacketException::class );
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11" );
			$this->Socket->Queue( $Data );

			$this->SourceQuery->GetRules();
		}

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
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11" );
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x45\x01\x00ayy\x00lmao\x00" );
			self::assertEquals( [ 'ayy' => 'lmao' ], $this->SourceQuery->GetRules() );

			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x45\x01\x00wow\x00much\x00" );
			self::assertEquals( [ 'wow' => 'much' ], $this->SourceQuery->GetRules() );
		}

		/**
		 * @dataProvider RulesProvider
		 * @param array<string> $RawInput
		 */
		public function testGetRules( array $RawInput, array $ExpectedOutput ) : void
		{
			$this->Socket->Queue( (string)hex2bin( "ffffffff4104fce20e" ) ); // Challenge

			foreach( $RawInput as $Packet )
			{
				$this->Socket->Queue( (string)hex2bin( $Packet ) );
			}

			$RealOutput = $this->SourceQuery->GetRules();

			self::assertEquals( $ExpectedOutput, $RealOutput );
		}

		public static function RulesProvider() : array
		{
			$DataProvider = [];

			$Files = glob( __DIR__ . '/Rules/*.raw', GLOB_ERR );

			if( $Files === false )
			{
				throw new Exception();
			}

			foreach( $Files as $File )
			{
				$DataProvider[] =
				[
					file( $File, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES ),
					json_decode( (string)file_get_contents( str_replace( '.raw', '.json', $File ) ), true )
				];
			}

			return $DataProvider;
		}

		/**
		 * @dataProvider PlayersProvider
		 * @param array<string> $RawInput
		 */
		public function testGetPlayers( array $RawInput, array $ExpectedOutput ) : void
		{
			$this->Socket->Queue( (string)hex2bin( "ffffffff4104fce20e" ) ); // Challenge

			foreach( $RawInput as $Packet )
			{
				$this->Socket->Queue( (string)hex2bin( $Packet ) );
			}

			$RealOutput = $this->SourceQuery->GetPlayers();

			self::assertEquals( $ExpectedOutput, $RealOutput );
		}

		public static function PlayersProvider() : array
		{
			$DataProvider = [];

			$Files = glob( __DIR__ . '/Players/*.raw', GLOB_ERR );

			if( $Files === false )
			{
				throw new Exception();
			}

			foreach( $Files as $File )
			{
				$DataProvider[] =
				[
					file( $File, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES ),
					json_decode( (string)file_get_contents( str_replace( '.raw', '.json', $File ) ), true )
				];
			}

			return $DataProvider;
		}

		public function testPing() : void
		{
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x6A\x00");
			self::assertTrue( $this->SourceQuery->Ping() );

			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\xEE");
			self::assertFalse( $this->SourceQuery->Ping() );
		}
	}
