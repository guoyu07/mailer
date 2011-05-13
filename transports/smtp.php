<?php

/**
 * Sends email using SMTP
 *
 * Copyright (C) 2011 FluxBB (http://fluxbb.org)
 * License: LGPL - GNU Lesser General Public License (http://www.gnu.org/licenses/lgpl.html)
 */

class SMTPMailTransport extends MailTransport
{
	const DEFAULT_HOST = 'localhost';
	const DEFAULT_PORT = 25;
	const DEFAULT_SSL = false;
	const DEFAULT_TIMEOUT = 10;
	const DEFAULT_STARTTLS = true;

	private static function get_hostname()
	{
		if (function_exists('gethostname'))
			return gethostname();

		return php_uname('n');
	}

	private $connection;
	private $extensions;

	/**
	* Initialise a new SMTP mailer.
	*/
	public function __construct($config)
	{
		$host = isset($config['host']) ? $config['host'] : self::DEFAULT_HOST;
		$port = isset($config['port']) ? $config['port'] : self::DEFAULT_PORT;
		$ssl = isset($config['ssl']) ? $config['ssl'] : self::DEFAULT_SSL;
		$timeout = isset($config['timeout']) ? $config['timeout'] : self::DEFAULT_TIMEOUT;
		$localhost = isset($config['localhost']) ? $config['localhost'] : self::get_hostname();

		$username = isset($config['username']) ? $config['username'] : null;
		$password = isset($config['password']) ? $config['password'] : null;
		$starttls = isset($config['starttls']) ? $config['starttls'] : self::DEFAULT_STARTTLS;

		// Create connection to the SMTP server
		$this->connection = new SMTPConnection($host, $port, $ssl, $timeout);

		// Check we received a valid welcome message (code 220)
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::SERVICE_READY)
			throw new Exception('Invalid connection response code received: '.$result['code']);

		// Negotiate and fetch a list of server supported extensions, if any
		$this->extensions = $this->negotiate($localhost);

		// If requested STARTTLS, and it is available (both here and the server), and we aren't already using SSL
		if ($starttls && extension_loaded('openssl') && !empty($this->extensions['STARTTLS']) && !$this->connection->is_secure())
		{
			$this->connection->write('STARTTLS');
			$result = $this->connection->read_response();
			if ($result['code'] != SMTPConnection::SERVICE_READY)
				throw new Exception('STARTTLS was not accepted, response code: '.$result['code']);

			// Enable TLS
			$this->connection->enable_crypto(true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

			// Renegotiate now that we have enabled TLS to get a new list of auth methods
			$this->extensions = $this->negotiate($localhost);
		}

		// If a username and password is given, attempt to authenticate
		if ($username !== null && $password !== null)
		{
			$result = $this->auth($username, $password);
			if ($result === false)
				throw new Exception('Failed to login to SMTP server, invalid credentials.');
		}
	}

	private function negotiate($localhost)
	{
		// Attempt to send EHLO command
		$this->connection->write('EHLO '.$localhost);
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::OKAY)
		{
			// EHLO was rejected, try a HELO
			$this->connection->write('HELO '.$localhost);
			$result = $this->connection->read_response();
			if ($result['code'] != SMTPConnection::OKAY)
				throw new Exception('HELO was not accepted, response code: '.$result['code']);
		}

		// Check which extensions are enabled, if any
		$lines = explode("\r\n", $result['value']);
		array_shift($lines); // Throw away the first line, it's just a greeting

		$extensions = array();

		// The remaining lines are the extensions which are enabled
		foreach ($lines as $line)
		{
			$line = strtoupper($line);
			$delim = strpos($line, ' ');
			if ($delim === false)
				$extensions[$line] = true;
			else
			{
				$verb = substr($line, 0, $delim);
				$arg = substr($line, $delim + 1);

				$extensions[$verb] = $arg;
			}
		}

