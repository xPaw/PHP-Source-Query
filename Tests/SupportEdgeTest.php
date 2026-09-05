<?php
declare(strict_types=1);

use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Socket;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\FakeRconServer;
use xPaw\SourceQuery\Tests\Support\FakeUdpServer;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * The parts of the shared infrastructure the other test files rely on but do not
 * exercise directly: the fragment builders, the scripting features of the fake
 * RCON server, and the failure modes of both fakes.
 */
class SupportEdgeTest extends \PHPUnit\Framework\TestCase
{
	private ?FakeUdpServer $UdpServer = null;
	private ?FakeRconServer $RconServer = null;
	private ?Socket $Socket = null;

	/** @var ?resource */
	private $Client = null;

	public function tearDown( ) : void
	{
		if( is_resource( $this->Client ) )
		{
			fclose( $this->Client );
		}

		$this->Socket?->Close( );
		$this->UdpServer?->Close( );
		$this->RconServer?->Stop( );

		$this->Client     = null;
		$this->Socket     = null;
		$this->UdpServer  = null;
		$this->RconServer = null;
	}

	//
	// FakeUdpServer
	//

	public function testA2SReplyPrefixesTheQueryHeader( ) : void
	{
		self::assertSame( "\xFF\xFF\xFF\xFF\x49body", FakeUdpServer::A2SReply( SourceQuery::S2A_INFO_SRC, 'body' ) );
		self::assertSame( "\xFF\xFF\xFF\xFF\x6A", FakeUdpServer::A2SReply( SourceQuery::A2A_ACK ) );
	}

	public function testInfoPayloadIsParsedByTheLibrary( ) : void
	{
		$Socket = new TestableSocket( );
		$Query  = new SourceQuery( $Socket );
		$Query->Connect( '', 2 );

		$Socket->Queue( FakeUdpServer::A2SReply( SourceQuery::S2A_INFO_SRC, FakeUdpServer::InfoPayload( 'Named Server', 730 ) ) );

		$Info = $Query->GetInfo( );

		self::assertSame( 'Named Server', $Info[ 'HostName' ] );
		self::assertSame( 730, $Info[ 'AppID' ] ?? null );

		$Query->Disconnect( );
	}

	public function testSourceFragmentHeaderShape( ) : void
	{
		$Fragments = FakeUdpServer::SplitPackets( str_repeat( 'x', 70 ), 32, 0x11223344 );

		self::assertCount( 3, $Fragments );
		self::assertSame( "\xFE\xFF\xFF\xFF" . pack( 'V', 0x11223344 ) . chr( 3 ) . chr( 0 ) . pack( 'v', 32 ), substr( $Fragments[ 0 ], 0, 12 ) );
		self::assertSame( chr( 3 ) . chr( 2 ) . pack( 'v', 6 ), substr( $Fragments[ 2 ], 8, 4 ) );
		self::assertSame( str_repeat( 'x', 70 ), implode( '', array_map( static fn( string $Fragment ) : string => substr( $Fragment, 12 ), $Fragments ) ) );
	}

	/**
	 * The GoldSource header is one byte: number in the high nibble, count in the
	 * low one, and no size field.
	 */
	public function testGoldSourceFragmentHeaderShape( ) : void
	{
		$Fragments = FakeUdpServer::SplitPacketsGoldSource( str_repeat( 'y', 70 ), 32, 0x11223344 );

		self::assertCount( 3, $Fragments );
		self::assertSame( "\xFE\xFF\xFF\xFF" . pack( 'V', 0x11223344 ) . chr( 0x03 ), substr( $Fragments[ 0 ], 0, 9 ) );
		self::assertSame( chr( 0x13 ), substr( $Fragments[ 1 ], 8, 1 ) );
		self::assertSame( chr( 0x23 ), substr( $Fragments[ 2 ], 8, 1 ) );
		self::assertSame( str_repeat( 'y', 70 ), implode( '', array_map( static fn( string $Fragment ) : string => substr( $Fragment, 9 ), $Fragments ) ) );
	}

