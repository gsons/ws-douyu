<?php

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use WsDouYu\SuperAsyncTcpConnection;
use WsDouYu\Log;
use WsDouYu\Room;

date_default_timezone_set('PRC');
$worker = new Worker("websocket://0.0.0.0:2346");
$worker->count = 4;
$worker->onWorkerStart = function ($worker) {

    $worker->onMessage = function ($connection, $data) {
        $jsonData = json_decode($data, true);
        $con = new SuperAsyncTcpConnection('tcp://openbarrage.douyutv.com:8601');
        $con->onConnect = function ($con) use ($jsonData) {
            $room = new Room($jsonData['roomId']);
            $con->roomId = $jsonData['roomId'];
            $room->login($con)->joinGroup($con)->heartLive($con);
            Log::log("[房间号{$con->roomId}:]已建立连接", LOG::WARN);
        };
        $con->onMessage = function ($con, $data) use ($connection, $jsonData) {
            $msgArr = Room::parserChat($data, $jsonData['roomId']);
            foreach ($msgArr as $msg) {
                $msg['roomId'] = $jsonData['roomId'];
                $connection->send(json_encode($msg));
            }

        };
        $con->onClose = function ($con) {
            Log::log("[房间号{$con->roomId}:]已关闭连接", LOG::ERROR);
            $con->reconnect();
            $room = new Room($con->roomId);
            $room->login($con)->joinGroup($con)->heartLive($con);
        };
        $con->onError = function ($con) {
            Log::log("[房间号{$con->roomId}:]连接错误", LOG::ERROR);
        };
        $con->connect();
    };;
};

Worker::runAll();

