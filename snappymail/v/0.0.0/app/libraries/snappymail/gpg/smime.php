<?php
/**
 * This class is inspired by PEAR Crypt_GPG and PECL gnupg
 * It does not support gpg v1 because that is missing ECDH, ECDSA, EDDSA
 * It does not support gpg < v2.2.5 as they are from before 2018
 *
 * https://www.gnupg.org/(en)/documentation/manuals/gnupg24/gpgsm.1.html
 *
 * gpgsm --dump-options
 */

namespace SnappyMail\GPG;

use SnappyMail\SensitiveString;

class SMIME extends Base
{
	private
		$_message,

		$ciphers = [],
		$hashes = [],
		$pubkey_types = [];

	protected
		// https://www.gnupg.org/documentation/manuals/gnupg/GPG-Configuration-Options.html
		$options = [
			'homedir' => '',
			'keyring' => '',
			'digest-algo' => '',
			'cipher-algo' => ''
		];

	function __construct(string $homedir)
	{
		parent::__construct($homedir);

		// the random seed file makes subsequent actions faster so only disable it if we have to.
		if ($this->options['homedir'] && !\is_writable($this->options['homedir'])) {
			$this->options['no-random-seed-file'] = true;
		}

		// How to use gpgme-json ?
		$this->binary = static::findBinary('gpgsm');

		$info = \preg_replace('/\R +/', ' ', `$this->binary --version`);
		if (\preg_match('/gpgsm.+([0-9]+\\.[0-9]+\\.[0-9]+)/', $info, $match)) {
			$this->version = $match[1];
		}
		if (\preg_match('/Cipher:(.+)/', $info, $match)) {
			$this->ciphers = \array_map('trim', \explode(',', $match[1]));
		}
		if (\preg_match('/Pubkey:(.+)/', $info, $match)) {
			$this->pubkey_types = \array_map('trim', \explode(',', $match[1]));
		}
		if (\preg_match('/Hash:(.+)/', $info, $match)) {
			$this->hashes = \array_map('trim', \explode(',', $match[1]));
		}
	}

	public static function isSupported() : bool
	{
		return parent::isSupported() && static::findBinary('gpgsm');
	}

	/**
	 * TODO: parse result
	 * https://github.com/the-djmaze/snappymail/issues/89
	 */
	protected function listDecryptKeys(/*string|resource*/ $input, /*string|resource*/ $output = null)
	{
		$this->setInput($input);
		$fclose = $this->setOutput($output);
		$_ENV['PINENTRY_USER_DATA'] = '';
		$result = $this->exec(['--list-secret-keys']);
		$fclose && \fclose($fclose);
		return $output ? true : ($result ? $result['output'] : false);
	}

	protected function _decrypt(/*string|resource*/ $input, /*string|resource*/ $output = null)
	{
		$this->setInput($input);

		$fclose = $this->setOutput($output);

		if ($this->pinentries) {
			$_ENV['PINENTRY_USER_DATA'] = \json_encode($this->pinentries);
		}

		$result = $this->exec(['--decrypt','--skip-verify']);

		$fclose && \fclose($fclose);

		return $output ? true : ($result ? $result['output'] : false);
	}

	/**
	 * Decrypts a given text
	 */
	public function decrypt(string $text) /*: string|false */
	{
		return $this->_decrypt($text);
	}

	/**
	 * Decrypts a given file
	 */
	public function decryptFile(string $filename) /*: string|false */
	{
		$fp = \fopen($filename, 'rb');
		try {
			if (!$fp) {
				throw new \Exception("Could not open file '{$filename}'");
			}
			return $this->_decrypt($fp, $output);
		} finally {
			$fp && \fclose($fp);
		}
	}

	/**
	 * Decrypts a given stream
	 */
	public function decryptStream($fp, /*string|resource*/ $output = null) /*: string|false*/
	{
		if (!$fp || !\is_resource($fp)) {
			throw new \Exception('Invalid stream resource');
		}
		return $this->_decrypt($fp, $output);
	}

	/**
	 * Decrypts and verifies a given text
	 */
	public function decryptVerify(string $text, string &$plaintext) /*: array|false*/
	{
		// TODO: https://github.com/the-djmaze/snappymail/issues/89
		return false;
	}

