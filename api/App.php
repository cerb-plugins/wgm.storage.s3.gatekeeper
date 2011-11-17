<?php
class DevblocksStorageEngineGatekeeper extends Extension_DevblocksStorageEngine {
	const ID = 'devblocks.storage.engine.gatekeeper';
	
	private $_data = null;

	public function setOptions($options=array()) {
		parent::setOptions($options);

		// Fail, this info is required.
		if(!isset($this->_options['username']))
			return false;
		if(!isset($this->_options['password']))
			return false;
		if(!isset($this->_options['url']))
			return false;
	}

	function testConfig() {
		// Test S3 connection info
		@$username = DevblocksPlatform::importGPC($_POST['username'],'string','');
		@$password = DevblocksPlatform::importGPC($_POST['password'],'string','');
		@$url = DevblocksPlatform::importGPC($_POST['url'],'string','');
		
		if(!isset($username) || !isset($password) || !isset($url))
			return false;
		
		if(!$this->_getSignedURL($username, $password, $url . '?test=true'))
			return false;

		return true;
	}

	function renderConfig(Model_DevblocksStorageProfile $profile) {
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('profile', $profile);

		$tpl->display("devblocks:wgm.storage.s3.gatekeeper::storage_engine/config/gatekeeper.tpl");
	}

	function saveConfig(Model_DevblocksStorageProfile $profile) {
		@$username = DevblocksPlatform::importGPC($_POST['username'],'string','');
		@$password = DevblocksPlatform::importGPC($_POST['password'],'string','');
		@$url = DevblocksPlatform::importGPC($_POST['url'], 'string', '');

		$fields = array(
		DAO_DevblocksStorageProfile::PARAMS_JSON => json_encode(array(
				'username' => $username,
				'password' => $password,
				'url' => $url
			)),
		);

		DAO_DevblocksStorageProfile::update($profile->id, $fields);
	}

	public function exists($namespace, $key) {
		$path = $this->escapeNamespace($namespace) . '/' . $key;
		
		if(false == ($url = $this->_getSignedURL('scott', 'slut', 'GET', $path)))
			return false;
		
// 		return false !== ($info = $this->_s3->getObjectInfo($bucket, $key));
	}
	
	public function put($namespace, $id, $data, $length = null) {
		// Get a unique hash path for this namespace+id
		$hash = base_convert(sha1($this->escapeNamespace($namespace).$id), 16, 32);
		$key = sprintf("%s/%s/%d",
			substr($hash,0,1),
			substr($hash,1,1),
			$id
		);
		
		$path = $this->escapeNamespace($namespace) . '/' . $key;
	
		if(false == ($url = $this->_getSignedURL($this->_options['username'], $this->_options['password'], $this->_options['url'], 'PUT', $path)))
			return false;
		
		if(false == ($this->_execute($url, 'PUT', $data, $length)))
			return false;
	
		return $key;
	}
	
	public function get($namespace, $key, &$fp=null) {
		$path = $this->escapeNamespace($namespace) . '/' . $key;
		
		if(false == ($url = $this->_getSignedURL($this->_options['username'], $this->_options['password'], $this->_options['url'], 'GET', $path)))
			return false;
		
		if($fp && is_resource($fp)) {
			// [TODO] Make this work with streams
			if(false == ($data = $this->_execute($url, 'GET')))
				return false;
				
			//$tmpfile = DevblocksPlatform::getTempFileInfo($fp);
			//file_put_contents($tmpfile, $data);
			fputs($fp, $data, strlen($data));
			fseek($fp, 0);
			return TRUE;
				
		} else {
			if(false == ($data = $this->_execute($url, 'GET')))
				return false;
				
			return $data;
		}
	
		return false;
	}
	
	public function delete($namespace, $key) {
		// [TODO] Fail gracefully if resource doesn't exist (pass)
		$path = $this->escapeNamespace($namespace) . '/' . $key;
	
		if(false ==  ($url = $this->_getSignedURL($this->_options['username'], $this->_options['password'], $this->_options['url'], $path)))
			return false;
	
		if(false == ($data = $this->_execute($url, 'DELETE')))
			return false;
	
		return TRUE;
	}

