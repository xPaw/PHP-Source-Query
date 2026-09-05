<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\InvalidPacketException;

/**
 * The Buffer readers on their own: byte order, signedness, and what each of
 * them does at the end of the data.
 */
class BufferTest extends \PHPUnit\Framework\TestCase
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
	// Fixed width readers, the wire format is little endian regardless of the host
	//

	public function testReadInt16IsUnsigned( ) : void
	{
		self::assertSame( 65535, self::Buffer( "\xFF\xFF" )->ReadInt16( ) );
		self::assertSame( 40000, self::Buffer( pack( 'v', 40000 ) )->ReadInt16( ) );
	}

	public function testReadInt32IsSignedLittleEndian( ) : void
	{
		$Buffer = self::Buffer( "\xFE\xFF\xFF\xFF\x0A\x00\x00\x00\x00\x00\x00\x80" );

		self::assertSame( -2, $Buffer->ReadInt32( ) );
		self::assertSame( 10, $Buffer->ReadInt32( ) );
		self::assertSame( -2147483648, $Buffer->ReadInt32( ) );
		self::assertSame( 0, $Buffer->Remaining( ) );
	}

	public function testReadUInt32IsUnsignedLittleEndian( ) : void
	{
		$Buffer = self::Buffer( "\xFE\xFF\xFF\xFF\xFF\xFF\xFF\xFF" );

		self::assertSame( 4294967294, $Buffer->ReadUInt32( ) );
		self::assertSame( 4294967295, $Buffer->ReadUInt32( ) );
	}

	public function testReadFloat32IsLittleEndian( ) : void
	{
		$Buffer = self::Buffer( "\x00\x00\x80\x3F\x00\x00\x00\x00\x00\x00\x80\xBF" );

		self::assertSame( 1.0, $Buffer->ReadFloat32( ) );
		self::assertSame( 0.0, $Buffer->ReadFloat32( ) );
		self::assertSame( -1.0, $Buffer->ReadFloat32( ) );
		self::assertSame( 0, $Buffer->Remaining( ) );
	}

	public function testReadFloat32DecodesSpecialValues( ) : void
	{
		self::assertNan( self::Buffer( "\x00\x00\xC0\x7F" )->ReadFloat32( ) );
		self::assertSame( INF, self::Buffer( "\x00\x00\x80\x7F" )->ReadFloat32( ) );
		self::assertSame( -INF, self::Buffer( "\x00\x00\x80\xFF" )->ReadFloat32( ) );
	}

	public function testReadInt16WithoutEnoughData( ) : void
	{
		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::BUFFER_EMPTY );
		$this->expectExceptionMessage( 'Not enough data to unpack.' );

		self::Buffer( "\x01" )->ReadInt16( );
	}

	public function testReadInt32WithoutEnoughData( ) : void
	{
		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::BUFFER_EMPTY );
		$this->expectExceptionMessage( 'Not enough data to unpack.' );

		self::Buffer( "\x01\x02\x03" )->ReadInt32( );
	}

	public function testReadUInt32WithoutEnoughData( ) : void
	{
		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::BUFFER_EMPTY );
		$this->expectExceptionMessage( 'Not enough data to unpack.' );

		self::Buffer( "\x01\x02\x03" )->ReadUInt32( );
	}

	public function testReadFloat32WithoutEnoughData( ) : void
	{
		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::BUFFER_EMPTY );
		$this->expectExceptionMessage( 'Not enough data to unpack.' );

		self::Buffer( "\x01\x02\x03" )->ReadFloat32( );
	}

	//
	// ReadNullTermString
	//

	public function testReadNullTermStringConsumesTheTerminator( ) : void
	{
		$Buffer = self::Buffer( "ayy\x00lmao\x00" );

		self::assertSame( 'ayy', $Buffer->ReadNullTermString( ) );
		self::assertSame( 'lmao', $Buffer->ReadNullTermString( ) );
		self::assertSame( 0, $Buffer->Remaining( ) );
	}

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

	// Known bugs.

	/**
	 * A string that runs off the end of the buffer without its terminator must
	 * throw, not return '' and consume nothing, which misaligns every later field.
	 */
	#[Group( 'known-bug' )]
	public function testReadNullTermStringOnUnterminatedStringMustThrow( ) : void
	{
		$Buffer = self::Buffer( 'AliceXYZ' );

		$this->expectException( InvalidPacketException::class );
		$this->expectExceptionCode( InvalidPacketException::BUFFER_EMPTY );

		$Buffer->ReadNullTermString( );
	}
}