	/**
	 * Decrypts and verifies a given file
	 */
	public function decryptVerifyFile(string $filename, string &$plaintext) /*: array|false*/
	{
		// TODO: https://github.com/the-djmaze/snappymail/issues/89
		return false;
	}

	protected function _encrypt(/*string|resource*/ $input, /*string|resource*/ $output = null)
	{
		if (!$this->encryptKeys) {
			throw new \Exception('No encryption keys specified.');
		}

		$this->setInput($input);

		$fclose = $this->setOutput($output);

		$arguments = [
			'--encrypt'
		];
		if ($this->armor) {
			$arguments[] = '--armor';
		}

		foreach ($this->encryptKeys as $fingerprint => $dummy) {
			$arguments[] = '--recipient ' . \escapeshellarg($fingerprint);
		}

		$result = $this->exec($arguments);

		$fclose && \fclose($fclose);

		return $output ? true : $result['output'];
	}

	/**
	 * Encrypts a given text
	 */
	public function encrypt(string $plaintext, /*string|resource*/ $output = null) /*: string|false*/
	{
		return $this->_encrypt($plaintext, $output);
	}

	/**
	 * Encrypts a given text
	 */
	public function encryptFile(string $filename, /*string|resource*/ $output = null) /*: string|false*/
	{
		$fp = \fopen($filename, 'rb');
		try {
			if (!$fp) {
				throw new \Exception("Could not open file '{$filename}'");
			}
			return $this->_encrypt($filename, $output);
		} finally {
			$fp && \fclose($fp);
		}
	}

	public function encryptStream(/*resource*/ $fp, /*string|resource*/ $output = null) /*: string|false*/
	{
		if (!$fp || !\is_resource($fp)) {
			throw new \Exception('Invalid stream resource');
		}
		return $this->_encrypt($fp, $output);
	}

	/**
	 * Encrypts and signs a given text
	 */
	public function encryptSign(string $plaintext) /*: string|false*/
	{
		return false;
	}

	/**
	 * Encrypts and signs a given text
	 */
	public function encryptSignFile(string $filename) /*: string|false*/
	{
		return false;
	}

	/**
	 * Exports a public or private key
	 */
	public function export(string $fingerprint, ?SensitiveString $passphrase = null) /*: string|false*/
	{
		$private = null !== $passphrase;
		$keys = $this->keyInfo($fingerprint, $private);
		if (!$keys) {
			throw new \Exception(($private ? 'Private' : 'Public') . ' key not found: ' . $fingerprint);
		}
		if ($private) {
			$_ENV['PINENTRY_USER_DATA'] = \json_encode([$fingerprint => \strval($passphrase)]);
		}
		$result = $this->exec([
			$private ? '--export-secret-key-p12' : '--export',
			'--armor',
			\escapeshellarg($keys[0]['subkeys'][0]['fingerprint']),
		]);
		return $result['output'];
	}

	protected function _importKey($input) /*: array|false*/
	{
		$arguments = ['--import'];

		if ($this->pinentries) {
			$_ENV['PINENTRY_USER_DATA'] = \json_encode($this->pinentries);
		} else {
			$arguments[] = '--batch';
		}

		$this->setInput($input);
		$result = $this->exec($arguments);

		foreach ($result['status'] as $line) {
			if (false !== \strpos($line, 'IMPORT_RES')) {
				$line = \explode(' ', \explode('IMPORT_RES ', $line)[1]);
				return [
					'imported' => (int) $line[2],
					'unchanged' => (int) $line[4],
					'newuserids' => (int) $line[5],
					'newsubkeys' => (int) $line[6],
					'secretimported' => (int) $line[10],
					'secretunchanged' => (int) $line[11],
					'newsignatures' => (int) $line[7],
					'skippedkeys' => (int) $line[12],
					'fingerprint' => ''
				];
			}
		}

		if (!empty($result['errors'][0])) {
			\SnappyMail\Log::warning('GPG', $result['errors'][0]);
		}

		return false;
	}

