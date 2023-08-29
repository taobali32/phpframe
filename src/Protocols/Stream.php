<?php

namespace Jtar\Protocols;

class Stream implements Protocol
{

    // 檢測一條消息是否完整
    public function Len($data){
        if (strlen($data) < 4){
            return false;
        }

        $tmp = unpack("NtotalLen", $data);

        // 粘包判斷
        if (strlen($data) < $tmp['totalLen']){
            return false;
        }

        return true;
    }

    // 打包
    public function encode($data = ''){
        //  双方设计协议时必须知道数据包长度
        // 数据包总长度4， 2byte是发送命令，   自己设计的!
        $totalLen = strlen($data) + 6;

        // 这里Nn对应后面数据， N是数据总长度， "1"是发送的命令， 然后连起来数据
        $bin = pack("Nn", $totalLen,"1") . $data;

        return [$totalLen,$bin];
    }

    public function decode($data = ''){
        $cmd = substr($data,4,2);

        $msg = substr($data, 6);

        return $msg;
    }

    //
    public function msgLen($data = ''){
        $tmp = unpack("Nlen", $data);

        return $tmp['len'];
    }
}