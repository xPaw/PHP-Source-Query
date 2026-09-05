<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * Split-packet reassembly in BaseSocket::ReadInternal( ), driven through
 * TestableSocket so no real sockets are involved. $ThrowOnEmptyQueue is false
 * throughout, so a lost datagram looks like a read timeout.
 *
 * The known-bug group asserts the correct behaviour and fails until it is fixed.
 */
class SplitPacketTest extends \PHPUnit\Framework\TestCase
{
	/** A valid S2C_CHALLENGE datagram, consumed by GetRules( )/GetPlayers( ). */
	private const Challenge = "\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11";

	/** 16 MiB, the decompressed size of the bz2 bomb below. */
	private const BombSize = 16 * 1024 * 1024;

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

	// Baseline: behaviour that must keep working.

	public function testCompleteSplitPacketIsReassembled( ) : void
	{
		$Rules = self::Rules( 12 );

		$this->Socket->Queue( self::Challenge );

		foreach( self::SourceFragments( self::RulesPayload( $Rules ), 3 ) as $Fragment )
		{
			$this->Socket->Queue( $Fragment );
		}

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
		self::assertTrue( $this->Socket->IsQueueEmpty( ) );
	}

	/** UDP datagrams can arrive in any order, see commit 8af3be6. */
	public function testOutOfOrderFragmentsAreReassembled( ) : void
	{
		$Rules     = self::Rules( 12 );
		$Fragments = self::SourceFragments( self::RulesPayload( $Rules ), 3 );

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue( $Fragments[ 2 ] );
		$this->Socket->Queue( $Fragments[ 0 ] );
		$this->Socket->Queue( $Fragments[ 1 ] );

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
	}

	/**
	 * GoldSource uses a single header byte, count in the low nibble and packet
	 * number in the high one, with no size field.
	 */
	public function testGoldSourceSplitPacketIsReassembled( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;

		$Rules = self::Rules( 12 );

		$this->Socket->Queue( self::Challenge );

		foreach( self::GoldSourceFragments( self::RulesPayload( $Rules ), 3 ) as $Fragment )
		{
			$this->Socket->Queue( $Fragment );
		}

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
	}

	public function testGoldSourceOutOfOrderFragmentsAreReassembled( ) : void
	{
		$this->Socket->Engine = SourceQuery::GOLDSOURCE;

		$Rules     = self::Rules( 12 );
		$Fragments = self::GoldSourceFragments( self::RulesPayload( $Rules ), 3 );

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue( $Fragments[ 1 ] );
		$this->Socket->Queue( $Fragments[ 2 ] );
		$this->Socket->Queue( $Fragments[ 0 ] );

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
	}

	// Known bugs.

	/**
	 * A missing final fragment must throw instead of returning a truncated result
	 * built from the fragments that did arrive.
	 */
	public function testMissingLastFragmentMustThrow( ) : void
	{
		$Rules     = self::Rules( 12 );
		$Fragments = self::SourceFragments( self::RulesPayload( $Rules ), 3 );

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue( $Fragments[ 0 ] );
		$this->Socket->Queue( $Fragments[ 1 ] );
		// Fragment 2 was lost, the next read times out.

		try
		{
			$Actual = $this->SourceQuery->GetRules( );

			self::fail( 'Expected InvalidPacketException for 2 of 3 fragments, got ' . count( $Actual ) . ' of ' . count( $Rules ) . ' rules: ' . json_encode( $Actual ) );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertNotSame( '', $Exception->getMessage( ) );
		}
	}

	/**
	 * A missing middle fragment corrupts rather than truncates: the surviving
	 * fragments are concatenated across the gap and keys/values shift.
	 */
	public function testMissingMiddleFragmentMustThrow( ) : void
	{
		$Rules     = self::Rules( 12 );
		$Fragments = self::SourceFragments( self::RulesPayload( $Rules ), 3 );

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue( $Fragments[ 0 ] );
		// Fragment 1 was lost.
		$this->Socket->Queue( $Fragments[ 2 ] );

		try
		{
			$Actual = $this->SourceQuery->GetRules( );

			self::fail( 'Expected InvalidPacketException for a lost middle fragment, got ' . count( $Actual ) . ' rules: ' . json_encode( $Actual ) );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertNotSame( '', $Exception->getMessage( ) );
		}
	}

