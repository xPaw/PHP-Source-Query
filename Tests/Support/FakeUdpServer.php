<?php
declare(strict_types=1);

/**
 * Single process fake A2S game server, for tests that need the real
 * {@see \xPaw\SourceQuery\Socket} with its fread/fwrite behaviour and timeouts.
 *
 * Usage:
 *
 *     $Server = new FakeUdpServer( );
 *     $Socket = new \xPaw\SourceQuery\Socket( );
 *     $Query  = new \xPaw\SourceQuery\SourceQuery( $Socket );
 *     $Query->Connect( $Server->Host( ), $Server->Port( ), 1 );
 *     $Server->Attach( $Socket );                       // learns the client port
 *     $Server->Queue( FakeUdpServer::A2SReply( ... ) ); // lands in the client's rx buffer
 *     $Info = $Query->GetInfo( );
 *     $Requests = $Server->Requests( );                 // what the library wrote
 */

namespace xPaw\SourceQuery\Tests\Support;

use xPaw\SourceQuery\BaseSocket;

class FakeUdpServer
{
	/** @var ?resource */
	private $Server;

	private string $Host;
	private int $Port;

	/** Client address as "ip:port", learned via Attach( ) or from an incoming datagram. */
	private ?string $ClientAddress = null;

	/** @var array<int, string> */
	private array $Received = [];

	/**
	 * @param string $Host '127.0.0.1' (default) or '[::1]' for IPv6.
	 */
	public function __construct( string $Host = '127.0.0.1' )
	{
		$ErrNo  = 0;
		$ErrStr = '';

		$Server = @stream_socket_server( 'udp://' . $Host . ':0', $ErrNo, $ErrStr, STREAM_SERVER_BIND );

		if( $Server === false )
		{
			throw new \RuntimeException( 'FakeUdpServer: could not bind udp://' . $Host . ':0 - ' . $ErrStr );
		}

		stream_set_blocking( $Server, false );

		$this->Server = $Server;

		$Name = stream_socket_get_name( $Server, false );

		if( $Name === false )
		{
			throw new \RuntimeException( 'FakeUdpServer: could not resolve the bound address.' );
		}

		$Colon = strrpos( $Name, ':' );

		if( $Colon === false )
		{
			throw new \RuntimeException( 'FakeUdpServer: unexpected bound address "' . $Name . '".' );
		}

		$this->Host = substr( $Name, 0, $Colon );
		$this->Port = (int)substr( $Name, $Colon + 1 );
	}

	public function __destruct( )
	{
		$this->Close( );
	}

	/** Address to hand to SourceQuery::Connect( ), '127.0.0.1' or '[::1]'. */
	public function Host( ) : string
	{
		return $this->Host;
	}

	/** Port this fake server is bound to. */
	public function Port( ) : int
	{
		return $this->Port;
	}

	/**
	 * Learns the client's local address from an already opened socket, so that
	 * datagrams can be pushed to it before the library sends anything.
	 */
	public function Attach( BaseSocket $Socket ) : void
	{
		$Resource = $Socket->Socket;

		if( !is_resource( $Resource ) )
		{
			throw new \RuntimeException( 'FakeUdpServer: the socket is not open, call Connect( ) first.' );
		}

		$Name = stream_socket_get_name( $Resource, false );

		if( $Name === false )
		{
			throw new \RuntimeException( 'FakeUdpServer: could not resolve the client address.' );
		}

		$this->ClientAddress = $Name;
	}

	/**
	 * Sends a datagram to the attached client at once, so it already sits in the
	 * client's receive buffer when the library reads.
	 *
	 * @return int Bytes sent.
	 */
	public function Queue( string $Datagram ) : int
	{
		if( $this->Server === null )
		{
			throw new \RuntimeException( 'FakeUdpServer: server is closed.' );
		}

		if( $this->ClientAddress === null )
		{
			throw new \RuntimeException( 'FakeUdpServer: no client attached, call Attach( $Socket ) first.' );
		}

		$Sent = @stream_socket_sendto( $this->Server, $Datagram, 0, $this->ClientAddress );

		if( $Sent === false || $Sent < 0 )
		{
			throw new \RuntimeException( 'FakeUdpServer: failed to send a datagram to ' . $this->ClientAddress . '.' );
		}

		return $Sent;
	}

	/**
	 * Sends several datagrams in order.
	 *
	 * @param array<int, string> $Datagrams
	 */
	public function QueueMany( array $Datagrams ) : void
	{
		foreach( $Datagrams as $Datagram )
		{
			$this->Queue( $Datagram );
		}
	}