	/**
	 * Imports a key
	 */
	public function import(string $keydata) /*: array|false*/
	{
		if (!$keydata) {
			throw new \Exception('No valid input data found.');
		}
		return $this->_importKey($keydata);
	}

	/**
	 * Imports a key
	 */
	public function importFile(string $filename) /*: array|false*/
	{
		$fp = \fopen($filename, 'rb');
		try {
			if (!$fp) {
				throw new \Exception("Could not open file '{$filename}'");
			}
			return $this->_importKey($fp);
		} finally {
			$fp && \fclose($fp);
		}
	}

	public function deleteKey(string $keyId, bool $private)
	{
		$key = $this->keyInfo($keyId, $private);
		if (!$key) {
			throw new \Exception(($private ? 'Private' : 'Public') . ' key not found: ' . $keyId);
		}
		if ($private) {
			throw new \Exception('Delete private key not possible: ' . $keyId);
		}

		$result = $this->exec([
			'--batch',
			'--yes',
			'--delete-key',
			\escapeshellarg($key[0]['subkeys'][0]['fingerprint'])
		]);

//		$result['status'][0] = '[GNUPG:] ERROR keylist.getkey 17'
//		$result['errors'][0] = 'gpg: error reading key: No public key'

//		print_r($result);
		return true;
	}

	/**
	 * Returns an array with information about all keys that matches the given pattern
	 */
	public function keyInfo(string $pattern, bool $private = false) : array
	{
		// According to The file 'doc/DETAILS' in the GnuPG distribution, using
		// double '--with-fingerprint' also prints the fingerprint for subkeys.
		$arguments = [
			'--with-colons',
			'--with-fingerprint',
			'--with-fingerprint',
			$private ? '--list-secret-keys' : '--list-keys'
		];
		if ($pattern) {
			$arguments[] = \escapeshellarg($pattern);
		}

		$result = $this->exec($arguments);

		$keys   = [];
		$key    = null; // current key
		$subKey = null; // current sub-key

		foreach (\explode(PHP_EOL, $result['output']) as $line) {
			$tokens = \explode(':', $line);

			switch ($tokens[0])
			{
			case 'tru':
				break;

			case 'sec':
			case 'pub':
				// new primary key means last key should be added to the array
				if ($key !== null) {
					$keys[] = $key;
				}
				$key = [
					'disabled' => false,
					'expired' => false,
					'revoked' => false,
					'is_secret' => 'ssb' === $tokens[0],
					'can_sign' => \str_contains($tokens[11], 's'),
					'can_encrypt' => \str_contains($tokens[11], 'e'),
					'uids' => [],
					'subkeys' => []
				];
				// Fall through to add subkey
			case 'ssb': // secure subkey
			case 'sub': // public subkey
				$key['subkeys'][] = [
					'fingerprint' => '', // fpr:::::::::....:
					'keyid' => $tokens[4],
					'timestamp' => $tokens[5],
					'expires' => $tokens[6],
					'is_secret' => 'ssb' === $tokens[0],
					'invalid' => false,
					// escaESCA
					'can_encrypt' => \str_contains($tokens[11], 'e'),
					'can_sign' => \str_contains($tokens[11], 's'),
					'can_certify' => \str_contains($tokens[11], 'c'),
					'can_authenticate' => \str_contains($tokens[11], 'a'),
					'disabled' => false,
					'expired' => false,
					'revoked' => 'r' === $tokens[1],
					'length' => $tokens[2],
					'algorithm' => $tokens[3],
				];
				break;

			case 'fpr':
				$key['subkeys'][\array_key_last($key['subkeys'])]['fingerprint'] = $tokens[9];
				break;

			case 'grp':
				$key['subkeys'][\array_key_last($key['subkeys'])]['keygrip'] = $tokens[9];
				break;

			case 'uid':
				$string  = \stripcslashes($tokens[9]); // as per documentation
				$name    = '';
				$email   = '';
				$comment = '';
				$matches = [];

				// get email address from end of string if it exists
				if (\preg_match('/^(.*?)<([^>]+)>$/', $string, $matches)) {
					$string = \trim($matches[1]);
					$email  = $matches[2];
				}

				// get comment from end of string if it exists
				$matches = [];
				if (\preg_match('/^(.+?) \(([^\)]+)\)$/', $string, $matches)) {
					$string  = $matches[1];
					$comment = $matches[2];
				}

				// there can be an email without a name
				if (!$email && \preg_match('/^[\S]+@[\S]+$/', $string, $matches)) {
					$email = $string;
				} else {
					$name = $string;
				}

				$key['uids'][] = [
					'name' => $name,
					'comment' => $comment,
					'email' => $email,
					'uid' => $tokens[9],
					'revoked' => 'r' === $tokens[1],
					'invalid' => false,
				];
				break;
			}
		}

		// add last key
		if ($key) {
			$keys[] = $key;
		}

		return $keys;
	}

