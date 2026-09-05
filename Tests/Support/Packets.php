<?php
declare(strict_types=1);

/**
 * Builders for the datagrams the tests feed to the library. The A2S builders
 * return reply bodies, wrap them with A2SReply( ) to get a datagram.
 */

namespace xPaw\SourceQuery\Tests\Support;

use xPaw\SourceQuery\SourceQuery;

final class Packets
{
	/** The challenge bytes used wherever a test does not care about the number. */
	public const ChallengeBytes = "\x11\x22\x33\x44";

	/** A single A2S datagram: 0xFFFFFFFF, the type byte, then the payload. */
	public static function A2SReply( int $Type, string $Payload = '' ) : string
	{
		return "\xFF\xFF\xFF\xFF" . self::Byte( $Type ) . $Payload;
	}

	/** The low byte of a value, as protocol fields are one byte wide. */
	private static function Byte( int $Value ) : string
	{
		return chr( $Value & 0xFF );
	}

	/** A complete S2C_CHALLENGE datagram. */
	public static function Challenge( string $Challenge = self::ChallengeBytes ) : string
	{
		return self::A2SReply( SourceQuery::S2C_CHALLENGE, $Challenge );
	}

	/** An S2A_INFO_SRC (0x49) body, up to and including the version string. */
	public static function InfoPayload( string $HostName = 'Fake Server', int $AppID = 440, int $Protocol = 17, int $Bots = 0, string $Ship = '' ) : string
	{
		return self::Byte( $Protocol )
			. $HostName . "\0"
			. "de_dust2\0"                  // Map
			. "cstrike\0"                   // ModDir
			. "Counter-Strike\0"            // ModDesc
			. pack( 'v', $AppID )
			. chr( 4 )                      // Players
			. chr( 32 )                     // MaxPlayers
			. self::Byte( $Bots )
			. 'd'                           // Dedicated
			. 'l'                           // Os
			. chr( 0 )                      // Password
			. chr( 1 )                      // Secure
			. $Ship
			. "1.0.0.0\0";                  // Version
	}

	/** An S2A_INFO_DETAILED (0x6D) body. $Mod is the block that follows the IsMod flag. */
	public static function DetailedInfoPayload( string $HostName = 'GoldSource Server', bool $Password = false, string $Mod = '' ) : string
	{
		return "127.0.0.1:27015\0"          // Address
			. $HostName . "\0"
			. "crossfire\0"                 // Map
			. "valve\0"                     // ModDir
			. "Half-Life\0"                 // ModDesc
			. chr( 5 )                      // Players
			. chr( 32 )                     // MaxPlayers
			. chr( 47 )                     // Protocol
			. 'd'                           // Dedicated
			. 'l'                           // Os
			. chr( $Password ? 1 : 0 )
			. chr( $Mod === '' ? 0 : 1 )    // IsMod
			. $Mod
			. chr( 1 )                      // Secure
			. chr( 1 );                     // Bots
	}

	/**
	 * One A2S_PLAYER record: byte index, name, int32 frags, float32 seconds.
	 * The time may also be given as its 4 raw bytes.
	 */
	public static function PlayerRecord( int $Index, string $Name, int $Frags = 10, float|string $Time = 60.0 ) : string
	{
		return self::Byte( $Index ) . $Name . "\0" . pack( 'l', $Frags ) . ( is_string( $Time ) ? $Time : pack( 'f', $Time ) );
	}

	/** An S2A_PLAYER body: the record count, then the records. */
	public static function PlayersPayload( string ...$Records ) : string
	{
		return self::Byte( count( $Records ) ) . implode( '', $Records );
	}

	/**
	 * An S2A_RULES body: the int16 count, then null terminated key/value pairs.
	 *
	 * @param array<string, string> $Rules
	 */
	public static function RulesPayload( array $Rules ) : string
	{
		$Payload = pack( 'v', count( $Rules ) );

		foreach( $Rules as $Key => $Value )
		{
			$Payload .= $Key . "\0" . $Value . "\0";
		}

		return $Payload;
	}

	/**
	 * Rules named rule_000 upwards, padded when a reply has to be pushed past a
	 * size boundary.
	 *
	 * @return array<string, string>
	 */
	public static function GeneratedRules( int $Count, int $Padding = 0 ) : array
	{
		$Rules = [];

		for( $i = 0; $i < $Count; $i++ )
		{
			$Rules[ sprintf( 'rule_%03d', $i ) ] = sprintf( 'value_%03d', $i ) . str_repeat( 'x', $Padding );
		}

		return $Rules;
	}

	/**
	 * Source split header: 0xFFFFFFFE, int32 request id, byte total, byte number
	 * (0 based) and the int16 size, which Source 2006 era servers omit.
	 */
	public static function SplitHeader( int $RequestID, int $Total, int $Number, ?int $Size = null ) : string
	{
		return "\xFE\xFF\xFF\xFF" . pack( 'V', $RequestID ) . self::Byte( $Total ) . self::Byte( $Number )
			. ( $Size === null ? '' : pack( 'v', $Size ) );
	}

	/**
	 * Source fragments of at most $FragmentSize bytes each.
	 *
	 * @return array<int, string> Fragments in ascending packet-number order.
	 */
	public static function SplitPackets( string $Payload, int $FragmentSize = 32, int $RequestID = 0x11223344 ) : array
	{
		return self::SourceFragments( self::ChunksOfSize( $Payload, $FragmentSize ), $RequestID );
	}

