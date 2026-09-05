<?php
declare(strict_types=1);

/**
 * Drives a scripted Source RCON TCP server that runs in a child process.
 *
 * The child process is required because SourceRcon::Open( ) + Authorize( )
 * connect, write and then block on a read inside one library call, so a
 * same-process fake server would never get a chance to answer.
 *
 * Usage:
 *
 *     $Server = new FakeRconServer( );
 *     $Port   = $Server->Start(
 *     [
 *         [ [ 'type' => 2, 'id' => 'same' ] ],                          // step 0: the AUTH request
 *         [ [ 'type' => 0, 'id' => 'same', 'body' => 'hello world' ] ], // step 1: the first command
 *     ] );
 *
 *     $Query = new \xPaw\SourceQuery\SourceQuery( );
 *     $Query->Connect( '127.0.0.1', $Port, 2 );   // UDP connect never fails locally
 *     $Query->SetRconPassword( 'pw' );
 *     self::assertSame( 'hello world', $Query->Rcon( 'status' ) );
 *
 *     $Server->Stop( ); // always in tearDown( )
 */

namespace xPaw\SourceQuery\Tests\Support;

class FakeRconServer
{
	/** @var ?resource */
	private $Process = null;

	/** @var array<int, resource> */
	private array $Pipes = [];

	private string $Directory = '';
	private string $RequestsFile = '';
	private int $Port = 0;

	public function __destruct( )
	{
		$this->Stop( );
	}

	/**
	 * Boots the child process and waits until it reports its listening port.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $Script   One entry per received request; each entry is a list of response specs.
	 * @param ?array<int, array<string, mixed>>            $Fallback Responses used for any request beyond the end of $Script.
	 * @param ?array<int, array<string, mixed>>            $Greeting Responses written immediately after the client connects, before any request.
	 * @param ?array<int, array<int, array<string, mixed>>> $ByType  Responses keyed by request type; a matching request is answered from here and does not consume a positional step.
	 *
	 * @return int The TCP port the fake server listens on.
	 */
	public function Start( array $Script, ?array $Fallback = null, ?array $Greeting = null, float $Lifetime = 20.0, ?array $ByType = null ) : int
	{
		if( $this->Process !== null )
		{
			throw new \RuntimeException( 'FakeRconServer: already started.' );
		}

		$Directory = sys_get_temp_dir( ) . DIRECTORY_SEPARATOR . 'php-source-query-rcon-' . bin2hex( random_bytes( 8 ) );

		if( !mkdir( $Directory ) && !is_dir( $Directory ) )
		{
			throw new \RuntimeException( 'FakeRconServer: could not create "' . $Directory . '".' );
		}

		$this->Directory = $Directory;

		$ScriptFile   = $Directory . DIRECTORY_SEPARATOR . 'script.json';
		$PortFile     = $Directory . DIRECTORY_SEPARATOR . 'port.txt';
		$StdOutFile   = $Directory . DIRECTORY_SEPARATOR . 'stdout.txt';
		$StdErrFile   = $Directory . DIRECTORY_SEPARATOR . 'stderr.txt';
		$RequestsFile = $Directory . DIRECTORY_SEPARATOR . 'requests.jsonl';

		$this->RequestsFile = $RequestsFile;

		$Json = json_encode(
		[
			'steps'    => $Script,
			'fallback' => $Fallback,
			'greeting' => $Greeting,
			'byType'   => $ByType,
		] );

		if( $Json === false )
		{
			throw new \RuntimeException( 'FakeRconServer: could not encode the script.' );
		}

		file_put_contents( $ScriptFile, $Json );
		file_put_contents( $RequestsFile, '' );

		$Descriptors =
		[
			0 => [ 'pipe', 'r' ],
			1 => [ 'file', $StdOutFile, 'w' ],
			2 => [ 'file', $StdErrFile, 'w' ],
		];

		$Pipes = [];
		$Process = proc_open(
		[
			PHP_BINARY,
			__DIR__ . DIRECTORY_SEPARATOR . 'rcon-server-process.php',
			$ScriptFile,
			$RequestsFile,
			$PortFile,
			(string)$Lifetime,
		], $Descriptors, $Pipes );

		if( $Process === false )
		{
			throw new \RuntimeException( 'FakeRconServer: proc_open failed.' );
		}

		$this->Process = $Process;
		$this->Pipes   = $Pipes;

		$Deadline = microtime( true ) + 10.0;

		while( microtime( true ) < $Deadline )
		{
			$Contents = @file_get_contents( $PortFile );

			if( is_string( $Contents ) && str_contains( $Contents, "\n" ) )
			{
				$this->Port = (int)trim( $Contents );

				break;
			}

			usleep( 5000 );
		}

		if( $this->Port === 0 )
		{
			$Error = @file_get_contents( $StdErrFile );

			$this->Stop( );

			throw new \RuntimeException( 'FakeRconServer: child process never reported a port. stderr: ' . ( is_string( $Error ) ? $Error : '' ) );
		}

		return $this->Port;
	}