	protected function _sign(/*string|resource*/ $input, /*string|resource*/ $output = null) /*: string|false*/
	{
		if (empty($this->pinentries)) {
			throw new \Exception('No signing keys specified.');
		}

		$this->setInput($input);

		$fclose = $this->setOutput($output);

		$arguments = [
			'--sign'
		];

		if ($this->armor) {
			$arguments[] = '--armor';
		}

		if ($this->pinentries) {
			foreach ($this->pinentries as $fingerprint => $pass) {
				$arguments[] = '--local-user ' . \escapeshellarg($fingerprint);
			}
			$_ENV['PINENTRY_USER_DATA'] = \json_encode($this->pinentries);
		}

		$result = $this->exec($arguments);

		$fclose && \fclose($fclose);

		return $output ? true : $result['output'];
	}

	/**
	 * Signs a given text
	 */
	public function sign(string $plaintext, /*string|resource*/ $output = null) /*: string|false*/
	{
		return $this->_sign($plaintext, $output);
	}

	/**
	 * Signs a given file
	 */
	public function signFile(string $filename, /*string|resource*/ $output = null) /*: string|false*/
	{
		$fp = \fopen($filename, 'rb');
		try {
			if (!$fp) {
				throw new \Exception("Could not open file '{$filename}'");
			}
			return $this->_sign($fp, $output);
		} finally {
			$fp && \fclose($fp);
		}
	}

	/**
	 * Signs a given file
	 */
	public function signStream($fp, /*string|resource*/ $output = null) /*: string|false*/
	{
		if (!$fp || !\is_resource($fp)) {
			throw new \Exception('Invalid stream resource');
		}
		return $this->_sign($fp, $output);
	}

	protected function _verify($input, string $signature) /*: array|false*/
	{
		$arguments = ['--verify'];
		if ($signature) {
			// detached signature
			$this->setInput($signature);
			$this->_message =& $input;
			// Signed data goes in FD_MESSAGE, detached signature data goes in FD_INPUT.
			$arguments[] = '--enable-special-filenames';
			$arguments[] = '- "-&' . self::FD_MESSAGE . '"';
		} else {
			// signed or clearsigned data
			$this->setInput($input);
		}

		$result = $this->exec($arguments);

		$signatures = [];
		foreach ($result['status'] as $line) {
			$tokens = \explode(' ', $line);
			switch ($tokens[0])
			{
			case 'VERIFICATION_COMPLIANCE_MODE':
			case 'TRUST_FULLY':
				break;

			case 'EXPSIG':
			case 'EXPKEYSIG':
			case 'REVKEYSIG':
			case 'BADSIG':
			case 'ERRSIG':
			case 'GOODSIG':
				$signatures[] = [
					'fingerprint' => '',
					'validity' => 0,
					'timestamp' => 0,
					'status' => 'GOODSIG' === $tokens[0] ? 0 : 1,
					'summary' => 'GOODSIG' === $tokens[0] ? 0 : 4,
					'keyid' => $tokens[1],
					'uid' => \rawurldecode(\implode(' ', \array_splice($tokens, 2))),
					'valid' => false
				];
				break;

			case 'VALIDSIG':
				$last = \array_key_last($signatures);
				$signatures[$last]['fingerprint'] = $tokens[1];
				$signatures[$last]['timestamp'] = (int) $tokens[3];
				$signatures[$last]['expires'] = (int) $tokens[4];
				$signatures[$last]['version'] = (int) $tokens[5];
//				$signatures[$last]['reserved'] = (int) $tokens[6];
//				$signatures[$last]['pubkey-algo'] = (int) $tokens[7];
//				$signatures[$last]['hash-algo'] = (int) $tokens[8];
//				$signatures[$last]['sig-class'] = $tokens[9];
//				$signatures[$last]['primary-fingerprint'] = $tokens[10];
				$signatures[$last]['valid'] = 0;
			}
		}

		return $signatures;
	}

