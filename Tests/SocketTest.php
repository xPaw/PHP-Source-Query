<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\Socket;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\FakeUdpServer;
use xPaw\SourceQuery\Tests\Support\Packets;
use xPaw\SourceQuery\Tests\Support\UdpServerFixture;

/**
 * The real Socket (fread/fwrite, stream timeouts, address parsing) against a
 * FakeUdpServer on loopback.
 */
class SocketTest extends \PHPUnit\Framework\TestCase
{
	use UdpServerFixture;

	//
	// Open and Close
	//

	public function testOpenStoresTheConnectionDetails( ) : void
	{
		$Socket = $this->OpenSocket( SourceQuery::GOLDSOURCE );

		self::assertSame( $this->UdpServer->Host( ), $Socket->Address );
		self::assertSame( $this->UdpServer->Port( ), $Socket->Port );
		self::assertSame( 1, $Socket->Timeout );
		self::assertSame( SourceQuery::GOLDSOURCE, $Socket->Engine );
		self::assertIsResource( $Socket->Socket );
	}

	public function testOpenOnAnUnresolvableAddress( ) : void
	{
		$Socket = new Socket( );

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

	public function testCloseIsIdempotent( ) : void
	{
		$Socket = $this->OpenSocket( );

		$Socket->Close( );
		$Socket->Close( );

		self::assertNull( $Socket->Socket );
	}

	public function testDestructorClosesTheSocket( ) : void
	{
		$this->UdpServer = new FakeUdpServer( );

		$Socket = new Socket( );
		$Query  = new SourceQuery( $Socket );
		$Query->Connect( $this->UdpServer->Host( ), $this->UdpServer->Port( ), 1 );

		self::assertIsResource( $Socket->Socket );

		unset( $Query );

		self::assertNull( $Socket->Socket );
	}

	public function testWriteAfterClose( ) : void
	{
		$Socket = $this->OpenSocket( );
		$Socket->Close( );

		$this->expectException( SocketException::class );
		$this->expectExceptionCode( SocketException::NOT_CONNECTED );
		$this->expectExceptionMessage( 'Not connected.' );

		$Socket->Write( SourceQuery::A2A_PING );
	}

	public function testReadAfterClose( ) : void
	{
		$Socket = $this->OpenSocket( );
		$Socket->Close( );

		$this->expectException( SocketException::class );
		$this->expectExceptionCode( SocketException::NOT_CONNECTED );
		$this->expectExceptionMessage( 'Not connected.' );

		$Socket->Read( );
	}

	public function testSherlockAfterClose( ) : void
	{
		$Socket = $this->OpenSocket( );
		$Socket->Close( );

		$this->expectException( SocketException::class );
		$this->expectExceptionCode( SocketException::NOT_CONNECTED );
		$this->expectExceptionMessage( 'Not connected.' );

		$Socket->Sherlock( new Buffer( ) );
	}

	//
	// Write
	//

	public function testWriteSendsTheDatagramWithTheQueryHeader( ) : void
	{
		$Socket = $this->OpenSocket( );

		self::assertTrue( $Socket->Write( SourceQuery::A2S_INFO, "Source Engine Query\0" ) );
		self::assertSame( [ "\xFF\xFF\xFF\xFF\x54Source Engine Query\x00" ], $this->UdpServer->WaitForRequests( 1 ) );
	}

	public function testWriteWithoutAPayload( ) : void
	{
		$Socket = $this->OpenSocket( );

		self::assertTrue( $Socket->Write( SourceQuery::A2A_PING ) );
		self::assertSame( [ "\xFF\xFF\xFF\xFF\x69" ], $this->UdpServer->WaitForRequests( 1 ) );
	}

	//
	// Sherlock, which decides whether another split fragment follows
	//

	public function testSherlockRecognisesAFurtherSplitFragment( ) : void
	{
		$Socket = $this->OpenSocket( );

		$this->Queue( Packets::SplitHeader( 0x0BADF00D, 2, 1, 4 ) . 'tail' );

		self::assertTrue( $Socket->Sherlock( new Buffer( ) ) );
	}

	public function testSherlockRejectsASinglePacketDatagram( ) : void
	{
		$Socket = $this->OpenSocket( );

		$this->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_SRC ) );

