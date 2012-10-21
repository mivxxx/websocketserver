<?php
namespace Iris\WebSocket;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class WebSocketServer implements MessageComponentInterface {
	protected $version = '2.0'; // Websocket server version

    protected $clients;         // WS connections
	protected $oktell = null;   // oktell connection (stored twice for more fast searching)


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
		$this->sendWhoAreYou($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg) {
		echo 'read> '.$msg.chr(10);
		//$server->log('read> '.$msg);
		$messageData = json_decode($msg, true);
		
		// if multipart message is recieved, then collect them
		if ($messageData[0] == 'multipart') {
		}
		
		switch ($messageData[0]) {
			case 'whoareyou':
				$this->whoareyouHandler($from, $messageData);
				
			case 'iam':
				$this->iamHandler($from, $messageData);
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
	
	protected function whoareyouHandler(ConnectionInterface $conn) {
		$message = json_encode(
			array(
				'whoareyou', 
				array(
					"qid" => substr($this->getGUID(), 1, 36), 
					"type" => 'ws-server', 
					"name" => 'Iris CRM', 
					"version" => $this->version 
				)
			)
		);
		$conn->send($message);
	}

	protected function sendIam(ConnectionInterface $conn, $messageData) {
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
			// TODO: request pbxnumbers
		}
	}
}