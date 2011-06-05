<?php

class Http_Socket_Interface
{
	protected $socket = null;
	
	protected $connected_to = array(null, null);
	
	protected $out_stream = null;
	
	protected $config = array(
        'persistent'		=> false,
        'ssltransport'	=> 'ssl',
        'sslcert'			=> null,
        'sslpassphrase'	=> null,
        'sslusecontext'	=> false,
		  'timeout'			=> '1'
    );
	
	public function __construct()
	{
	}
	
	public function getStreamContext()
	{
		if (! $this->_context) {
			$this->_context = stream_context_create();
		}

		return $this->_context;
	}
	
	public function connect($host, $port = 80, $secure = false)
	{
		// If the URI should be accessed via SSL, prepend the Hostname with ssl://
		$host = ($secure ? $this->config['ssltransport'] : 'tcp') . '://' . $host;

		// If we are connected to the wrong host, disconnect first
		if (($this->connected_to[0] != $host || $this->connected_to[1] != $port)) {
			if (is_resource($this->socket)) $this->close();
		}

		// Now, if we are not connected, connect
		if (! is_resource($this->socket) ) {
			$context = $this->getStreamContext();

			$flags = STREAM_CLIENT_CONNECT;
			if ($this->config['persistent']) $flags |= STREAM_CLIENT_PERSISTENT;

			$this->socket = @stream_socket_client($host . ':' . $port,
					$errno,
					$errstr,
					(int) $this->config['timeout'],
					$flags,
					$context);

			if (! $this->socket) {
				$this->close();
				return "Error, not connected";
			}

			// Set the stream timeout
			if (! stream_set_timeout($this->socket, (int) $this->config['timeout'])) {
				return "Error, stream timed out";
			}

			// Update connected_to
			$this->connected_to = array($host, $port);
		}
	}

	/**
	* Send request to the remote server
	*
	* @param string		$method
	* @param string		$uri
	* @param string		$http_ver
	* @param array			$headers
	* @param string		$body
	* @return string Request as string
	*/
	public function write($method, $uri, $http_ver = '1.1', $headers = array(), $body = '')
	{
		// Make sure we're properly connected
		if (! $this->socket) {
			return "Error, not connected";
		}

		$host = parse_url($uri, PHP_URL_HOST);
		
		if( $http_ver === '1.1' && !isset($headers['Host']) )
			$headers = array_merge(array('Host'=>$host),$headers);
			
		$host = (strtolower(parse_url($uri, PHP_URL_SCHEME)) == 'https' ? $this->config['ssltransport'] : 'tcp') . '://' . $host;
		if ($this->connected_to[0] != $host || (parse_url($uri, PHP_URL_PORT) && $this->connected_to[1] != parse_url($uri, PHP_URL_PORT))) {
			return "Error, attempting to connect to different host";
		}

		// Save request method for later
		$this->method = $method;
		
		// Build request headers
		$path = ($path = parse_url($uri, PHP_URL_PATH)) ? $path : '/';
		if (parse_url($uri, PHP_URL_QUERY)) $path .= '?' . parse_url($uri, PHP_URL_QUERY);
		$request = $method." ".$path." "."HTTP/".$http_ver."\r\n";
		foreach ($headers as $k => $v) {
			if (is_string($k)) $v = ucfirst($k) . ': '.$v;
			$request .= $v."\r\n";
		}

		if(is_resource($body)) {
			$request .= "\r\n";
		} else {
			// Add the request body
			$request .= "\r\n" . $body;
		}
		
		// Send the request
		if (! @fwrite($this->socket, $request)) {
			return "Error writing request to server";
		}

		
		if(is_resource($body)) {
			if(stream_copy_to_stream($body, $this->socket) == 0) {
				return "Error writing request to server";
			}
		}
		
		return $request;
	}