		return $extensions;
	}

	private function auth($username, $password)
	{
		// Check if auth is actually supported
		if (empty($this->extensions['AUTH']))
			return true;

		$methods = explode(' ', $this->extensions['AUTH']);

		// If we have DIGEST-MD5 available, use it
		if (in_array('DIGEST-MD5', $methods))
			$result = $this->authDigestMD5($username, $password);
		// If we have CRAM-MD5 available, use it
		else if (in_array('CRAM-MD5', $methods))
			$result = $this->authCramMD5($username, $password);
		// If we have LOGIN available, use it
		else if (in_array('LOGIN', $methods))
			$result = $this->authLogin($username, $password);
		// Otherwise use PLAIN
		else if (in_array('PLAIN', $methods))
			$result = $this->authPlain($username, $password);
		// This shouldn't happen since at least PLAIN should be supported
		else
			throw new Exception('No supported authentication methods.');

		// Handle the returned result
		switch ($result['code'])
		{
			// Authentication Succeeded
			case SMTPConnection::AUTH_SUCCESS: return true;
			// Authentication credentials invalid
			case SMTPConnection::AUTH_FAILURE: return false;
			// Other
			default: throw new Exception('Unrecognized response to auth attempt: '.$result['code']);
		}
	}

	private function authDigestMD5($username, $password)
	{
		$this->connection->write('AUTH DIGEST-MD5');
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		$challenge = base64_decode($result['value']);
		$digest = base64_encode($this->authDigestMD5_generateDigest($username, $password, $challenge, $this->connection->get_host()));

		// Send the digest
		$this->connection->write($digest);
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		// SMTP doesn't allow subsequent authentication so we don't use this step
		$this->connection->write('');
		return $this->connection->read_response();
	}

	private function authDigestMD5_generateDigest($username, $password, $challenge, $host)
	{
		// Parse the challenge and check it was valid
		$challenge = $this->authDigestMD5_parseChallenge($challenge);
		if (empty($challenge))
			throw new Exception('Received invalid challenge from AUTH DIGEST-MD5 attempt.');

		$cnonce = uniqid('', true); // Generate a client nonce
		$digest_uri = 'smtp/'.$host;
		$counter = '00000001';
		$qop = 'auth'; // We only support qop method 'auth'

		// Check the server supports auth as a qop method
		if (!in_array($qop, explode(',', $challenge['qop'])))
			throw new Exception('Server does not support qop='.$qop.'.');

		$HA1 = md5(md5($username.':'.$challenge['realm'].':'.$password, true).':'.$challenge['nonce'].':'.$cnonce);
		$HA2 = md5('AUTHENTICATE:'.$digest_uri);
		$response = md5($HA1.':'.$challenge['nonce'].':'.$counter.':'.$cnonce.':'.$qop.':'.$HA2);

		$digest = array(
			'username'		=> $username,
			'realm'			=> $challenge['realm'],
			'nonce'			=> $challenge['nonce'],
			'cnonce'		=> $cnonce,
			'nc'			=> $counter,
			'qop'			=> $qop,
			'digest-uri'	=> $digest_uri,
			'response'		=> $response,
			'maxbuf'		=> $challenge['maxbuf'],
		);

		$temp = array();
		foreach ($digest as $key => $value)
			$temp[] = $key.'="'.$value.'"';

		return implode(',', $temp);
	}

	private function authDigestMD5_parseChallenge($challenge)
	{
		// Attempt to parse the challenge
		if (!preg_match_all('%([a-z-]+)=("[^"]+(?<!\\\)"|[^,]+)%i', $challenge, $matches, PREG_SET_ORDER))
			return array();

		$tokens = array();
		foreach ($matches as $match)
			$tokens[$match[1]] = trim($match[2], '"');

		// Check for required fields
		if (empty($tokens['nonce']) || empty($tokens['algorithm']))
			return array();

		// rfc2831 says to ignore these...
		unset ($tokens['opaque'], $tokens['domain']);

		// If there's no realm default to blank
		if (!isset($tokens['realm']))
			$tokens['realm'] = '';

		// If there's no maximum buffer size, set default
		if (empty($tokens['maxbuf']))
			$tokens['maxbuf'] = 65536;

		// If there's no qop default to auth
		if (empty($tokens['qop']))
			$tokens['qop'] = 'auth';

		return $tokens;
	}

	private function authCramMD5($username, $password)
	{
		$this->connection->write('AUTH CRAM-MD5');
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		$challenge = base64_decode($result['value']);
		$digest = base64_encode($username.' '.hash_hmac('md5', $challenge, $password));

		// Send the digest
		$this->connection->write($digest);
		return $this->connection->read_response();
	}

	private function authLogin($username, $password)
	{
		$this->connection->write('AUTH LOGIN');
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		// Send the username
		$this->connection->write(base64_encode($username));
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		// Send the password
		$this->connection->write(base64_encode($password));
		return $this->connection->read_response();
	}

	private function authPlain($username, $password)
	{
		$this->connection->write('AUTH PLAIN');
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::SERVER_CHALLENGE)
			throw new Exception('Invalid response to auth attempt: '.$result['code']);

		// Send the username and password
		$this->connection->write(base64_encode("\0".$username."\0".$password));
		return $this->connection->read_response();
	}

	public function send($from, $recipients, $message, $headers)
	{
		$this->connection->write('MAIL FROM: <'.$from.'>');
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::OKAY)
			throw new Exception('Invalid response to mail attempt: '.$result['code']);

		// Add all the recipients
		foreach ($recipients as $recipient)
		{
			$this->connection->write('RCPT TO: <'.$recipient.'>');
			$result = $this->connection->read_response();
			if ($result['code'] != SMTPConnection::OKAY && $result['code'] != SMTPConnection::WILL_FORWARD)
				throw new Exception('Invalid response to mail attempt: '.$result['code']);
		}

		// If we have a Bcc header, unset it so that it isn't sent!
		unset ($headers['Bcc']);

		// Start with a blank message
		$data = '';

		// Append the header strings
		$data .= Email::create_header_str($headers);

		// Append the header divider
		$data .= "\r\n";

		// Append the message body
		$data .= $message."\r\n";

		// Append the DATA terminator
		$data .= '.';

		if (!empty($this->extensions['SIZE']) && (strlen($data) + 2) > $this->extensions['SIZE'])
			throw new Exception('Message size exceeds server limit: '.(strlen($data) + 2).' > '.$this->extensions['SIZE']);

		// Inform the server we are about to send data
		$this->connection->write('DATA');
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::START_INPUT)
			throw new Exception('Invalid response to data request: '.$result['code']);

		// Send the mail DATA
		$this->connection->write($data);
		$result = $this->connection->read_response();
		if ($result['code'] != SMTPConnection::OKAY)
			throw new Exception('Invalid response to data terminaton: '.$result['code']);

		return true;
	}

	public function __destruct()
	{
		try
		{
			// Send the QUIT command
			$this->connection->write('QUIT');

			// Close the connection
			$this->connection->close();
		}
		catch (Exception $e) { } // Ignore errors since we are terminating anyway
	}
}

