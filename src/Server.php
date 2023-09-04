<?php

namespace Jtar;


namespace Jtar;

use Jtar\Event\Epoll;
use Jtar\Event\Event;
use Jtar\Event\Select;
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

    static public $_eventLoop;

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


//        if (DIRECTORY_SEPARATOR == "/"){
//            static::$_eventLoop = new Epoll();
//        }else{
            static::$_eventLoop = new Select();
//        }
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


    public function eventLoop(){
        static::$_eventLoop->loop();
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

            echo "接受到客户端连接\r\n";
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
            }
        }
    }

    public function start(){
        $this->listen();

        static::$_eventLoop->add($this->_mainSocket,Event::EVENT_READ,[$this,"accept"]);
        $this->eventLoop();
    }
}