	/**
	 * Every datagram the client has sent so far, non blocking and accumulating
	 * across calls.
	 *
	 * @return array<int, string> Raw datagrams, oldest first.
	 */
	public function Requests( ) : array
	{
		$this->Drain( );

		return $this->Received;
	}

	/**
	 * Blocks until at least $Count datagrams have been received, or the timeout expires.
	 *
	 * @return array<int, string> Everything received so far.
	 */
	public function WaitForRequests( int $Count, float $TimeoutSeconds = 2.0 ) : array
	{
		$Deadline = microtime( true ) + $TimeoutSeconds;

		while( microtime( true ) < $Deadline )
		{
			$this->Drain( );

			if( count( $this->Received ) >= $Count )
			{
				break;
			}

			usleep( 2000 );
		}

		return $this->Received;
	}

	public function Close( ) : void
	{
		if( is_resource( $this->Server ) )
		{
			fclose( $this->Server );
		}

		$this->Server = null;
	}

	/**
	 * A single A2S datagram: 0xFFFFFFFF, the type byte, then the payload.
	 *
	 * @param int $Type Response type byte, e.g. SourceQuery::S2A_INFO_SRC.
	 */
	public static function A2SReply( int $Type, string $Payload = '' ) : string
	{
		return "\xFF\xFF\xFF\xFF" . chr( $Type & 0xFF ) . $Payload;
	}

	/**
	 * An S2A_INFO_SRC (0x49) body without the header and type byte, to be wrapped
	 * with A2SReply( ).
	 */
	public static function InfoPayload( string $HostName = 'Fake Server', int $AppID = 440 ) : string
	{
		return chr( 17 )                    // Protocol
			. $HostName . "\0"              // HostName
			. "de_dust2\0"                  // Map
			. "cstrike\0"                   // ModDir
			. "Counter-Strike\0"            // ModDesc
			. pack( 'v', $AppID )           // AppID
			. chr( 4 )                      // Players
			. chr( 32 )                     // MaxPlayers
			. chr( 0 )                      // Bots
			. 'd'                           // Dedicated
			. 'l'                           // Os
			. chr( 0 )                      // Password
			. chr( 1 )                      // Secure
			. "1.0.0.0\0";                  // Version
	}

	/**
	 * Splits a complete datagram into Source engine fragments: FE FF FF FF, int32
	 * request id, byte total, byte number (0 based), int16 size, fragment bytes.
	 *
	 * @return array<int, string> Fragments in ascending packet-number order.
	 */
	public static function SplitPackets( string $Payload, int $FragmentSize = 32, int $RequestID = 0x11223344 ) : array
	{
		$Chunks = self::Chunk( $Payload, $FragmentSize );
		$Total  = count( $Chunks );
		$Result = [];

		foreach( $Chunks as $Number => $Chunk )
		{
			$Result[] = "\xFE\xFF\xFF\xFF"
				. pack( 'V', $RequestID )
				. chr( $Total & 0xFF )
				. chr( $Number & 0xFF )
				. pack( 'v', strlen( $Chunk ) )
				. $Chunk;
		}

		return $Result;
	}

	/**
	 * GoldSource fragments: FE FF FF FF, int32 request id, one byte holding
	 * ( number << 4 ) | total, then the fragment bytes.
	 *
	 * @return array<int, string> Fragments in ascending packet-number order.
	 */
	public static function SplitPacketsGoldSource( string $Payload, int $FragmentSize = 32, int $RequestID = 0x11223344 ) : array
	{
		$Chunks = self::Chunk( $Payload, $FragmentSize );
		$Total  = count( $Chunks );
		$Result = [];

		foreach( $Chunks as $Number => $Chunk )
		{
			$Result[] = "\xFE\xFF\xFF\xFF"
				. pack( 'V', $RequestID )
				. chr( ( ( $Number << 4 ) & 0xF0 ) | ( $Total & 0x0F ) )
				. $Chunk;
		}

		return $Result;
	}

	/**
	 * @return array<int, string>
	 */
	private static function Chunk( string $Payload, int $FragmentSize ) : array
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

	private function Drain( ) : void
	{
		if( $this->Server === null )
		{
			return;
		}

		do
		{
			$Peer = '';
			$Data = @stream_socket_recvfrom( $this->Server, 65536, 0, $Peer );

			if( $Data === false || $Data === '' )
			{
				break;
			}

			if( $this->ClientAddress === null && is_string( $Peer ) && $Peer !== '' )
			{
				$this->ClientAddress = $Peer;
			}

			$this->Received[] = $Data;
		}
		while( true );
	}
}
