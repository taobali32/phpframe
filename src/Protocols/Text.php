<?php

namespace Jtar\Protocols;

class Text implements Protocol
{
    // 檢測一條消息是否完整
    public function Len($data){

        if (strlen($data)){
            return  strpos($data, "\n");
        }

        return false;
    }

    // 打包
    public function encode($data = ''){

        $data = $data . "\n";

        return [strlen($data),$data];
    }

    public function decode($data = ''){
        return rtrim($data, "\n");
    }

    //
    public function msgLen($data = ''){
        return strpos($data, "\n") + 1;
    }
}