	/**
	 * Verifies a signed text
	 */
	public function verify(string $signed_text, string $signature, ?string &$plaintext = null) /*: array|false*/
	{
		return $this->_verify($signed_text, $signature);
	}

	/**
	 * Verifies a signed file
	 */
	public function verifyFile(string $filename, string $signature, ?string &$plaintext = null) /*: array|false*/
	{
		$fp = \fopen($filename, 'rb');
		try {
			if (!$fp) {
				throw new \Exception("Could not open file '{$filename}'");
			}
			return $this->_verify($fp, $signature);
		} finally {
			$fp && \fclose($fp);
		}
	}

	/**
	 * Verifies a signed file
	 */
	public function verifyStream($fp, string $signature, ?string &$plaintext = null) /*: array|false*/
	{
		if (!$fp || !\is_resource($fp)) {
			throw new \Exception('Invalid stream resource');
		}
		return $this->_verify($fp, $signature);
	}

	public function getEncryptedMessageKeys(/*string|resource*/ $data) : array
	{
		$this->_debug('BEGIN DETECT MESSAGE KEY IDs');
		$this->setInput($data);
//		$_ENV['PINENTRY_USER_DATA'] = null;
		$result = $this->exec(['--decrypt','--skip-verify']);
		$info = [
			'ENC_TO' => [],
//			'KEY_CONSIDERED' => [],
//			'NO_SECKEY' => [],
//			'errors' => $result['errors']
		];
		foreach ($result['status'] as $line) {
			$tokens = \explode(' ', $line);
			if (isset($info[$tokens[0]])) {
				$info[$tokens[0]][] = $tokens[1];
			}
		}
		$this->_debug('END DETECT MESSAGE KEY IDs');
		return $info['ENC_TO'];
	}

