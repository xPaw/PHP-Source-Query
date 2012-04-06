<?php
	require __DIR__ . '/SourceQuery.class.php';
	
	// Edit this ->
	define( 'SQ_SERVER_ADDR', 'tf.animuservers.ru' );
	define( 'SQ_SERVER_PORT', 27015 );
	define( 'SQ_TIMEOUT',     1 );
	define( 'SQ_ENGINE',      SourceQuery :: SOURCE );
	// Edit this <-
	
	$Timer = MicroTime( True );
	$Query = new SourceQuery( );
	
	$Info    = Array( );
	$Rules   = Array( );
	$Players = Array( );
	
	try
	{
		$Query->Connect( SQ_SERVER_ADDR, SQ_SERVER_PORT, SQ_TIMEOUT, SQ_ENGINE );
		
		$Info    = $Query->GetInfo( );
		$Players = $Query->GetPlayers( );
		$Rules   = $Query->GetRules( );
	}
	catch( SourceQueryException $e )
	{
		$Error = $e->getMessage( );
	}
	
	$Query->Disconnect( );
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>Source Query PHP Class</title>
	
	<link rel="stylesheet" href="http://twitter.github.com/bootstrap/assets/css/bootstrap.css">
	<style type="text/css">
		footer {
			margin-top: 45px;
			padding: 35px 0 36px;
			border-top: 1px solid #e5e5e5;
		}
		footer p {
			margin-bottom: 0;
			color: #555;
		}
	</style>
</head>

<body>
    <div class="container">
    	<div class="page-header">
			<h1>Source Query PHP Class</h1>
		</div>

<?php if( isset( $Error ) ): ?>
		<div class="alert alert-info">
			<h4 class="alert-heading">Exception:</h4>
			<?php echo htmlspecialchars( $Error ); ?>
		</div>
<?php else: ?>
		<div class="row">
			<div class="span6">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th colspan="2">Server info</th>
						</tr>
					</thead>
					<tbody>
<?php foreach( $Info as $InfoKey => $InfoValue ): ?>
						<tr>
							<td><?php echo htmlspecialchars( $InfoKey ); ?></td>
							<td><?php
	if( Is_Array( $InfoValue ) )
	{
		echo "<pre>";
		print_r( $InfoValue );
		echo "</pre>";
	}
	else
	{
		echo htmlspecialchars( $InfoValue );
	}
?></td>
						</tr>
<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="span6">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th>Players</th>
						</tr>
					</thead>
					<tbody>
<?php if( !Empty( $Players ) ): ?>
<?php foreach( $Players as $Player ): ?>
						<tr>
							<td><?php echo htmlspecialchars( $Player[ 'Name' ] ); ?></td>
						</tr>
<?php endforeach; ?>
<?php else: ?>
						<tr>
							<td>No players in da house!</td>
						</tr>
<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<div class="row">
			<div class="span12">
				<table class="table table-condensed table-bordered table-striped">
					<thead>
						<tr>
							<th colspan="2">Rules</th>
						</tr>
					</thead>
					<tbody>
<?php foreach( $Rules as $Rule => $Value ): ?>
						<tr>
							<td><?php echo htmlspecialchars( $Rule ); ?></td>
							<td><?php echo htmlspecialchars( $Value ); ?></td>
						</tr>
<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
<?php endif; ?>
		<footer>
			<p class="pull-right">Generated in <span class="badge badge-success"><?php echo Number_Format( ( MicroTime( True ) - $Timer ), 4, '.', '' ); ?>s</span></p>
			
			<p>Written by <a href="http://xpaw.ru" target="_blank">xPaw</a></p>
			<p>Code licensed under the <a href="http://creativecommons.org/licenses/by-nc-sa/3.0/" target="_blank">CC BY-NC-SA 3.0</a></p>
			<p>Sourcecode available on <a href="https://github.com/xPaw/PHP-Source-Query-Class" target="_blank">GitHub</a></p>
		</footer>
	</div>
</body>
</html>
