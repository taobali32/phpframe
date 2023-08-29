<?php

namespace Jtar;

use Jtar\Protocols\Stream;

class TcpConnection
{
    public $_connfd;

    public $_clientIp;

    public $_server;

    public $_readBufferSize = 1024;

    public $_recvBufferSize = 1024 * 100; // 当前连接接收缓冲区大小 100KB

    public $_recvLen = 0; // 当前连接目前接收到的字节数大小

    public $_recvBufferFull = 0; //  当前连接接收的字节数是否超出缓冲区

    public $_recvBuffer = '';


    public $_sendLen = 0;
    public $_sendBuffer = '';
    public $_sendBufferSize = 1024 * 100;

    public $_sendBufferFull = 0;

    public $_protocol;

    public function __construct($connfd,$clientIp,$server){
        $this->_connfd = $connfd;
        $this->_clientIp = $clientIp;
        $this->_server = $server;

        $this->_protocol = new Stream();
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

                    $server->runEventCallBack('close',[(int)$this->_connfd, $this]);

                    $server->removeConnection($this->_connfd);
                }
            }else{
                // 接收到的数据放在缓冲区
                $this->_recvBuffer .= $data;

                $this->_recvLen += strlen($data);

                $this->_server->onRecv();
            }

        }else{
            $this->_recvBufferFull++;
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

                $this->_recvLen -= $msgLen;

                $msg = $server->_protocol->decode($oneMsg);
                $server->runEventCallBack("receive", [$msg,$this]);
            }
        }else{

            $server->runEventCallBack("receive", [$this->_recvBuffer,$this]);

            $this->_recvBuffer = "";
            $this->_recvLen = 0;
            $this->_recvBufferFull = 0;
        }
    }

    public function send($data)
    {
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
            // TODO 数据发一半 还没发完!
        }else{
            // 对端关闭了!
            $this->_server->removeConnection($this->_connfd);
        }
    }
}