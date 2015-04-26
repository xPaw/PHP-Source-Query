# PHP Source Query

This class was created to query game server which use the Source query protocol, this includes all source games, and all the games that implement Steamworks.

The class also allows you to query servers using RCON although this only works for half-life 1 and source engine games.

[Minecraft](http://www.minecraft.net) also uses Source RCON protocol, and this means you can use this class to send commands to your minecraft server while having engine set to Source engine.

**This library requires a [modern version](https://php.net/supported-versions.php) of PHP, so make sure you are using at least PHP 5.4 or newer.**

### Protocol specifications can be found over at VDC
* https://developer.valvesoftware.com/wiki/Server_queries
* https://developer.valvesoftware.com/wiki/Source_RCON_Protocol

## Supported Games
* All multiplayer games released by Valve: *[Counter-Strike 1.6](http://store.steampowered.com/app/10/), [Counter-Strike: Global Offensive](http://store.steampowered.com/app/730/), [Team Fortress 2](http://store.steampowered.com/app/440/), etc...*
* [Rag Doll Kung Fu](http://store.steampowered.com/app/1002/)
* [The Ship](http://store.steampowered.com/app/2400/)
* [Dino D-Day](http://store.steampowered.com/app/70000/)
* [Nuclear Dawn](http://store.steampowered.com/app/17710/)
* [Call of Duty: Modern Warfare 3](http://store.steampowered.com/app/115300/)
* [Starbound](http://store.steampowered.com/app/211820/) *(use SetUseOldGetChallengeMethod method after connecting)*
* [Arma 3](http://store.steampowered.com/app/107410/) *(add +1 to the server port, their implementation also violates Source query protocol spec.)*
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

Also refer to [an example](Example.php) to work things out.

## License
    PHP Source Query
    Copyright (C) 2012-2015 xPaw

    This library is free software; you can redistribute it and/or
    modify it under the terms of the GNU Lesser General Public
    License as published by the Free Software Foundation; either
    version 2.1 of the License, or (at your option) any later version.

    This library is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
    Lesser General Public License for more details.

    You should have received a copy of the GNU Lesser General Public
    License along with this library; if not, write to the Free Software
    Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