	private function _getSignedURL($username, $password, $url, $verb = 'GET', $filename = null) {
		$logger = DevblocksPlatform::getConsoleLog();
		
		$header = array();
		$ch = curl_init();
		
		$payload = array('verb' => $verb, 'key' => $filename);
		$http_date = gmdate(DATE_RFC822);
		
		$header[] = 'Date: '.$http_date;
		$header[] = 'Content-Type: application/x-www-form-urlencoded; charset=utf-8';
		
		$postfields = '';
		
		if(!is_null($payload)) {
			if(is_array($payload)) {
				foreach($payload as $key => $value) {
					$postfields .= $key.'='.rawurlencode($value) . '&';
				}
				rtrim($postfields,'&');
			} elseif (is_string($payload)) {
				$postfields = $payload;
			}
		}
		
		$header[] = 'Content-Length: ' .  strlen($postfields);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
		
		// Authentication
		$url_parts = parse_url($url);
		$url_path = $url_parts['path'];
		
		$url_query = '';
		if(isset($url_parts['query']) && !empty($url_parts))
			$url_query = $this->_sortQueryString($url_parts['query']);
		
		$secret = strtolower(md5($password));
		
		// Hardcoded as POST because we will only ever POST to the gatekeeper script
		$string_to_sign = "POST\n$http_date\n$url_path\n$url_query\n$postfields\n$secret\n";
		$hash = md5($string_to_sign); // base64_encode(sha1(
		$header[] = 'Cerb5-Auth: '.sprintf("%s:%s",$username,$hash);
		
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		
		$output = curl_exec($ch);
			
		// Check status code
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if(2 != substr($status,0,1)) {
			$logger->error(sprintf("[Storage] Error connecting to Gatekeeper: %s", $status));
			return false;
		}
		
		// Parse output
		$output = json_decode($output, true);
		
		if(!is_array($output) || empty($output) || $output['__status'] == 'error') {
			$logger->error(sprintf('Error connecting to Gatekeeper: %s', $output['message']));
			return false;
		} else {
			$output = $output['message'];
		}
		return $output;
	}
	
	// [TODO] Make this streaming safe
	private function _execute($url, $verb = 'GET', $data = null, $length = null) {
		$logger = DevblocksPlatform::getConsoleLog();
		try {
			$ch = curl_init($url);
			$http_date = gmdate(DATE_RFC822);
	
			//curl_setopt($ch, CURLOPT_HEADER, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	
			if(is_resource($data)) {
				// set class var
				$this->_data = $data;
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
				curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
				curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 180);
				curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
				curl_setopt($ch, CURLOPT_READFUNCTION, array($this, 'streamData'));
				curl_setopt($ch, CURLOPT_UPLOAD, true);
				curl_setopt($ch, CURLINFO_CONTENT_LENGTH_UPLOAD, $length);
			} else {
				$length = strlen($data);
				switch($verb) {
					case 'GET':
						break;
					case 'PUT':
						curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
						curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
						break;
					case 'DELETE':
						curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
						break;
				}
			}
			
			$header[] = 'Date: '.$http_date;
			// Blank out since we pre-signed the URL
			$header[] = 'Content-Type:';
			$header[] = 'Content-Length: '.$length;
			$header[] = 'Expect: ';
			$header[] = 'Transfer-Encoding: ';
	
			curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
			$response = curl_exec($ch);
			
			// Check status code
			$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			
			if(2 != substr($status,0,1)) {
				$logger->error(sprintf('[Storage] Error connecting to remote URL: %s %s - %s', $verb, $url, $status));
				return false;
			}
	
			curl_close($ch);
		} catch (Exception $e) {
			return false;
		}
		
		// Return
		switch($verb) {
			case 'GET':
				return $response;
				break;
			default:
				return true;
			break;
		}
	}
	
	public function streamData($handle, $fd, $length) {
		return fread($this->_data, $length);
	}
	
	private function _sortQueryString($query) {
		// Strip the leading ?
		if(substr($query,0,1)=='?') $query = substr($query,1);
		$args = array();
		$parts = explode('&', $query);
		foreach($parts as $part) {
			$pair = explode('=', $part, 2);
			if(is_array($pair) && 2==count($pair))
			$args[$pair[0]] = $part;
		}
		ksort($args);
		return implode("&", $args);
	}
};