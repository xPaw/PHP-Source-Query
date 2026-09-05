<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\Packets;
use xPaw\SourceQuery\Tests\Support\TestableSocketTestCase;

/**
 * The datagram framing in BaseSocket::ReadInternal( ): the 0xFFFFFFFF single
 * packet header, the 0xFFFFFFFE split header and the bzip2 compressed form.
 * An unanswered read stands for a lost datagram throughout.
 */
class SplitPacketTest extends TestableSocketTestCase
{
	protected const bool TimeoutOnEmptyQueue = true;

	//
	// Single packets
	//

	/** Only 0xFFFFFFFF and 0xFFFFFFFE are valid datagram headers. */
	public function testUnknownRawHeaderIsRejected( ) : void
	{
		$this->Socket->Queue( "\x11\x11\x11\x11rest of the datagram" );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::PACKET_HEADER_MISMATCH );
		$this->expectExceptionMessage( '0x11111111' );

		$this->SourceQuery->GetInfo( );
	}

	public function testEmptyReadIsReported( ) : void
	{
		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::BUFFER_EMPTY );
		$this->expectExceptionMessage( 'Failed to read any data from socket' );

		$this->SourceQuery->GetInfo( );
	}

	/** A datagram shorter than the 4 byte header cannot be framed at all. */
	public function testTruncatedHeaderIsReported( ) : void
	{
		$this->Socket->Queue( "\xFF\xFF" );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::BUFFER_EMPTY );

		$this->SourceQuery->GetInfo( );
	}

	//
	// Split packets
	//

	public function testCompleteSplitPacketIsReassembled( ) : void
	{
		$Rules = Packets::GeneratedRules( 12 );

		$this->Socket->Queue( Packets::Challenge( ) );

		foreach( Packets::SplitPacketsByCount( self::RulesDatagram( $Rules ), 3 ) as $Fragment )
		{
			$this->Socket->Queue( $Fragment );
		}

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
		self::assertSame( 0, $this->Socket->QueuedCount( ) );
	}

	/** UDP datagrams can arrive in any order, see commit 8af3be6. */
	public function testOutOfOrderFragmentsAreReassembled( ) : void
	{
		$Rules     = Packets::GeneratedRules( 12 );
		$Fragments = Packets::SplitPacketsByCount( self::RulesDatagram( $Rules ), 3 );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( $Fragments[ 2 ] );
		$this->Socket->Queue( $Fragments[ 0 ] );
		$this->Socket->Queue( $Fragments[ 1 ] );

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
	}

	/** A split reply of one fragment is complete on arrival, nothing more is read. */
	public function testSplitReplyOfOneFragment( ) : void
	{
		$Datagram = self::RulesDatagram( [ 'sv_gravity' => '800' ] );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::SplitHeader( 0x0BADF00D, 1, 0, strlen( $Datagram ) ) . $Datagram );

		self::assertSame( [ 'sv_gravity' => '800' ], $this->SourceQuery->GetRules( ) );
	}

	/**
	 * Source 2006 era servers split without the int16 size field: 0xFFFFFFFE, int32
	 * request id, byte total, byte number, payload. Reading a size field there eats
	 * the first two bytes of every fragment.
	 */
	public function testSplitPacketWithoutSizeFieldIsReassembled( ) : void
	{
		$Rules    = Packets::GeneratedRules( 12 );
		$Datagram = self::RulesDatagram( $Rules );
		$Half     = intdiv( strlen( $Datagram ), 2 );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::SplitHeader( 0x0002B206, 2, 0 ) . substr( $Datagram, 0, $Half ) );
		$this->Socket->Queue( Packets::SplitHeader( 0x0002B206, 2, 1 ) . substr( $Datagram, $Half ) );

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
	}

	/**
	 * GoldSource uses a single header byte, count in the low nibble and packet
	 * number in the high one, with no size field.
	 */
	public function testGoldSourceSplitPacketIsReassembled( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;

		$Rules = Packets::GeneratedRules( 12 );

		$this->Socket->Queue( Packets::Challenge( ) );

		foreach( Packets::SplitPacketsGoldSourceByCount( self::RulesDatagram( $Rules ), 3 ) as $Fragment )
		{
			$this->Socket->Queue( $Fragment );
		}

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
	}

	public function testGoldSourceOutOfOrderFragmentsAreReassembled( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;

		$Rules     = Packets::GeneratedRules( 12 );
		$Fragments = Packets::SplitPacketsGoldSourceByCount( self::RulesDatagram( $Rules ), 3 );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( $Fragments[ 1 ] );
		$this->Socket->Queue( $Fragments[ 2 ] );
		$this->Socket->Queue( $Fragments[ 0 ] );

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
	}

	/**
	 * The split header layout depends on the engine, so a value that is neither
	 * GoldSource nor Source cannot be decoded.
	 */
	public function testSplitReplyWithAnUnknownEngine( ) : void
	{
		$this->Socket->Engine = 42;
		$this->Socket->Queue( Packets::SplitHeader( 0x0BADF00D, 1, 0, 4 ) . 'data' );

		$this->expectException( SocketException::class );
		$this->expectExceptionCode( SocketException::INVALID_ENGINE );
		$this->expectExceptionMessage( 'Unknown engine.' );

		$this->SourceQuery->GetInfo( );
	}

	/**
	 * A missing final fragment must throw instead of returning a truncated result
	 * built from the fragments that did arrive.
	 */
	public function testMissingLastFragmentMustThrow( ) : void
	{
		$Fragments = Packets::SplitPacketsByCount( self::RulesDatagram( Packets::GeneratedRules( 12 ) ), 3 );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( $Fragments[ 0 ] );
		$this->Socket->Queue( $Fragments[ 1 ] );
		// Fragment 2 was lost, the next read times out.

		$this->expectException( InvalidPacketException::class );

		$this->SourceQuery->GetRules( );
	}

	/**
	 * A missing middle fragment corrupts rather than truncates: the surviving
	 * fragments are concatenated across the gap and keys/values shift.
	 */
	public function testMissingMiddleFragmentMustThrow( ) : void
	{
		$Fragments = Packets::SplitPacketsByCount( self::RulesDatagram( Packets::GeneratedRules( 12 ) ), 3 );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( $Fragments[ 0 ] );
		// Fragment 1 was lost.
		$this->Socket->Queue( $Fragments[ 2 ] );

		$this->expectException( InvalidPacketException::class );

		$this->SourceQuery->GetRules( );
	}

	/**
	 * A stray single-packet datagram in the middle of a split reply must make
	 * reassembly fail loudly rather than be swallowed.
	 */
	public function testStraySinglePacketDuringReassemblyMustThrow( ) : void
	{
		$Fragments = Packets::SplitPacketsByCount( self::RulesDatagram( Packets::GeneratedRules( 12 ) ), 3 );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( $Fragments[ 0 ] );
		$this->Socket->Queue( Packets::A2SReply( SourceQuery::S2A_INFO_SRC ) ); // Reply to an earlier request.
		$this->Socket->Queue( $Fragments[ 1 ] );
		$this->Socket->Queue( $Fragments[ 2 ] );

		try
		{
			$this->SourceQuery->GetRules( );

			self::fail( 'A stray datagram interrupting reassembly went unnoticed.' );
		}
		catch( InvalidPacketException )
		{
			// The two unread fragments must survive the failed reassembly.
			self::assertSame( 2, $this->Socket->QueuedCount( ) );
		}
	}

	/**
	 * The request id identifies the response a fragment belongs to, so fragments
	 * of two different responses must not be reassembled into one.
	 */
	public function testMismatchedRequestIdMustThrow( ) : void
	{
		$Datagram = self::RulesDatagram( Packets::GeneratedRules( 8 ) );
		$Half     = intdiv( strlen( $Datagram ), 2 );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::SplitHeader( 0x11223344, 2, 0, $Half ) . substr( $Datagram, 0, $Half ) );
		$this->Socket->Queue( Packets::SplitHeader( 0x55667788, 2, 1, strlen( $Datagram ) - $Half ) . substr( $Datagram, $Half ) );

		$this->expectException( InvalidPacketException::class );

		$this->SourceQuery->GetRules( );
	}

	//
	// Compressed split packets
	//

	/**
	 * Pre-Orange-Box Source servers bzip2 their split replies and flag it with bit
	 * 31 of the request id. Fragment 0 then carries an int32 decompressed size and
	 * a uint32 crc32, and there is no int16 size field at all.
	 */
	#[RequiresPhpExtension( 'bz2' )]
	public function testCompressedSingleFragmentIsDecoded( ) : void
	{
		$Rules = Packets::GeneratedRules( 8 );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::CompressedSplitPackets( self::RulesDatagram( $Rules ) )[ 0 ] );

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
	}

	/**
	 * Only fragment 0 carries the decompressed size and crc32; later fragments have
	 * just the request id, total and number in front of their share of the bzip2
	 * stream. Reading the 8 byte trailer on every fragment corrupts the stream.
	 */
	#[RequiresPhpExtension( 'bz2' )]
	public function testCompressedMultiFragmentIsDecoded( ) : void
	{
		$Rules = Packets::GeneratedRules( 8 );

		$this->Socket->Queue( Packets::Challenge( ) );

		foreach( Packets::CompressedSplitPackets( self::RulesDatagram( $Rules ), 2 ) as $Fragment )
		{
			$this->Socket->Queue( $Fragment );
		}

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
	}

	/**
	 * The uint32 after the decompressed size is a crc32 of the whole decompressed
	 * datagram.
	 */
	#[RequiresPhpExtension( 'bz2' )]
	public function testCompressedFragmentWithAWrongChecksum( ) : void
	{
		$Datagram = self::RulesDatagram( [ 'sv_gravity' => '800' ] );

		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::CompressedSplitPacket(
			0x80001650, 1, 0, strlen( $Datagram ), crc32( $Datagram ) ^ 0xFFFF, Packets::Compress( $Datagram )
		) );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::CHECKSUM_MISMATCH );

		$this->SourceQuery->GetRules( );
	}

	/** Data flagged as compressed but not bzip2 fails like a corrupted stream. */
	#[RequiresPhpExtension( 'bz2' )]
	public function testCompressedFragmentThatIsNotBzip2( ) : void
	{
		$this->Socket->Queue( Packets::Challenge( ) );
		$this->Socket->Queue( Packets::CompressedSplitPacket( 0x80001650, 1, 0, 16, 0, 'this is not bzip2' ) );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::CHECKSUM_MISMATCH );

		$this->SourceQuery->GetRules( );
	}

	/**
	 * A complete S2A_RULES datagram, 0xFFFFFFFF header included, as it looks before
	 * a server splits it up.
	 *
	 * @param array<string, string> $Rules
	 */
	private static function RulesDatagram( array $Rules ) : string
	{
		return Packets::A2SReply( SourceQuery::S2A_RULES, Packets::RulesPayload( $Rules ) );
	}
}
