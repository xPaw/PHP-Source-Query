<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use xPaw\SourceQuery\SourceQueryFactory;

$timer = microtime(true);

$query = SourceQueryFactory::createSourceQuery();

$info = [];
$rules = [];
$players = [];
$exception = null;

try {
    $query->connect('localhost', 27015);
    //$query->setUseOldGetChallengeMethod(true); // Use this when players/rules retrieval fails on games like Starbound.

    $info = $query->getInfo();
    $players = $query->getPlayers();
    $rules = $query->getRules();
} catch (Exception $e) {
    $exception = $e;
} finally {
    $query->disconnect();
}

$timer = number_format(microtime(true) - $timer, 4, '.', '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>Source Query PHP Library</title>

	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css">
	<style>
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
<?php if (null !== $exception) { ?>
		<div class="panel panel-error">
			<pre class="panel-body"><?php echo htmlspecialchars($exception->__toString()); ?></pre>
		</div>
<?php } ?>
		<div class="row">
			<div class="col-sm-6">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th class="info-column">Server Info</th>
							<th><span class="label label-<?php echo $timer > 1.0 ? 'danger' : 'success'; ?>"><?php echo $timer; ?>s</span></th>
						</tr>
					</thead>
					<tbody>
<?php if (!empty($info)) { ?>
<?php foreach ($info as $infoKey => $infoValue) { ?>
						<tr>
							<td><?php echo htmlspecialchars($infoKey); ?></td>
							<td><?php
    if (is_array($infoValue)) {
        echo '<pre>';
        print_r($infoValue);
        echo '</pre>';
    } elseif (true === $infoValue) {
        echo 'true';
    } elseif (false === $infoValue) {
        echo 'false';
    } elseif (is_int($infoValue)) {
        echo $infoValue;
    } else {
        echo htmlspecialchars($infoValue);
    }
?></td>
						</tr>
<?php } ?>
<?php } else { ?>
						<tr>
							<td colspan="2">No information received</td>
						</tr>
<?php } ?>
					</tbody>
				</table>
			</div>
			<div class="col-sm-6">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th>Player <span class="label label-info"><?php echo count($players); ?></span></th>
							<th class="frags-column">Frags</th>
							<th class="frags-column">Time</th>
						</tr>
					</thead>
					<tbody>
<?php if (!empty($players)) { ?>
<?php foreach ($players as $player) { ?>
						<tr>
							<td><?php echo htmlspecialchars($player['Name']); ?></td>
							<td><?php echo $player['Frags']; ?></td>
							<td><?php echo $player['TimeF']; ?></td>
						</tr>
<?php } ?>
<?php } else { ?>
						<tr>
							<td colspan="3">No players received</td>
						</tr>
<?php } ?>
					</tbody>
				</table>
			</div>
		</div>
		<div class="row">
			<div class="col-sm-12">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th colspan="2">Rules <span class="label label-info"><?php echo count($rules); ?></span></th>
						</tr>
					</thead>
					<tbody>
<?php if (!empty($rules)) { ?>
<?php foreach ($rules as $ruleKey => $ruleValue) { ?>
						<tr>
							<td><?php echo htmlspecialchars($ruleKey); ?></td>
							<td><?php echo htmlspecialchars($ruleValue); ?></td>
						</tr>
<?php } ?>
<?php } else { ?>
						<tr>
							<td colspan="2">No rules received</td>
						</tr>
<?php } ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</body>
</html>
