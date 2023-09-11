<?php
ini_set("memory_limit","2048M");
ini_set('display_errors',1);            //错误信息
ini_set('display_startup_errors',1);    //php启动错误信息
error_reporting(-1);                    //打印出所有的 错误信息

use Jtar\Server;
use Jtar\TcpConnection;

require_once "vendor/autoload.php";

$server = new Server("tcp://0.0.0.0:8889");

$server->setting([
    'workerNum' =>  2,
    'taskNum'=>2,
    "daemon"=>true,
    "task"  =>  [
        "unix_socket_server_file" => "/home/ubuntu/php/jtar/sock/te_unix_socket_server",
        "unix_socket_client_file" => "/home/ubuntu/php/jtar/sock/te_unix_socket_client",
    ]
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

$server->on("masterStart", function (Server $server){
    fprintf(STDOUT,"master server start\r\n");
});

$server->on("workerReload",function (Server $server){
    fprintf(STDOUT,"worker <pid:%d> reload\r\n",posix_getpid());
});

$server->on("masterShutdown", function (Server $server){
    fprintf(STDOUT,"master server shutdown\r\n");
});

$server->on("workerStart", function (Server $server){
    fprintf(STDOUT,"worker <pid:%d> start\r\n",posix_getpid());

});


$server->on("workerStop", function (Server $server){
    fprintf(STDOUT,"worker <pid:%d> stop\r\n",posix_getpid());
});


$server->on("receive", function (Server $server, $msg, TcpConnection $connection){
//    fprintf(STDOUT, "有客户端发送数据了:%s\r\n",$msg);
    fprintf(STDOUT, "<pid:%d>recv from client<%d>:%s\r\n",posix_getpid(),(int)$connection->_connfd,$msg);

//    $data = file_get_contents("./text.txt");
    

    $server->task(function ($result)use($server){

        sleep(5);

//        $server->echoLog("异步任务我执行完，时间到了\r\n");
        echo time()."\r\n";

    });//耗时任务可以投递到任务进程来做
    $connection->send("aaa");
});

$server->on("close", function (Server $server, $connfd, TcpConnection $connection){
    fprintf(STDOUT, "有客户端关闭了:%d\r\n",(int)$connfd);
});

// 缓冲区满了
$server->on("receiveBufferFull", function (Server $server,TcpConnection $connection){
    fprintf(STDOUT, "接收缓冲区已满\r\n");
});

$server->on("task", function (Server $server,\Jtar\UdpConnection $connection,$msg){
    fprintf(STDOUT, "task process <pid:%d> on task %s\r\n",posix_getpid(),$msg);
});

$server->start();
