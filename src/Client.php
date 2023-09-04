<?php

namespace Jtar;

use Jtar\Event\Event;
use Jtar\Event\Select;
use Jtar\Protocols\Stream;

class Client
{
    public static $_eventLoop = null;
    public $_mainClient = null;
    public $_events = [];
    private $_readBufferSize = 1024 * 100;
    public $_recvBufferSize = 1024 * 100;
    public $_recvLen = 0;

    // 接收缓冲区，可以接收多条消息【数据】，数据像水一样，黏在一起
    public $_recvBuffer = '';

    // 使用的那个协议
    public $_protocol = '';

    public $_local_socket;


    public $_sendLen = 0;
    public $_sendBuffer = '';
    public $_sendBufferSize = 1024 * 100;

    public $_sendBufferFull = 0;


    //  执行发送函数执行了几次
    public $_sendNum = 0;

    //  发送了多少条消息
    public $_sendMsgNum = 0;


    const STATUS_CLOSE = 10;
    const STATUS_CONNECT = 11;
    public $_status;


    public function on($eventName,$eventCall){
        $this->_events[$eventName] = $eventCall;
    }


    public function onSendWrite(){
        ++$this->_sendNum;
    }

    public function onSendMsgNum(){
        ++$this->_sendMsgNum;
    }

    
    public function __construct($local_socket)
    {
        $this->_local_socket = $local_socket;

        $this->_protocol = new Stream();

        static::$_eventLoop = new Select();
    }


    public function start(){

        //  打开 Internet 或 Unix 域套接字连接
        //  默认情况下，流将以阻塞模式打开。您可以使用stream_set_blocking()将其切换到非阻塞模式 。
        $this->_mainClient = stream_socket_client($this->_local_socket,$errno,$errstr);

        if (is_resource($this->_mainClient)){
            $this->runEventCallBack("connect", [$this]);

            $this->_status = self::STATUS_CONNECT;

            static::$_eventLoop->add($this->_mainClient,Event::EVENT_READ,[$this,"recv4socket"]);

        }else{
            $this->runEventCallBack("error", [$this, $errno, $errstr]);
            exit(0);
        }
    }




    public function runEventCallBack($eventName,$args = []){
        if (isset($this->_events[$eventName]) && is_callable($this->_events[$eventName])) {
            $this->_events[$eventName]($this,...$args);
        }
    }

    public function clientFd(){
        return $this->_mainClient;
    }

    public function send111($data)
    {
        $len = strlen($data);

        if ($this->_sendLen + $len < $this->_sendBufferSize){
            $bin = $this->_protocol->encode($data);

            $this->_sendBuffer .= $bin[1];
            $this->_sendLen += $bin[0];

            if ($this->_sendLen >= $this->_sendBufferSize){
                $this->_sendBufferFull++;
            }

            $this->onSendMsgNum();
        }else{
            $this->runEventCallBack("receiveBufferFull",[$this]);
        }

        // 等到可写事件的时候发送,不写在这里了

        // 发送数据的时候
        //  1.网络不好只发送一半
        //  2.能完整的发送
        //  3. 对端关了

        $writeLen = fwrite($this->_mainClient,$this->_sendBuffer,$this->_sendLen);

        if ($writeLen == $this->_sendLen){

            // 发送完成后
            $this->_sendBuffer = '';
            $this->_sendLen = 0;
            $this->_sendBufferFull = 0;

            static::$_eventLoop->del($this->_mainClient,Event::EVENT_WRITE);

            $this->onSendWrite();
            
            return true;
        }elseif ($writeLen > 0){

            $this->_sendBuffer = substr($this->_sendBuffer,$writeLen);

            $this->_sendLen = $writeLen;
            $this->_sendBufferFull--;

            // 没写完才添加到里面,你不能在构造函数里面把 读写都添加了, 这是epoll规定的..
            static::$_eventLoop->add($this->_mainClient,Event::EVENT_WRITE,[$this,"write2socket"]);

            return true;
        }else{
            $this->onClose();
        }

        return false;
    }

    public function send($data){
        $len = strlen($data);

        if ($this->_sendLen +$len < $this->_sendBufferSize){
            $bin = $this->_protocol->encode($data);

            $this->_sendBuffer .= $bin[1];
            $this->_sendLen += $bin[0];

            if ($this->_sendLen >= $this->_sendBufferSize){
                $this->_sendBufferFull++;
            }

            $this->onSendMsgNum();
        }else{
            $this->runEventCallBack("receiveBufferFull",[$this]);
        }
    }


    public function needWrite()
    {
        return $this->_sendLen > 0;
    }


    public function loop(){
       return static::$_eventLoop->loop1();
    }


    public function eventLoop()
    {
        if (is_resource($this->_mainClient)){
            $readFds = [$this->_mainClient];

            $writeFds = [$this->_mainClient];

            $exptFds = [$this->_mainClient];

            $ret = stream_select($readFds, $writeFds, $exptFds, NULL);

            if ($ret <= 0 || $ret == false){
                return false;
            }

            if ($readFds){
                $this->recv4socket();
            }

            //  有可写事件发生
            if ($writeFds){
                $this->write2socket();
            }

            return true;
        }else{
            return false;
        }

    }


    public function onClose()
    {
        fclose($this->_mainClient);

        $this->_status = self::STATUS_CLOSE;
        $this->runEventCallBack("close",[$this]);

        $this->_mainClient = null;
    }



    public function recv4socket()
    {
        if ($this->isConnected()){
            $data = fread($this->_mainClient, $this->_readBufferSize);

            //  对端关闭了
            if ($data === '' || $data == false){
                if (feof($this->_mainClient) || !is_resource($this->_mainClient)){
                    $this->onClose();
                }
            }else{
                $this->_recvBuffer .= $data;
                $this->_recvLen += strlen($data);
            }

            if ($this->_recvLen > 0){
                $this->handleMessage();
            }
        }

    }

    public function handleMessage()
    {
        while ( $this->_protocol->Len($this->_recvBuffer)){

            $msgLen = $this->_protocol->msgLen($this->_recvBuffer);

            // 截取一条消息
            $oneMsg = substr($this->_recvBuffer,0,$msgLen);

            $this->_recvBuffer = substr($this->_recvBuffer, $msgLen);
            $this->_recvLen -= $msgLen;

            $msg = $this->_protocol->decode($oneMsg);

            $this->runEventCallBack("receive", [$msg]);
        }
    }


    public function isConnected(): bool
    {
        return  $this->_status == self::STATUS_CONNECT  && is_resource($this->_mainClient);
    }


    public function write2socket()
    {
        if ($this->needWrite() && $this->isConnected()){

            $writeLen = fwrite($this->_mainClient,$this->_sendBuffer, $this->_sendLen);

            $this->onSendWrite();

            if ($writeLen == $this->_sendLen){

                $this->_sendBuffer = '';
                $this->_sendLen = 0;
                return true;
            }elseif ($writeLen > 0){
                $this->_sendLen -= $writeLen;
                $this->_sendBuffer = substr($this->_sendBuffer, $writeLen);
                return true;
            }else{
                $this->onClose();
            }
        }
    }
}