	private function exec(array $arguments, bool $throw = true) /*: array|false*/
	{
		if (\version_compare($this->version, '2.2.5', '<')) {
			\SnappyMail\Log::error('GPG', "{$this->version} too old");
			return false;
		}

		$defaultArguments = [
			'--status-fd ' . self::FD_STATUS,
			'--passphrase-fd ' . self::FD_COMMAND,
//			'--no-greeting',
			'--no-secmem-warning',
			'--no-tty',
			'--no-default-keyring',         // ignored if keying files are not specified
			'--no-options',                 // prevent creation of ~/.gnupg directory
			// If no passphrases are set, cancel them
			'--pinentry-mode ' . (empty($_ENV['PINENTRY_USER_DATA']) ? 'cancel' : 'loopback') // 2.1.13+
		];

		if (!$this->strict) {
			$defaultArguments[] = '--ignore-time-conflict';
		}

		foreach ($this->options as $option => $value) {
			if (\is_string($value)) {
				if (\strlen($value)) {
					$defaultArguments[] = "--{$option} " . \escapeshellarg($value);
				}
			} else if (true === $value) {
				$defaultArguments[] = "--{$option}";
			}
		}

		$commandLine = $this->binary . ' ' . \implode(' ', \array_merge($defaultArguments, $arguments));

		$descriptorSpec = [
			self::FD_INPUT   => array('pipe', 'rb'), // stdin
			self::FD_OUTPUT  => array('pipe', 'wb'), // stdout
			self::FD_ERROR   => array('pipe', 'wb'), // stderr
			self::FD_STATUS  => array('pipe', 'wb'), // status
			self::FD_COMMAND => array('pipe', 'rb'), // command
			self::FD_MESSAGE => array('pipe', 'rb')  // message
		];

		$this->_debug('OPENING SUBPROCESS WITH THE FOLLOWING COMMAND:');
		$this->_debug($commandLine);

		// Don't localize GnuPG results.
		$env = $_ENV;
		$env['LC_ALL'] = 'C';

		$proc_pipes = [];

		$this->proc_resource = \proc_open(
			$commandLine,
			$descriptorSpec,
			$proc_pipes,
			null,
			$env,
			['binary_pipes' => true]
		);

		if (!\is_resource($this->proc_resource)) {
			throw new \Exception('Unable to open process.');
		}

		$this->_openPipes = new ProcPipes($proc_pipes);

		$this->_debug('BEGIN PROCESSING');

		$commandBuffer   = '';    // buffers input to GPG
		$messageBuffer   = '';    // buffers input to GPG
		$inputBuffer     = '';    // buffers input to GPG
		$outputBuffer    = '';    // buffers output from GPG
		$inputComplete   = false; // input stream is completely buffered
		$messageComplete = false; // message stream is completely buffered

		if (\is_string($this->_input)) {
			$inputBuffer   = $this->_input;
			$inputComplete = true;
		}

		if (\is_string($this->_message)) {
			$messageBuffer   = $this->_message;
			$messageComplete = true;
		}

		$status = [];
		$errors = [];

		// convenience variables
		$fdInput   = $proc_pipes[self::FD_INPUT];
		$fdOutput  = $proc_pipes[self::FD_OUTPUT];
		$fdError   = $proc_pipes[self::FD_ERROR];
		$fdStatus  = $proc_pipes[self::FD_STATUS];
		$fdCommand = $proc_pipes[self::FD_COMMAND];
		$fdMessage = $proc_pipes[self::FD_MESSAGE];

		// select loop delay in milliseconds
		$delay         = 0;
		$inputPosition = 0;

		$start = \microtime(1);

		while (true) {
			// Timeout after 5 seconds
			if (5 < \microtime(1) - $start) {
				$errors[] = 'timeout';
				return [
					'output' => '',
					'status' => $status,
					'errors' => $errors
				];
				exit;
			}

			$inputStreams     = [];
			$outputStreams    = [];
			$exceptionStreams = [];

			// set up input streams
			if (!$inputComplete && \is_resource($this->_input)) {
				if (\feof($this->_input)) {
					$inputComplete = true;
				} else {
					$inputStreams[] = $this->_input;
				}
			}

			// close GPG input pipe if there is no more data
			if ('' == $inputBuffer && $inputComplete) {
				$this->_debug('=> closing input pipe');
				$this->_openPipes->close(self::FD_INPUT);
			}

			if (\is_resource($this->_message) && !$messageComplete) {
				if (\feof($this->_message)) {
					$messageComplete = true;
				} else {
					$inputStreams[] = $this->_message;
				}
			}

			if (!\feof($fdOutput)) {
				$inputStreams[] = $fdOutput;
			}

			if (!\feof($fdStatus)) {
				$inputStreams[] = $fdStatus;
			}

			if (!\feof($fdError)) {
				$inputStreams[] = $fdError;
			}

			// set up output streams
			if ('' != $outputBuffer && $this->_output) {
				$outputStreams[] = $this->_output;
			}

			if ($commandBuffer != '' && \is_resource($fdCommand)) {
				$outputStreams[] = $fdCommand;
			}

			if ('' != $messageBuffer) {
				if (\is_resource($fdMessage)) {
					$outputStreams[] = $fdMessage;
				}
			} else if ($messageComplete) {
				// close GPG message pipe if there is no more data
				$this->_debug('=> closing message pipe');
				$this->_openPipes->close(self::FD_MESSAGE);
			}

			if ($inputBuffer != '' && \is_resource($fdInput)) {
				$outputStreams[] = $fdInput;
			}

			// no streams left to read or write, we're all done
			if (!\count($inputStreams) && !\count($outputStreams)) {
				break;
			}

			$this->_debug('selecting streams');

			$ready = \stream_select(
				$inputStreams,
				$outputStreams,
				$exceptionStreams,
				5
			);

			$this->_debug('=> got ' . $ready);

			if ($ready === false) {
				throw new \Exception(
					'Error selecting stream for communication with GPG ' .
					'subprocess. Please file a bug report at: ' .
					'http://pear.php.net/bugs/report.php?package=Crypt_GPG'
				);
			}

			if ($ready === 0) {
				throw new \Exception(
					'stream_select() returned 0. This can not happen! Please ' .
					'file a bug report at: ' .
					'http://pear.php.net/bugs/report.php?package=Crypt_GPG'
				);
			}

			// write input (to GPG)
			if (\in_array($fdInput, $outputStreams, true)) {
				$this->_debug('ready for input');
				$chunk  = \substr($inputBuffer, $inputPosition, self::CHUNK_SIZE);
				$length = \strlen($chunk);
				$this->_debug('=> about to write ' . $length . ' bytes to input');
				$length = $this->_openPipes->writePipe(self::FD_INPUT, $chunk, $length);
				if ($length) {
					$this->_debug('=> wrote ' . $length . ' bytes');
					// Move the position pointer, don't modify $inputBuffer (#21081)
					if (\is_string($this->_input)) {
						$inputPosition += $length;
					} else {
						$inputPosition = 0;
						$inputBuffer   = \substr($inputBuffer, $length);
					}
				} else {
					$this->_debug('=> pipe broken and closed');
				}
			}

			// read input (from PHP stream)
			// If the buffer is too big wait until it's smaller, we don't want
			// to use too much memory
			if (\in_array($this->_input, $inputStreams, true) && \strlen($inputBuffer) < self::CHUNK_SIZE) {
				$this->_debug('input stream is ready for reading');
				$this->_debug('=> about to read ' . self::CHUNK_SIZE . ' bytes from input stream');
				$chunk        = \fread($this->_input, self::CHUNK_SIZE);
				$length       = \strlen($chunk);
				$inputBuffer .= $chunk;
				$this->_debug('=> read ' . $length . ' bytes');
			}

			// write message (to GPG)
			if (\in_array($fdMessage, $outputStreams, true)) {
				$this->_debug('ready for message data');
				$this->_debug('=> about to write ' . \min(self::CHUNK_SIZE, \strlen($messageBuffer)) . ' bytes to message');
				$length = $this->_openPipes->writePipe(self::FD_MESSAGE, $messageBuffer, self::CHUNK_SIZE);
				if ($length) {
					$this->_debug('=> wrote ' . $length . ' bytes');
					$messageBuffer = \substr($messageBuffer, $length);
				} else {
					$this->_debug('=> pipe broken and closed');
				}
			}

			// read message (from PHP stream)
			if (\in_array($this->_message, $inputStreams, true)) {
				$this->_debug('message stream is ready for reading');
				$this->_debug('=> about to read ' . self::CHUNK_SIZE . ' bytes from message stream');
				$chunk          = \fread($this->_message, self::CHUNK_SIZE);
				$length         = \strlen($chunk);
				$messageBuffer .= $chunk;
				$this->_debug('=> read ' . $length . ' bytes');
			}

			// read output (from GPG)
			if (\in_array($fdOutput, $inputStreams, true)) {
				$this->_debug('output stream ready for reading');
				$this->_debug('=> about to read ' . self::CHUNK_SIZE . ' bytes from output');
				$chunk         = \fread($fdOutput, self::CHUNK_SIZE);
				$length        = \strlen($chunk);
				$outputBuffer .= $chunk;
				$this->_debug('=> read ' . $length . ' bytes');
			}

			// write output (to PHP stream)
			if (\in_array($this->_output, $outputStreams, true)) {
				$this->_debug('output stream is ready for data');
				$chunk  = \substr($outputBuffer, 0, self::CHUNK_SIZE);
				$length = \strlen($chunk);
				$this->_debug('=> about to write ' . $length . ' bytes to output stream');
				$length = \fwrite($this->_output, $chunk, $length);
				if (!$length) {
					$this->_debug('=> broken pipe on output stream');
					$this->_debug('=> closing pipe output stream');
					$this->_openPipes->close(self::FD_OUTPUT);
				} else {
					$this->_debug('=> wrote ' . $length . ' bytes');
					$outputBuffer = \substr($outputBuffer, $length);
				}
			}

			// read error (from GPG)
			if (\in_array($fdError, $inputStreams, true)) {
				$this->_debug('error stream ready for reading');
				$this->_debug('=> about to read ' . self::CHUNK_SIZE . ' bytes from error');
				foreach ($this->_openPipes->readPipeLines(self::FD_ERROR) as $line) {
					$errors[] = $line;
					$this->_debug("\t{$line}");
				}
			}

			// read status (from GPG)
			if (\in_array($fdStatus, $inputStreams, true)) {
				$this->_debug('status stream ready for reading');
				$this->_debug('=> about to read ' . self::CHUNK_SIZE . ' bytes from status');
				// pass lines to status handlers
				foreach ($this->_openPipes->readPipeLines(self::FD_STATUS) as $line) {
					// only pass lines beginning with magic prefix
					if ('[GNUPG:] ' == \substr($line, 0, 9)) {
						$line = \substr($line, 9);
						$status[] = $line;
						$this->_debug("\t{$line}");

						$tokens = \explode(' ', $line);
						// NEED_PASSPHRASE 0123456789ABCDEF 0123456789ABCDEF 1 0
						if ('NEED_PASSPHRASE' === $tokens[0]) {
							// key ?: subkey
							$passphrase = $this->getPassphrase($tokens[1]) ?: $this->getPassphrase($tokens[2]);
							$commandBuffer .= $passphrase . PHP_EOL;
						}
					}
				}
			}

			// write command (to GPG)
			if (\in_array($fdCommand, $outputStreams, true)) {
				$this->_debug('ready for command data');
				$chunk  = \substr($commandBuffer, 0, self::CHUNK_SIZE);
				$length = \strlen($chunk);
				$this->_debug('=> about to write ' . $length . ' bytes to command');
				$length = $this->_openPipes->writePipe(self::FD_COMMAND, $chunk, $length);
				if ($length) {
					$this->_debug('=> wrote ' . $length);
					$commandBuffer = \substr($commandBuffer, $length);
				} else {
					$this->_debug('=> pipe broken and closed');
				}
			}

			if (\count($outputStreams) === 0 || \count($inputStreams) === 0) {
				// we have an I/O imbalance, increase the select loop delay
				// to smooth things out
				$delay += 10;
			} else {
				// things are running smoothly, decrease the delay
				$delay -= 8;
				$delay = \max(0, $delay);
			}

			if ($delay > 0) {
				\usleep($delay);
			}

		} // end loop while streams are open

		$this->_debug('END PROCESSING');

		$this->proc_close();

		$this->_message = null;
		$this->_input   = null;
		$this->_output  = null;

		return [
			'output' => $outputBuffer,
			'status' => $status,
			'errors' => $errors
		];
	}

