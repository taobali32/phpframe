<?php

namespace Jtar;

use Jtar\Event\Event;
use Jtar\Protocols\Stream;

class TcpConnection
{
    public $_connfd;

    public $_clientIp;

    public $_server;

    public $_readBufferSize = 1024;

    public $_recvBufferSize = 1024 * 1000 * 10; // 当前连接接收缓冲区大小 10m

    public $_recvLen = 0; // 当前连接目前接收到的字节数大小

    public $_recvBufferFull = 0; //  当前连接接收的字节数是否超出缓冲区

    public $_recvBuffer = '';


    public $_sendLen = 0;
    public $_sendBuffer = '';
    public $_sendBufferSize = 1024*1000*100;


    public $_sendBufferFull = 0;

    public $_protocol;

    public $_heartTime = 0;

    const HEART_TIME = 20;


    const STATUS_CLOSED = 10;
    const STATUS_CONNECT = 11;
    public $_status;


    public function isConnected(): bool
    {
        return  $this->_status == self::STATUS_CONNECT && is_resource($this->_connfd);
    }



    public function resetHeartTime(){
        $this->_heartTime = time();
    }

    public function checkHeartTime(){
        $now = time();

        if ($now - $this->_heartTime >= self::HEART_TIME){

            fprintf(STDOUT, "心跳时间已经超出:%d\n", $now - $this->_heartTime);
            return true;
        }

        return false;
    }

    public function __construct($connfd,$clientIp,$server)
    {
        $this->_connfd = $connfd;
        stream_set_blocking($this->_connfd,0);
        stream_set_write_buffer($this->_connfd, 0);
        stream_set_read_buffer($this->_connfd, 0);

        $this->_clientIp = $clientIp;
        $this->_server = $server;

        $this->_protocol = new Stream();

        $this->_heartTime = time();

        $this->_status = self::STATUS_CONNECT;

        Server::$_eventLoop->add($connfd,Event::EVENT_READ,[$this,"recv4socket"]);

        // 这里不要上来就直接监听可写时间,不然一直写不会停了,哪里需要就哪里写!!
    }

    public function connfd(){
        return $this->_connfd;
    }

    public function recv4socket()
    {
        if ($this->_recvLen < $this->_recvBufferSize){
            $data = fread($this->_connfd, $this->_readBufferSize);

            //  对端关闭了
            if ($data === '' || $data == false){

                /**
                 * @var Server $server
                 */
                $server = $this->_server;

                // 对端关闭
                if (feof($this->_connfd) || !is_resource($this->_connfd)){
                    $this->Close();
                }
            }else{
                // 接收到的数据放在缓冲区
                $this->_recvBuffer .= $data;
                $this->_recvLen += strlen($data);
                $this->_server->onRecv();
            }

        }else{
            $this->_recvBufferFull++;
            $this->_server->runEventCallBack("receiveBufferFull", [$this]);
        }

        if ($this->_recvLen > 0){
            $this->handleMessage();
        }
    }

    public function handleMessage()
    {
        $server = $this->_server;

        if (is_object($server->_protocol) && $server->_protocol != null){

            while ($server->_protocol->Len($this->_recvBuffer)){

                $msgLen = $server->_protocol->msgLen($this->_recvBuffer);
                // 截取一条消息
                $oneMsg = substr($this->_recvBuffer,0,$msgLen);

                // 截取后就给把前面的扔了， 在前面的数据截取后在截取一次放在里面
                $this->_recvBuffer = substr($this->_recvBuffer, $msgLen);

                $this->_recvBufferFull--;

                $this->_server->onMsg();
                $this->resetHeartTime();

                $this->_recvLen -= $msgLen;

                $msg = $server->_protocol->decode($oneMsg);
                $server->runEventCallBack("receive", [$msg,$this]);
            }
        }else{
            // 如果没使用协议

            $server->runEventCallBack("receive", [$this->_recvBuffer,$this]);
            $this->_recvBuffer = "";
            $this->_recvLen = 0;
            $this->_recvBufferFull = 0;
            $this->_server->onMsg();
            $this->resetHeartTime();
        }
    }

