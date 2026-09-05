<?php
declare(strict_types=1);

use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\Socket;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\FakeUdpServer;

/**
 * The real Socket on its own: opening, closing, the guards that fire once the
 * socket is gone, and the Sherlock callback that decides whether another split
 * fragment follows.
 */
class UdpSocketEdgeTest extends \PHPUnit\Framework\TestCase
{
	private ?FakeUdpServer $Server = null;
	private ?Socket $Socket = null;

	public function tearDown( ) : void
	{
		$this->Socket?->Close( );
		$this->Server?->Close( );

		$this->Socket = null;
		$this->Server = null;
	}

	public function testOpenStoresTheConnectionDetails( ) : void
	{
		$Socket = $this->Open( );

		self::assertSame( $this->Server( )->Host( ), $Socket->Address );
		self::assertSame( $this->Server( )->Port( ), $Socket->Port );
		self::assertSame( 1, $Socket->Timeout );
		self::assertSame( SourceQuery::GOLDSOURCE, $Socket->Engine );
		self::assertIsResource( $Socket->Socket );
	}

	public function testOpenOnAnUnresolvableAddress( ) : void
	{
		$Socket = new Socket( );
		$this->Socket = $Socket;

		try
		{
			$Socket->Open( 'no-such-host.invalid', 27015, 1, SourceQuery::SOURCE );

			self::fail( 'Expected SocketException' );
		}
		catch( SocketException $Exception )
		{
			self::assertSame( SocketException::COULD_NOT_CREATE_SOCKET, $Exception->getCode( ) );
			self::assertStringStartsWith( 'Could not create socket:', $Exception->getMessage( ) );
		}

		self::assertNull( $Socket->Socket );
	}

	public function testWriteSendsTheDatagramWithTheQueryHeader( ) : void
	{
		$Socket = $this->Open( );

		self::assertTrue( $Socket->Write( SourceQuery::A2S_INFO, "Source Engine Query\0" ) );

		$Requests = $this->Server( )->WaitForRequests( 1 );

		self::assertSame( [ "\xFF\xFF\xFF\xFF\x54Source Engine Query\x00" ], $Requests );
	}

	public function testWriteWithoutAPayload( ) : void
	{
		$Socket = $this->Open( );

		self::assertTrue( $Socket->Write( SourceQuery::A2A_PING ) );

		self::assertSame( [ "\xFF\xFF\xFF\xFF\x69" ], $this->Server( )->WaitForRequests( 1 ) );
	}

	//
	// Close
	//

	public function testCloseIsIdempotent( ) : void
	{
		$Socket = $this->Open( );

		$Socket->Close( );
		$Socket->Close( );

		self::assertNull( $Socket->Socket );
	}

	public function testWriteAfterClose( ) : void
	{
		$Socket = $this->Open( );
		$Socket->Close( );

		$this->ExpectNotConnected( static fn( ) : bool => $Socket->Write( SourceQuery::A2A_PING ) );
	}

	public function testReadAfterClose( ) : void
	{
		$Socket = $this->Open( );
		$Socket->Close( );

		$this->ExpectNotConnected( static fn( ) : Buffer => $Socket->Read( ) );
	}

	public function testSherlockAfterClose( ) : void
	{
		$Socket = $this->Open( );
		$Socket->Close( );

		$Buffer = new Buffer( );

		$this->ExpectNotConnected( static fn( ) : bool => $Socket->Sherlock( $Buffer ) );
	}

	//
	// Sherlock
	//

	/**
	 * Sherlock is asked whether the next datagram continues a split reply, so only
	 * another 0xFFFFFFFE fragment is true.
	 */
	public function testSherlockRecognisesAFurtherSplitFragment( ) : void
	{
		$Socket = $this->Open( );

		$this->Server( )->Queue( "\xFE\xFF\xFF\xFF" . pack( 'V', 0x0BADF00D ) . chr( 2 ) . chr( 1 ) . pack( 'v', 4 ) . 'tail' );

		$Buffer = new Buffer( );

		self::assertTrue( $Socket->Sherlock( $Buffer ) );
	}

	public function testSherlockRejectsASinglePacketDatagram( ) : void
	{
		$Socket = $this->Open( );

		$this->Server( )->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_INFO_SRC ) );

		$Buffer = new Buffer( );

		self::assertFalse( $Socket->Sherlock( $Buffer ) );
	}

	public function testSherlockRejectsADatagramShorterThanTheHeader( ) : void
	{
		$Socket = $this->Open( );

		$this->Server( )->Queue( "\xFE\xFF" );

		$Buffer = new Buffer( );

		self::assertFalse( $Socket->Sherlock( $Buffer ) );
	}

	//
	// Helpers
	//

	private function Open( int $Engine = SourceQuery::GOLDSOURCE ) : Socket
	{
		$Server = new FakeUdpServer( );
		$Socket = new Socket( );

		$Socket->Open( $Server->Host( ), $Server->Port( ), 1, $Engine );
		$Server->Attach( $Socket );

		$this->Server = $Server;
		$this->Socket = $Socket;

		return $Socket;
	}

	private function Server( ) : FakeUdpServer
	{
		$Server = $this->Server;

		if( $Server === null )
		{
			self::fail( 'The fake server was not started.' );
		}

		return $Server;
	}

	/**
	 * @param callable( ) : mixed $Action
	 */
	private function ExpectNotConnected( callable $Action ) : void
	{
		try
		{
			$Action( );

			self::fail( 'Expected SocketException' );
		}
		catch( SocketException $Exception )
		{
			self::assertSame( SocketException::NOT_CONNECTED, $Exception->getCode( ) );
			self::assertSame( 'Not connected.', $Exception->getMessage( ) );
		}
	}
}
