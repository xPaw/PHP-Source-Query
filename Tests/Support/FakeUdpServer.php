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
 *     $Server->Attach( $Socket );                  // learns the client port
 *     $Server->Queue( Packets::A2SReply( ... ) );  // lands in the client's rx buffer
 *     $Info = $Query->GetInfo( );
 *     $Requests = $Server->WaitForRequests( 1 );   // what the library wrote
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
	 * Blocks until at least $Count datagrams have been received, or the timeout expires.
	 *
	 * @return array<int, string> Everything received so far, oldest first.
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
