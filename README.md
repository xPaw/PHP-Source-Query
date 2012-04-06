PHP SourceQuery
===============

### Description

This class was created to query servers that use 'Source Query' protocol, for games like HL1, HL2, The Ship, SiN 1, Rag Doll Kung Fu and more.

This also works for **Call of Duty: Modern Warfare 3**, as it uses Source Query protocol as well.

You can find protocol specifications here: http://developer.valvesoftware.com/wiki/Server_queries

### Usage

You can find easy examples on how to use it in `Test.php` file, or just run `view.php`

### Functions

Open connection to a server: `Connect( $IP, $Port );`

Close connection: `Disconnect( );`

Set rcon password for future use: `SetRconPassword( $RconPassword );`

Execute Rcon command: `Rcon( $Command );`

### License

> *This work is licensed under a Creative Commons Attribution-NonCommercial-ShareAlike 3.0 Unported License.<br>
> To view a copy of this license, visit http://creativecommons.org/licenses/by-nc-sa/3.0/*