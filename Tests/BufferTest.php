<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Tests\Support\TestableSocket;

/**
 * Buffer readers, and the places where SourceQuery reads fixed width fields
 * without checking that anything was read.
 *
 * The known-bug group asserts the correct behaviour and fails until it is fixed.
 */
class BufferTest extends \PHPUnit\Framework\TestCase
{
	/** S2C_CHALLENGE with only 2 of the 4 challenge bytes. */
	private const ShortChallenge = "\xFF\xFF\xFF\xFF\x41\x11\x11";

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

	// Baseline: the wire format is little endian regardless of the host.

	public function testReadInt32IsLittleEndian( ) : void
	{
		$Buffer = new Buffer( );
		$Buffer->Set( "\xFE\xFF\xFF\xFF\x0A\x00\x00\x00\x00\x00\x00\x80" );

		self::assertSame( -2, $Buffer->ReadInt32( ) );
		self::assertSame( 10, $Buffer->ReadInt32( ) );
		self::assertSame( -2147483648, $Buffer->ReadInt32( ) );
		self::assertSame( 0, $Buffer->Remaining( ) );
	}

	public function testReadFloat32IsLittleEndian( ) : void
	{
		$Buffer = new Buffer( );
		$Buffer->Set( "\x00\x00\x80\x3F\x00\x00\x00\x00\x00\x00\x80\xBF" );

		self::assertSame( 1.0, $Buffer->ReadFloat32( ) );
		self::assertSame( 0.0, $Buffer->ReadFloat32( ) );
		self::assertSame( -1.0, $Buffer->ReadFloat32( ) );
		self::assertSame( 0, $Buffer->Remaining( ) );
	}

	public function testReadUInt32IsLittleEndian( ) : void
	{
		$Buffer = new Buffer( );
		$Buffer->Set( "\xFE\xFF\xFF\xFF" );

		self::assertSame( 4294967294, $Buffer->ReadUInt32( ) );
	}

	public function testReadNullTermStringConsumesTheTerminator( ) : void
	{
		$Buffer = new Buffer( );
		$Buffer->Set( "ayy\x00lmao\x00" );

		self::assertSame( 'ayy', $Buffer->ReadNullTermString( ) );
		self::assertSame( 'lmao', $Buffer->ReadNullTermString( ) );
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
		$Buffer = new Buffer( );
		$Buffer->Set( "AliceXYZ" );

		try
		{
			$String = $Buffer->ReadNullTermString( );

			self::fail( 'Expected InvalidPacketException, got ' . var_export( $String, true ) . ' with ' . $Buffer->Remaining( ) . ' bytes still unconsumed' );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertSame( InvalidPacketException::BUFFER_EMPTY, $Exception->getCode( ) );
		}
	}

	/**
	 * The same unterminated string through GetPlayers( ): the truncated player must
	 * not come back with an empty name and Frags/Time read from its leftover bytes.
	 */
	#[Group( 'known-bug' )]
	public function testTruncatedPlayerNameMustNotProduceGarbagePlayer( ) : void
	{
		$Payload =
			"\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_PLAYER ) . chr( 2 ) .
			chr( 0 ) . "Bob\x00" . pack( 'V', 5 ) . pack( 'g', 4.0 ) .
			chr( 0 ) . 'AliceXYZ'; // No null terminator, the rest of the reply was lost.

		$this->Socket->Queue( "\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11" );
		$this->Socket->Queue( $Payload );

		try
		{
			$Players = $this->SourceQuery->GetPlayers( );

			self::fail( 'Expected InvalidPacketException for an unterminated player name, got ' . json_encode( $Players ) );
		}
		catch( InvalidPacketException $Exception )
		{
			self::assertNotSame( '', $Exception->getMessage( ) );
		}
	}

	/**
	 * An S2C_CHALLENGE with fewer than 4 challenge bytes must make GetInfo( ) fail,
	 * not store the short challenge as '' and repeat the request with it.
	 */
	#[Group( 'known-bug' )]
	public function testShortChallengeInGetInfoMustThrow( ) : void
	{
		$this->Socket->Queue( self::ShortChallenge );
		$this->Socket->Queue( "\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_INFO_SRC ) . self::InfoPayload( ) );

		try
		{
			$Info = $this->SourceQuery->GetInfo( );

			self::fail( 'Expected InvalidPacketException for a 2 byte challenge, got HostName ' . var_export( $Info[ 'HostName' ], true ) . ' after ' . count( $this->Socket->Written ) . ' writes' );
		}
		catch( InvalidPacketException $Exception )
		{
			// The request must not be repeated with an empty challenge.
			self::assertCount( 1, $this->Socket->Written );
		}
	}

	/**
	 * The same short challenge through the GetChallenge( ) path used by
	 * GetPlayers( ): A2S_PLAYER must not be sent with an empty challenge.
	 */
	#[Group( 'known-bug' )]
	public function testShortChallengeInGetPlayersMustThrow( ) : void
	{
		$this->Socket->Queue( self::ShortChallenge );
		$this->Socket->Queue(
			"\xFF\xFF\xFF\xFF" . chr( SourceQuery::S2A_PLAYER ) . chr( 1 ) .
			chr( 0 ) . "Bob\x00" . pack( 'V', 5 ) . pack( 'g', 4.0 )
		);

		try
		{
			$Players = $this->SourceQuery->GetPlayers( );

			self::fail( 'Expected InvalidPacketException for a 2 byte challenge, got ' . json_encode( $Players ) );
		}
		catch( InvalidPacketException $Exception )
		{
			// The A2S_PLAYER request must not be sent with an empty challenge.
			self::assertCount( 1, $this->Socket->Written );
		}
	}

	/** A complete S2A_INFO_SRC body without any extra data flags. */
	private static function InfoPayload( ) : string
	{
		return
			chr( 17 ) . "Fake Server\x00" . "de_dust2\x00" . "cstrike\x00" . "Counter-Strike\x00" .
			pack( 'v', 440 ) . chr( 4 ) . chr( 32 ) . chr( 0 ) . 'd' . 'l' . chr( 0 ) . chr( 1 ) .
			"1.0.0.0\x00";
	}
}
