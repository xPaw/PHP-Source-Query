<?php
	use xPaw\SourceQuery\BaseSocket;
	use xPaw\SourceQuery\Exception\InvalidPacketException;
	use xPaw\SourceQuery\SourceQuery;
	use xPaw\SourceQuery\Buffer;
	
	class TestableSocket extends BaseSocket
	{
		public $NextOutput = '';
		
		public function Close( )
		{
			//
		}
		
		public function Open( $Ip, $Port, $Timeout, $Engine )
		{
			//
		}
		
		public function Write( $Header, $String = '' )
		{
			//
		}
		
		public function Read( $Length = 1400 )
		{
			if( strlen( $this->NextOutput ) === 0 )
			{
				throw new InvalidPacketException( 'Buffer is empty', InvalidPacketException::BUFFER_EMPTY );
			}
			
			$Buffer = new Buffer( );
			$Buffer->Set( $this->NextOutput );
			
			$this->NextOutput = '';
			
			$this->ReadInternal( $Buffer, $this->Sherlock );
			
			return $Buffer;
		}
		
		private function Sherlock( $Buffer, $Length )
		{
			return false;
		}
	}
	
	class SourceQueryTests extends PHPUnit_Framework_TestCase
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
		}
		
		/**
		 * @dataProvider InfoProvider
		 */
		public function testGetInfo( $RawInput, $ExpectedOutput )
		{
			$this->Socket->NextOutput = $RawInput;
			
			$RealOutput = $this->SourceQuery->GetInfo();
			
			foreach( $ExpectedOutput as $Key => $ExpectedValue )
			{
				$this->assertEquals( $ExpectedValue, $RealOutput[ $Key ], $Key );
			}
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
	     * @dataProvider BadInfoProvider
	     */
		public function testBadGetInfo( $Data )
		{
			$this->Socket->NextOutput = $Data;
			
			$this->SourceQuery->GetInfo();
		}
		
		public function BadInfoProvider( )
		{
			return
			[
				[ "" ],
				[ "\xff\xff\xff\xff" ], // No type
				[ "\xff\xff\xff\xff\x49" ], // Correct type, but no data after
				[ "\xff\xff\xff\xff\x6D" ], // Old info packet, but tests are done for source
				[ "\xff\xff\xff\xff\x11" ], // Wrong type
				[ "\xff" ], // Should be 4 bytes, but it's 1
			];
		}
	}
