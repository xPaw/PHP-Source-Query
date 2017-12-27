<?php
	use PHPUnit\Framework\TestCase;
	use xPaw\SourceQuery\BaseSocket;
	use xPaw\SourceQuery\Exception\InvalidPacketException;
	use xPaw\SourceQuery\SourceQuery;
	use xPaw\SourceQuery\Buffer;
	
	class TestableSocket extends BaseSocket
	{
		private $PacketQueue;
		
		public function __construct( )
		{
			$this->PacketQueue = new \SplQueue();
			$this->PacketQueue->setIteratorMode( \SplDoublyLinkedList::IT_MODE_DELETE );
			
		}
		
		public function Queue( $Data )
		{
			$this->PacketQueue->push( $Data );
		}
		
		public function Close( )
		{
			//
		}
		
		public function Open( $Address, $Port, $Timeout, $Engine )
		{
			$this->Timeout = $Timeout;
			$this->Engine  = $Engine;
			$this->Port    = $Port;
			$this->Address = $Address;
		}
		
		public function Write( $Header, $String = '' )
		{
			//
		}
		
		public function Read( $Length = 1400 )
		{
			$Buffer = new Buffer( );
			$Buffer->Set( $this->PacketQueue->shift() );
			
			$this->ReadInternal( $Buffer, $Length, [ $this, 'Sherlock' ] );
			
			return $Buffer;
		}
		
		public function Sherlock( $Buffer, $Length )
		{
			if( $this->PacketQueue->isEmpty() )
			{
				return false;
			}
			
			$Buffer->Set( $this->PacketQueue->shift() );
			
			return $Buffer->GetLong( ) === -2;
		}
	}
	
	class SourceQueryTests extends TestCase
	{
		private $Socket;
		private $SourceQuery;
		
		public function setUp()
		{
			$this->Socket = new TestableSocket();
			$this->SourceQuery = new SourceQuery( $this->Socket );
			$this->SourceQuery->Connect( 1, 2 );
		}
		
		public function tearDown()
		{
			$this->SourceQuery->Disconnect();
			
			unset( $this->Socket, $this->SourceQuery );
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\InvalidArgumentException
		 */
		public function testInvalidTimeout()
		{
			$SourceQuery = new SourceQuery( );
			$SourceQuery->Connect( 1, 2, -1 );
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\SocketException
		 */
		public function testNotConnectedGetInfo()
		{
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->GetInfo();
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\SocketException
		 */
		public function testNotConnectedPing()
		{
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->Ping();
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\SocketException
		 */
		public function testNotConnectedGetPlayers()
		{
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->GetPlayers();
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\SocketException
		 */
		public function testNotConnectedGetRules()
		{
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->GetRules();
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\SocketException
		 */
		public function testNotConnectedSetRconPassword()
		{
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->SetRconPassword('a');
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\SocketException
		 */
		public function testNotConnectedRcon()
		{
			$this->SourceQuery->Disconnect();
			$this->SourceQuery->Rcon('a');
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\SocketException
		 */
		public function testRconWithoutPassword()
		{
			$this->SourceQuery->Rcon('a');
		}
		
		/**
		 * @dataProvider InfoProvider
		 */
		public function testGetInfo( $RawInput, $ExpectedOutput )
		{
			if( isset( $ExpectedOutput[ 'IsMod' ] ) )
			{
				$this->Socket->Engine = SourceQuery::GOLDSOURCE;
			}
			
			$this->Socket->Queue( $RawInput );
			
			$RealOutput = $this->SourceQuery->GetInfo();
			
			$this->assertEquals( $ExpectedOutput, $RealOutput );
		}
		
		public function InfoProvider()
		{
			$DataProvider = [];
			
			$Files = glob( __DIR__ . '/Info/*.raw', GLOB_ERR );
			
			foreach( $Files as $File )
			{
				$DataProvider[] =
				[
					hex2bin( trim( file_get_contents( $File ) ) ),
					json_decode( file_get_contents( str_replace( '.raw', '.json', $File ) ), true )
				];
			}
			
			return $DataProvider;
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\InvalidPacketException
		 * @dataProvider BadPacketProvider
		 */
		public function testBadGetInfo( $Data )
		{
			$this->Socket->Queue( $Data );
			
			$this->SourceQuery->GetInfo();
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\InvalidPacketException
		 * @dataProvider BadPacketProvider
		 */
		public function testBadGetChallengeViaPlayers( $Data )
		{
			$this->Socket->Queue( $Data );
			
			$this->SourceQuery->GetPlayers();
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\InvalidPacketException
		 * @dataProvider BadPacketProvider
		 */
		public function testBadGetPlayersAfterCorrectChallenge( $Data )
		{
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11" );
			$this->Socket->Queue( $Data );
			
			$this->SourceQuery->GetPlayers();
		}
		
		/**
		 * @expectedException xPaw\SourceQuery\Exception\InvalidPacketException
		 * @dataProvider BadPacketProvider
		 */
		public function testBadGetRulesAfterCorrectChallenge( $Data )
		{
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11" );
			$this->Socket->Queue( $Data );
			
			$this->SourceQuery->GetRules();
		}
		
		public function BadPacketProvider( )
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
		
		public function testGetChallengeTwice( )
		{
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11" );
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x45\x01\x00ayy\x00lmao\x00" );
			$this->assertEquals( [ 'ayy' => 'lmao' ], $this->SourceQuery->GetRules() );
			
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x45\x01\x00wow\x00much\x00" );
			$this->assertEquals( [ 'wow' => 'much' ], $this->SourceQuery->GetRules() );
		}
		
		/**
		 * @dataProvider RulesProvider
		 */
		public function testGetRules( $RawInput, $ExpectedOutput )
		{
			$this->Socket->Queue( hex2bin( "ffffffff4104fce20e" ) ); // Challenge
			
			foreach( $RawInput as $Packet )
			{
				$this->Socket->Queue( hex2bin( $Packet ) );
			}
			
			$RealOutput = $this->SourceQuery->GetRules();
			
			$this->assertEquals( $ExpectedOutput, $RealOutput );
		}
		
		public function RulesProvider()
		{
			$DataProvider = [];
			
			$Files = glob( __DIR__ . '/Rules/*.raw', GLOB_ERR );
			
			foreach( $Files as $File )
			{
				$DataProvider[] =
				[
					file( $File, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES ),
					json_decode( file_get_contents( str_replace( '.raw', '.json', $File ) ), true )
				];
			}
			
			return $DataProvider;
		}
		
		/**
		 * @dataProvider PlayersProvider
		 */
		public function testGetPlayers( $RawInput, $ExpectedOutput )
		{
			$this->Socket->Queue( hex2bin( "ffffffff4104fce20e" ) ); // Challenge
			
			foreach( $RawInput as $Packet )
			{
				$this->Socket->Queue( hex2bin( $Packet ) );
			}
			
			$RealOutput = $this->SourceQuery->GetPlayers();
			
			$this->assertEquals( $ExpectedOutput, $RealOutput );
		}
		
		public function PlayersProvider()
		{
			$DataProvider = [];
			
			$Files = glob( __DIR__ . '/Players/*.raw', GLOB_ERR );
			
			foreach( $Files as $File )
			{
				$DataProvider[] =
				[
					file( $File, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES ),
					json_decode( file_get_contents( str_replace( '.raw', '.json', $File ) ), true )
				];
			}
			
			return $DataProvider;
		}
		
		public function testPing()
		{
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x6A\x00");
			$this->assertTrue( $this->SourceQuery->Ping() );
			
			$this->Socket->Queue( "\xFF\xFF\xFF\xFF\xEE");
			$this->assertFalse( $this->SourceQuery->Ping() );
		}
	}