	/** The port returned by Start( ), or 0 when not started. */
	public function Port( ) : int
	{
		return $this->Port;
	}

	/**
	 * Every request the child decoded so far.
	 *
	 * @return array<int, array{size: int, id: int, type: int, body: string, raw: string}>
	 */
	public function Requests( ) : array
	{
		if( $this->RequestsFile === '' )
		{
			return [];
		}

		$Contents = @file_get_contents( $this->RequestsFile );

		if( !is_string( $Contents ) || $Contents === '' )
		{
			return [];
		}

		$Requests = [];

		foreach( explode( "\n", $Contents ) as $Line )
		{
			$Line = trim( $Line );

			if( $Line === '' )
			{
				continue;
			}

			$Decoded = json_decode( $Line, true );

			if( !is_array( $Decoded ) )
			{
				continue;
			}

			$Requests[] =
			[
				'size' => self::AsInt( $Decoded, 'size' ),
				'id'   => self::AsInt( $Decoded, 'id' ),
				'type' => self::AsInt( $Decoded, 'type' ),
				'body' => self::AsString( $Decoded, 'body' ),
				'raw'  => self::AsString( $Decoded, 'raw' ),
			];
		}

		return $Requests;
	}

	/**
	 * Blocks until at least $Count requests have been logged, or the timeout expires.
	 *
	 * @return array<int, array{size: int, id: int, type: int, body: string, raw: string}>
	 */
	public function WaitForRequests( int $Count, float $TimeoutSeconds = 5.0 ) : array
	{
		$Deadline = microtime( true ) + $TimeoutSeconds;
		$Requests = $this->Requests( );

		while( count( $Requests ) < $Count && microtime( true ) < $Deadline )
		{
			usleep( 5000 );

			$Requests = $this->Requests( );
		}

		return $Requests;
	}

	/** Whatever the child wrote to stderr. */
	public function StdErr( ) : string
	{
		if( $this->Directory === '' )
		{
			return '';
		}

		$Contents = @file_get_contents( $this->Directory . DIRECTORY_SEPARATOR . 'stderr.txt' );

		return is_string( $Contents ) ? $Contents : '';
	}

	/** Terminates the child and removes its temporary directory, twice is fine. */
	public function Stop( ) : void
	{
		foreach( $this->Pipes as $Pipe )
		{
			if( is_resource( $Pipe ) )
			{
				fclose( $Pipe );
			}
		}

		$this->Pipes = [];

		if( is_resource( $this->Process ) )
		{
			proc_terminate( $this->Process );
			proc_close( $this->Process );
		}

		$this->Process = null;
		$this->Port    = 0;

		if( $this->Directory !== '' )
		{
			$Files = @scandir( $this->Directory );

			if( is_array( $Files ) )
			{
				foreach( $Files as $File )
				{
					if( $File !== '.' && $File !== '..' )
					{
						@unlink( $this->Directory . DIRECTORY_SEPARATOR . $File );
					}
				}
			}

			@rmdir( $this->Directory );

			$this->Directory    = '';
			$this->RequestsFile = '';
		}
	}

	/** One framed RCON response as raw bytes, for use with the 'rawHex' spec. */
	public static function Frame( int $Id, int $Type, string $Body = '' ) : string
	{
		$Packet = pack( 'VV', $Id, $Type ) . $Body . "\x00\x00";

		return pack( 'V', strlen( $Packet ) ) . $Packet;
	}

	/**
	 * @param array<mixed> $Values
	 */
	private static function AsInt( array $Values, string $Key ) : int
	{
		$Value = $Values[ $Key ] ?? null;

		return is_int( $Value ) ? $Value : 0;
	}

	/**
	 * @param array<mixed> $Values
	 */
	private static function AsString( array $Values, string $Key ) : string
	{
		$Value = $Values[ $Key ] ?? null;

		return is_string( $Value ) ? $Value : '';
	}
}
