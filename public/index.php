<?php

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Factory;
use React\Http\Response;
use React\Http\Server;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();
$client = new React\HttpClient\Client($loop);

const ETHERSCAN_API_KEY = 'KEY';
const ETHERSCAN_MORIARTY_ADDRESS = '0xE53B252391638CbB780d98b7320132F03A6cE9dE';
const ETHERSCAN_API_ETHPRICE_URL = 'https://api.etherscan.io/api?module=stats&action=ethprice&apikey=' . ETHERSCAN_API_KEY;
const ETHERSCAN_API_BALANCE_URL = 'https://api.etherscan.io/api?module=account&action=balance&address=' .
    ETHERSCAN_MORIARTY_ADDRESS . '&tag=latest&apikey=' . ETHERSCAN_API_KEY;

const TELEGRAM_TOKEN = 'TOKEN';

const ETH_COURSE = 'Курс ефира';
const MORIARTY_BALANCE = 'Баланс';
$menu = [
    ETH_COURSE,
    MORIARTY_BALANCE,
];

$server = new Server(function (ServerRequestInterface $request) use ($menu, $client) {
    $body = json_decode($request->getBody()->getContents(), true);

    $chatId = $body['message']['chat']['id'];
    $text = $body['message']['text'];
    $bot = new \TelegramBot\Api\BotApi(TELEGRAM_TOKEN);
    $keyboard = new \TelegramBot\Api\Types\ReplyKeyboardMarkup([$menu], true, true); // true for one-time keyboard

    echo $text;
    if ($text === '/start') {
        $keyboard = new \TelegramBot\Api\Types\ReplyKeyboardMarkup([$menu], true, true); // true for one-time keyboard

        $bot->sendMessage($chatId, 'Выберите в меню, что интересует', null, false, null, $keyboard);
    } else if ($text === ETH_COURSE) {
        $apiRequest = $client->request('GET', ETHERSCAN_API_ETHPRICE_URL);

        $apiRequest->on('response', function ($response) use ($bot, $chatId, $keyboard) {
            $response->on('data', function ($chunk) use ($bot, $chatId, $keyboard) {
                $data = json_decode($chunk, true);
                $bot->sendMessage($chatId, $data['result']['ethusd'] . '$', null, false, null, $keyboard);
            });
        });

        $apiRequest->end();
    } else if ($text === MORIARTY_BALANCE) {
        $apiRequest = $client->request('GET', ETHERSCAN_API_BALANCE_URL);

        $apiRequest->on('response', function ($response) use ($bot, $chatId, $keyboard) {
            $response->on('data', function ($chunk) use ($bot, $chatId, $keyboard) {
                $data = json_decode($chunk, true);
                $bot->sendMessage($chatId, ($data['result']  / 10**18) . '$', null, false, null, $keyboard);
            });
        });

        $apiRequest->end();
    } else {
        $bot->sendMessage($chatId, 'Выберите в меню, что интересует', null, false, null, $keyboard);
    }


    return new Response(
        200,
        array(
            'Content-Type' => 'text/plain'
        ),
        "Hello world!\n"
    );
});

$socket = new \React\Socket\Server('0.0.0.0:80', $loop);
// $socket = new \React\Socket\SecureServer($socket, $loop, array(
//     'local_cert' => isset($argv[2]) ? $argv[2] :'/etc/letsencrypt/live/johnny-dev.pp.ua/fullchain.pem'
// ));
$server->listen($socket);

echo 'Listening on ' . str_replace('tls:', 'https:', $socket->getAddress()) . PHP_EOL;

$loop->run();
