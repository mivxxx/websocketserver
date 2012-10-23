<?php
namespace Iris\WebSocket;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class WebSocketServer implements MessageComponentInterface {
	protected $version = '2.0'; // Websocket server version

    protected $clients;         // WS connections
	protected $oktell = null;   // oktell connection (stored twice for more fast searching)
	protected pbxNumbers = array();


	///////////////////////////////////
	///////  public functions   ///////
	///////////////////////////////////

    public function __construct() {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {
		echo '---connected'.chr(10);
        // Store the new connection to send messages to later
        $this->clients->attach($conn);
		
		// send whoareyou message to client
		$this->onOpenHandler($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg) {
		echo 'read> '.$msg.chr(10);
		//$server->log('read> '.$msg);
		$messageData = json_decode($msg, true);

		// if multipart message is recieved, then collect them
		if ($messageData[0] == 'multipart') {
			// TODO
		}

		switch ($messageData[0]) {
			// client messages
			case 'login':
			case 'logout':
			case 'entercallcenter':
			case 'exitcallcenter':
			case 'getuserstate':
			case 'setuserstate':
			case 'getuserstate':

			case 'pbxautocallstart':
			case 'pbxautocallabort':
			case 'pbxtransfercall':
			case 'getpbxnumbers':
			case 'pbxabortcall':
			case 'sendusertextmessage':
				$this->transferToOktell($messageData, true);
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
				$this->pingHandler($from, $messagedata);
			break;

			// Oktell API commands
			case 'getavailableforms':
			case 'getavailablemethods':
			case 'showform':
			case 'executemethod':
				//$this->apiCommandsHandler($from, $messagedata);
			break;
			
			default:
				// TODO
			}
/*
        foreach ($this->clients as $client) {
            //if ($from !== $client) {
                $client->send($msg);
                //$client->send($from->name.':'.$msg);
            //}
        }
*/
    }

    public function onClose(ConnectionInterface $conn) {
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";

        $conn->close();
    }

	///////////////////////////////////
	/////// protected functions ///////
	///////////////////////////////////

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
		$conn->send($message);
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
		if ($messageData[1]['type'] == 'iriscrm-client') {
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
		if ($messageData[1]['type'] == 'commserver') {
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

	protected function updatePbxNumbers($numbers) {
		foreach ($numbers as $number) {
			if ($this->pbxNumbers[$numbers['num']] == null)
				continue;
				
			$this->pbxNumbers[$number['num']]['state'] = $number['num']['numstateid'];
		}
	}

	protected function transferToOktell($data, $isEncode) {
		$message = $isEncode ? json_encode($data) : $data;
		if ($this->oktell) {
			$this->oktell->send($message);
		}
	}

	protected function transferToClient($data) {
        foreach ($this->clients as $client) {
			if ($client->type != 'iriscrm-client') {
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
			if ($client->type != 'iriscrm-client') {
				continue;
			}

			$client->send(json_encode($messageData));
			}
        }
	}

	protected function getactiveusersHandler($messageData) {
		$activeUsers = array();
        foreach ($this->clients as $client) {
			if ($client->type != 'iriscrm-client') {
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
				"numbers" => $$this->pbxNumbers
			)
		));
	}

	protected function pingHandler($from, $messageData) {
		$answer = $messageData;
		$answer[0] = 'pong';
		$from->send(json_encode($answer));
	}

}