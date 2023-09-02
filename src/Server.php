<?php

namespace Jtar;


namespace Jtar;

use Jtar\Protocols\Stream;
use Jtar\Protocols\Text;

class Server
{
    private $_mainSocket;
    private $_local_socket;
    static public $_connections = [];

    public $_events = [];

    public $_protocol = null;


    public $_protocol_layout;

    public $_protocols = [
        'stream'    =>  Stream::class,
        "text"      =>  Text::class,
        "ws"        =>  "",
        "http"      =>  "",
        "mqtt"      =>  ""
    ];

    static public $_clientNum = 0; //  客户端连接数量
    static public $_recvNum = 0;  // 执行recv/fread调用多少次， 1秒内
    static public $_msgNum = 0;    // 1秒中内接收了多少消息

    public $_startTime = 0;

    public function on($eventName,$eventCall){
        $this->_events[$eventName] = $eventCall;
    }

    public function __construct($local_socket = "tcp://0.0.0.0:12345")
    {
        list($protocol,$ip, $port) = explode(":", $local_socket);
        if ( isset($this->_protocols[$protocol])){
            
            $this->_protocol = new $this->_protocols[$protocol]();
        }

        $this->_startTime = time();

        $this->_local_socket = "tcp:" . $ip . ":" . $port;
    }

    public function statistics()
    {
        $nowTime = time();
        $diffTime = $nowTime - $this->_startTime;

        $this->_startTime = $nowTime;

        if ($diffTime >= 1){
            fprintf(STDOUT, "time:%s--socket<%d>--<clientNum:%d>--<recvNum:%d>--<msgNum:%d>\r\n",$diffTime, (int)$this->_mainSocket, static::$_clientNum, static::$_recvNum, static::$_msgNum);

            static::$_recvNum = 0;
            static::$_msgNum = 0;
        }
    }


    public function listen()
    {
        $flag = STREAM_SERVER_LISTEN|STREAM_SERVER_BIND;
        $option['socket']['backlog'] = 102400;

        //  创建并返回一个资源流上下文，该资源流中包含了 options 中提前设定的所有参数的值。
        //  https://www.php.net/manual/zh/function.stream-context-create.php
        //  https://www.php.net/manual/zh/context.php
        $content = stream_context_create($option);

        //  在指定 address 上创建 stream 或者数据包套接字（datagram socket）。
        //  此函数仅创建套接字，并使用 stream_socket_accept() 开始接受连接。
        $this->_mainSocket = stream_socket_server($this->_local_socket, $errno, $errstr, $flag, $content);

        // 设置为非阻塞IO
        stream_set_blocking($this->_mainSocket,0);
        if (!is_resource($this->_mainSocket)) {
            fprintf(STDOUT, "server create fail:%s\n", $errstr);
            exit(0);
        }

        fprintf(STDOUT, "listen on:%s\n", $this->_local_socket);
    }



    public function eventLoop()
    {
        $readFds = [$this->_mainSocket];

        while (1) {

            $reads = $readFds;
            $writes = [];
            $expts = [];

            $this->statistics();

            $this->checkHeartTime();

            if (!empty(static::$_connections)) {
                foreach (static::$_connections as $connection) {
                    $sockfd = $connection->connfd();

                    if (is_resource($sockfd)){
                        $reads[] = $sockfd;
                        $writes[] = $sockfd;
                    }
                }
            }

//           这些是不可以重复的,重复了会出现好多奇怪的问题!!
//            print_r($reads);
//            print_r($writes);

            //  函数是 PHP 中用于多路复用的一个函数 它可以检查多个文件流（套接字、文件等）是否可读、可写或出现异常，并在有可读、可写或异常情况发生时返回相应的文件流。
            $ret = stream_select($reads, $writes, $expts, 0,100);

            restore_error_handler();
            if ($ret === false) {
                break;
            }

            if ($reads){
                foreach ($reads as $fd) {
                    // 如果是监听socket
                    if ($fd === $this->_mainSocket) {
                        $this->accept();
                    } else {

                        if (isset(static::$_connections[(int)$fd])) {
                            /**
                             * @var TcpConnection $connection
                             */
                            $connection = static::$_connections[(int)$fd];

                            if ($connection->isConnected()) {
                                $connection->recv4socket();
                            }
                        }
                    }
                }
            }

            if ($writes){
                foreach ($writes as $fd){

                    if (isset(static::$_connections[(int)$fd])){

                        /**
                         * @var TcpConnection $connection
                         */
                        $connection = static::$_connections[(int)$fd];

                        if ($connection->isConnected()){
                            $connection->write2socket();
                        }
                    }
                }
            }
        }
    }


    public function onClientJoin(){
        ++static::$_clientNum;
    }

    public function checkHeartTime(){
        foreach (static::$_connections as $idx => $connection){

            if ($connection->checkHeartTime()){
                $connfd = $connection->connfd();

                // 超出心跳时间,关闭掉这个客户端
                $this->removeConnection($connfd);
                $this->runEventCallBack('close',[(int)$connfd, $connection]);
            }
        }
    }

    public function onRecv(){
        ++static::$_recvNum;
    }

    public function onMsg(){
        ++static::$_msgNum;
    }


    public function accept()
    {
        $connfd = stream_socket_accept($this->_mainSocket, -1,$peername);

        if (is_resource($connfd)) {

            $connection = new TcpConnection($connfd, $peername,$this);
            $this->onClientJoin();
            
            static::$_connections[(int)$connfd] = $connection;

            $this->runEventCallBack('connect',[$connection]);
        }
    }

    public function runEventCallBack($eventName,$args = []){
        if (isset($this->_events[$eventName]) && is_callable($this->_events[$eventName])) {
            $this->_events[$eventName]($this,...$args);
        }
    }


    public function removeConnection($connfd)
    {
        if (isset(static::$_connections[(int)$connfd])) {
            unset(static::$_connections[(int)$connfd]);

            --static::$_clientNum;

            if (is_resource($connfd)){
                fclose($connfd);
                //
            }
        }
    }

    public function start(){
        $this->listen();
        $this->eventLoop();
    }
}
