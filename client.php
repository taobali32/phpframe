<?php
require_once "vendor/autoload.php";

$client = new \Jtar\Client("tcp://127.0.0.1:8888");

$client->on("connect",function (\Jtar\Client $client){
    $client->write2socket("hello");
});

$client->on("close",function (\Jtar\Client $client){
    fprintf(STDOUT, "服务器断开我的链了\n");
});


$client->on("error",function (\Jtar\Client $client,$errno,$errstr){
    fprintf(STDOUT, "errno:%d,errstr=%s\n", $errno,$errstr);
});

$client->on("receive",function (\Jtar\Client $client,$msg){

    fprintf(STDOUT, "client receive:%s\n", $msg);

    $client->write2socket("world");

});

$client->start();

while (1){
    $client->write2socket("hello, i am client");
    if (!$client->eventLoop()){
        exit(0);
    }
}