	protected function getPassphrase($key)
	{
		$passphrase  = '';
		$keyIdLength = \strlen($key);
		if ($keyIdLength && !empty($_ENV['PINENTRY_USER_DATA'])) {
			$passphrases = \json_decode($_ENV['PINENTRY_USER_DATA'], true);
			foreach ($passphrases as $keyId => $pass) {
				$length = \min($keyIdLength, \strlen($keyId));
				if (\substr($keyId, -$length) === \substr($key, -$length)) {
					return $pass;
				}
			}
		}
//		throw new \Exception("Passphrase not found for {$key}");
		return '';
	}

	protected function proc_close() : int
	{
		$exitCode = 0;

		// clear PINs from environment if they were set
		$_ENV['PINENTRY_USER_DATA'] = null;

		if (\is_resource($this->proc_resource)) {
			$this->_debug('CLOSING SUBPROCESS');

			// close remaining open pipes
			$this->_openPipes->closeAll();

			$status   = \proc_get_status($this->proc_resource);
			$exitCode = \proc_close($this->proc_resource);
			$this->proc_resource = null;

			// proc_close() can return -1 in some cases,
			// get the real exit code from the process status
			if ($exitCode < 0 && $status && !$status['running']) {
				$exitCode = $status['exitcode'];
			}

			if ($exitCode > 0) {
				$this->_debug('=> subprocess returned an unexpected exit code: ' . $exitCode);
			}
		}

		return $exitCode;
	}
}
