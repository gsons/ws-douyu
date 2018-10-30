<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/10/30
 * Time: 14:45
 */

namespace WsDouYu;
use Workerman\Lib\Timer;


class Room
{
    private $roomId;
    private $heartTime;
    private static $giftInfo;

    public function __construct($roomId, $heartTime = 45)
    {
        $this->roomId = $roomId;
        $this->heartTime = $heartTime;
    }

    /**
     * @param $conn SuperAsyncTcpConnection
     * @return  Room;
     */
    public function login($conn)
    {
        $msg = new SocketMsg("type@=loginreq/roomid@={$this->roomId}/");
        $conn->send($msg->getByte(), false, $msg->getLength());
        return $this;
    }

    /**
     * @param $conn SuperAsyncTcpConnection
     * @return  Room;
     */
    public function joinGroup($conn)
    {
        $msg = new SocketMsg("type@=joingroup/rid@={$this->roomId}/gid@=-9999/");
        $conn->send($msg->getByte(), false, $msg->getLength());
        return $this;
    }

    /**
     * @param $conn SuperAsyncTcpConnection
     */
    public function heartLive($conn)
    {
        Timer::add($this->heartTime, function () use ($conn) {
            $msg = new SocketMsg(sprintf('type@=keeplive/tick@=%s/', time()));
            $conn->send($msg->getByte(), false, $msg->getLength());
        });
    }


    /**
     * 解析弹幕信息
     * @param string $content 弹幕信息
     * @throws \Exception
     * @param  $roomId
     * @return array;
     */
    public static function parserChat($content, $roomId)
    {
        preg_match_all('/(type@=.*?)\x00/', $content, $matches);
        $msgArr = [];
        foreach ($matches[1] as $vo) {
            $msg = preg_replace('/@=/', '":"', $vo);
            $msg = preg_replace('/\//', '","', $msg);
            $msg = substr($msg, 0, strlen($msg) - 3);
            $msg = '{"' . $msg . '"}';
            $obj = json_decode($msg, true);
            $msg_obj = false;
            switch ($obj['type']) {
                case 'chatmsg':
                    $msg_obj = self::buildChat($obj);
                    break;
                case 'dgb':
                    $msg_obj = self::buildGift($obj, $roomId);
                    break;
                case 'bc_buy_deserve':
                    $msg_obj = self::buildDeserve($obj);
                    break;
            }
            if ($msg_obj) $msgArr[] = $msg_obj;
        }
        return $msgArr;
    }

    /**
     * 组装聊天消息数组
     * @access private
     * @param $msgArr
     * @return array
     */
    public static function buildChat($msgArr)
    {
        $plat = 'pc_web';
        if (isset($msgArr['ct']) && $msgArr['ct'] == '1') {
            $plat = 'android';
        } else if (isset($msgArr['ct']) && $msgArr['ct'] == '2') {
            $plat = 'ios';
        }
        return array(
            'type' => 'chat',
            'time' => time(),
            'id' => $msgArr['cid'],
            'content' => $msgArr['txt'],
            'from' => array(
                'name' => $msgArr['nn'],
                'rid' => $msgArr['uid'],
                'level' => $msgArr['level'],
                'plat' => $plat
            )
        );
    }

    /**
     *  组装礼物消息数组
     * @access private
     * @param $msgArr
     * @param  $roomId
     * @return mixed
     * @throws \Exception
     */
    public static function buildGift($msgArr, $roomId)
    {
        self::$giftInfo = self::$giftInfo || self::getGiftInfo($roomId);
        if (!self::$giftInfo) throw new  \Exception('获取礼物信息失败');
        $freeGift = array('name' => '鱼丸', 'price' => 0, 'is_yuwan' => false);
        $gift = isset(self::$giftInfo[$msgArr['gfid']]) ? self::$giftInfo[$msgArr['gfid']] : $freeGift;
        $msg_obj = array(
            'type' => 'gift',
            'time' => time(),
            'name' => $gift['name'],
            'from' => array(
                'name' => $msgArr['nn'],
                //'rid' => $msgArr['uid'],
                'level' => $msgArr['level']
            ),
            // 'id' => `{$msgArr['uid']}{$msgArr['rid']}{$msgArr['gfid']}{$msgArr['hits']}{$msgArr['level']}`,
            'count' => $msgArr['gfcnt'] || 1,
            'price' => ($msgArr['gfcnt'] || 1) * $gift['price'],
            'earn' => ($msgArr['gfcnt'] || 1) * $gift['price']
        );
        if ($gift['is_yuwan']) {
            $msg_obj['type'] = 'yuwan';
            unset($msg_obj['price']);
            unset($msg_obj['earn']);
        }
        return $msg_obj;
    }

    /**
     *  组装酬勤消息数组
     * @access private
     * @param $msgArr
     * @return mixed
     */
    public static function buildDeserve($msgArr)
    {
        $name = '初级酬勤';
        $price = 15;
        if ($msgArr['lev'] === '2') {
            $name = '中级酬勤';
            $price = 30;
        } else if ($msgArr['lev'] === '3') {
            $name = '高级酬勤';
            $price = 50;
        }
        $sui = $msgArr['sui'];
        $sui = preg_replace('/@A=/g', '":"', $sui);
        $sui = preg_replace('/@S/g', '","', $sui);
        $sui = substr(0, strlen($sui) - 2, $sui);
        $sui = json_decode($sui, true);
        return array(
            'type' => 'deserve',
            'time' => time(),
            'name' => $name,
            'from' => array(
                'name' => $sui['nick'],
                //'rid' => $sui['id'],
                'level' => $sui['level'],
            ),
            //'id' => "{$sui['id']}{$msgArr['rid']}{$msgArr['lev']}{$msgArr['hits']}{$sui['level']}{$sui['exp']}",
            'count' => $msgArr['cnt'] || 1,
            'price' => $price,
            'earn' => $price
        );
    }

    public static function getGiftInfo($roomId)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "http://open.douyucdn.cn/api/RoomApi/room/{$roomId}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "cache-control: no-cache",
                "content-type: application/x-www-form-urlencoded"
            ),
        ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            return false;
        } else {
            $info = json_decode($response, true);
            $res = array();
            foreach ($info['data']['gift'] as $v) {
                $res[$v['id']] = array('name' => $v['name'], 'price' => $v['pc'], 'is_yuwan' => $v['type'] == '1' ? true : false);
            }
            return $res;
        }
    }

}