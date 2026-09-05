<?php
declare(strict_types=1);

/**
 * Scripted Source RCON TCP server, meant to be launched as a CHILD PROCESS by
 * {@see \xPaw\SourceQuery\Tests\Support\FakeRconServer}. Never require( ) this file.
 *
 * Usage: php rcon-server-process.php <script.json> <requests.jsonl> <port.txt>
 *
 * It binds tcp://127.0.0.1:0, reports the chosen port on stdout and in
 * <port.txt>, accepts a single connection, then loops: read one framed request,
 * append it to <requests.jsonl>, write out the matching script step's responses.
 */

namespace xPaw\SourceQuery\Tests\Support;

/** The child gives up on an idle test rather than outliving the suite. */
const Lifetime = 20.0;

/**
 * @param array<mixed> $Values
 */
function ArgAsString( array $Values, int $Index ) : string
{
	$Value = $Values[ $Index ] ?? null;

	return is_string( $Value ) ? $Value : '';
}

/**
 * @param array<mixed> $Spec
 */
function SpecString( array $Spec, string $Key ) : ?string
{
	$Value = $Spec[ $Key ] ?? null;

	return is_string( $Value ) ? $Value : null;
}

/**
 * @param array<mixed> $Spec
 */
function SpecInt( array $Spec, string $Key ) : ?int
{
	$Value = $Spec[ $Key ] ?? null;

	return is_int( $Value ) ? $Value : null;
}

/**
 * @param array<mixed> $Spec
 */
function SpecBool( array $Spec, string $Key ) : bool
{
	return ( $Spec[ $Key ] ?? null ) === true;
}

function Bail( string $Message ) : never
{
	fwrite( STDERR, 'rcon-server-process: ' . $Message . PHP_EOL );

	exit( 1 );
}

/**
 * Reads exactly $Length bytes, or null on EOF / timeout.
 *
 * @param resource $Client
 */
function ReadExactly( $Client, int $Length ) : ?string
{
	$Data = '';

	while( strlen( $Data ) < $Length )
	{
		$Chunk = fread( $Client, max( 1, $Length - strlen( $Data ) ) );

		if( $Chunk === false || $Chunk === '' )
		{
			return null;
		}

		$Data .= $Chunk;
	}

	return $Data;
}

/**
 * Writes one scripted response. Returns false when the connection was closed.
 *
 * @param resource $Client
 * @param array<mixed> $Spec
 */
function EmitResponse( $Client, array $Spec, int $RequestID ) : bool
{
	$Delay = SpecInt( $Spec, 'delayMs' );

	if( $Delay !== null )
	{
		usleep( $Delay * 1000 );

		return true;
	}

	if( SpecBool( $Spec, 'close' ) )
	{
		fclose( $Client );

		return false;
	}

	$RawHex = SpecString( $Spec, 'rawHex' );

	if( $RawHex !== null )
	{
		$Raw = hex2bin( $RawHex );

		if( $Raw === false )
		{
			Bail( 'invalid rawHex in script' );
		}

		fwrite( $Client, $Raw );

		return true;
	}

	$BodyHex = SpecString( $Spec, 'bodyHex' );

	if( $BodyHex !== null )
	{
		$Body = hex2bin( $BodyHex );

		if( $Body === false )
		{
			Bail( 'invalid bodyHex in script' );
		}
	}
	else
	{
		$Body = SpecString( $Spec, 'body' ) ?? '';
	}

	$IdValue = $Spec[ 'id' ] ?? 'same';
	$Id      = $IdValue === 'same' ? $RequestID : ( SpecInt( $Spec, 'id' ) ?? $RequestID );

	$Type = SpecInt( $Spec, 'type' ) ?? 0;

	$Packet = pack( 'VV', $Id, $Type ) . $Body;

	if( !SpecBool( $Spec, 'raw' ) )
	{
		$Packet .= "\x00\x00";
	}

	$Size = SpecInt( $Spec, 'size' ) ?? strlen( $Packet );

	fwrite( $Client, pack( 'V', $Size ) . $Packet );

	return true;
}

/**
 * @param resource $Client
 * @param array<mixed> $Responses
 */
function EmitAll( $Client, array $Responses, int $RequestID ) : bool
{
	foreach( $Responses as $Spec )
	{
		if( !is_array( $Spec ) )
		{
			continue;
		}

		if( !EmitResponse( $Client, $Spec, $RequestID ) )
		{
			return false;
		}
	}

	return true;
}