    public function send($data)
    {
        if (!$this->isConnected()){
            $this->Close();
            return false;
        }

        $len = strlen($data);

        /**
         * @var Server $server
         */
        $server = $this->_server;

        if ($this->_sendLen + $len < $this->_sendBufferSize){

            if (is_object($server->_protocol) && $server->_protocol != null){
                $bin = $this->_server->_protocol->encode($data);

                $this->_sendBuffer .= $bin[1];
                $this->_sendLen += $bin[0];
            }else{

                $this->_sendBuffer .= $data;
                $this->_sendLen += $len;
            }

            if ($this->_sendLen >= $this->_sendBufferSize){
                $this->_sendBufferFull++;
            }
        }

        // 等到可写事件的时候发送,不写在这里了

        // 发送数据的时候
        //  1.网络不好只发送一半
        //  2.能完整的发送
        //  3. 对端关了

        $writeLen = fwrite($this->_connfd,$this->_sendBuffer,$this->_sendLen);

        if ($writeLen == $this->_sendLen){

            // 发送完成后
            $this->_sendBuffer = '';
            $this->_sendLen = 0;
            $this->_sendBufferFull = 0;
            return true;
        }elseif ($writeLen > 0){
            
            $this->_sendBuffer = substr($this->_sendBuffer,$writeLen);

            $this->_sendLen = $writeLen;
            $this->_sendBufferFull--;

            // 没写完才添加到里面,你不能在构造函数里面把 读写都添加了, 这是epoll规定的..
            Server::$_eventLoop->add($this->_connfd,Event::EVENT_WRITE,[$this,"write2socket"]);

            return true;
        }else{
            if (feof($this->_connfd) || !is_resource($this->_connfd)){
                $this->Close();
            }
        }

        return false;
    }


    public function needWrite()
    {
        return $this->_sendLen > 0;
    }


    public function write2socket()
    {
        if ($this->needWrite()) {

            // 除掉发送警告
            set_error_handler(function () {});
            $writeLen = fwrite($this->_connfd, $this->_sendBuffer, $this->_sendLen);

            // 恢复警告
            restore_error_handler();
            if ($writeLen == $this->_sendLen) {
                $this->_sendBuffer = '';
                $this->_sendLen = 0;
                $this->_sendBufferFull = 0;

                var_dump("移除了_eventLoop->de");
                Server::$_eventLoop->del($this->_connfd,Event::EVENT_WRITE);

                return true;
            } elseif ($writeLen > 0) {

                var_dump("第一次没发送完,继续发送");
                $this->_sendBuffer = substr($this->_sendBuffer,$writeLen);
                $this->_sendLen = $writeLen;
                return true;
            } else {

                if (feof($this->_connfd)||!is_resource($this->_connfd)){

                    $this->Close();
                }
            }
        }
    }


    public function Close()
    {
//        $this->_server->echoLog("移除<socket:%d>连接\r\n",(int)$this->_sockfd);
        Server::$_eventLoop->del($this->_connfd,Event::EVENT_READ);
        Server::$_eventLoop->del($this->_connfd,Event::EVENT_WRITE);

        if (is_resource($this->_connfd)){
            fclose($this->_connfd);
        }

        /** @var Server $server */
        $server = $this->_server;
        $server->runEventCallBack("close",[$this->_connfd,$this]);
        $server->removeConnection($this->_connfd);
        $this->_status = self::STATUS_CLOSED;
        $this->_connfd=null;
        $this->_sendLen=0;
        $this->_sendBuffer='';
        $this->_sendBufferFull=0;
        $this->_sendBufferSize=0;

        $this->_recvLen=0;
        $this->_recvBuffer='';
        $this->_recvBufferFull=0;
        $this->_recvBufferSize=0;
    }
}