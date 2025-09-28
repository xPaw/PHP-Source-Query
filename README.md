# PHP Source Query

[![Packagist Downloads](https://img.shields.io/packagist/dt/xpaw/php-source-query-class.svg)](https://packagist.org/packages/xpaw/php-source-query-class)
[![Packagist Version](https://img.shields.io/packagist/v/xpaw/php-source-query-class.svg)](https://packagist.org/packages/xpaw/php-source-query-class)

This class was created to query game server which use the Source query protocol, this includes all source games, and all the games that implement Steamworks.

The class also allows you to query servers using RCON although this only works for half-life 1 and source engine games.

[Minecraft](http://www.minecraft.net) also uses Source RCON protocol, and this means you can use this class to send commands to your minecraft server while having engine set to Source engine.

**:warning: Do not send me emails if this does not work for you, I will not help you.**

## Requirements
* [Modern PHP version](https://php.net/supported-versions.php)
* 64-bit PHP or [gmp module](https://secure.php.net/manual/en/book.gmp.php)
* Your server must allow UDP connections

## Protocol Specifications
* https://developer.valvesoftware.com/wiki/Server_queries
* https://developer.valvesoftware.com/wiki/Source_RCON_Protocol

## Supported Games
AppID | Game | Query | RCON | Notes
----- | ---- | :---: | :--: | ----
~ | All HL1/HL2 games and mods | :white_check_mark: | :white_check_mark: | 
10 | [Counter-Strike 1.6](http://store.steampowered.com/app/10/) | :white_check_mark: | :white_check_mark: | 
440 | [Team Fortress 2](http://store.steampowered.com/app/440/) | :white_check_mark: | :white_check_mark: | 
550 | [Left 4 Dead 2](http://store.steampowered.com/app/550/) | :white_check_mark: | :white_check_mark: | 
730 | [Counter-Strike 2](http://store.steampowered.com/app/730/) | :white_check_mark: | :white_check_mark: | `host_name_store 1; host_info_show 2; host_players_show 2`
1002 | [Rag Doll Kung Fu](http://store.steampowered.com/app/1002/) | :white_check_mark: | :white_check_mark: | 
2400 | [The Ship](http://store.steampowered.com/app/2400/) | :white_check_mark: | :white_check_mark: | 
4000 | [Garry's Mod](http://store.steampowered.com/app/4000/) | :white_check_mark: | :white_check_mark: | 
17710 | [Nuclear Dawn](http://store.steampowered.com/app/17710/) | :white_check_mark: | :white_check_mark: | 
70000 | [Dino D-Day](http://store.steampowered.com/app/70000/) | :white_check_mark: | :white_check_mark: | 
107410 | [Arma 3](http://store.steampowered.com/app/107410/) | :white_check_mark: | :x: | Add +1 to the server port
115300 | [Call of Duty: Modern Warfare 3](http://store.steampowered.com/app/115300/) | :white_check_mark: | :white_check_mark: | 
162107 | [DeadPoly](https://store.steampowered.com/app/1621070/) | :white_check_mark: | :x: |
211820 | [Starbound](http://store.steampowered.com/app/211820/) | :white_check_mark: | :white_check_mark: | Call `SetUseOldGetChallengeMethod` method after connecting
244850 | [Space Engineers](http://store.steampowered.com/app/244850/) | :white_check_mark: | :x: | Add +1 to the server port
304930 | [Unturned](https://store.steampowered.com/app/304930/) | :white_check_mark: | :x: | Add +1 to the server port
251570 | [7 Days to Die](http://store.steampowered.com/app/251570) | :white_check_mark: | :x: |
252490 | [Rust](http://store.steampowered.com/app/252490/) | :white_check_mark: | :x: |
282440 | [Quake Live](http://store.steampowered.com/app/282440) | :white_check_mark: | :x: | Quake Live uses the ZMQ messaging queue protocol for rcon control.
346110 | [ARK: Survival Evolved](http://store.steampowered.com/app/346110/) | :white_check_mark: | :white_check_mark: | 
~ | [Minecraft](http://www.minecraft.net/) | :x: | :white_check_mark: | 
108600 | [Project: Zomboid](https://store.steampowered.com/app/108600/) | :white_check_mark: | :white_check_mark: 

Open a pull request if you know another game which supports Source Query and/or RCON protocols.

## How to tell if the game supports Source Query Protocol?

Add your server to your favourites in Steam server browser, and if Steam can display information about your server, then the protocol is supported.

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

Also refer to [examples folder](Examples/) to work things out.

## License
    PHP Source Query
    Copyright (C) 2012-2025 Pavel Djundik

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
