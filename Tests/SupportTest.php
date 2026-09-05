<?php
declare(strict_types=1);

use xPaw\SourceQuery\Socket;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\FakeRconServer;
use xPaw\SourceQuery\Tests\Support\FakeUdpServer;

/**
 * The fake servers in Tests/Support: that a scripted RCON session reaches the
 * library, and the failure modes the other files rely on to fail loudly.
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

	//
	// FakeUdpServer
	//

	/**
	 * Without Attach( ) the server learns the client from its first datagram, so a
	 * request has to arrive before anything can be sent back.
	 */
	public function testQueueBeforeTheClientIsKnown( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'no client attached' );

		$this->UdpServer->Queue( 'anything' );
	}

	public function testAttachRequiresAnOpenSocket( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'the socket is not open' );

		$this->UdpServer->Attach( new Socket( ) );
	}

	public function testCloseIsIdempotentAndQueueingAfterwardsFails( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );
		$this->UdpServer->Close( );
		$this->UdpServer->Close( );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'server is closed' );

		$this->UdpServer->Queue( 'anything' );
	}

	public function testWaitForRequestsGivesUp( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		self::assertSame( [], $this->UdpServer->WaitForRequests( 1, 0.05 ) );
	}

	//
	// FakeRconServer
	//

	/** A scripted session, with the fallback answering the sentinel request. */
	public function testScriptedRconSessionReachesTheLibrary( ) : void
	{
		$this->RconServer = new FakeRconServer( );

		$Port = $this->RconServer->Start(
		[
			FakeRconServer::AuthOk( ),
			[
				[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'hostname: Scripted' ],
			],
		], null,
		[
			[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 'same', 'body' => 'Unknown request' ],
		] );

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
	}

	public function testStartTwiceIsRejected( ) : void
	{
		$this->RconServer = new FakeRconServer( );
		$this->RconServer->Start( [] );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'already started' );

		$this->RconServer->Start( [] );
	}

	public function testStopTwice( ) : void
	{
		$Server = new FakeRconServer( );
		$Server->Start( [] );

		$Server->Stop( );
		$Server->Stop( );

		self::assertSame( 0, $Server->Port( ) );
	}
}