	public function testGoldSourceFragmentsReassemble( ) : void
	{
		$Socket = new TestableSocket( );
		$Socket->Engine = SourceQuery::GOLDSOURCE;

		$Query = new SourceQuery( $Socket );
		$Query->Connect( '', 2, 1, SourceQuery::GOLDSOURCE );

		$Datagram = FakeUdpServer::A2SReply( SourceQuery::S2A_INFO_SRC, FakeUdpServer::InfoPayload( 'Fragmented Server' ) );

		foreach( FakeUdpServer::SplitPacketsGoldSource( $Datagram, 24 ) as $Fragment )
		{
			$Socket->Queue( $Fragment );
		}

		self::assertSame( 'Fragmented Server', $Query->GetInfo( )[ 'HostName' ] );

		$Query->Disconnect( );
	}

	public function testAnEmptyPayloadYieldsOneEmptyFragment( ) : void
	{
		self::assertCount( 1, FakeUdpServer::SplitPackets( '' ) );
		self::assertCount( 1, FakeUdpServer::SplitPacketsGoldSource( '' ) );
	}

	public function testFragmentSizeBelowOneIsRejected( ) : void
	{
		$this->expectException( \InvalidArgumentException::class );

		FakeUdpServer::SplitPackets( 'payload', 0 );
	}

	public function testQueueManySendsEveryDatagramInOrder( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		$Socket = new Socket( );
		$this->Socket = $Socket;
		$Socket->Open( $this->UdpServer->Host( ), $this->UdpServer->Port( ), 1, SourceQuery::SOURCE );

		$this->UdpServer->Attach( $Socket );
		$this->UdpServer->QueueMany(
		[
			"\xFF\xFF\xFF\xFF" . chr( SourceQuery::A2A_ACK ),
			"\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2C_CHALLENGE ) . 'abcd',
		] );

		self::assertSame( SourceQuery::A2A_ACK, $Socket->Read( )->ReadByte( ) );
		self::assertSame( SourceQuery::S2C_CHALLENGE, $Socket->Read( )->ReadByte( ) );
	}

	/**
	 * Without Attach( ) the server learns the client from its first datagram, so a
	 * request has to arrive before anything can be sent back.
	 */
	public function testQueueBeforeTheClientIsKnown( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		try
		{
			$this->UdpServer->Queue( 'anything' );

			self::fail( 'Expected RuntimeException' );
		}
		catch( \RuntimeException $Exception )
		{
			self::assertStringContainsString( 'no client attached', $Exception->getMessage( ) );
		}
	}

