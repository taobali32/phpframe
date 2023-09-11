<?php

namespace Jtar;

use Jtar\Event\Event;

class UdpConnection
{
    public static $_eventLoop = null;

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

    /**
     * @var mixed
     */
    public $_sockfd;

    public function __construct($sockfd,$len,$buf,$unixClientFile){

        $this->_sockfd = $sockfd;

        $this->_clientIp = $unixClientFile;

        $this->_recvBuffer.=$buf;
        $this->_recvLen+=$len;

        $this->handleMessage();
    }


    public function handleMessage()
    {
        while ($this->_recvLen){

            $bin = unpack("Nlength",$this->_recvBuffer);
            $length = $bin['length'];

            $oneMsg = substr($this->_recvBuffer,0,$length);
            $this->_recvBuffer = substr($this->_recvBuffer,$length);
            $this->_recvLen-=$length;

            if ($oneMsg){

                $data = substr($oneMsg,4);
                $wrapper = unserialize($data);
                $closure = $wrapper->getClosure();
                $closure($this);
            }
        }
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

}