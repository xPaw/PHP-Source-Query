<?php
declare(strict_types=1);

/**
 * Base for the tests that drive the library through TestableSocket, so no
 * network is involved.
 */

namespace xPaw\SourceQuery\Tests\Support;

use PHPUnit\Framework\TestCase;
use xPaw\SourceQuery\SourceQuery;

abstract class TestableSocketTestCase extends TestCase
{
	/** Set to true in files where an unanswered read stands for a timeout. */
	protected const bool TimeoutOnEmptyQueue = false;

	protected TestableSocket $Socket;
	protected SourceQuery $SourceQuery;

	public function setUp( ) : void
	{
		$this->Socket = new TestableSocket( TimeoutOnEmptyQueue: static::TimeoutOnEmptyQueue );

		$this->SourceQuery = new SourceQuery( $this->Socket );
		$this->SourceQuery->Connect( '', 2 );
	}

	public function tearDown( ) : void
	{
		$this->SourceQuery->Disconnect( );
	}
}