class SMTPConnection
{
	const DEBUG = false;

	// Response codes. See http://www.greenend.org.uk/rjk/2000/05/21/smtp-replies.html
	const ERROR = -1;
	const SERVICE_READY = 220;
	const SERVICE_CLOSING = 221;
	const AUTH_SUCCESS = 235;
	const OKAY = 250;
	const WILL_FORWARD = 251;
	const SERVER_CHALLENGE = 334;
	const START_INPUT = 354;
	const AUTH_FAILURE = 535;

	private $addr;
	private $socket;

	public function __construct($hostname, $port, $secure, $timeout)
	{
		// Create a socket address
		$this->addr = ($secure ? 'ssl' : 'tcp').'://'.$hostname.':'.$port;

		$errno = null;
		$errstr = null;
		$this->socket = stream_socket_client($this->addr, $errno, $errstr, $timeout);
		if ($this->socket === false)
			throw new Exception($errstr);
	}

	public function is_secure()
	{
		return parse_url($this->addr, PHP_URL_SCHEME) == 'ssl';
	}

	public function get_host()
	{
		return parse_url($this->addr, PHP_URL_HOST);
	}

	public function get_port()
	{
		return parse_url($this->addr, PHP_URL_PORT);
	}

	public function enable_crypto($enabled, $crypto_type)
	{
		return stream_socket_enable_crypto($this->socket, $enabled, $crypto_type);
	}

	public function read_line()
	{
		$line = fgets($this->socket);
		if ($line === false)
			return null;

		$line = rtrim($line, "\r\n");
		if (self::DEBUG)
			echo $line.PHP_EOL;

		return $line;
	}

	public function read_response()
	{
		$code = self::ERROR;
		$values = array();

		while (($line = $this->read_line()) !== null)
		{
			$code = intval(substr($line, 0, 3));
			$values[] = trim(substr($line, 4));

			// If this is not a multiline response we're done
			if ($line{3} != '-')
				break;
		}

		return array('code' => $code, 'value' => implode("\r\n", $values));
	}

	public function write($line)
	{
		if (self::DEBUG)
			echo $line.PHP_EOL;

		return fwrite($this->socket, $line."\r\n");
	}

	public function close()
	{
		if ($this->socket === null)
			return;

		fclose($this->socket);
		$this->socket = null;
	}
}
