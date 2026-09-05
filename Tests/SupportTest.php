<?php
declare(strict_types=1);

use xPaw\SourceQuery\Socket;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\FakeRconServer;
use xPaw\SourceQuery\Tests\Support\FakeUdpServer;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * The shared infrastructure in Tests/Support: the fake servers and the socket
 * double behave the way the other test files rely on.
 */
class SupportTest extends \PHPUnit\Framework\TestCase
{
	private ?FakeUdpServer $UdpServer = null;
	private ?FakeRconServer $RconServer = null;

	public function tearDown( ) : void
	{
		$this->UdpServer?->Close( );
		$this->RconServer?->Stop( );

		$this->UdpServer  = null;
		$this->RconServer = null;
	}

	public function testFakeUdpServerSinglePacket( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		$Socket = new Socket( );
		$Query  = new SourceQuery( $Socket );
		$Query->Connect( $this->UdpServer->Host( ), $this->UdpServer->Port( ), 1 );

		$this->UdpServer->Attach( $Socket );
		$this->UdpServer->Queue( FakeUdpServer::A2SReply( SourceQuery::S2A_INFO_SRC, FakeUdpServer::InfoPayload( 'Single Datagram Server' ) ) );

		$Info = $Query->GetInfo( );

		self::assertSame( 'Single Datagram Server', $Info[ 'HostName' ] );
		self::assertSame( 'de_dust2', $Info[ 'Map' ] );

		$Requests = $this->UdpServer->WaitForRequests( 1 );

		self::assertCount( 1, $Requests );
		self::assertSame( "\xFF\xFF\xFF\xFF\x54Source Engine Query\x00", $Requests[ 0 ] );

		$Query->Disconnect( );
	}

	public function testFakeUdpServerSplitPacket( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		$Socket = new Socket( );
		$Query  = new SourceQuery( $Socket );
		$Query->Connect( $this->UdpServer->Host( ), $this->UdpServer->Port( ), 1 );

		$this->UdpServer->Attach( $Socket );

		$Datagram  = FakeUdpServer::A2SReply( SourceQuery::S2A_INFO_SRC, FakeUdpServer::InfoPayload( 'Split Across Fragments' ) );
		$Fragments = FakeUdpServer::SplitPackets( $Datagram, 24 );

		self::assertGreaterThan( 1, count( $Fragments ) );

		$this->UdpServer->QueueMany( $Fragments );

		$Info = $Query->GetInfo( );

		self::assertSame( 'Split Across Fragments', $Info[ 'HostName' ] );

		$Query->Disconnect( );
	}

	public function testFakeRconServer( ) : void
	{
		$this->RconServer = new FakeRconServer( );

		$Port = $this->RconServer->Start(
		[
			// Step 0: the SERVERDATA_AUTH request.
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same' ],
				[ 'type' => SourceQuery::SERVERDATA_AUTH_RESPONSE, 'id' => 'same' ],
			],
			// Step 1: the SERVERDATA_EXECCOMMAND request.
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'hostname: Scripted' ],
			],
		],
		[
			[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'Unknown request' ],
		] );

		self::assertGreaterThan( 0, $Port );

		$Query = new SourceQuery( );
		$Query->Connect( '127.0.0.1', $Port, 2 );
		$Query->SetRconPassword( 'testpassword' );

		self::assertSame( 'hostname: Scripted', $Query->Rcon( 'status' ) );

		$Requests = $this->RconServer->WaitForRequests( 3 );

		self::assertCount( 3, $Requests );
		self::assertSame( SourceQuery::SERVERDATA_AUTH, $Requests[ 0 ][ 'type' ] );
		self::assertSame( 'testpassword', $Requests[ 0 ][ 'body' ] );
		self::assertSame( SourceQuery::SERVERDATA_EXECCOMMAND, $Requests[ 1 ][ 'type' ] );
		self::assertSame( 'status', $Requests[ 1 ][ 'body' ] );
		self::assertSame( SourceQuery::SERVERDATA_REQUESTVALUE, $Requests[ 2 ][ 'type' ] ); // Answered by the fallback

		$Query->Disconnect( );
	}

	public function testTestableSocketRecordsWrites( ) : void
	{
		$Socket = new TestableSocket( );
		$Query  = new SourceQuery( $Socket );
		$Query->Connect( '', 2 );

		$Socket->Queue( "\xFF\xFF\xFF\xFF\x6A\x00" );

		self::assertTrue( $Query->Ping( ) );

		self::assertSame( [ [ 'Header' => SourceQuery::A2A_PING, 'String' => '' ] ], $Socket->Written );
		self::assertSame( [ 'Header' => SourceQuery::A2A_PING, 'String' => '' ], $Socket->LastWritten( ) );
		self::assertTrue( $Socket->IsQueueEmpty( ) );

		$Query->Disconnect( );
	}

	public function testTestableSocketEmptyQueueBehavesLikeTimeout( ) : void
	{
		$Socket = new TestableSocket( );
		$Socket->ThrowOnEmptyQueue = false;

		$Query = new SourceQuery( $Socket );
		$Query->Connect( '', 2 );

		try
		{
			$Query->GetInfo( );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( xPaw\SourceQuery\Exception\InvalidPacketException $Exception )
		{
			self::assertSame( xPaw\SourceQuery\Exception\InvalidPacketException::BUFFER_EMPTY, $Exception->getCode( ) );
		}

		$Query->Disconnect( );
	}
}