	public function testRequestsLearnsTheClientAddress( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		$Socket = new Socket( );
		$this->Socket = $Socket;
		$Socket->Open( $this->UdpServer->Host( ), $this->UdpServer->Port( ), 1, SourceQuery::SOURCE );

		// No Attach( ) call, the client is identified by its first datagram.
		$Socket->Write( SourceQuery::A2A_PING );

		self::assertSame( [ "\xFF\xFF\xFF\xFF\x69" ], $this->UdpServer->WaitForRequests( 1 ) );

		$this->UdpServer->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::A2A_ACK ) );

		self::assertSame( SourceQuery::A2A_ACK, $Socket->Read( )->ReadByte( ) );
	}

	public function testRequestsAccumulateAcrossCalls( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		$Socket = new Socket( );
		$this->Socket = $Socket;
		$Socket->Open( $this->UdpServer->Host( ), $this->UdpServer->Port( ), 1, SourceQuery::SOURCE );

		$Socket->Write( SourceQuery::A2A_PING );

		self::assertCount( 1, $this->UdpServer->WaitForRequests( 1 ) );

		$Socket->Write( SourceQuery::A2S_INFO );

		self::assertCount( 2, $this->UdpServer->WaitForRequests( 2 ) );
	}

	public function testWaitForRequestsGivesUp( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		$Started = microtime( true );

		self::assertSame( [], $this->UdpServer->WaitForRequests( 1, 0.2 ) );
		self::assertLessThan( 2.0, microtime( true ) - $Started );
	}

	public function testAttachRequiresAnOpenSocket( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		$this->expectException( \RuntimeException::class );

		$this->UdpServer->Attach( new Socket( ) );
	}

	public function testCloseIsIdempotentAndQueueingAfterwardsFails( ) : void
	{
		$Server = new FakeUdpServer( );
		$Server->Close( );
		$Server->Close( );

		$this->expectException( \RuntimeException::class );

		$Server->Queue( 'anything' );
	}

	//
	// FakeRconServer
	//

	public function testPortAndStdErrBeforeStart( ) : void
	{
		$Server = new FakeRconServer( );

		self::assertSame( 0, $Server->Port( ) );
		self::assertSame( '', $Server->StdErr( ) );
		self::assertSame( [], $Server->Requests( ) );

		$Server->Stop( );
	}

	public function testStartTwiceIsRejected( ) : void
	{
		$this->RconServer = new FakeRconServer( );
		$this->RconServer->Start( [], null, null, 5.0 );

		$this->expectException( \RuntimeException::class );

		$this->RconServer->Start( [] );
	}

	public function testStopTwice( ) : void
	{
		$Server = new FakeRconServer( );
		$Server->Start( [], null, null, 5.0 );

		$Server->Stop( );
		$Server->Stop( );

		self::assertSame( 0, $Server->Port( ) );
	}

	public function testFrameBuildsAFramedPacket( ) : void
	{
		$Frame = FakeRconServer::Frame( 7, SourceQuery::SERVERDATA_RESPONSE_VALUE, 'hi' );

		self::assertSame( pack( 'V', 12 ) . pack( 'VV', 7, 0 ) . "hi\x00\x00", $Frame );
	}

	public function testGreetingIsWrittenBeforeAnyRequest( ) : void
	{
		$Client = $this->StartAndConnect( [], null,
		[
			[ 'type' => SourceQuery::SERVERDATA_RESPONSE_VALUE, 'id' => 4242, 'body' => 'welcome' ],
		] );

		self::assertSame( [ 'id' => 4242, 'type' => 0, 'body' => 'welcome' ], self::ReadFrame( $Client ) );
	}

	/**
	 * Responses keyed by request type answer that type wherever it turns up,
	 * without advancing the positional script.
	 */
	public function testByTypeResponsesDoNotConsumePositionalSteps( ) : void
	{
		$Client = $this->StartAndConnect(
		[
			[ [ 'type' => 0, 'id' => 'same', 'body' => 'step zero' ] ],
			[ [ 'type' => 0, 'id' => 'same', 'body' => 'step one' ] ],
		], null, null,
		[
			SourceQuery::SERVERDATA_REQUESTVALUE => [ [ 'type' => 0, 'id' => 'same', 'body' => 'by type' ] ],
		] );

		self::SendRequest( $Client, 1, SourceQuery::SERVERDATA_EXECCOMMAND, 'first' );
		self::assertSame( 'step zero', self::ReadFrame( $Client )[ 'body' ] );

		self::SendRequest( $Client, 2, SourceQuery::SERVERDATA_REQUESTVALUE, '' );
		self::assertSame( 'by type', self::ReadFrame( $Client )[ 'body' ] );

		self::SendRequest( $Client, 3, SourceQuery::SERVERDATA_EXECCOMMAND, 'second' );
		self::assertSame( 'step one', self::ReadFrame( $Client )[ 'body' ] );

		$Requests = $this->RconServer?->WaitForRequests( 3 ) ?? [];

		self::assertCount( 3, $Requests );
		self::assertSame( [ 'first', '', 'second' ], array_column( $Requests, 'body' ) );
		self::assertSame( [ 1, 2, 3 ], array_column( $Requests, 'id' ) );
	}

	public function testFallbackAnswersRequestsBeyondTheScript( ) : void
	{
		$Client = $this->StartAndConnect(
		[
			[ [ 'type' => 0, 'id' => 'same', 'body' => 'scripted' ] ],
		],
		[
			[ 'type' => 0, 'id' => -1, 'body' => 'fallback' ],
		] );

		self::SendRequest( $Client, 1, SourceQuery::SERVERDATA_EXECCOMMAND, 'one' );
		self::assertSame( 'scripted', self::ReadFrame( $Client )[ 'body' ] );

		self::SendRequest( $Client, 2, SourceQuery::SERVERDATA_EXECCOMMAND, 'two' );

		$Frame = self::ReadFrame( $Client );

		self::assertSame( 'fallback', $Frame[ 'body' ] );
		self::assertSame( -1, $Frame[ 'id' ] );
	}

	/**
	 * A delay step writes nothing, it only spaces the writes around it out so the
	 * client sees two separate TCP segments.
	 */
	public function testDelayStepSplitsTheWrites( ) : void
	{
		$Frame = FakeRconServer::Frame( 9, SourceQuery::SERVERDATA_RESPONSE_VALUE, 'delayed body' );

		$Client = $this->StartAndConnect(
		[
			[
				[ 'rawHex' => bin2hex( substr( $Frame, 0, 6 ) ) ],
				[ 'delayMs' => 120 ],
				[ 'rawHex' => bin2hex( substr( $Frame, 6 ) ) ],
			],
		] );

		self::SendRequest( $Client, 1, SourceQuery::SERVERDATA_EXECCOMMAND, 'go' );

		$Started = microtime( true );

		self::assertSame( [ 'id' => 9, 'type' => 0, 'body' => 'delayed body' ], self::ReadFrame( $Client ) );
		self::assertGreaterThan( 0.05, microtime( true ) - $Started );
	}

	public function testCloseStepEndsTheConnection( ) : void
	{
		$Client = $this->StartAndConnect(
		[
			[ [ 'close' => true ] ],
		] );

		self::SendRequest( $Client, 1, SourceQuery::SERVERDATA_EXECCOMMAND, 'go' );

		self::assertSame( '', fread( $Client, 4 ) );
	}

	public function testStdErrIsEmptyForAHealthyRun( ) : void
	{
		$Client = $this->StartAndConnect(
		[
			[ [ 'type' => 0, 'id' => 'same', 'body' => 'fine' ] ],
		] );

		self::SendRequest( $Client, 1, SourceQuery::SERVERDATA_EXECCOMMAND, 'go' );

		self::assertSame( 'fine', self::ReadFrame( $Client )[ 'body' ] );
		self::assertSame( '', $this->RconServer?->StdErr( ) );
	}

	//
	// TestableSocket
	//

	public function testOpenStoresTheConnectionDetails( ) : void
	{
		$Socket = new TestableSocket( );
		$Socket->Open( 'somewhere', 27015, 4, SourceQuery::GOLDSOURCE );

		self::assertSame( 'somewhere', $Socket->Address );
		self::assertSame( 27015, $Socket->Port );
		self::assertSame( 4, $Socket->Timeout );
		self::assertSame( SourceQuery::GOLDSOURCE, $Socket->Engine );
	}

	public function testQueueAccountingAndLastWritten( ) : void
	{
		$Socket = new TestableSocket( );

		self::assertSame( 0, $Socket->QueuedCount( ) );
		self::assertTrue( $Socket->IsQueueEmpty( ) );
		self::assertNull( $Socket->LastWritten( ) );

		$Socket->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::A2A_ACK ) );
		$Socket->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::A2A_ACK ) );

		self::assertSame( 2, $Socket->QueuedCount( ) );
		self::assertFalse( $Socket->IsQueueEmpty( ) );

		self::assertTrue( $Socket->Write( SourceQuery::A2S_RULES, 'body' ) );
		self::assertSame( [ 'Header' => SourceQuery::A2S_RULES, 'String' => 'body' ], $Socket->LastWritten( ) );

		$Socket->Read( );

		self::assertSame( 1, $Socket->QueuedCount( ) );

		$Socket->Close( );

		self::assertSame( 1, $Socket->QueuedCount( ) );
	}

	/**
	 * By default an unanswered read is a test author's mistake rather than a
	 * simulated timeout, so it fails loudly.
	 */
	public function testReadingAnEmptyQueueThrowsByDefault( ) : void
	{
		$Socket = new TestableSocket( );

		self::assertTrue( $Socket->ThrowOnEmptyQueue );

		$this->expectException( \RuntimeException::class );

		$Socket->Read( );
	}

	public function testSherlockOnAnEmptyQueue( ) : void
	{
		$Socket = new TestableSocket( );
		$Buffer = new Buffer( );

		self::assertFalse( $Socket->Sherlock( $Buffer ) );

		$Socket->Queue( "\xFE\xFF\xFF\xFFtail" );

		self::assertTrue( $Socket->Sherlock( $Buffer ) );
	}

	//
	// Helpers
	//

	/**
	 * Boots the scripted RCON server and returns a raw TCP client for it.
	 *
	 * @param array<int, array<int, array<string, mixed>>>  $Script
	 * @param ?array<int, array<string, mixed>>             $Fallback
	 * @param ?array<int, array<string, mixed>>             $Greeting
	 * @param ?array<int, array<int, array<string, mixed>>> $ByType
	 *
	 * @return resource
	 */
	private function StartAndConnect( array $Script, ?array $Fallback = null, ?array $Greeting = null, ?array $ByType = null )
	{
		$this->RconServer = new FakeRconServer( );

		$Port = $this->RconServer->Start( $Script, $Fallback, $Greeting, 10.0, $ByType );

		$ErrNo  = 0;
		$ErrStr = '';
		$Client = @stream_socket_client( 'tcp://127.0.0.1:' . $Port, $ErrNo, $ErrStr, 2.0 );

		if( $Client === false )
		{
			self::fail( 'Could not connect to the fake RCON server: ' . $ErrStr );
		}

		stream_set_timeout( $Client, 3 );

		$this->Client = $Client;

		return $Client;
	}

	/**
	 * @param resource $Client
	 */
	private static function SendRequest( $Client, int $Id, int $Type, string $Body ) : void
	{
		$Packet = pack( 'VV', $Id, $Type ) . $Body . "\x00\x00";

		fwrite( $Client, pack( 'V', strlen( $Packet ) ) . $Packet );
	}

	/**
	 * @param resource $Client
	 *
	 * @return array{id: int, type: int, body: string}
	 */
	private static function ReadFrame( $Client ) : array
	{
		$Header = self::ReadExactly( $Client, 4 );
		$Size   = unpack( 'V', $Header );

		if( $Size === false || !is_int( $Size[ 1 ] ) )
		{
			self::fail( 'Could not decode the response size.' );
		}

		$Payload = self::ReadExactly( $Client, $Size[ 1 ] );
		$Fields  = unpack( 'Vid/Vtype', $Payload );

		if( $Fields === false || !is_int( $Fields[ 'id' ] ) || !is_int( $Fields[ 'type' ] ) )
		{
			self::fail( 'Could not decode the response header.' );
		}

		$Id = $Fields[ 'id' ];

		return
		[
			'id'   => $Id > 0x7FFFFFFF ? $Id - 0x100000000 : $Id,
			'type' => $Fields[ 'type' ],
			'body' => rtrim( substr( $Payload, 8 ), "\x00" ),
		];
	}

	/**
	 * @param resource $Client
	 */
	private static function ReadExactly( $Client, int $Length ) : string
	{
		$Data = '';

		while( strlen( $Data ) < $Length )
		{
			$Chunk = fread( $Client, max( 1, $Length - strlen( $Data ) ) );

			if( $Chunk === false || $Chunk === '' )
			{
				self::fail( 'The fake RCON server sent ' . strlen( $Data ) . ' of ' . $Length . ' expected bytes.' );
			}

			$Data .= $Chunk;
		}

		return $Data;
	}
}
