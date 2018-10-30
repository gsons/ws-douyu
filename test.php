<?php
/**
 * ws-douyu 斗鱼websocket
 * 消息类型:
 * 1>加入房间({msgType:joinRoom,roomId:520})
 * 2>退出房间({msgType:leaveRoom,roomId:520})
 * 3>发送心跳({msgType:heartLive})
 */
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use WsDouYu\SuperAsyncTcpConnection;
use WsDouYu\Log;
use WsDouYu\Room;
use Workerman\lib\Timer;

date_default_timezone_set('PRC');
defined('HEARTBEAT_TIME') OR define('HEARTBEAT_TIME', 45);

$worker = new Worker("websocket://0.0.0.0:2346");
$worker->count = 4;
$worker->connectionArr = [];
$worker->ConnectCount = 0;
$worker->onWorkerStart = function ($worker) {
    $worker->onMessage = function ($connection, $data) {
        global $worker;
        $connection->lastMessageTime = time();
        $jsonData = json_decode($data, true);
        if (!isset($jsonData['msgType'])) return;
        $msgType = $jsonData['msgType'];//消息类型
        //消息类型为进入房间
        if ($msgType === 'joinRoom' && $roomId = isset($jsonData['roomId'])) {
            $uid = uniqid();
            $roomId =$jsonData['roomId'];
            $connection->uid = $uid;
            $connection->roomId = $roomId;
            $worker->connectionArr[$roomId][$uid] = $connection;
            $connLen = count($worker->connectionArr[$roomId]);
            //房间有且只有一个连接时 连接斗鱼socket加入指定房间
            if ($connLen === 1) {
                $con = new SuperAsyncTcpConnection('tcp://openbarrage.douyutv.com:8601');
                $con->onConnect = function ($con) use ($roomId, $connection) {
                    $room = new Room($roomId);
                    $con->roomId = $roomId;
                    $room->login($con)->joinGroup($con)->heartLive($con);
                    Log::log("[斗鱼房间号{$con->roomId}:]已建立连接", LOG::WARN);
                };
                $con->onMessage = function ($con, $data) {
                    global $worker;
                    $msgArr = Room::parserChat($data, $con->roomId);
                    foreach ($msgArr as $msg) {
                        $msg['roomId'] = $con->roomId;
                        foreach ($worker->connectionArr[$con->roomId] as $_conn) {
                            $_conn->send(json_encode($msg));
                        }
                    }
                };
                $con->onClose = function ($con) {
                    Log::log("[斗鱼房间号{$con->roomId}:]已关闭连接", LOG::ERROR);
                    $con->reconnect(1);
                    $room = new Room($con->roomId);
                    $room->login($con)->joinGroup($con)->heartLive($con);
                };
                $con->onError = function ($con) {
                    Log::log("[斗鱼房间号{$con->roomId}:]连接错误", LOG::ERROR);
                };
                $con->connect();
            }

        } elseif ($msgType === 'leaveRoom' && isset($connection->roomId)) {
            $connection->close();
        }
    };
    $worker->onConnect = function ($connection) {
        global $worker;
        $worker->ConnectCount++;
        Log::log("WS已建立连接,连接总数:{$worker->ConnectCount}", LOG::WARN);
    };
    $worker->onClose = function ($connection) {
        global $worker;
        $worker->ConnectCount--;
        if (isset($connection->uid) && isset($connection->roomId)) {
            unset($worker->connectionArr[$connection->roomId][$connection->uid]);
            unset($connection->uid);
            unset($connection->roomId);
        }
        Log::log("WS关闭连接,连接总数:{$worker->ConnectCount}", LOG::WARN);
    };
    $worker->onError = function ($connection) {
        global $worker;
        if (isset($connection->uid) && isset($connection->roomId)) {
            unset($worker->connectionArr[$connection->roomId][$connection->uid]);
            unset($connection->uid);
            unset($connection->roomId);
        }
        Log::log("WS连接错误", LOG::WARN);
    };

    Timer::add(1, function () use ($worker) {
        $time_now = time();
        foreach ($worker->connections as $connection) {
            // 有可能该connection还没收到过消息，则lastMessageTime设置为当前时间
            if (empty($connection->lastMessageTime)) {
                $connection->lastMessageTime = $time_now;
                continue;
            }
            // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
            if ($time_now - $connection->lastMessageTime > HEARTBEAT_TIME) {
                $connection->close();
            }
        }
    });
};
Worker::runAll();

