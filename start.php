<?php
ini_set("memory_limit","2048M");
ini_set('display_errors',1);            //错误信息
ini_set('display_startup_errors',1);    //php启动错误信息
error_reporting(-1);                    //打印出所有的 错误信息

use Jtar\Server;
use Jtar\TcpConnection;

require_once "vendor/autoload.php";

$server = new Server("tcp://0.0.0.0:8888");

$server->setting([
    'workerNum' =>  3
]);

// tcp connect recevie/close
// udp packet /close
// stream/text
// http request
// ws open/message/close
// mqtt connect/subscribe/unsubscribe/publish/close

$server->on("connect",function (Server $server, TcpConnection $connection){
//    fprintf(STDOUT, "有客户端连了\n");
});

$server->on("receive", function (Server $server, $msg, TcpConnection $connection){
//    fprintf(STDOUT, "有客户端发送数据了:%s\r\n",$msg);
    fprintf(STDOUT, "<pid:%d>recv from client<%d>:%s\r\n",posix_getpid(),(int)$connection->_connfd,$msg);

//    $data = file_get_contents("./text.txt");
    //  1`11
    $connection->send("aaa");

});

$server->on("close", function (Server $server, $connfd, TcpConnection $connection){
    fprintf(STDOUT, "有客户端关闭了:%d\r\n",(int)$connfd);
});

// 缓冲区满了
$server->on("receiveBufferFull", function (Server $server,TcpConnection $connection){
    fprintf(STDOUT, "接收缓冲区已满\r\n");
});

$server->start();
