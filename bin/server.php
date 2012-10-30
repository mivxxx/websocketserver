<?php
use Iris\WebSocket\WebSocketServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use Monolog\Logger;

    require dirname(__DIR__) . '/vendor/autoload.php';

	// load settings
	$settings = json_decode(
		file_get_contents(realpath(dirname(__FILE__)).'/../settings/settings.json')
	);

	// init log class
    $log = new Logger('events');
    $stdout = new \Monolog\Handler\StreamHandler('php://stdout');
	$file = new \Monolog\Handler\StreamHandler(realpath(dirname(__FILE__)).'/../log/'.$settings->logFileName.'.log');	
	$dateFormat = "d.m.Y H:i:s.u";
	$output = "%datetime% %level_name%: %message% %context% %extra%\n";
	$formatter = new \Monolog\Formatter\LineFormatter($output, $dateFormat);
	$stdout->setFormatter($formatter);
	$file->setFormatter($formatter);

	if ($settings->logToStdout) {
		$log->pushHandler($stdout);
	}
	if ($settings->logToFile) {
		$log->pushHandler($file);
	}

	// init and run websocket server
    $server = IoServer::factory(
        new WsServer(
            new WebSocketServer(
				$settings,
				$log
			)
        )
      , $settings->listenPort
    );

    $server->run();