$Argv = $_SERVER[ 'argv' ] ?? null;
$Argv = is_array( $Argv ) ? $Argv : [];

$ScriptFile   = ArgAsString( $Argv, 1 );
$RequestsFile = ArgAsString( $Argv, 2 );
$PortFile     = ArgAsString( $Argv, 3 );

$ScriptRaw = @file_get_contents( $ScriptFile );

if( $ScriptRaw === false )
{
	Bail( 'could not read script file "' . $ScriptFile . '"' );
}

$ScriptData = json_decode( $ScriptRaw, true );

if( !is_array( $ScriptData ) )
{
	Bail( 'script file is not valid JSON' );
}

$Steps = $ScriptData[ 'steps' ] ?? [];
$Steps = is_array( $Steps ) ? array_values( $Steps ) : [];

// A request whose type has an entry here is answered from it and does not
// consume a positional step.
$ByType = $ScriptData[ 'byType' ] ?? null;
$ByType = is_array( $ByType ) ? $ByType : [];

$Fallback = $ScriptData[ 'fallback' ] ?? null;
$Fallback = is_array( $Fallback ) ? $Fallback : null;

$ErrNo  = 0;
$ErrStr = '';
$Server = @stream_socket_server( 'tcp://127.0.0.1:0', $ErrNo, $ErrStr );

if( $Server === false )
{
	Bail( 'could not bind tcp://127.0.0.1:0 - ' . $ErrStr );
}

$Name = stream_socket_get_name( $Server, false );

if( $Name === false )
{
	Bail( 'could not resolve the bound address' );
}

$Colon = strrpos( $Name, ':' );
$Port  = $Colon === false ? 0 : (int)substr( $Name, $Colon + 1 );

echo $Port, PHP_EOL;

if( $PortFile !== '' )
{
	file_put_contents( $PortFile, $Port . PHP_EOL );
}

$Deadline = microtime( true ) + Lifetime;
$Client   = @stream_socket_accept( $Server, Lifetime );

if( $Client === false )
{
	Bail( 'no client connected within ' . Lifetime . 's' );
}

stream_set_blocking( $Client, true );
stream_set_timeout( $Client, 5 );

$Open      = true;
$StepIndex = 0;

while( $Open && microtime( true ) < $Deadline )
{
	$Header = ReadExactly( $Client, 4 );

	if( $Header === null )
	{
		break;
	}

	$Unpacked = unpack( 'Vsize', $Header );

	if( $Unpacked === false )
	{
		break;
	}

	$Size = $Unpacked[ 'size' ];

	if( !is_int( $Size ) || $Size < 8 || $Size > 1 << 20 )
	{
		break;
	}

	$Payload = ReadExactly( $Client, $Size );

	if( $Payload === null )
	{
		break;
	}

	$Fields = unpack( 'Vid/Vtype', $Payload );

	if( $Fields === false )
	{
		break;
	}

	$RequestID = $Fields[ 'id' ];
	$Type      = $Fields[ 'type' ];

	if( !is_int( $RequestID ) || !is_int( $Type ) )
	{
		break;
	}

	if( $RequestID > 0x7FFFFFFF )
	{
		$RequestID -= 0x100000000;
	}

	$Rest = substr( $Payload, 8 );
	$Zero = strpos( $Rest, "\x00" );
	$Body = $Zero === false ? $Rest : substr( $Rest, 0, $Zero );

	if( $RequestsFile !== '' )
	{
		$Line = json_encode(
		[
			'size' => $Size,
			'id'   => $RequestID,
			'type' => $Type,
			'body' => $Body,
			'raw'  => bin2hex( $Payload ),
		] );

		if( $Line !== false )
		{
			file_put_contents( $RequestsFile, $Line . PHP_EOL, FILE_APPEND );
		}
	}

	$TypeStep = $ByType[ $Type ] ?? null;

	if( is_array( $TypeStep ) )
	{
		$Open = EmitAll( $Client, $TypeStep, $RequestID );

		continue;
	}

	$Step = $Steps[ $StepIndex ] ?? null;
	$StepIndex++;

	if( is_array( $Step ) )
	{
		$Open = EmitAll( $Client, $Step, $RequestID );
	}
	else if( $Fallback !== null )
	{
		$Open = EmitAll( $Client, $Fallback, $RequestID );
	}
}

if( $Open && is_resource( $Client ) )
{
	fclose( $Client );
}

fclose( $Server );
