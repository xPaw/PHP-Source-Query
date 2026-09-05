<?php
declare(strict_types=1);

/**
 * Test double for {@see \xPaw\SourceQuery\BaseSocket}: feeds queued datagrams
 * into the library without touching the network and records everything it wrote.
 */

namespace xPaw\SourceQuery\Tests\Support;

use xPaw\SourceQuery\BaseSocket;
use xPaw\SourceQuery\Buffer;

class TestableSocket extends BaseSocket
{
	/** @var \SplQueue<string> */
	private \SplQueue $PacketQueue;

	/**
	 * Every Write( ) call made by the library, in order.
	 *
	 * @var array<int, array{Header: int, String: string}>
	 */
	public array $Written = [];

	/**
	 * @param bool $TimeoutOnEmptyQueue When true, reading an empty queue behaves like a read
	 *                                  timeout on the real Socket: an empty string reaches
	 *                                  ReadInternal( ), so the library throws
	 *                                  InvalidPacketException( BUFFER_EMPTY ). When false, the
	 *                                  default, a missing datagram is a mistake in the test and
	 *                                  raises a \RuntimeException.
	 */
	public function __construct( private readonly bool $TimeoutOnEmptyQueue = false )
	{
		$this->PacketQueue = new \SplQueue( );
		$this->PacketQueue->setIteratorMode( \SplDoublyLinkedList::IT_MODE_DELETE );
	}

	/** Appends a raw datagram to the queue the library reads from. */
	public function Queue( string $Data ) : void
	{
		$this->PacketQueue->push( $Data );
	}

	/** Number of queued datagrams that have not been consumed yet. */
	public function QueuedCount( ) : int
	{
		return $this->PacketQueue->count( );
	}

	/**
	 * The last Write( ), or null when nothing was written.
	 *
	 * @return ?array{Header: int, String: string}
	 */
	public function LastWritten( ) : ?array
	{
		if( $this->Written === [] )
		{
			return null;
		}

		return $this->Written[ array_key_last( $this->Written ) ];
	}

	public function Close( ) : void
	{
		//
	}

	public function Open( string $Address, int $Port, int $Timeout, int $Engine ) : void
	{
		$this->Timeout = $Timeout;
		$this->Engine  = $Engine;
		$this->Port    = $Port;
		$this->Address = $Address;
	}

	public function Write( int $Header, string $String = '' ) : bool
	{
		$this->Written[] =
		[
			'Header' => $Header,
			'String' => $String,
		];

		return true;
	}

	public function Read( ) : Buffer
	{
		$Buffer = new Buffer( );
		$Buffer->Set( $this->Shift( ) );

		$this->ReadInternal( $Buffer, [ $this, 'Sherlock' ] );

		return $Buffer;
	}

	public function Sherlock( Buffer $Buffer ) : bool
	{
		if( $this->PacketQueue->isEmpty( ) )
		{
			return false;
		}

		$Buffer->Set( $this->PacketQueue->shift( ) );

		return $Buffer->ReadInt32( ) === -2;
	}

	private function Shift( ) : string
	{
		if( $this->PacketQueue->isEmpty( ) && $this->TimeoutOnEmptyQueue )
		{
			return '';
		}

		return $this->PacketQueue->shift( );
	}
}
