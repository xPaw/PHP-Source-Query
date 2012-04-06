PHP Source Query
================

### Description
This class was created to query game server which use the Source query protocol, this includes all source games, half-life 1 engine games and Call of Duty: Modern Warfare 3

The class also allows you to query servers using RCON although this only works for half-life 1 and source engine games.

[Minecraft](http://minecraft.net) also uses Source RCON protocol, and this means you can use this class to send commands to your minecraft server while having engine set to source.

##### Protocol specifications can be found over at VDC
* https://developer.valvesoftware.com/wiki/Server_queries
* https://developer.valvesoftware.com/wiki/Source_RCON_Protocol

### Usage
You can find easy examples on how to use it in `View.php`

### Example
```php
<?php
	require 'SourceQuery.class.php';
	
	$Query = new SourceQuery( );
	
	try
	{
		$Query->Connect( 'localhost', 27015, 3, SourceQuery :: GOLDSOURCE );
		
		print_r( $Query->GetInfo( ) );
		print_r( $Query->GetPlayers( ) );
		print_r( $Query->GetRules( ) );
		
		$Query->SetRconPassword( 'this_is_your_leet_rcon_password' );
		echo $Query->Rcon( 'status' );
	}
	catch( SQueryException $e )
	{
		echo $e->getMessage( );
	}
	
	$Query->Disconnect( );
?>
```

### Functions
<table>
	<tr>
		<td>Connect( $Ip, $Port, $Timeout, $Engine )</td>
		<td>Opens connection to a server</td>
	</tr>
	<tr>
		<td>Disconnect( )</td>
		<td>Closes all open connections</td>
	</tr>
	<tr>
		<td>Ping( )</td>
		<td>Ping the server to see if it exists<br><b>Warning:</b> Source engine may not answer to this</td>
	</tr>
	<tr>
		<td>GetInfo( )</td>
		<td>Returns server info in an array</td>
	</tr>
	<tr>
		<td>GetPlayers( )</td>
		<td>Returns players on the server in an array</td>
	</tr>
	<tr>
		<td>GetRules( )</td>
		<td>Returns public rules <i>(cvars)</i> in an array</td>
	</tr>
	<tr>
		<td>SetRconPassword( $Password )</td>
		<td>Sets rcon password for later use with <i>Rcon()</i></td>
	</tr>
	<tr>
		<td>Rcon( $Command )</td>
		<td>Execute rcon command on the server</td>
	</tr>
</table>

### License
> *This work is licensed under a Creative Commons Attribution-NonCommercial-ShareAlike 3.0 Unported License.<br>
> To view a copy of this license, visit http://creativecommons.org/licenses/by-nc-sa/3.0/*
