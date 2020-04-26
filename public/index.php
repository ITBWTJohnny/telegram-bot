<?php

use Clue\React\Buzz\Browser;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Factory;
use React\Http\Response;
use React\Http\Server;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__, '/../.env');
$dotenv->load();

$loop = Factory::create();

$client = new React\HttpClient\Client($loop);

$factory = new \React\MySQL\Factory($loop);
$uri = rawurlencode(getenv('MYSQL_USER')) . ':' . rawurlencode(getenv('MYSQL_PASSWORD')) . '@' . getenv('MYSQL_HOST') .':3306/' . getenv('MYSQL_DB');
$connection = $factory->createLazyConnection($uri);

const ETH_COURSE = 'Курс ефира';
const MORIARTY_BALANCE = 'Баланс';
const MORIARTY_ANALYTICS = 'Аналитика';
const MORIARTY_SITE = 'Сайт';

$menu = [
    ETH_COURSE,
    MORIARTY_BALANCE,
    MORIARTY_ANALYTICS,
    MORIARTY_SITE,
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

$server = new Server(function (ServerRequestInterface $request) use ($menu, $menu2, $client, $connection) {
    $body = json_decode($request->getBody()->getContents(), true);
    var_dump($body);

    if (empty($body['message'])) {
        $text = $body['callback_query']['data'];
        $chatId = $body['callback_query']['message']['chat']['id'];
    } else {
        $text = $body['message']['text'];
        $chatId = $body['message']['chat']['id'];
    }

    $bot = new \TelegramBot\Api\BotApi(getenv('TELEGRAM_TOKEN'));
    $keyboard = new \TelegramBot\Api\Types\ReplyKeyboardMarkup([$menu], true, true); // true for one-time keyboard
    $inlineKeyboard = new \TelegramBot\Api\Types\Inline\InlineKeyboardMarkup([$menu2]); // true for one-time keyboard

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
        $ch = curl_init('https://api.telegram.org/bot' . getenv('TELEGRAM_TOKEN') . '/sendMessage');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($message),
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => 10
        ));
        $r = json_decode(curl_exec($ch), true);
    } else if ($text === '/inline') {
            $bot->sendMessage($chatId, 'Выберите в меню, что интересует', null, false, null, $inlineKeyboard);
    } else if ($text === ETH_COURSE) {
        $apiRequest = $client->request('GET', getenv('ETHERSCAN_API_ETHPRICE_URL') . '&apikey=' . getenv('ETHERSCAN_API_KEY'));

        $apiRequest->on('response', function ($response) use ($bot, $chatId, $keyboard) {
            $response->on('data', function ($chunk) use ($bot, $chatId, $keyboard) {
                $data = json_decode($chunk, true);
                $bot->sendMessage($chatId, $data['result']['ethusd'] . '$', null, false, null, $keyboard);
            });
        });

        $apiRequest->end();
    } else if ($text === MORIARTY_BALANCE) {
        $apiRequest = $client->request('GET', getenv('MORIARTY_CONTRACT_API_URL'));

        $apiRequest->on('response', function ($response) use ($bot, $chatId, $keyboard) {
            $response->on('data', function ($chunk) use ($bot, $chatId, $keyboard) {
                $data = json_decode($chunk, true);
                $currentBalance = $data['balance'];
                $maxBalance = $data['max_balance'];
                $result = round($currentBalance, 2) . ' ETH';

                if ($maxBalance > $currentBalance) {
                    $percent = round(($maxBalance - $currentBalance) / $maxBalance * 100, 2);

                    $result .= ', max: ' . round($maxBalance, 2) . ' ETH ' . "\u{2193}" . $percent . '%';
                }

                $bot->sendMessage($chatId, $result, null, false, null, $keyboard);
            });
        });

        $apiRequest->end();
    } else if ($text === MORIARTY_ANALYTICS) {
        $query = 'select sum(paidAmount) as sum, date(createdAt) as date from payments where DATE(createdAt) > (NOW() - INTERVAL 7 DAY) group by date';
        $paymentsPromise = $connection->query($query);
        $query = 'select sum(amount) as sum, date(createdAt) as date from withdraws where DATE(createdAt) > (NOW() - INTERVAL 7 DAY) group by date';
        $withdrawPromise = $connection->query($query);

        $promise = \React\Promise\all([$paymentsPromise, $withdrawPromise])->then(function ($data) use ($bot, $chatId, $keyboard) {
            $paymentsData = $data[0]->resultRows;
            $withdrawData = $data[1]->resultRows;
            $paymentsByDate = $withdrawByDate = [];

            foreach ($paymentsData as $item) {
                $paymentsByDate[$item['date']] = $item['sum'];
            }

            foreach ($withdrawData as $item) {
                $withdrawByDate[$item['date']] = $item['sum'];
            }

            $period = new DatePeriod(
                (new DateTime())->setTimestamp(strtotime('-6 day')),
                new DateInterval('P1D'),
                (new DateTime())->setTimestamp(strtotime('+1 day'))
            );

            $message = '';
            $today = date('Y-m-d');
            var_dump($period);
            foreach ($period as $item) {
                $date = $item->format('Y-m-d');
                $paymentSum = $paymentsByDate[$date] ?? 0;
                $withdrawSum = $withdrawByDate[$date] ?? 0;
                $dayDifferent = round($paymentSum - $withdrawSum, 2);
                $char = $dayDifferent ? "\u{2191}" : (($dayDifferent == 0) ? '' : "\u{2193}");
                $message .= $date . ' ' . $char . $dayDifferent . " ETH";

                if ($today !== $date) {
                    $message .= "\n";
                }
            }
            var_dump($message);
            $bot->sendMessage($chatId, $message, null, false, null, $keyboard);
        });
    } else if ($text === MORIARTY_SITE) {
        $bot->sendMessage($chatId, getenv('MORIARTY_URL'), null, false, null, $keyboard);
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

$checkWithdrawTimer = $loop->addPeriodicTimer(30 * 60, function () use ($connection, $client) {
    $postData = json_encode(['email' => getenv('MORIARTY_EMAIL'), 'password' => getenv('MORIARTY_PASSWORD')]);
    $request = $client->request('POST', getenv('MORIARTY_CREATE_TOKEN_API_URL'), [
        'Content-Type' => 'application/json',
        'Content-Length' => strlen($postData),
    ]);
    $request->write($postData);

    $request->on('response', function ($response) use ($client, $connection) {
        $response->on('data', function ($chunk) use ($client, $connection) {
            $responseData = json_decode($chunk, true);
            var_dump($responseData);

            if (!empty($responseData['token'])) {
                $token = $responseData['token'];

                $request = $client->request('GET', 'https://api.moriarty-2.io/api/withdraw/table/?page=1', [
                    'authorization' => 'Bearer ' . $token
                ]);

                $request->on('response', function ($response) use ($connection, $client, $token) {
                    $response->on('data', function ($data) use ($connection, $client, $token) {
                        $dataArray = json_decode($data, true);
                        var_dump($dataArray);

                        $connection->query('SELECT COUNT(id) as count, max(createdAt) as maxDate from withdraws')->then(
                            function (\React\MySQL\QueryResult $result) use ($dataArray, $client, $token, $connection) {
                                var_dump($result);
                                $countInDb = $result->resultRows[0]['count'];
                                $lastItemTimestamp = strtotime($result->resultRows[0]['maxDate']);

                                if (!empty($dataArray['total'])) {
                                    $different = $dataArray['total'] - $countInDb;
                                    $pageCount = ceil($different / $dataArray['pageSize']);

                                    for ($i = 1; $i < $pageCount; $i++) {
                                        var_dump('request #' . $i);
                                        $request = $client->request('GET', 'https://api.moriarty-2.io/api/withdraw/table/?page=' . ($i) . '&pageSize=7', [
                                            'authorization' => 'Bearer ' . $token
                                        ]);

                                        $request->on('response', function ($response) use ($connection, $lastItemTimestamp) {
                                            $response->on('data', function ($chunk) use ($connection, $lastItemTimestamp) {
                                                $responseData = json_decode($chunk, true);

                                                if (!empty($responseData['data'])) {
                                                    foreach ($responseData['data'] as $item) {
                                                        if ($lastItemTimestamp < $item['createdAt'] / 1000) {
                                                            $query = "INSERT INTO withdraws (hash, method, gameType, amount, type, status, createdAt)
                                                    VALUES (?, ?, ?, ?, ?, ?, ?)";

                                                            var_dump($responseData);
                                                            $connection->query($query, [$item['id'], $item['method'], $item['gameType'], $item['amount'], $item['type'], $item['status'], date('Y-m-d H:i:s', ($item['createdAt'] / 1000))])->then(
                                                                function (\React\MySQL\QueryResult $command) {
                                                                    var_dump($command);
                                                                },
                                                                function (Exception $error) {
                                                                    echo 'Error: ' . $error->getMessage() . PHP_EOL;
                                                                }
                                                            );
                                                        }
                                                    }
                                                }
                                            });
                                        });

                                        $request->end();
                                    }
                                }
                            });
                    });
                });

                $request->end();
            }
        });
        $response->on('end', function() {
            echo 'DONE';
        });
    });
    $request->on('error', function (\Exception $e) {
        echo $e;
    });
    var_dump('case analytics');
});

$checkPaymentsTimer = $loop->addPeriodicTimer(30 * 60, function () use ($connection, $client) {
    $postData = json_encode(['email' => '19ivan.lev97@gmail.com', 'password' => getenv('MORIARTY_PASSWORD')]);
    $request = $client->request('POST', getenv('MORIARTY_CREATE_TOKEN_API_URL'), [
        'Content-Type' => 'application/json',
        'Content-Length' => strlen($postData),
    ]);
    $request->write($postData);

    $request->on('response', function ($response) use ($client, $connection) {
        $response->on('data', function ($chunk) use ($client, $connection) {
            $responseData = json_decode($chunk, true);
            var_dump($responseData);

            if (!empty($responseData['token'])) {
                $token = $responseData['token'];

                $request = $client->request('GET', 'https://api.moriarty-2.io/api/payment/table/?page=1', [
                    'authorization' => 'Bearer ' . $token
                ]);

                $request->on('response', function ($response) use ($connection, $client, $token) {
                    $response->on('data', function ($data) use ($connection, $client, $token) {
                        $dataArray = json_decode($data, true);
                        var_dump($dataArray);

                        $connection->query('SELECT COUNT(id) as count, max(createdAt) as maxDate from payments')->then(
                            function (\React\MySQL\QueryResult $result) use ($dataArray, $client, $token, $connection) {
                                var_dump($result);
                                $countInDb = $result->resultRows[0]['count'];
                                $lastItemTimestamp = strtotime($result->resultRows[0]['maxDate']);

                                if (!empty($dataArray['total'])) {
                                    $different = $dataArray['total'] - $countInDb;
                                    $pageCount = ceil($different / $dataArray['pageSize']);

                                    for ($i = 1; $i < $pageCount; $i++) {
                                        var_dump('request #' . $i);
                                        $request = $client->request('GET', 'https://api.moriarty-2.io/api/payment/table/?page=' . ($i) . '&pageSize=7', [
                                            'authorization' => 'Bearer ' . $token
                                        ]);

                                        $request->on('response', function ($response) use ($connection, $lastItemTimestamp) {
                                            $response->on('data', function ($chunk) use ($connection, $lastItemTimestamp) {
                                                $responseData = json_decode($chunk, true);

                                                if (!empty($responseData['data'])) {
                                                    foreach ($responseData['data'] as $item) {
                                                        if ($lastItemTimestamp < $item['createdAt'] / 1000) {
                                                            $query = "INSERT INTO payments (hash, pair, method, requestAmount, paidAmount, confirmations, gameType, type, status, createdAt)
                                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                                                            var_dump($responseData);
                                                            $connection->query($query, [$item['id'], $item['pair'], $item['method'], $item['requestAmount'], $item['paidAmount'], $item['confirmations'], $item['gameType'], $item['type'], $item['status'], date('Y-m-d H:i:s', ($item['createdAt'] / 1000))])->then(
                                                                function (\React\MySQL\QueryResult $command) {
                                                                    var_dump($command);
                                                                },
                                                                function (Exception $error) {
                                                                    echo 'Error: ' . $error->getMessage() . PHP_EOL;
                                                                }
                                                            );
                                                        }
                                                    }
                                                }
                                            });
                                        });

                                        $request->end();
                                    }
                                }
                            });
                    });
                });

                $request->end();
            }
        });
        $response->on('end', function() {
            echo 'DONE';
        });
    });
    $request->on('error', function (\Exception $e) {
        echo $e;
    });
});

$socket = new \React\Socket\Server('0.0.0.0:80', $loop);

$server->listen($socket);

echo 'Listening on ' . str_replace('tls:', 'https:', $socket->getAddress()) . PHP_EOL;

//$connection->quit();
$loop->run();
