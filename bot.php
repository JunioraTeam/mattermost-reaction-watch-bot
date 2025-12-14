<?php

require __DIR__.'/vendor/autoload.php';

use Amp\Http\Client\SocketException;
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\ConstantRateLimit;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\PeriodicHeartbeatQueue;
use Dotenv\Dotenv;
use Medoo\Medoo;

function sendMessage(array $parameters): object
{
    $ch = curl_init($_ENV['MATTERMOST_BASE_URL'].'/api/v4/posts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Token '.$_ENV['BOT_TOKEN'],
        ],
        CURLOPT_POSTFIELDS => json_encode($parameters),
    ]);
    $response = curl_exec($ch);

    return json_decode($response);
}

function getUser(string $id): object
{
    static $cache = [];

    if (! empty($cache[$id])) {
        return $cache[$id];
    }

    $ch = curl_init($_ENV['MATTERMOST_BASE_URL'].'/api/v4/users/ids');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Token '.$_ENV['BOT_TOKEN'],
        ],
        CURLOPT_POSTFIELDS => json_encode([
            $id,
        ]),
    ]);
    $response = curl_exec($ch);

    $user = json_decode($response)[0];

    $cache[$id] = $user;

    return $user;
}

function getChannel(string $id): object
{
    static $cache = [];

    if (! empty($cache[$id])) {
        return $cache[$id];
    }

    $ch = curl_init($_ENV['MATTERMOST_BASE_URL'].'/api/v4/channels/'.$id);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Token '.$_ENV['BOT_TOKEN'],
        ],
    ]);
    $response = curl_exec($ch);

    $channel = json_decode($response);

    $cache[$id] = $channel;

    return $channel;
}

function getTeam(string $id): object
{
    static $cache = [];

    if (! empty($cache[$id])) {
        return $cache[$id];
    }

    $ch = curl_init($_ENV['MATTERMOST_BASE_URL'].'/api/v4/teams/'.$id);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Token '.$_ENV['BOT_TOKEN'],
        ],
    ]);
    $response = curl_exec($ch);

    $team = json_decode($response);

    $cache[$id] = $team;

    return $team;
}

function getPost(string $id): object
{
    static $cache = [];

    if (! empty($cache[$id])) {
        return $cache[$id];
    }

    $ch = curl_init($_ENV['MATTERMOST_BASE_URL'].'/api/v4/posts/'.$id);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Token '.$_ENV['BOT_TOKEN'],
        ],
    ]);
    $response = curl_exec($ch);

    $post = json_decode($response);

    $cache[$id] = $post;

    return $post;
}

function createDirectMessageChannel(array $userIds): object
{
    static $cache = [];

    $cacheKey = implode(',', $userIds);
    if (! empty($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $ch = curl_init($_ENV['MATTERMOST_BASE_URL'].'/api/v4/channels/direct');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Token '.$_ENV['BOT_TOKEN'],
        ],
        CURLOPT_POSTFIELDS => json_encode($userIds),
    ]);
    $response = curl_exec($ch);

    $channel = json_decode($response);

    $cache[$cacheKey] = $channel;

    return $channel;
}

$env = Dotenv::createImmutable(__DIR__);
$env->load();

$db = new Medoo([
    'type' => $_ENV['DB_TYPE'],
    'host' => $_ENV['DB_HOST'],
    'username' => $_ENV['DB_USERNAME'],
    'password' => $_ENV['DB_PASSWORD'],
    'database' => $_ENV['DB_NAME'],
]);

$tableExists = $db->query('SHOW TABLES LIKE \'watches\';')->fetch();

if (! $tableExists) {
    $db->create('watches', [
        'user_id' => [
            'VARCHAR(255)',
            'NOT NULL',
        ],
        'channel_id' => [
            'VARCHAR(255)',
            'NOT NULL',
        ],
        'post_id' => [
            'VARCHAR(255)',
            'NOT NULL',
        ],
        'type' => [
            'VARCHAR(255)',
            'NOT NULL',
        ],
    ]);
}

