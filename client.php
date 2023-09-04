<?php
ini_set("memory_limit","2048M");

use Jtar\Client;

require_once "vendor/autoload.php";

$clientNum = $argv['1'];

$sendMessageNum = $argv['2'] ?? 10;

$startTime = time();

$clients = [];

for ($i = 0; $i < $clientNum;$i++){
    $clients[] = $client = new Client("tcp://127.0.0.1:8888");

    $client->on("connect",function (Client $client){
        fprintf(STDOUT,"socket<%d> connect success!\r\n",(int)$client->clientFd());
    });

    $client->on("close",function (Client $client){
        fprintf(STDOUT, "服务器断开我的链接了\n");
    });

    $client->on("error",function (Client $client, $errno, $errstr){
        fprintf(STDOUT, "errno:%d,errstr=%s\n", $errno,$errstr);
    });

    $client->on("receive",function (Client $client, $msg){

        fprintf(STDOUT, "client receive:%s\n", $msg);

//        $client->write2socket("world");
    });

    // 缓冲区满了
    $client->on("sendBufferFull", function (Client $client){
        fprintf(STDOUT, "发送缓冲区已满\r\n");

    });

    $client->start();
}

while (1){

    $now = time();
    $diff = $now-$startTime;
    $startTime = $now;

    if ($diff>=1){
        $sendNum=0;
        $sendMsgNum=0;

        foreach ($clients as $client){

            $sendNum+=$client->_sendNum;
            $sendMsgNum+=$client->_sendMsgNum;
        }

        fprintf(STDOUT,"time:<%s>--<clientNum:%d>--<sendNum:%d>--<msgNum:%d>\r\n",
            $diff,$clientNum,$sendNum,$sendMsgNum*$sendMessageNum);

        foreach ($clients as $client){

            $client->_sendNum = 0;
            $client->_sendMsgNum = 0;
        }
    }

    for ($i=0;$i<$clientNum;$i++) {

        /**
         * @var \Jtar\Client $client
         */
        $client = $clients[$i];

        $res = !$client->loop();

        if ($res) {
            break;
        }

        for ($j=0;$j<$sendMessageNum;$j++){
            $client->send("hello".time());
        }

    }
//    sleep(1);

}