	/**
	 * A stray single-packet datagram in the middle of a split reply must make
	 * reassembly fail loudly rather than be swallowed.
	 */
	public function testStraySinglePacketDuringReassemblyMustThrow( ) : void
	{
		$Rules     = self::Rules( 12 );
		$Fragments = self::SourceFragments( self::RulesPayload( $Rules ), 3 );

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue( $Fragments[ 0 ] );
		$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x49" ); // Stray single packet reply for an earlier request.
		$this->Socket->Queue( $Fragments[ 1 ] );
		$this->Socket->Queue( $Fragments[ 2 ] );

		try
		{
			$Actual = $this->SourceQuery->GetRules( );

			self::fail( 'Expected InvalidPacketException when a stray datagram interrupts reassembly, got ' . count( $Actual ) . ' rules: ' . json_encode( $Actual ) );
		}
		catch( InvalidPacketException $Exception )
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
		$Rules  = self::Rules( 8 );
		$Chunks = self::Chunks( self::RulesPayload( $Rules ), 2 );

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue( self::SourceFragment( 0x11223344, 2, 0, $Chunks[ 0 ] ) );
		$this->Socket->Queue( self::SourceFragment( 0x55667788, 2, 1, $Chunks[ 1 ] ) );

		try
		{
			$Actual = $this->SourceQuery->GetRules( );

			self::fail( 'Expected InvalidPacketException for fragments from two different responses, got ' . count( $Actual ) . ' rules: ' . json_encode( $Actual ) );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertNotSame( '', $Exception->getMessage( ) );
		}
	}

	/**
	 * Source 2006 era servers split without the int16 size field: 0xFFFFFFFE, int32
	 * request id, byte total, byte number, payload. Reading a size field there eats
	 * the first two bytes of every fragment.
	 */
	#[Group( 'known-bug' )]
	public function testSplitPacketWithoutSizeFieldIsReassembled( ) : void
	{
		$Rules = self::Rules( 12 );

		$this->Socket->Queue( self::Challenge );

		foreach( self::Chunks( self::RulesPayload( $Rules ), 2 ) as $Number => $Chunk )
		{
			$this->Socket->Queue( "\xFE\xFF\xFF\xFF" . pack( 'V', 0x0002B206 ) . pack( 'C', 2 ) . pack( 'C', $Number ) . $Chunk );
		}

		try
		{
			$Actual = $this->SourceQuery->GetRules( );
		}
		catch( InvalidPacketException $Exception )
		{
			self::fail( 'A split reply without the int16 size field was rejected: ' . $Exception->getMessage( ) );
		}

		self::assertSame( $Rules, $Actual );
	}

	/**
	 * Pre-Orange-Box Source servers bzip2 their split replies and flag it with bit
	 * 31 of the request id. Fragment 0 then carries an int32 decompressed size and
	 * a uint32 crc32, and there is no int16 size field at all.
	 */
	public function testCompressedSingleFragmentIsDecoded( ) : void
	{
		$Rules     = self::Rules( 8 );
		$Fragments = self::CompressedFragments( self::RulesPayload( $Rules ), 1 );

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue( $Fragments[ 0 ] );

		self::assertSame( $Rules, $this->SourceQuery->GetRules( ) );
	}

	/**
	 * Only fragment 0 carries the decompressed size and crc32; later fragments have
	 * just the request id, total and number in front of their share of the bzip2
	 * stream. Reading the 8 byte trailer on every fragment corrupts the stream.
	 */
	public function testCompressedMultiFragmentIsDecoded( ) : void
	{
		$Rules     = self::Rules( 8 );
		$Fragments = self::CompressedFragments( self::RulesPayload( $Rules ), 2 );

		$this->Socket->Queue( self::Challenge );
		$this->Socket->Queue( $Fragments[ 0 ] );
		$this->Socket->Queue( $Fragments[ 1 ] );

		try
		{
			$Actual = $this->SourceQuery->GetRules( );
		}
		catch( InvalidPacketException $Exception )
		{
			self::fail( 'A compressed reply split over two fragments was rejected: ' . $Exception->getMessage( ) );
		}

		self::assertSame( $Rules, $Actual );
	}

	/**
	 * A tiny datagram that expands to 16 MiB must be refused on its declared
	 * decompressed size, not decompressed first and rejected afterwards.
	 */
	#[Group( 'known-bug' )]
	public function testCompressedSplitPacketBombIsRefusedBeforeDecompressing( ) : void
	{
		self::RequireBz2( );

		$Payload    = str_repeat( "\x00", self::BombSize );
		$Checksum   = crc32( $Payload );
		$Compressed = bzcompress( $Payload );

		unset( $Payload );

		if( !is_string( $Compressed ) )
		{
			self::fail( 'bzcompress( ) failed with error ' . var_export( $Compressed, true ) );
		}

		$this->Socket->Queue(
			"\xFE\xFF\xFF\xFF" . pack( 'V', 0x80000001 ) . chr( 1 ) . chr( 0 ) .
			pack( 'V', self::BombSize ) . pack( 'V', $Checksum ) . $Compressed
		);

		$Datagram = strlen( $Compressed ) + 18;

		gc_collect_cycles( );
		memory_reset_peak_usage( );

		$Baseline = memory_get_usage( );
		$Thrown   = null;

		try
		{
			$this->SourceQuery->GetInfo( );
		}
		catch( InvalidPacketException $Exception )
		{
			$Thrown = $Exception;
		}

		$Allocated = memory_get_peak_usage( ) - $Baseline;

		self::assertLessThan(
			4 * 1024 * 1024,
			$Allocated,
			'A ' . $Datagram . ' byte datagram declaring ' . self::BombSize . ' decompressed bytes allocated ' . $Allocated . ' bytes; the declared size must be refused before bzdecompress( ) runs'
		);
		self::assertInstanceOf( InvalidPacketException::class, $Thrown );
	}