$connectionFactory = new Rfc6455ConnectionFactory(
    heartbeatQueue: new PeriodicHeartbeatQueue(
        heartbeatPeriod: 5, // 5 seconds
    ),
    rateLimit: new ConstantRateLimit(
        bytesPerSecondLimit: 2 ** 17, // 128 KiB
        framesPerSecondLimit: 10,
    ),
    parserFactory: new Rfc6455ParserFactory(
        messageSizeLimit: 2 ** 20, // 1 MiB
    ),
    frameSplitThreshold: 2 ** 14, // 16 KiB
    closePeriod: 0.5, // 0.5 seconds
);

$connector = new Rfc6455Connector($connectionFactory);

while (true) {
    try {
        $handshake = new WebsocketHandshake($_ENV['MATTERMOST_WEBSOCKET_URL']);
        $connection = $connector->connect($handshake);
        $connection->sendText(json_encode([
            'seq' => 1,
            'action' => 'authentication_challenge',
            'data' => [
                'token' => $_ENV['BOT_TOKEN'],
            ],
        ]));
        $botUserId = null;
        foreach ($connection as $message) {
            $message = json_decode($message->buffer());
            $message->event ??= null;
            if ($message->event === 'hello') {
                $botUserId = $message->broadcast->user_id;
                continue;
            }
            if (empty($botUserId)) {
                continue;
            }
            if (! in_array($message->event, ['reaction_added', 'reaction_removed'], true)) {
                continue;
            }

            $reaction = json_decode($message->data->reaction);
            if ($message->event === 'reaction_added') {
                $threadWatch = $db->get(
                    'watches',
                    [
                        'channel_id',
                        'user_id',
                        'type',
                    ],
                    [
                        'post_id' => $reaction->post_id,
                        'type' => 'thread',
                    ],
                );
                $dmWatches = $db->select(
                    'watches',
                    [
                        'channel_id',
                        'user_id',
                        'type',
                    ],
                    [
                        'post_id' => $reaction->post_id,
                        'type' => 'dm',
                    ],
                );

                $user = getUser($reaction->user_id);
                $teamName = getTeam(getChannel($reaction->channel_id)->team_id)->name;

                if (! empty($threadWatch)) {
                    $rootId = getPost($reaction->post_id)->root_id;
                    sendMessage([
                        'channel_id' => $threadWatch['channel_id'],
                        'message' => sprintf(
                            empty($rootId)
                                ? 'کاربر %s %s ری‌اکشن :%s: را به پیام اضافه کرد.'
                                : 'کاربر %s %s ری‌اکشن :%s: را به این پیام اضافه کرد:',
                            $user->first_name,
                            $user->last_name,
                            $reaction->emoji_name,
                        ).(! empty($rootId) ? sprintf("\n\n%s/%s/pl/%s", $_ENV['MATTERMOST_BASE_URL'], $teamName, $reaction->post_id) : ''),
                        'root_id' => $rootId ?: $reaction->post_id,
                    ]);
                }
                foreach ($dmWatches as $watch) {
                    sendMessage([
                        'channel_id' => createDirectMessageChannel([$botUserId, $reaction->user_id])->id,
                        'message' => sprintf(
                            "کاربر %s %s ری‌اکشن :%s: را به این پیام اضافه کرد:\n\n%s/%s/pl/%s",
                            $user->first_name,
                            $user->last_name,
                            $reaction->emoji_name,
                            $_ENV['MATTERMOST_BASE_URL'],
                            $teamName,
                            $reaction->post_id,
                        ),
                    ]);
                }

                if (in_array($reaction->emoji_name, [$_ENV['THREAD_WATCH_EMOJI_NAME'], $_ENV['DM_WATCH_EMOJI_NAME']], true)) {
                    $row = [
                        'user_id' => $reaction->user_id,
                        'channel_id' => $reaction->channel_id,
                        'post_id' => $reaction->post_id,
                        'type' => $reaction->emoji_name === $_ENV['THREAD_WATCH_EMOJI_NAME'] ? 'thread' : 'dm',
                    ];
                    if (! $db->has('watches', $row)) {
                        $db->insert('watches', $row);
                        sendMessage([
                            'channel_id' => createDirectMessageChannel([$botUserId, $reaction->user_id])->id,
                            'message' => sprintf(
                                $reaction->emoji_name === $_ENV['THREAD_WATCH_EMOJI_NAME']
                                    ? "از این پس، اطلاعیه ری‌اکشن‌های روی این پیام در ترد مربوط به آن ارسال خواهند شد.\n\n%s/%s/pl/%s"
                                    : "از این پس، اطلاعیه ری‌اکشن‌های روی این پیام از طریق پیام مستقیم دریافت خواهید کرد.\n\n%s/%s/pl/%s",
                                $_ENV['MATTERMOST_BASE_URL'],
                                $teamName,
                                $reaction->post_id,
                            ),
                        ]);
                    }
                }
            } if ($message->event === 'reaction_removed') {
                if (in_array($reaction->emoji_name, [$_ENV['THREAD_WATCH_EMOJI_NAME'], $_ENV['DM_WATCH_EMOJI_NAME']], true)) {
                    $row = [
                        'user_id' => $reaction->user_id,
                        'post_id' => $reaction->post_id,
                        'type' => $reaction->emoji_name === $_ENV['THREAD_WATCH_EMOJI_NAME'] ? 'thread' : 'dm',
                    ];

                    if ($db->has('watches', $row)) {
                        sendMessage([
                            'channel_id' => createDirectMessageChannel([$botUserId, $reaction->user_id])->id,
                            'message' => sprintf(
                                $reaction->emoji_name === $_ENV['THREAD_WATCH_EMOJI_NAME']
                                    ? "از این پس، اطلاعیه ری‌اکشن‌های روی این پیام در صورتی که ری‌اکشن :".$_ENV['THREAD_WATCH_EMOJI_NAME'].": بر روی آن باقی نمانده باشد، در ترد مربوط به آن ارسال نخواهند شد.\n\n%s/%s/pl/%s"
                                    : "از این پس، اطلاعیه ری‌اکشن‌های روی این پیام از طریق پیام مستقیم دریافت نخواهید کرد.\n\n%s/%s/pl/%s",
                                $_ENV['MATTERMOST_BASE_URL'],
                                $teamName,
                                $reaction->post_id,
                            ),
                        ]);
                        $db->delete('watches', $row);
                    }
                }

                $threadWatch = $db->get(
                    'watches',
                    [
                        'channel_id',
                        'user_id',
                        'type',
                    ],
                    [
                        'post_id' => $reaction->post_id,
                        'type' => 'thread',
                    ],
                );
                $dmWatches = $db->select(
                    'watches',
                    [
                        'channel_id',
                        'user_id',
                        'type',
                    ],
                    [
                        'post_id' => $reaction->post_id,
                        'type' => 'dm',
                    ],
                );

                $channelId = $db->get('watches', 'channel_id', ['post_id' => $reaction->post_id]);
                if (empty($channelId)) {
                    continue;
                }
                $user = getUser($reaction->user_id);
                $teamName = getTeam(getChannel($channelId)->team_id)->name;

                if (! empty($threadWatch)) {
                    $rootId = getPost($reaction->post_id)->root_id;
                    sendMessage([
                        'channel_id' => $threadWatch['channel_id'],
                        'message' => sprintf(
                            empty($rootId)
                                ? 'کاربر %s %s ری‌اکشن :%s: را از روی پیام برداشت.'
                                : 'کاربر %s %s ری‌اکشن :%s: را از روی این پیام برداشت:',
                            $user->first_name,
                            $user->last_name,
                            $reaction->emoji_name,
                        ).(! empty($rootId) ? sprintf("\n\n%s/%s/pl/%s", $_ENV['MATTERMOST_BASE_URL'], $teamName, $reaction->post_id) : ''),
                        'root_id' => $rootId ?: $reaction->post_id,
                    ]);
                }
                foreach ($dmWatches as $watch) {
                    sendMessage([
                        'channel_id' => createDirectMessageChannel([$botUserId, $reaction->user_id])->id,
                        'message' => sprintf(
                            "کاربر %s %s ری‌اکشن :%s: را از روی این پیام برداشت:\n\n%s/%s/pl/%s",
                            $user->first_name,
                            $user->last_name,
                            $reaction->emoji_name,
                            $_ENV['MATTERMOST_BASE_URL'],
                            $teamName,
                            $reaction->post_id,
                        ),
                    ]);
                }
            }
        }
    } catch (SocketException) {
        Sleep::for(1)->seconds();
    } catch (Exception) {
        break;
    }
}
