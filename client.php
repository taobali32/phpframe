<?php
require_once "vendor/autoload.php";

$clientNum = $argv['1'];


$clients = [];

for ($i = 0; $i < $clientNum;$i++){
    $clients[] = $client = new \Jtar\Client("tcp://127.0.0.1:8888");

    $client->on("connect",function (\Jtar\Client $client){

//        $client->write2socket("hello");
    });

    $client->on("close",function (\Jtar\Client $client){
        fprintf(STDOUT, "服务器断开我的链接了\n");
    });


    $client->on("error",function (\Jtar\Client $client,$errno,$errstr){
        fprintf(STDOUT, "errno:%d,errstr=%s\n", $errno,$errstr);
    });

    $client->on("receive",function (\Jtar\Client $client,$msg){

        fprintf(STDOUT, "client receive:%s\n", $msg);

//        $client->write2socket("world");
    });

    $client->start();
}


//
$pid = pcntl_fork();
if ($pid==0){

    while (1){
        for ($i=0;$i<$clientNum;$i++){
            /**
             * @var \Jtar\Client $client
             */
            $client = $clients[$i];

            if (is_resource($client->clientFd())){
                $client->write2socket("hello,i am client");

                if (!$client->eventLoop()){
                    break;
                }
            }
        }

    }

    exit(0);
}