	// Helpers.

	/**
	 * @return array<string, string>
	 */
	private static function Rules( int $Count ) : array
	{
		$Rules = [];

		for( $i = 1; $i <= $Count; $i++ )
		{
			$Rules[ sprintf( 'rule_%02d', $i ) ] = sprintf( 'value_%02d', $i );
		}

		return $Rules;
	}

	/**
	 * A complete S2A_RULES datagram, 0xFFFFFFFF header included.
	 *
	 * @param array<string, string> $Rules
	 */
	private static function RulesPayload( array $Rules ) : string
	{
		$Payload = "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_RULES ) . pack( 'v', count( $Rules ) );

		foreach( $Rules as $Key => $Value )
		{
			$Payload .= $Key . "\x00" . $Value . "\x00";
		}

		return $Payload;
	}

	/**
	 * Splits a string into exactly $Count pieces.
	 *
	 * @return array<int, string>
	 */
	private static function Chunks( string $Payload, int $Count ) : array
	{
		$Size   = intdiv( strlen( $Payload ), $Count );
		$Chunks = [];

		for( $i = 0; $i < $Count; $i++ )
		{
			$Chunks[] = $i === $Count - 1 ? substr( $Payload, $i * $Size ) : substr( $Payload, $i * $Size, $Size );
		}

		return $Chunks;
	}

	/**
	 * Source split header: 0xFFFFFFFE, int32 request id, byte total, byte number
	 * (0 based), int16 size, payload.
	 */
	private static function SourceFragment( int $RequestID, int $Total, int $Number, string $Chunk ) : string
	{
		return "\xFE\xFF\xFF\xFF" . pack( 'V', $RequestID ) . pack( 'C', $Total ) . pack( 'C', $Number ) . pack( 'v', strlen( $Chunk ) ) . $Chunk;
	}

	/**
	 * @return array<int, string>
	 */
	private static function SourceFragments( string $Payload, int $Count, int $RequestID = 0x11223344 ) : array
	{
		$Fragments = [];

		foreach( self::Chunks( $Payload, $Count ) as $Number => $Chunk )
		{
			$Fragments[] = self::SourceFragment( $RequestID, $Count, $Number, $Chunk );
		}

		return $Fragments;
	}

	private static function RequireBz2( ) : void
	{
		if( !extension_loaded( 'bz2' ) )
		{
			self::markTestSkipped( 'The bz2 extension is required to build a compressed split packet.' );
		}
	}

	/**
	 * Compressed split reply as sent by Source 2006 era servers: 0xFFFFFFFE, int32
	 * request id with bit 31 set, byte total, byte number, and on fragment 0 the
	 * int32 decompressed size plus uint32 crc32. No int16 size field anywhere.
	 *
	 * @return array<int, string>
	 */
	private static function CompressedFragments( string $Payload, int $Count, int $RequestID = 0x80001650 ) : array
	{
		self::RequireBz2( );

		$Compressed = bzcompress( $Payload );

		if( !is_string( $Compressed ) )
		{
			self::fail( 'bzcompress( ) failed with error ' . var_export( $Compressed, true ) );
		}

		$Fragments = [];

		foreach( self::Chunks( $Compressed, $Count ) as $Number => $Chunk )
		{
			$Fragment = "\xFE\xFF\xFF\xFF" . pack( 'V', $RequestID ) . pack( 'C', $Count ) . pack( 'C', $Number );

			if( $Number === 0 )
			{
				$Fragment .= pack( 'V', strlen( $Payload ) ) . pack( 'V', crc32( $Payload ) );
			}

			$Fragments[] = $Fragment . $Chunk;
		}

		return $Fragments;
	}

	/**
	 * GoldSource split header: 0xFFFFFFFE, int32 request id, one byte holding
	 * ( number << 4 ) | count, payload. No size field.
	 *
	 * @return array<int, string>
	 */
	private static function GoldSourceFragments( string $Payload, int $Count, int $RequestID = 0x11223344 ) : array
	{
		$Fragments = [];

		foreach( self::Chunks( $Payload, $Count ) as $Number => $Chunk )
		{
			$Fragments[] = "\xFE\xFF\xFF\xFF" . pack( 'V', $RequestID ) . pack( 'C', ( $Number << 4 ) | $Count ) . $Chunk;
		}

		return $Fragments;
	}
}
