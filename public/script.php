<?php

use Clue\React\Buzz\Browser;
use React\EventLoop\Factory;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();
$client = new React\HttpClient\Client($loop);
$browserClient = new Browser($loop);

$socket = new \React\Socket\Server('0.0.0.0:8080', $loop);
// $socket = new \React\Socket\SecureServer($socket, $loop, array(
//     'local_cert' => isset($argv[2]) ? $argv[2] :'/etc/letsencrypt/live/johnny-dev.pp.ua/fullchain.pem'
// ));
$server->listen($socket);

echo 'Listening on ' . str_replace('tls:', 'https:', $socket->getAddress()) . PHP_EOL;

$loop->run();