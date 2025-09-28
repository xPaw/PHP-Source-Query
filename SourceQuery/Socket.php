<?php
/**
 * This file is part of Source Query
 *
 * Source Query is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Source Query is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with Source Query.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace xPaw\SourceQuery;

use xPaw\SourceQuery\Exception\SocketException;

class Socket extends BaseSocket
{
    public $Socket;
    public $Timeout;
    public $Engine;
    public $Address;
    public $Port;

    public function Open( string $Address, int $Port, int $Timeout, int $Engine ) : void
    {
        $this->Timeout = $Timeout;
        $this->Engine  = $Engine;
        $this->Port    = $Port;
        $this->Address = $Address;

        if (function_exists('socket_create')) {
            $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($sock === false) {
                throw new SocketException('Could not create socket: ' . socket_strerror(socket_last_error()), SocketException::COULD_NOT_CREATE_SOCKET);
            }
            @socket_set_nonblock($sock);
            $connected = @socket_connect($sock, $Address, $Port);
            if ($connected === false) {
                @socket_set_block($sock);
                if (!@socket_connect($sock, $Address, $Port)) {
                    $err = socket_last_error($sock);
                    socket_close($sock);
                    throw new SocketException('Could not connect socket: ' . socket_strerror($err), SocketException::COULD_NOT_CREATE_SOCKET);
                }
            }
            $stream = @socket_export_stream($sock);
            if ($stream === false) {
                socket_close($sock);
                throw new SocketException('Could not export socket to stream.', SocketException::COULD_NOT_CREATE_SOCKET);
            }
            stream_set_blocking($stream, true);
            stream_set_timeout($stream, $Timeout);
            $this->Socket = $stream;
            $this->SocketResource = $sock;
        } else {
            $socket = @fsockopen('udp://' . $Address, $Port, $ErrNo, $ErrStr, $Timeout);
            if ($ErrNo || $socket === false) {
                throw new SocketException('Could not create socket: ' . $ErrStr, SocketException::COULD_NOT_CREATE_SOCKET);
            }
            $this->Socket = $socket;
            stream_set_timeout($this->Socket, $Timeout);
        }
    }

    public function Close( ) : void
    {
        if( $this->Socket !== null )
        {
            @fclose( $this->Socket );
            $this->Socket = null;
        }
        if (isset($this->SocketResource) && $this->SocketResource !== null) {
            @socket_close($this->SocketResource);
            $this->SocketResource = null;
        }
    }

    public function Read( int $Length = 1400 ) : Buffer
    {
        $Buffer = new Buffer( );
        $data = '';
        if (is_resource($this->Socket)) {
            $data = fread( $this->Socket, $Length );
        } else {
            $data = '';
        }

        $Buffer->Set( $data );
        $this->ReadInternal( $Buffer, $Length, [ $this, 'Sherlock' ] );

        return $Buffer;
    }

    public function Write( int $Header, string $String = '' ) : bool
    {
        if( !is_resource( $this->Socket ) )
        {
            throw new SocketException( 'Socket is not connected.', SocketException::NOT_CONNECTED );
        }

        $Data = chr( $Header ) . $String;

        $len = strlen($Data);
        $written = 0;
        while($written < $len) {
            $w = fwrite($this->Socket, substr($Data, $written));
            if ($w === false) {
                throw new SocketException( 'Could not write to socket.', SocketException::COULD_NOT_WRITE_TO_SOCKET );
            }
            $written += $w;
        }

        return true;
    }

    public function Sherlock( Buffer $Buffer, int $Length ) : bool
    {
        $Data = '';
        if (is_resource($this->Socket)) {
            $Data = fread( $this->Socket, $Length );
        }

        if( strlen( $Data ) < 4 )
        {
            return false;
        }

        $Buffer->Set( $Data );

        return $Buffer->GetLong( ) === -2;
    }
}
