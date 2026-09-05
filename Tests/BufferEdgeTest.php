<?php
declare(strict_types=1);

use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\InvalidPacketException;

/**
 * Boundary behaviour of the Buffer readers at the end of the data, and how
 * Read( ) treats its length argument.
 */
class BufferEdgeTest extends \PHPUnit\Framework\TestCase
{
	private static function Buffer( string $Data ) : Buffer
	{
		$Buffer = new Buffer( );
		$Buffer->Set( $Data );

		return $Buffer;
	}

	//
	// Read
	//

	public function testReadWithoutALengthTakesEverythingThatIsLeft( ) : void
	{
		$Buffer = self::Buffer( 'abcdef' );

		self::assertSame( 'ab', $Buffer->Read( 2 ) );
		self::assertSame( 'cdef', $Buffer->Read( ) );
		self::assertSame( 0, $Buffer->Remaining( ) );
		self::assertSame( '', $Buffer->Read( ) );
	}

	public function testReadOfZeroBytesConsumesNothing( ) : void
	{
		$Buffer = self::Buffer( 'abcdef' );

		self::assertSame( '', $Buffer->Read( 0 ) );
		self::assertSame( 6, $Buffer->Remaining( ) );
	}

	/** -1 is the "read the rest" length, and it stays valid on an empty buffer. */
	public function testReadOfMinusOneOnAnExhaustedBuffer( ) : void
	{
		$Buffer = self::Buffer( '' );

		self::assertSame( '', $Buffer->Read( -1 ) );
		self::assertSame( 0, $Buffer->Remaining( ) );
	}

	public function testReadBeyondTheEndConsumesNothing( ) : void
	{
		$Buffer = self::Buffer( 'abc' );

		self::assertSame( '', $Buffer->Read( 4 ) );
		self::assertSame( 3, $Buffer->Remaining( ) );
		self::assertSame( 'abc', $Buffer->Read( 3 ) );
	}

	//
	// ReadByte
	//

	public function testReadByteReturnsTheAsciiValue( ) : void
	{
		$Buffer = self::Buffer( "\x00\x7F\x80\xFF" );

		self::assertSame( 0, $Buffer->ReadByte( ) );
		self::assertSame( 127, $Buffer->ReadByte( ) );
		self::assertSame( 128, $Buffer->ReadByte( ) );
		self::assertSame( 255, $Buffer->ReadByte( ) );
	}

	/**
	 * ReadByte reports 0 past the end instead of throwing, so a reply that stops
	 * early still produces a value for the missing field.
	 */
	public function testReadByteAtTheEndIsZero( ) : void
	{
		$Buffer = self::Buffer( '' );

		self::assertSame( 0, $Buffer->ReadByte( ) );
		self::assertSame( 0, $Buffer->Remaining( ) );
	}

	//
	// Fixed width readers
	//

	public function testReadInt16IsUnsigned( ) : void
	{
		self::assertSame( 65535, self::Buffer( "\xFF\xFF" )->ReadInt16( ) );
		self::assertSame( 40000, self::Buffer( pack( 'v', 40000 ) )->ReadInt16( ) );
	}

	public function testReadInt32IsSigned( ) : void
	{
		self::assertSame( -1, self::Buffer( "\xFF\xFF\xFF\xFF" )->ReadInt32( ) );
	}

	public function testReadUInt32IsUnsigned( ) : void
	{
		self::assertSame( 4294967295, self::Buffer( "\xFF\xFF\xFF\xFF" )->ReadUInt32( ) );
	}

	public function testReadInt16WithoutEnoughData( ) : void
	{
		self::ExpectBufferEmpty( fn( ) : int => self::Buffer( "\x01" )->ReadInt16( ) );
	}

	public function testReadInt32WithoutEnoughData( ) : void
	{
		self::ExpectBufferEmpty( fn( ) : int => self::Buffer( "\x01\x02\x03" )->ReadInt32( ) );
	}

	public function testReadUInt32WithoutEnoughData( ) : void
	{
		self::ExpectBufferEmpty( fn( ) : int => self::Buffer( "\x01\x02\x03" )->ReadUInt32( ) );
	}

	public function testReadFloat32WithoutEnoughData( ) : void
	{
		self::ExpectBufferEmpty( fn( ) : float => self::Buffer( "\x01\x02\x03" )->ReadFloat32( ) );
	}

	public function testReadFloat32DecodesSpecialValues( ) : void
	{
		self::assertNan( self::Buffer( "\x00\x00\xC0\x7F" )->ReadFloat32( ) );
		self::assertSame( INF, self::Buffer( "\x00\x00\x80\x7F" )->ReadFloat32( ) );
		self::assertSame( -INF, self::Buffer( "\x00\x00\x80\xFF" )->ReadFloat32( ) );
	}

	//
	// ReadNullTermString
	//

	public function testReadNullTermStringOnAnEmptyString( ) : void
	{
		$Buffer = self::Buffer( "\0rest" );

		self::assertSame( '', $Buffer->ReadNullTermString( ) );
		self::assertSame( 4, $Buffer->Remaining( ) );
	}

	public function testReadNullTermStringAtTheEndOfTheBuffer( ) : void
	{
		$Buffer = self::Buffer( "done\0" );

		self::assertSame( 'done', $Buffer->ReadNullTermString( ) );
		self::assertSame( 0, $Buffer->Remaining( ) );
		self::assertSame( '', $Buffer->ReadNullTermString( ) );
		self::assertSame( 0, $Buffer->Remaining( ) );
	}

	//
	// Set
	//

	public function testSetRewindsThePosition( ) : void
	{
		$Buffer = self::Buffer( 'abcdef' );

		self::assertSame( 'abc', $Buffer->Read( 3 ) );

		$Buffer->Set( 'xy' );

		self::assertSame( 2, $Buffer->Remaining( ) );
		self::assertSame( 'xy', $Buffer->Read( ) );
	}

	public function testSetToAnEmptyString( ) : void
	{
		$Buffer = self::Buffer( 'abcdef' );
		$Buffer->Set( '' );

		self::assertSame( 0, $Buffer->Remaining( ) );
	}

	/**
	 * @param callable( ) : (int|float) $Read
	 */
	private static function ExpectBufferEmpty( callable $Read ) : void
	{
		try
		{
			$Read( );

			self::fail( 'Expected InvalidPacketException' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::BUFFER_EMPTY, $Exception->getCode( ) );
			self::assertSame( 'Not enough data to unpack.', $Exception->getMessage( ) );
		}
	}
}
