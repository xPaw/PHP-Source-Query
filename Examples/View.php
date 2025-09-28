<?php
	declare(strict_types=1);

	require __DIR__ . '/../vendor/autoload.php';

	use xPaw\SourceQuery\SourceQuery;

	// Edit this ->
	define( 'SQ_SERVER_ADDR', 'localhost' );
	define( 'SQ_SERVER_PORT', 27015 );
	define( 'SQ_TIMEOUT',     3 );
	define( 'SQ_ENGINE',      SourceQuery::SOURCE );
	// Edit this <-

	$Timer = microtime( true );

	$Query = new SourceQuery( );

	$Info    = null;
	$Rules   = [];
	$Players = [];
	$Exception = null;

	try
	{
		$Query->Connect( SQ_SERVER_ADDR, SQ_SERVER_PORT, SQ_TIMEOUT, SQ_ENGINE );
		//$Query->SetUseOldGetChallengeMethod( true ); // Use this when players/rules retrieval fails on games like Starbound

		$Info    = $Query->GetInfo( );
		$Players = $Query->GetPlayers( );
		$Rules   = $Query->GetRules( );
	}
	catch( Exception $e )
	{
		$Exception = $e;
	}
	finally
	{
		$Query->Disconnect( );
	}

	$Timer = number_format( microtime( true ) - $Timer, 4, '.', '' );
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title>Source Query PHP Library</title>

	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css">
	<style type="text/css">
		.table {
			table-layout: fixed;
			border-top-color: #428BCA;
		}

		.table td {
			overflow-x: auto;
		}

		.table thead th {
			background-color: #428BCA;
			border-color: #428BCA !important;
			color: #FFF;
		}

		.info-column {
			width: 120px;
		}

		.frags-column {
			width: 80px;
		}
	</style>
</head>

<body>
	<div class="jumbotron">
		<div class="container">
			<h1>Source Query PHP Library</h1>

			<p class="lead">This library was created to query game server which use the Source (Steamworks) query protocol.</p>

			<p>
				<a class="btn btn-large btn-primary" href="https://xpaw.me">Made by xPaw</a>
				<a class="btn btn-large btn-primary" href="https://github.com/xPaw/PHP-Source-Query">View on GitHub</a>
				<a class="btn btn-large btn-danger" href="https://github.com/xPaw/PHP-Source-Query/blob/master/LICENSE">LGPL v2.1</a>
			</p>
		</div>
	</div>

	<div class="container">
<?php if( $Exception !== null ): ?>
		<div class="panel panel-error">
			<pre class="panel-body"><?php echo htmlspecialchars( $Exception->__toString( ) ); ?></pre>
		</div>
<?php endif; ?>
		<div class="row">
			<div class="col-sm-6">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th class="info-column">Server Info</th>
							<th><span class="label label-<?php echo $Timer > 1.0 ? 'danger' : 'success'; ?>"><?php echo $Timer; ?>s</span></th>
						</tr>
					</thead>
					<tbody>
<?php if( $Info !== null ): ?>
<?php foreach( $Info as $InfoKey => $InfoValue ): ?>
						<tr>
							<td><?php echo htmlspecialchars( $InfoKey ); ?></td>
							<td><?php
	if( is_array( $InfoValue ) )
	{
		echo "<pre>";
		print_r( $InfoValue );
		echo "</pre>";
	}
	else
	{
		if( $InfoValue === true )
		{
			echo 'true';
		}
		else if( $InfoValue === false )
		{
			echo 'false';
		}
		else
		{
			echo htmlspecialchars( (string)$InfoValue );
		}
	}
?></td>
						</tr>
<?php endforeach; ?>
<?php else: ?>
						<tr>
							<td colspan="2">No information received</td>
						</tr>
<?php endif; ?>
					</tbody>
				</table>
			</div>
			<div class="col-sm-6">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th>Player <span class="label label-info"><?php echo count( $Players ); ?></span></th>
							<th class="frags-column">Frags</th>
							<th class="frags-column">Time</th>
						</tr>
					</thead>
					<tbody>
<?php if( count( $Players ) > 0 ): ?>
<?php foreach( $Players as $Player ): ?>
						<tr>
							<td><?php echo htmlspecialchars( $Player[ 'Name' ] ); ?></td>
							<td><?php echo $Player[ 'Frags' ]; ?></td>
							<td><?php echo $Player[ 'TimeF' ]; ?></td>
						</tr>
<?php endforeach; ?>
<?php else: ?>
						<tr>
							<td colspan="3">No players received</td>
						</tr>
<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<div class="row">
			<div class="col-sm-12">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th colspan="2">Rules <span class="label label-info"><?php echo count( $Rules ); ?></span></th>
						</tr>
					</thead>
					<tbody>
<?php if( count( $Rules ) > 0 ): ?>
<?php foreach( $Rules as $Rule => $Value ): ?>
						<tr>
							<td><?php echo htmlspecialchars( $Rule ); ?></td>
							<td><?php echo htmlspecialchars( $Value ); ?></td>
						</tr>
<?php endforeach; ?>
<?php else: ?>
						<tr>
							<td colspan="2">No rules received</td>
						</tr>
<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</body>
</html>
