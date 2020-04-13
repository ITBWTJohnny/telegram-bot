<?php

use Clue\React\Buzz\Browser;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Factory;
use React\Http\Response;
use React\Http\Server;
use Symfony\Component\DomCrawler\Crawler;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();
$client = new React\HttpClient\Client($loop);
$browserClient = new Browser($loop);

const ETHERSCAN_API_KEY = 'KEY';
const ETHERSCAN_MORIARTY_ADDRESS = '0xE53B252391638CbB780d98b7320132F03A6cE9dE';
const ETHERSCAN_API_ETHPRICE_URL = 'https://api.etherscan.io/api?module=stats&action=ethprice&apikey=' . ETHERSCAN_API_KEY;
const ETHERSCAN_API_BALANCE_URL = 'https://api.etherscan.io/api?module=account&action=balance&address=' .
    ETHERSCAN_MORIARTY_ADDRESS . '&tag=latest&apikey=' . ETHERSCAN_API_KEY;
const MORIARTY_CONTRACT_API_URL = 'https://api.moriarty-2.io/api/game/contract/';

const TELEGRAM_TOKEN = 'TOKEN';

const ETH_COURSE = 'Курс ефира';
const MORIARTY_BALANCE = 'Баланс';
$menu = [
    ETH_COURSE,
    MORIARTY_BALANCE,
];

$menu2 = [
    [
        'text' => ETH_COURSE,
        'callback_data' => ETH_COURSE,
    ],
    [
        'text' => MORIARTY_BALANCE,
        'callback_data' => MORIARTY_BALANCE,
    ]
];

$server = new Server(function (ServerRequestInterface $request) use ($menu, $menu2, $client) {
    $body = json_decode($request->getBody()->getContents(), true);
    var_dump($body);

    if (empty($body['message'])) {
        $text = $body['callback_query']['data'];
        $chatId = $body['callback_query']['message']['chat']['id'];
    } else {
        $text = $body['message']['text'];
        $chatId = $body['message']['chat']['id'];
    }

    $bot = new \TelegramBot\Api\BotApi(TELEGRAM_TOKEN);
    $keyboard = new \TelegramBot\Api\Types\ReplyKeyboardMarkup([$menu], true, true); // true for one-time keyboard
    $inlineKeyboard = new \TelegramBot\Api\Types\Inline\InlineKeyboardMarkup([$menu2]); // true for one-time keyboard

    echo $text;
    if ($text === '/start') {
        $bot->sendMessage($chatId, 'Выберите в меню, что интересует', null, false, null, $keyboard);
    } else if ($text === '/manual') {
        $buttons = json_encode([
            'inline_keyboard' => [
                [
                    [
                        "text" => ETH_COURSE,
                        "callback_data" => ETH_COURSE,
                    ],
                    [
                        "text" => MORIARTY_BALANCE,
                        "callback_data" => MORIARTY_BALANCE,
                    ],
                ]
            ],
        ], true);

        $message = [
            "chat_id" => $chatId,
            "text" => $text,
            "reply_markup" => $buttons
        ];
        $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_TOKEN . '/sendMessage');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($message),
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => 10
        ));
        $r = json_decode(curl_exec($ch), true);
        var_dump($r);
    } else if ($text === '/inline') {
            $bot->sendMessage($chatId, 'Выберите в меню, что интересует', null, false, null, $inlineKeyboard);
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
        $apiRequest = $client->request('GET', MORIARTY_CONTRACT_API_URL);

        $apiRequest->on('response', function ($response) use ($bot, $chatId, $keyboard) {
            $response->on('data', function ($chunk) use ($bot, $chatId, $keyboard) {
                $data = json_decode($chunk, true);
                $currentBalance = $data['balance'];
                $maxBalance = $data['max_balance'];
                $result = round($currentBalance, 2) . ' ETH';

                if ($maxBalance > $currentBalance) {
                    $percent = round(($maxBalance - $currentBalance) / $maxBalance * 100, 2);

                    $result .= ', max: ' . round($maxBalance, 2) . ' ETH '. "\u{2193}" . $percent . '%';
                }

                $bot->sendMessage($chatId, $result, null, false, null, $keyboard);
            });
        });

        $apiRequest->end();
    } else {
        $bot->sendMessage($chatId, 'Выберите в меню, что интересует', null, false, null, $keyboard);
    }


});

$socket = new \React\Socket\Server('0.0.0.0:80', $loop);

$server->listen($socket);

echo 'Listening on ' . str_replace('tls:', 'https:', $socket->getAddress()) . PHP_EOL;

$loop->run();