	/**
	 * Source fragments, exactly $Count of them.
	 *
	 * @return array<int, string>
	 */
	public static function SplitPacketsByCount( string $Payload, int $Count, int $RequestID = 0x11223344 ) : array
	{
		return self::SourceFragments( self::ChunksByCount( $Payload, $Count ), $RequestID );
	}

	/**
	 * @return array<int, string>
	 */
	public static function SplitPacketsGoldSource( string $Payload, int $FragmentSize = 32, int $RequestID = 0x11223344 ) : array
	{
		return self::GoldSourceFragments( self::ChunksOfSize( $Payload, $FragmentSize ), $RequestID );
	}

	/**
	 * @return array<int, string>
	 */
	public static function SplitPacketsGoldSourceByCount( string $Payload, int $Count, int $RequestID = 0x11223344 ) : array
	{
		return self::GoldSourceFragments( self::ChunksByCount( $Payload, $Count ), $RequestID );
	}

	/**
	 * One fragment of a compressed split reply, with the decompressed size and the
	 * checksum spelled out so a test can get them wrong on purpose.
	 */
	public static function CompressedSplitPacket( int $RequestID, int $Total, int $Number, int $DeclaredSize, int $Checksum, string $Data ) : string
	{
		return "\xFE\xFF\xFF\xFF" . pack( 'V', $RequestID ) . self::Byte( $Total ) . self::Byte( $Number )
			. pack( 'V', $DeclaredSize ) . pack( 'V', $Checksum ) . $Data;
	}

	/**
	 * A bzip2 compressed split reply as Source 2006 era servers send it: bit 31 of
	 * the request id flags the compression, fragment 0 carries the decompressed
	 * size and the crc32, and there is no int16 size field anywhere.
	 *
	 * @return array<int, string>
	 */
	public static function CompressedSplitPackets( string $Payload, int $Count = 1, int $RequestID = 0x80001650 ) : array
	{
		$Compressed = self::Compress( $Payload );
		$Fragments  = [];

		foreach( self::ChunksByCount( $Compressed, $Count ) as $Number => $Chunk )
		{
			$Fragments[] = $Number === 0
				? self::CompressedSplitPacket( $RequestID, $Count, 0, strlen( $Payload ), crc32( $Payload ), $Chunk )
				: "\xFE\xFF\xFF\xFF" . pack( 'V', $RequestID ) . self::Byte( $Count ) . self::Byte( $Number ) . $Chunk;
		}

		return $Fragments;
	}

	/** bzip2 compresses a payload; the extension is required by the caller. */
	public static function Compress( string $Payload ) : string
	{
		$Compressed = bzcompress( $Payload );

		if( !is_string( $Compressed ) )
		{
			throw new \RuntimeException( 'bzcompress( ) failed with error ' . var_export( $Compressed, true ) );
		}

		return $Compressed;
	}

	/**
	 * One GoldSource console redirect datagram, with the two terminating null
	 * bytes the engine writes.
	 */
	public static function PrintReply( string $Text ) : string
	{
		return self::A2SReply( SourceQuery::S2A_RCON, $Text . "\0\0" );
	}

	/** The answer to 'challenge rcon': the echoed request and the challenge number. */
	public static function RconChallengeReply( string $Number ) : string
	{
		return "\xFF\xFF\xFF\xFF" . 'challenge rcon ' . $Number . "\n\0";
	}

	/**
	 * @param array<int, string> $Chunks
	 *
	 * @return array<int, string>
	 */
	private static function SourceFragments( array $Chunks, int $RequestID ) : array
	{
		$Total     = count( $Chunks );
		$Fragments = [];

		foreach( $Chunks as $Number => $Chunk )
		{
			$Fragments[] = self::SplitHeader( $RequestID, $Total, $Number, strlen( $Chunk ) ) . $Chunk;
		}

		return $Fragments;
	}

	/**
	 * GoldSource fragments: one byte holding ( number << 4 ) | total, no size field.
	 *
	 * @param array<int, string> $Chunks
	 *
	 * @return array<int, string>
	 */
	private static function GoldSourceFragments( array $Chunks, int $RequestID ) : array
	{
		$Total     = count( $Chunks );
		$Fragments = [];

		foreach( $Chunks as $Number => $Chunk )
		{
			$Fragments[] = "\xFE\xFF\xFF\xFF" . pack( 'V', $RequestID )
				. self::Byte( ( $Number << 4 ) | ( $Total & 0x0F ) ) . $Chunk;
		}

		return $Fragments;
	}

	/**
	 * @return array<int, string>
	 */
	private static function ChunksOfSize( string $Payload, int $FragmentSize ) : array
	{
		if( $FragmentSize < 1 )
		{
			throw new \InvalidArgumentException( 'FragmentSize must be at least 1.' );
		}

		if( $Payload === '' )
		{
			return [ '' ];
		}

		return str_split( $Payload, $FragmentSize );
	}

	/**
	 * @return array<int, string>
	 */
	private static function ChunksByCount( string $Payload, int $Count ) : array
	{
		if( $Count < 1 )
		{
			throw new \InvalidArgumentException( 'Count must be at least 1.' );
		}

		$Size   = intdiv( strlen( $Payload ), $Count );
		$Chunks = [];

		for( $i = 0; $i < $Count; $i++ )
		{
			$Chunks[] = $i === $Count - 1 ? substr( $Payload, $i * $Size ) : substr( $Payload, $i * $Size, $Size );
		}

		return $Chunks;
	}
}
