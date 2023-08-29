<?php

use Jtar\Server;
use Jtar\TcpConnection;

require_once "vendor/autoload.php";

$server = new Server("stream://0.0.0.0:8888");

// tcp connect recevie/close
// udp packet /close
// stream/text
// http request
// ws open/message/close
// mqtt connect/subscribe/unsubscribe/publish/close

$server->on("connect",function (Server $server, TcpConnection $connection){
    fprintf(STDOUT, "有客户端连了\n");
});

$server->on("receive", function (Server $server, $msg, TcpConnection $connection){
//    fprintf(STDOUT, "有客户端发送数据了:%s\r\n",$msg);
//    fprintf(STDOUT, "recv from client<%d>:%s\r\n",(int)$connection->_connfd,$msg);

    $connection->send("server receive");
});

$server->on("close", function (Server $server, $connfd, TcpConnection $connection){
    fprintf(STDOUT, "有客户端关闭了:%d\r\n",(int)$connfd);
});

$server->start();