	/**
	* Read response from server
	*
	* @return string
	*/
	public function read($returnArray = false, $trimChunks = false, &$error = '')
	{
		// First, read headers only
		$response = '';
		$gotStatus = false;
		
		while (($line = @fgets($this->socket)) !== false) {
			$gotStatus = $gotStatus || (strpos($line, 'HTTP') !== false);
			if ($gotStatus) {
				$response .= $line;
				if (rtrim($line) === '') break;
			}
		}

		if( $this->_checkSocketReadTimeout() ) {
			$error = "Error, timed out";
			return null;
		}

		$statusCode = $this->extractCode($response);
		
		// Handle 100 and 101 responses internally by restarting the read again
		if ($statusCode == 100 || $statusCode == 101) return $this->read();

		// Check headers to see what kind of connection / transfer encoding we have
		$headers = $this->extractHeaders($response);

		/**
		* Responses to HEAD requests and 204 or 304 responses are not expected
		* to have a body - stop reading here
		*/
		if ($statusCode == 304 || $statusCode == 204 ||
				$this->method == 'HEAD') {

			// Close the connection if requested to do so by the server
			if (isset($headers['connection']) && $headers['connection'] == 'close') {
				$this->close();
			}
			return ($returnArray) ? array('headers'=>$headers) : $response;
		}

		// If we got a 'transfer-encoding: chunked' header
		if (isset($headers['transfer-encoding'])) {
			
			if (strtolower($headers['transfer-encoding']) == 'chunked') {
				
				do {
					$line  = @fgets($this->socket);
					$this->_checkSocketReadTimeout();

					$chunk = ($trimChunks) ? '' : $line;

					// Figure out the next chunk size
					$chunksize = trim($line);
					if (! ctype_xdigit($chunksize)) {
						$this->close();
						$error = "Error, chunksize error.";
						return null;
					}

					// Convert the hexadecimal value to plain integer
					$chunksize = hexdec($chunksize);

					// Read next chunk
					$read_to = ftell($this->socket) + $chunksize;

					do {
						$current_pos = ftell($this->socket);
						if ($current_pos >= $read_to) break;

						if($this->out_stream) {
							if(stream_copy_to_stream($this->socket, $this->out_stream, $read_to - $current_pos) == 0) {
								$this->_checkSocketReadTimeout();
								break;   
							}
						} else {
							$line = @fread($this->socket, $read_to - $current_pos);
							if ($line === false || strlen($line) === 0) {
								$this->_checkSocketReadTimeout();
								break;
							}
							$chunk .= $line;
						}
					} while (! feof($this->socket));

					$chunk .= @fgets($this->socket);
					$this->_checkSocketReadTimeout();

					if(!$this->out_stream) {
						$response .= ($trimChunks) ? trim($chunk) : $chunk;
					}
				} while ($chunksize > 0);
			} else {
				$this->close();
				$error = "Unsupported transfer encoding";
				return null;
			}
		// Else, if we got the content-length header, read this number of bytes
		} elseif (isset($headers['content-length'])) {

			// If we got more than one Content-Length header (see ZF-9404) use
			// the last value sent
			if (is_array($headers['content-length'])) {
				$contentLength = $headers['content-length'][count($headers['content-length']) - 1]; 
			} else {
				$contentLength = $headers['content-length'];
			}
			
			$current_pos = ftell($this->socket);
			$chunk = '';

			for ($read_to = $current_pos + $contentLength;
				$read_to > $current_pos;
				$current_pos = ftell($this->socket)) {

				if($this->out_stream) {
					if(@stream_copy_to_stream($this->socket, $this->out_stream, $read_to - $current_pos) == 0) {
						$this->_checkSocketReadTimeout();
						break;   
					}
				} else {
					$chunk = @fread($this->socket, $read_to - $current_pos);
					if ($chunk === false || strlen($chunk) === 0) {
						$this->_checkSocketReadTimeout();
						break;
					}

					$response .= $chunk;
				}

				// Break if the connection ended prematurely
				if (feof($this->socket)) break;
			}

		// Fallback: just read the response until EOF
		} else {

			do {
				if($this->out_stream) {
					if(@stream_copy_to_stream($this->socket, $this->out_stream) == 0) {
						$this->_checkSocketReadTimeout();
						break;   
					}
				 } else {
					$buff = @fread($this->socket, 8192);
					if ($buff === false || strlen($buff) === 0) {
						$this->_checkSocketReadTimeout();
						break;
					} else {
						$response .= $buff;
					}
				}

			} while (feof($this->socket) === false);

			$this->close();
		}

		// Close the connection if requested to do so by the server
		if (isset($headers['connection']) && $headers['connection'] == 'close') {
			$this->close();
		}
		
		return ($returnArray) ? array('headers'=>$headers, 'body'=>$this->extractBody($response)) : $response;
	}

	/**
	* Close the connection to the server
	*
	*/
	public function close()
	{
		if (is_resource($this->socket)) @fclose($this->socket);
		$this->socket = null;
		$this->connected_to = array(null, null);
	}

	protected function _checkSocketReadTimeout()
	{
		if ($this->socket) {
			$info = stream_get_meta_data($this->socket);
			$timedout = $info['timed_out'];
			if ($timedout) {
				$this->close();
				return true;
			}
		}
	}
	
	public static function extractCode($response_str)
	{
		preg_match("|^HTTP/[\d\.x]+ (\d+)|", $response_str, $m);

		if (isset($m[1])) {
			return (int) $m[1];
		} else {
			return false;
		}
	}
	
	public static function extractHeaders($response_str)
	{
		$headers = array();

		// First, split body and headers
		$parts = preg_split('|(?:\r?\n){2}|m', $response_str, 2);
		if (! $parts[0]) return $headers;

		// Split headers part to lines
		$lines = explode("\n", $parts[0]);
		unset($parts);
		$last_header = null;

		foreach($lines as $line) {
			$line = trim($line, "\r\n");
			if ($line == "") break;

			// Locate headers like 'Location: ...' and 'Location:...' (note the missing space)
			if (preg_match("|^([\w-]+):\s*(.+)|", $line, $m)) {
					unset($last_header);
					$h_name = strtolower($m[1]);
					$h_value = $m[2];

					if (isset($headers[$h_name])) {
						if (! is_array($headers[$h_name])) {
							$headers[$h_name] = array($headers[$h_name]);
						}

						$headers[$h_name][] = $h_value;
					} else {
						$headers[$h_name] = $h_value;
					}
					$last_header = $h_name;
			} elseif (preg_match("|^\s+(.+)$|", $line, $m) && $last_header !== null) {
				if (is_array($headers[$last_header])) {
					end($headers[$last_header]);
					$last_header_key = key($headers[$last_header]);
					$headers[$last_header][$last_header_key] .= $m[1];
				} else {
					$headers[$last_header] .= $m[1];
				}
			}
		}

		return $headers;
	}
	
	public static function extractBody($response_str)
		{
			$parts = preg_split('|(?:\r?\n){2}|m', $response_str, 2);
			if (isset($parts[1])) {
				return $parts[1];
			}
			return '';
		}
}

?>