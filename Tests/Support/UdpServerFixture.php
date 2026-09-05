<?php
declare(strict_types=1);

/**
 * A FakeUdpServer on loopback with the real {@see \xPaw\SourceQuery\Socket}
 * connected to it, for the tests that need fread/fwrite and stream timeouts.
 */

namespace xPaw\SourceQuery\Tests\Support;

use xPaw\SourceQuery\Socket;
use xPaw\SourceQuery\SourceQuery;

trait UdpServerFixture
{
	private FakeUdpServer $UdpServer;
	private Socket $RealSocket;
	private SourceQuery $Query;

	/** Binds the fake server and opens a real socket against it. */
	protected function OpenSocket( int $Engine = SourceQuery::SOURCE, string $Host = '127.0.0.1' ) : Socket
	{
		$this->UdpServer  = new FakeUdpServer( $Host );
		$this->RealSocket = new Socket( );

		$this->RealSocket->Open( $this->UdpServer->Host( ), $this->UdpServer->Port( ), 1, $Engine );
		$this->UdpServer->Attach( $this->RealSocket );

		return $this->RealSocket;
	}

	/** Binds the fake server and connects a SourceQuery to it over a real socket. */
	protected function ConnectQuery( int $Engine = SourceQuery::SOURCE, string $Host = '127.0.0.1' ) : SourceQuery
	{
		$this->UdpServer  = new FakeUdpServer( $Host );
		$this->RealSocket = new Socket( );
		$this->Query      = new SourceQuery( $this->RealSocket );

		$this->Query->Connect( $this->UdpServer->Host( ), $this->UdpServer->Port( ), 1, $Engine );
		$this->UdpServer->Attach( $this->RealSocket );

		return $this->Query;
	}

	/** Pushes datagrams into the client's receive buffer, in order. */
	protected function Queue( string ...$Datagrams ) : void
	{
		foreach( $Datagrams as $Datagram )
		{
			$this->UdpServer->Queue( $Datagram );
		}
	}

	/** Cuts the read timeout, so a test that waits for one takes a tenth of a second. */
	protected function ShortenReadTimeout( ) : void
	{
		$Resource = $this->RealSocket->Socket;

		if( is_resource( $Resource ) )
		{
			stream_set_timeout( $Resource, 0, 100000 );
		}
	}

	public function tearDown( ) : void
	{
		if( isset( $this->Query ) )
		{
			$this->Query->Disconnect( );
		}

		if( isset( $this->RealSocket ) )
		{
			$this->RealSocket->Close( );
		}

		if( isset( $this->UdpServer ) )
		{
			$this->UdpServer->Close( );
		}
	}
}
