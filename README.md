# PHP Source Query

## Description
This class was created to query game server which use the Source query protocol, this includes all source games, half-life 1 engine games and Call of Duty: Modern Warfare 3

The class also allows you to query servers using RCON although this only works for half-life 1 and source engine games.

[Minecraft](http://www.minecraft.net) also uses Source RCON protocol, and this means you can use this class to send commands to your minecraft server while having engine set to source.

### Protocol specifications can be found over at VDC
* https://developer.valvesoftware.com/wiki/Server_queries
* https://developer.valvesoftware.com/wiki/Source_RCON_Protocol

## Supported Games
* All multiplayer games released by Valve: *[Counter-Strike 1.6](http://store.steampowered.com/app/10/), [Counter-Strike: Global Offensive](http://store.steampowered.com/app/730/), [Team Fortress 2](http://store.steampowered.com/app/440/), etc...*
* [Rag Doll Kung Fu](http://store.steampowered.com/app/1002/)
* [The Ship](http://store.steampowered.com/app/2400/)
* [Dino D-Day](http://store.steampowered.com/app/70000/)
* [Nuclear Dawn](http://store.steampowered.com/app/17710/)
* [Just Cause 2: Multiplayer Mod](http://store.steampowered.com/app/259080/)
* [Call of Duty: Modern Warfare 3](http://store.steampowered.com/app/115300/)
* [Minecraft](http://www.minecraft.net/) **(RCON ONLY!)**
* *and many other games that implement Source Query Protocol*

## Functions
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

## License
> *This work is licensed under a Creative Commons Attribution-NonCommercial-ShareAlike 3.0 Unported License.<br>
> To view a copy of this license, visit http://creativecommons.org/licenses/by-nc-sa/3.0/*
