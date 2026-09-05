<?php
declare(strict_types=1);

use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * The datagram framing layer shared by every socket: the 0xFFFFFFFF single
 * packet header, the 0xFFFFFFFE split header and the bzip2 compressed form.
 */
class ReadInternalTest extends \PHPUnit\Framework\TestCase
{
	private const Challenge = "\xFF\xFF\xFF\xFF\x41\x11\x22\x33\x44";

	private TestableSocket $Socket;
	private SourceQuery $SourceQuery;

	public function setUp( ) : void
	{
		$this->Socket = new TestableSocket( );
		$this->Socket->ThrowOnEmptyQueue = false;

		$this->SourceQuery = new SourceQuery( $this->Socket );
		$this->SourceQuery->Connect( '', 2 );
	}

	public function tearDown( ) : void
	{
		$this->SourceQuery->Disconnect( );

		unset( $this->Socket, $this->SourceQuery );
	}

	public function testSinglePacketHeaderIsConsumed( ) : void
	{
		$this->Socket->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::A2A_ACK ) );

		self::assertTrue( $this->SourceQuery->Ping( ) );
	}

	/** Only 0xFFFFFFFF and 0xFFFFFFFE are valid datagram headers. */
	public function testUnknownRawHeaderIsRejected( ) : void
	{
		$this->Socket->Queue( "\x11\x11\x11\x11rest of the datagram" );

		try
		{
			$this->SourceQuery->GetInfo( );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::PACKET_HEADER_MISMATCH, $Exception->getCode( ) );
			self::assertStringContainsString( '0x11111111', $Exception->getMessage( ) );
		}
	}

	public function testEmptyReadIsReported( ) : void
	{
		try
		{
			$this->SourceQuery->GetInfo( );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::BUFFER_EMPTY, $Exception->getCode( ) );
			self::assertSame( 'Failed to read any data from socket', $Exception->getMessage( ) );
		}
	}

	/** A datagram shorter than the 4 byte header cannot be framed at all. */
	public function testTruncatedHeaderIsReported( ) : void
	{
		$this->Socket->Queue( "\xFF\xFF" );

		try
		{
			$this->SourceQuery->GetInfo( );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::BUFFER_EMPTY, $Exception->getCode( ) );
		}
	}

	/** A split reply of one fragment is complete on arrival, nothing more is read. */
	public function testSplitReplyOfOneFragment( ) : void
	{
		$Payload = "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_RULES ) . pack( 'v', 1 ) . "sv_gravity\0" . "800\0";

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue( "\xFE\xFF\xFF\xFF" . pack( 'V', 0x0BADF00D ) . chr( 1 ) . chr( 0 ) . pack( 'v', strlen( $Payload ) ) . $Payload );

		self::assertSame( [ 'sv_gravity' => '800' ], $this->SourceQuery->GetRules( ) );
	}

	/**
	 * The split header layout depends on the engine, so a value that is neither
	 * GoldSource nor Source cannot be decoded.
	 */
	public function testSplitReplyWithAnUnknownEngine( ) : void
	{
		$this->Socket->Engine = 42;
		$this->Socket->Queue( "\xFE\xFF\xFF\xFF" . pack( 'V', 0x0BADF00D ) . chr( 1 ) . chr( 0 ) . pack( 'v', 4 ) . 'data' );

		try
		{
			$this->SourceQuery->GetInfo( );

			self::fail( 'Expected SocketException' );
		}
		catch( SocketException $Exception )
		{
			self::assertSame( SocketException::INVALID_ENGINE, $Exception->getCode( ) );
			self::assertSame( 'Unknown engine.', $Exception->getMessage( ) );
		}
	}

	/**
	 * The uint32 after the decompressed size is a crc32 of the whole decompressed
	 * datagram.
	 */
	public function testCompressedFragmentWithAWrongChecksum( ) : void
	{
		self::RequireBz2( );

		$Payload    = "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_RULES ) . pack( 'v', 1 ) . "sv_gravity\0" . "800\0";
		$Compressed = bzcompress( $Payload );

		self::assertIsString( $Compressed );

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue(
			"\xFE\xFF\xFF\xFF" . pack( 'V', 0x80001650 ) . chr( 1 ) . chr( 0 ) .
			pack( 'V', strlen( $Payload ) ) . pack( 'V', crc32( $Payload ) ^ 0xFFFF ) . $Compressed
		);

		try
		{
			$this->SourceQuery->GetRules( );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::CHECKSUM_MISMATCH, $Exception->getCode( ) );
		}
	}

	/** Data flagged as compressed but not bzip2 fails like a corrupted stream. */
	public function testCompressedFragmentThatIsNotBzip2( ) : void
	{
		self::RequireBz2( );

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue(
			"\xFE\xFF\xFF\xFF" . pack( 'V', 0x80001650 ) . chr( 1 ) . chr( 0 ) .
			pack( 'V', 16 ) . pack( 'V', 0 ) . 'this is not bzip2'
		);

		try
		{
			$this->SourceQuery->GetRules( );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::CHECKSUM_MISMATCH, $Exception->getCode( ) );
		}
	}

	private static function RequireBz2( ) : void
	{
		if( !extension_loaded( 'bz2' ) )
		{
			self::markTestSkipped( 'The bz2 extension is required to build a compressed split packet.' );
		}
	}
}
