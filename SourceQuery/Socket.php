<?php
/**
 * Socket.php - compatibility fixes for PHP 8.4 (typed properties)
 *
 * Adapted to prefer ext-sockets (socket_create/socket_connect) when available,
 * and fall back to stream sockets (fsockopen) otherwise. Exports ext-sockets
 * to a stream with socket_export_stream so existing fread/fwrite based code
 * continues to work.
 */

namespace xPaw\SourceQuery;

use xPaw\SourceQuery\Exception\SocketException;

class Socket extends BaseSocket
{
    /** @var resource|null */
    public $Socket;
    public int $Engine;
    public string $Address;
    public int $Port;
    public int $Timeout;

    /** @var resource|null */
    private $SocketResource = null;

    public function Open( string $Address, int $Port, int $Timeout, int $Engine ) : void
    {
        $this->Timeout = $Timeout;
        $this->Engine  = $Engine;
        $this->Port    = $Port;
        $this->Address = $Address;

        // Prefer ext-sockets if available
        if (function_exists('socket_create')) {
            $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($sock === false) {
                throw new SocketException('Could not create socket: ' . socket_strerror(socket_last_error()), SocketException::COULD_NOT_CREATE_SOCKET);
            }

            // Try to connect (for UDP this is optional but simplifies send/receive)
            if (!@socket_connect($sock, $Address, $Port)) {
                // For some environments connecting UDP socket may fail; close and continue with stream fallback
                $err = socket_last_error($sock);
                @socket_close($sock);
                throw new SocketException('Could not connect socket: ' . socket_strerror($err), SocketException::COULD_NOT_CREATE_SOCKET);
            }

            // Export to stream for compatibility with fread/fwrite
            $stream = @socket_export_stream($sock);
            if ($stream === false) {
                @socket_close($sock);
                throw new SocketException('Could not export socket to stream.', SocketException::COULD_NOT_CREATE_SOCKET);
            }

            stream_set_blocking($stream, true);
            @stream_set_timeout($stream, $Timeout);

            $this->Socket = $stream;
            $this->SocketResource = $sock;
        } else {
            // fallback to stream socket (udp://)
            $socket = @fsockopen('udp://' . $Address, $Port, $ErrNo, $ErrStr, $Timeout);
            if ($ErrNo || $socket === false) {
                throw new SocketException('Could not create socket: ' . $ErrStr, SocketException::COULD_NOT_CREATE_SOCKET);
            }
            $this->Socket = $socket;
            @stream_set_timeout($this->Socket, $Timeout);
        }
    }

    public function Close() : void
{
    if( isset($this->Socket) && is_resource($this->Socket) )
    {
        @fclose($this->Socket);
        $this->Socket = null;
    }

    if( isset($this->SocketResource) && is_resource($this->SocketResource) )
    {
        if( get_resource_type($this->SocketResource) === 'Socket' )
        {
            @socket_close($this->SocketResource);
        }
        $this->SocketResource = null;
    }
}

    public function Read( int $Length = 1400 ) : Buffer
    {
        $Buffer = new Buffer();

        $data = '';
        if (is_resource($this->Socket)) {
            $data = @fread($this->Socket, $Length);
            if ($data === false) {
                $data = '';
            }
        }

        $Buffer->Set($data);

        $this->ReadInternal($Buffer, $Length, [$this, 'Sherlock']);

        return $Buffer;
    }

    public function Write( int $Header, string $String = '' ) : bool
    {
        if (!is_resource($this->Socket)) {
            throw new SocketException('Socket is not connected.', SocketException::NOT_CONNECTED);
        }

        // Source engine expects 4-byte header -1 (0xFFFFFFFF) before the packet data.
        // Then a single byte header (eg. 'T' / 0x54) followed by payload.
        $Data = pack('V', -1) . chr($Header) . $String;

        $len = strlen($Data);
        $written = 0;
        while ($written < $len) {
            $w = @fwrite($this->Socket, substr($Data, $written));
            if ($w === false || $w === 0) {
                // Try to detect timeout / error
                $meta = stream_get_meta_data($this->Socket);
                if (isset($meta['timed_out']) && $meta['timed_out']) {
                    throw new SocketException('Write timed out.', SocketException::COULD_NOT_WRITE_TO_SOCKET);
                }
                throw new SocketException('Could not write to socket.', SocketException::COULD_NOT_WRITE_TO_SOCKET);
            }
            $written += $w;
        }

        return true;
    }

    public function Sherlock( Buffer $Buffer, int $Length ) : bool
    {
        $Data = '';
        if (is_resource($this->Socket)) {
            $Data = @fread($this->Socket, $Length);
            if ($Data === false) {
                $Data = '';
            }
        }

        if (strlen($Data) < 4) {
            return false;
        }

        $Buffer->Set($Data);

        return $Buffer->GetLong() === -2;
    }
}
