<?php
declare(strict_types=1);

/**
 * A scripted FakeRconServer with a SourceQuery connected to it.
 */

namespace xPaw\SourceQuery\Tests\Support;

use xPaw\SourceQuery\SourceQuery;

trait RconServerFixture
{
	/** The password the AuthOk step accepts. */
	private const Password = 'testpassword';

	private FakeRconServer $RconServer;
	private SourceQuery $Query;

	/**
	 * Boots the scripted server and connects, leaving the RCON password unset.
	 *
	 * @param array<int, array<int, array<string, mixed>>>  $Script
	 * @param ?array<int, array<int, array<string, mixed>>> $ByType
	 * @param ?array<int, array<string, mixed>>             $Fallback
	 */
	protected function ConnectQuery( array $Script, ?array $ByType = null, ?array $Fallback = null ) : SourceQuery
	{
		$this->RconServer = new FakeRconServer( );

		$Query = new SourceQuery( );
		$Query->Connect( '127.0.0.1', $this->RconServer->Start( $Script, $ByType, $Fallback ), 2 );

		$this->Query = $Query;

		return $Query;
	}

	/**
	 * The same, with the password set, which the script has to answer with an
	 * {@see FakeRconServer::AuthOk( )} step.
	 *
	 * @param array<int, array<int, array<string, mixed>>>  $Script
	 * @param ?array<int, array<int, array<string, mixed>>> $ByType
	 * @param ?array<int, array<string, mixed>>             $Fallback
	 */
	protected function ConnectAndAuthorize( array $Script, ?array $ByType = null, ?array $Fallback = null ) : SourceQuery
	{
		$Query = $this->ConnectQuery( $Script, $ByType, $Fallback );
		$Query->SetRconPassword( self::Password );

		return $Query;
	}

	public function tearDown( ) : void
	{
		if( isset( $this->Query ) )
		{
			$this->Query->Disconnect( );
		}

		if( isset( $this->RconServer ) )
		{
			$this->RconServer->Stop( );
		}
	}
}
