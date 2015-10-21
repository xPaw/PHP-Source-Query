<?php
	require __DIR__ . '/../SourceQuery/bootstrap.php';
	
	use xPaw\SourceQuery\BaseSocket;
	use xPaw\SourceQuery\Exception\InvalidPacketException;
	use xPaw\SourceQuery\SourceQuery;
	
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
			
			return $Buffer;
		}
	}
	
	class SourceQueryTests extends PHPUnit_Framework_TestCase
	{
		private $Socket;
		private $SourceQuery;
		
		public function setUpBeforeClass()
		{
			$this->Socket = new TestableSocket();
			$this->SourceQuery = new SourceQuery( $this->Socket );
			$this->SourceQuery->Connect( 1, 2 );
		}
		
		/**
		 * @dataProvider InfoProvider
		 */
		public function testGetInfo( $RawInput, $ExpectedOutput )
		{
			$this->Socket->NextOutput = $RawInput;
			
			$RealOutput = $this->SourceQuery->GetInfo();
			
			$this->assertEquals( $ExpectedOutput, $RealOutput );
		}
		
		public function InfoProvider()
		{
			// read from Tests/Info/ folder
		}
	}