		self::assertFalse( $Socket->Sherlock( new Buffer( ) ) );
	}

	public function testSherlockRejectsADatagramShorterThanTheHeader( ) : void
	{
		$Socket = $this->OpenSocket( );

		$this->Queue( "\xFE\xFF" );

		self::assertFalse( $Socket->Sherlock( new Buffer( ) ) );
	}

	//
	// Queries over the wire
	//

	public function testGetInfo( ) : void
	{
		$Query = $this->ConnectQuery( );

		$this->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_SRC, Packets::InfoPayload( 'Baseline Server' ) ) );

		$Info = $Query->GetInfo( );

		self::assertSame( 'Baseline Server', $Info[ 'HostName' ] );
		self::assertSame( 'de_dust2', $Info[ 'Map' ] );
		self::assertSame( 'cstrike', $Info[ 'ModDir' ] );
		self::assertSame( 4, $Info[ 'Players' ] );
		self::assertSame( 32, $Info[ 'MaxPlayers' ] );

		self::assertSame( [ "\xFF\xFF\xFF\xFF\x54Source Engine Query\x00" ], $this->UdpServer->WaitForRequests( 1 ) );
	}

	public function testGetPlayersWithChallenge( ) : void
	{
		$Query = $this->ConnectQuery( );

		$this->Queue(
			Packets::Challenge( ),
			Packets::A2SReply( SourceQuery::S2A_PLAYER, self::PlayersPayload( ) )
		);

		$Players = $Query->GetPlayers( );

		self::assertCount( 2, $Players );
		self::assertSame( 'Player One', $Players[ 0 ][ 'Name' ] );
		self::assertSame( 12, $Players[ 0 ][ 'Frags' ] );
		self::assertSame( 90, $Players[ 0 ][ 'Time' ] );
		self::assertSame( '01:30', $Players[ 0 ][ 'TimeF' ] );
		self::assertSame( 'Player Two', $Players[ 1 ][ 'Name' ] );

		self::assertSame(
		[
			"\xFF\xFF\xFF\xFF\x55\xFF\xFF\xFF\xFF",
			"\xFF\xFF\xFF\xFF\x55" . Packets::ChallengeBytes,
		], $this->UdpServer->WaitForRequests( 2 ) );
	}

	public function testGetRulesOverAThreeFragmentSplitReply( ) : void
	{
		$Query = $this->ConnectQuery( );

		$Rules     = Packets::GeneratedRules( 30, 10 );
		$Datagram  = Packets::A2SReply( SourceQuery::S2A_RULES, Packets::RulesPayload( $Rules ) );
		$Fragments = Packets::SplitPackets( $Datagram, (int)ceil( strlen( $Datagram ) / 3 ) );

		self::assertCount( 3, $Fragments );

		$this->Queue( Packets::Challenge( ), ...$Fragments );

		self::assertSame( $Rules, $Query->GetRules( ) );
	}

	public function testPing( ) : void
	{
		$Query = $this->ConnectQuery( );

		$this->Queue( Packets::A2SReply( SourceQuery::A2A_ACK, "\x00" ) );

		self::assertTrue( $Query->Ping( ) );
		self::assertSame( [ "\xFF\xFF\xFF\xFF\x69" ], $this->UdpServer->WaitForRequests( 1 ) );
	}

	/**
	 * MaxPacketLength promises 65536, so a datagram larger than PHP's 8192 byte
	 * stream chunk size must still be delivered in full.
	 */
	public function testSingleDatagramLargerThan8192BytesIsRead( ) : void
	{
		$Query = $this->ConnectQuery( );

		$Rules    = Packets::GeneratedRules( 200, 30 );
		$Datagram = Packets::A2SReply( SourceQuery::S2A_RULES, Packets::RulesPayload( $Rules ) );

		self::assertGreaterThan( 9000, strlen( $Datagram ), 'The datagram must be well past the 8192 byte stream chunk size.' );

		$this->Queue( Packets::Challenge( ), $Datagram );

		self::assertSame( $Rules, $Query->GetRules( ) );
	}

	/** IPv6 literals are passed in brackets, as fsockopen expects. */
	public function testBracketedIPv6LiteralWorks( ) : void
	{
		try
		{
			$Query = $this->ConnectQuery( Host: '[::1]' );
		}
		catch( \RuntimeException $Exception )
		{
			self::markTestSkipped( 'IPv6 loopback is not available: ' . $Exception->getMessage( ) );
		}

		$this->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_SRC, Packets::InfoPayload( 'IPv6 Server' ) ) );

		self::assertSame( 'IPv6 Server', $Query->GetInfo( )[ 'HostName' ] );
		self::assertCount( 1, $this->UdpServer->WaitForRequests( 1 ) );
	}

	// Known bugs.

	/**
	 * A datagram that arrives after the caller gave up must not be consumed as the
	 * answer to the next request; the socket has to be drained once a read times out.
	 */
	#[Group( 'known-bug' )]
	public function testLateDatagramDoesNotPoisonTheNextQuery( ) : void
	{
		$Query = $this->ConnectQuery( );

		$this->ShortenReadTimeout( );

		// Nothing queued: the read times out and the request is abandoned.
		try
		{
			$Query->GetInfo( );

			self::fail( 'The unanswered GetInfo( ) did not time out.' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::BUFFER_EMPTY, $Exception->getCode( ) );
		}

		// The late answer, followed by the replies to the next request.
		$this->Queue(
			Packets::A2SReply( SourceQuery::S2A_INFO_SRC, Packets::InfoPayload( 'stale' ) ),
			Packets::Challenge( ),
			Packets::A2SReply( SourceQuery::S2A_PLAYER, self::PlayersPayload( ) )
		);

		try
		{
			$Players = $Query->GetPlayers( );
		}
		catch( InvalidPacketException )
		{
			self::fail( 'The stale S2A_INFO datagram was consumed as the answer to a different request.' );
		}

		self::assertSame( [ 'Player One', 'Player Two' ], array_column( $Players, 'Name' ) );
	}

	private static function PlayersPayload( ) : string
	{
		return Packets::PlayersPayload(
			Packets::PlayerRecord( 0, 'Player One', 12, 90.0 ),
			Packets::PlayerRecord( 1, 'Player Two', 34, 4000.0 )
		);
	}
}
