<?php
	/**
	 * @author Pavel Djundik
	 *
	 * @link https://xpaw.me
	 * @link https://github.com/xPaw/PHP-Source-Query
	 *
	 * @license GNU Lesser General Public License, version 2.1
	 *
	 * @internal
	 */

	namespace xPaw\SourceQuery;
	
	use xPaw\SourceQuery\Exception\InvalidPacketException;
	use xPaw\SourceQuery\Exception\SocketException;

	/**
	 * Class Socket
	 *
	 * @package xPaw\SourceQuery
	 *
	 * @uses xPaw\SourceQuery\Exception\InvalidPacketException
	 * @uses xPaw\SourceQuery\Exception\SocketException
	 */
	class Socket extends BaseSocket
	{
		
public function Close( ) : void
		{
			if( $this->Socket !== null )
			{
				// close stream
				@fclose( $this->Socket );
				$this->Socket = null;
			}
			// if we have an ext-socket resource, close it too
			if (isset($this->SocketResource) && $this->SocketResource !== null) {
				@socket_close($this->SocketResource);
				$this->SocketResource = null;
			}
		}

		
		
public function Open( string $Address, int $Port, int $Timeout, int $Engine ) : void
		{
			$this->Timeout = $Timeout;
			$this->Engine  = $Engine;
			$this->Port    = $Port;
			$this->Address = $Address;

			// Prefer ext-sockets if available
			if (function_exists('socket_create')) {
				$bf = \FILTER_VALIDATE_IP;
				$addr = $Address;
				// create UDP socket (query uses UDP)
				$sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
				if ($sock === false) {
					throw new SocketException('Could not create socket: ' . socket_strerror(socket_last_error()), SocketException::COULD_NOT_CREATE_SOCKET);
				}
				// set non-blocking temporarily to allow connect with timeout handling
				@socket_set_nonblock($sock);
				$connected = @socket_connect($sock, $Address, $Port);
				if ($connected === false) {
					// try blocking connect
					@socket_set_block($sock);
					if (!@socket_connect($sock, $Address, $Port)) {
						$err = socket_last_error($sock);
						socket_close($sock);
						throw new SocketException('Could not connect socket: ' . socket_strerror($err), SocketException::COULD_NOT_CREATE_SOCKET);
					}
				}
				// export to stream for compatibility with existing fread/fwrite usage
				$stream = @socket_export_stream($sock);
				if ($stream === false) {
					socket_close($sock);
					throw new SocketException('Could not export socket to stream.', SocketException::COULD_NOT_CREATE_SOCKET);
				}
				stream_set_blocking($stream, true);
				stream_set_timeout($stream, $Timeout);
				$this->Socket = $stream;
				$this->SocketResource = $sock; // keep ext-socket to close later
			} else {
				// fallback to stream/socket wrapper
				$socket = @fsockopen( 'udp://' . $Address, $Port, $ErrNo, $ErrStr, $Timeout );
				if( $ErrNo || $socket === false )
				{
					throw new SocketException( 'Could not create socket: ' . $ErrStr, SocketException::COULD_NOT_CREATE_SOCKET );
				}
				$this->Socket = $socket;
				stream_set_timeout( $this->Socket, $Timeout );
			}
		}

		
		
public function Write( string $Data ) : void
		{
			if( !is_resource( $this->Socket ) )
			{
				throw new SocketException( 'Socket is not connected.', SocketException::NOT_CONNECTED );
			}

			$len = strlen($Data);
			$written = 0;
			while($written < $len) {
				$w = fwrite($this->Socket, substr($Data, $written));
				if ($w === false) {
					throw new SocketException( 'Could not write to socket.', SocketException::COULD_NOT_WRITE_TO_SOCKET );
				}
				$written += $w;
			}
		}

		
		/**
		 * Reads from socket and returns Buffer.
		 *
		 * @throws InvalidPacketException
		 *
		 * @return Buffer Buffer
		 */
		
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
