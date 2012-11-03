<?php
namespace Iris\WebSocket;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Monolog\Logger;

class WebSocketServer implements MessageComponentInterface {
	protected $version = '2.0'; // Websocket server version

	protected $settings;        // settings array
	protected $log;             // log class
    protected $clients;         // WS connections
	protected $oktell = null;   // oktell connection (stored twice for more fast searching)
	protected $pbxNumbers = array();
	protected $multipartBuffer = array();
	
	const TYPE_CLIENT = 'iriscrm-client';
	const TYPE_OKTELL = 'commserver';
	const BUFFER_SIZE = 2048;


	///////////////////////////////////
	///////  public functions   ///////
	///////////////////////////////////

    public function __construct($settings, Logger $log = null) {
		$this->settings = $settings;
		$this->log = $log;

        $this->clients = new \SplObjectStorage;

		$this->log->addInfo('------- websocket server started -------');
		$this->log->addInfo('Version   : '.$this->version);
		$this->log->addInfo('Listen on : '.$this->settings->listenPort);
		$this->log->addInfo('----------------------------------------');
    }

    public function onOpen(ConnectionInterface $conn) {
		$this->log->addInfo('new connection', $this->getConnectionInfo($conn));

        // Store the new connection to send messages to later
        $this->clients->attach($conn);
		
		// send whoareyou message to client
		$this->onOpenHandler($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg) {
		$messageData = json_decode($msg, true);

		$logData = $this->getConnectionInfo($from);
		$logData['message'] = $messageData;
		$this->log->addInfo('new message', $logData);		

		// if multipart message is recieved, then collect them
		if ($messageData[0] == 'multipart') {
			$messageData = $this->collectMultipartMessage($messageData);
			if ($messageData == null) {
				// if not all parts is recieved, then wait them
				return;
			} else {
				$logData['message'] = $messageData;
				$this->log->addInfo('new multipart message', $logData);		
			}
		}


		switch ($messageData[0]) {
			// client messages
			case 'login':
			case 'logout':
			case 'entercallcenter':
			case 'exitcallcenter':
			case 'getuserstate':
			case 'setuserstate':

			case 'pbxautocallstart':
			case 'pbxautocallabort':
			case 'pbxtransfercall':
			case 'getpbxnumbers':
			case 'pbxabortcall':
			case 'sendusertextmessage':
				$this->transferToOktell($messageData);
			break;

			// oktell messages
			case 'loginresult':
			case 'logoutresult':
			case 'userstatechanged':
			case 'getuserstateresult':
			case 'closeform':
			case 'shownotifymessage':

			case 'pbxautocallstartresult':
			case 'pbxtransfercallresult':

			// ring events (only transfer to client)
			case 'phoneevent_ringstarted':
			case 'phoneevent_ringstopped':
			case 'phoneevent_commstarted':
			case 'phoneevent_commstopped':
			case 'phoneevent_ivrstarted':
			case 'phoneevent_ivrstopped':
			case 'phoneevent_acmcallstarted':
			case 'phoneevent_acmcallstopped':
			case 'phoneevent_faxstarted':
			case 'phoneevent_faxstopped':
			case 'phoneevent_faxreceived':
			case 'usertextmessagereceived':
				$this->transferToClient($messageData);
			break;

			case 'whoareyou':
				$this->whoareyouHandler($from, $messageData);
				
			case 'iam':
				$this->iamHandler($from, $messageData);
			break;

			case 'getpbxnumbersresult':
				$this->savePbxNumbers($messageData);
			break;

			case 'pbxnumberstatechanged':
				$this->updatePbxNumbers($messageData);
				$this->broadcastToClients($messageData);
			break;

			case 'getactiveusers':
				$this->getactiveusersHandler($messageData);
			break;

			case 'iris_getpbxnumberslist':
				$this->getpbxnumberslistHandler($from, $messageData);
			break;

			case 'ping':
				$this->pingHandler($from, $messageData);
			break;

			// Oktell API commands
			case 'getavailableforms':
			case 'getavailablemethods':
			case 'showform':
			case 'executemethod':
				$this->apiCommandsHandler($from, $messageData);
			break;
			
			default:
				// TODO
			}
    }

    public function onClose(ConnectionInterface $conn) {
		$this->log->addInfo('connection closed', $this->getConnectionInfo($conn));
		if ($conn->type == static::TYPE_OKTELL) {
			$this->log->addError('commserver disconnected');
		}

        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        //echo "An error has occurred: {$e->getMessage()}\n";
		$logData = $this->getConnectionInfo($conn);
		$logData['errm'] = $e->getMessage();
		$this->log->addError('error occured, connection now closing', $logData);

        $conn->close();
    }

	///////////////////////////////////
	/////// protected functions ///////
	///////////////////////////////////

	protected function getConnectionInfo($conn) {
		$connectionInfo = array(
			'resource' => $conn->resourceId,
			'address' => $conn->remoteAddress
		);
		
		if (isset($conn->type)) {
			$connectionInfo['type'] = $conn->type;
		} else {
			return $connectionInfo;
		}
		if (isset($conn->userlogin)) {
			$connectionInfo['userlogin'] = $conn->userlogin;
		}
		return $connectionInfo;
	}
	
	// multipart messages collect function
	protected function collectMultipartMessage($messageData) {
		$messageId = $messageData[1]['message-id'];
		$packetNumber = $messageData[1]['packetnumber'];
		$packetCount = $messageData[1]['packetcount'];

		// first packet
		if ($this->multipartBuffer[$messageId] == null) {
			$this->multipartBuffer[$messageId]['packetcount'] = $packetCount;
			$this->multipartBuffer[$messageId]['packetrecieved'] = 0;
		}

		$dataPart = null;
		switch ($messageData[1]['content-transfer-encoding']) {
			case 'base64':
				$dataPart = base64_decode($messageData[1]['content']);
			break;
		}

		$this->multipartBuffer[$messageId]['datapart'][$packetNumber] = $dataPart;
		$this->multipartBuffer[$messageId]['packetrecieved']++;

		// if last packet is recieved then clean buffer and return full message
		if ($this->multipartBuffer[$messageId]['packetrecieved'] == $packetCount) {
			ksort($this->multipartBuffer[$messageId]['datapart']);
			$data = implode('', $this->multipartBuffer[$messageId]['datapart']);

			unset($this->multipartBuffer[$messageId]);
			return json_decode($data, true);
		}

		return null;
	}
	
	protected function sendMessage($conn, $message) {
		$logData = $this->getConnectionInfo($conn);
		$logData['message'] = $message;
		$this->log->addInfo('new message', $logData);

		$conn->send($message);
	}

	// Get random GUID
	protected function getGUID() {
	    if (function_exists('com_create_guid')){
	        return substr(com_create_guid(), 1, 36); // strip { and }
	    } else {
	        mt_srand((double)microtime()*10000); //optional for php 4.2.0 and up.
	        $charid = strtoupper(md5(uniqid(rand(), true)));
	        $hyphen = chr(45); // "-"
	        $uuid =  substr($charid, 0, 8).$hyphen
	                .substr($charid, 8, 4).$hyphen
	                .substr($charid,12, 4).$hyphen
	                .substr($charid,16, 4).$hyphen
	                .substr($charid,20,12);
	        return $uuid;
	    }
	}

	protected function onOpenHandler(ConnectionInterface $conn) {
		$message = json_encode(
			array(
				'whoareyou', 
				array(
					"qid" => $this->getGUID(),
					"type" => 'ws-server',
					"name" => 'Iris CRM',
					"version" => $this->version
				)
			)
		);
		$this->sendMessage($conn, $message);
	}

	protected function whoareyouHandler(ConnectionInterface $conn, $messageData) {
		$message = json_encode(
			array(
				'iam', 
				array(
					"qid" => $messageData[1]['qid'], 
					"type" => 'ws-server', 
					"name" => 'Iris CRM', 
					"version" => $this->version 
				)
			)
		);
		$conn->send($message);
	}

	protected function iamHandler(ConnectionInterface $conn, $messageData) {
		// store connection type
		$conn->type = $messageData[1]['type'];

		// if client is connected
		if ($messageData[1]['type'] == static::TYPE_CLIENT) {
			// store client info
			$conn->userid = $messageData[1]['userid'];
			$conn->userlogin = $messageData[1]['userlogin'];

			// send acknowledge to client
			$acknowledgeMessage = json_encode(array(
				'clientconnectacknowledge', 
				array(
					"userid" => $conn->userid
				)
			));			
			$conn->send($acknowledgeMessage);
		}
		
		// if oktell is connected
		if ($messageData[1]['type'] == static::TYPE_OKTELL) {
			$this->oktell = $conn;
			// request pbxnumbers
			$this->requestPbxNumbers();
		}
	}

	protected function requestPbxNumbers() {
		if ($this->oktell == null) {
			return;
		}
		$this->oktell->send(json_encode(array(
			'getpbxnumbers', 
			array(
				"qid" => $this->getGUID(),
				"userlogin" => '',
				"userid" => '',
				"mode" => "full"
			)
		)));
	}

	protected function savePbxNumbers($data) {
		$numbers = $data[1]['numbers'];
		foreach ($numbers as $number) {
			$this->pbxNumbers[$number['number']] = $number;
		}
		ksort($this->pbxNumbers);
	}

	protected function updatePbxNumbers($data) {
		$numbers = $data[1]['numbers'];
		foreach ($numbers as $number) {
			if ($this->pbxNumbers[$number['num']] == null)
				continue;
				
			$this->pbxNumbers[$number['num']]['state'] = $number['num']['numstateid'];
		}
	}

	protected function transferToOktell($data) {
		$message = json_encode($data);
		if ($this->oktell) {
			$this->oktell->send($message);
		}
	}

	protected function transferToClient($data) {
        foreach ($this->clients as $client) {
			if ($client->type != static::TYPE_CLIENT) {
				continue;
			}
            
			if 	(($client->userid != null) and
					(($client->userid == $data[1]['userid']) or 
					($client->userlogin == $data[1]['userlogin']))) {
				$client->send(json_encode($data));
				break;
			}
        }
	}

	protected function broadcastToClients($messageData) {
        foreach ($this->clients as $client) {
			if ($client->type != static::TYPE_CLIENT) {
				continue;
			}

			$client->send(json_encode($messageData));
        }
	}

	protected function getactiveusersHandler($messageData) {
		$activeUsers = array();
        foreach ($this->clients as $client) {
			if ($client->type != static::TYPE_CLIENT) {
				continue;
			}
 			$activeUsers[] = array(
				"userlogin" => $client->userlogin,
				"userid" => $client->userid,
				"type" => $client->type
			);
		}
		
		if ($this->oktell == null) {
			return;
		}
		$this->oktell->send(json_encode(array(
			'activeusers',
			array(
				"qid" => $messageData[1]['qid'],
				"users" => $activeUsers
			)
		)));
	}

	protected function getpbxnumberslistHandler($from, $messageData) {
		if ($this->pbxNumbers == null) {
			// TODO: request pbxNumbers from oktell
			return;
		}

		$this->transferToClient(array(
			"pbxnumberslist",
			array(
				"userid" => $messageData[1]["userid"],
				"userlogin" => $messageData[1]["userlogin"],
				"numbers" => $this->pbxNumbers
			)
		));
	}

	protected function pingHandler($from, $messageData) {
		$answer = $messageData;
		$answer[0] = 'pong';
		$from->send(json_encode($answer));
	}
	
	protected function apiCommandsHandler($from, $messageData) {
		// send POST request to Iris and transfer response to Oktell or client (in case showuserform message)
		$ch = curl_init($this->settings->commandURL);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // do not verify that ssl cert is valid (it is not the case for failover server)
		curl_setopt($ch, CURLOPT_USERAGENT, "Iris CRM ws server");
		curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 seconds
		curl_setopt($ch, CURLOPT_POSTFIELDS, array("data" => base64_encode(json_encode($messageData)), "isbase64" => 1));

		ob_start();
		$bSuccess=curl_exec($ch);
		$responseString=ob_get_contents();
		ob_end_clean();
		$http_result_code=curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$this->log->addInfo('command execute result', array($responseString));
		$responseData = json_decode($responseString, true);
		if (is_array($responseData) == false) {
			$this->log->addError('showform or executemethod returned with error', array($responseString));
			return;
		}

		if ($responseData[0] == 'showuserform') {
			// transfer showuserform command to client
			$this->transferToClient($responseData);
		} else {
			// transfer availableforms, availablemethods or methodresult commands to Oktell
			$messageParts = $this->splitLongMessageString($responseString);
			foreach ($messageParts as $message) {
				$this->log->addInfo('part', $message);
				$this->transferToOktell($message);
			}
		}
	}
	
	// split long message string to multipart messages
	// messageString - in, string
	// returns array arrays (messages)
	protected function splitLongMessageString($messageString) {
		if (strlen($messageString) <= static::BUFFER_SIZE) {
			return array(
				json_decode($messageString, true)
			);
		}

		$result = array();
		$message_id = $this->getGUID();
		$encoded = chunk_split(base64_encode($messageString), static::BUFFER_SIZE, chr(10));
		$encoded = explode(chr(10), $encoded);
		$packetcount = count($encoded) - 1;
		for ($i = 0; $i < $packetcount; $i++) {
			$result[] = array(
				"multipart",
				array (
					"message-id" => $message_id,
					"packetcount" => $packetcount,
					"packetnumber" => ($i+0),
					"content-type" => "text/json; charset=utf-8",
					"content-transfer-encoding" => "base64",
					"content-length" => strlen($encoded[$i]),
					"content" => $encoded[$i]
				)
			);
		}

		return $result;
	}

}