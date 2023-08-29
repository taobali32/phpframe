<?php

namespace Jtar;

use Jtar\Protocols\Stream;

class Client
{

    public $_mainClient;
    public $_events = [];
    private $_readBufferSize = 1024 * 100;
    public $_recvBufferSize = 1024 * 100;
    public $_recvLen = 0;

    // 接收缓冲区，可以接收多条消息【数据】，数据像水一样，黏在一起
    public $_recvBuffer = '';

    // 使用的那个协议
    public $_protocol = '';

    public $_local_socket;


    public function on($eventName,$eventCall){
        $this->_events[$eventName] = $eventCall;
    }

    
    public function __construct($local_socket)
    {
        $this->_local_socket = $local_socket;

        $this->_protocol = new Stream();

    }


    public function start(){

        //  打开 Internet 或 Unix 域套接字连接
        //  默认情况下，流将以阻塞模式打开。您可以使用stream_set_blocking()将其切换到非阻塞模式 。
        $this->_mainClient = stream_socket_client($this->_local_socket,$errno,$errstr);

        if (is_resource($this->_mainClient)){
            $this->runEventCallBack("connect", [$this]);

//            $this->eventLoop();
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


    public function eventLoop(){

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
        }

        return true;

    }


    public function onClose(){
        fclose($this->_mainClient);
        
        $this->runEventCallBack("close",[$this]);
    }


    public function recv4socket()
    {
        //  TODO 如果数据间断发送？ 第一次读了1kb，第二次读了2kb...
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


    public function write2socket($data){
        $bin = $this->_protocol->encode($data);

        $writeLen = fwrite($this->_mainClient,$bin[1],$bin[0]);

        fprintf(STDOUT, "write:%d size\n", $writeLen);
    }
}