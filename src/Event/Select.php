<?php

namespace Jtar\Event;

class Select implements Event
{
    public $_eventBase;

    public $_allEvents = [];

    public $_signalEvents = [];

    public $_timers = [];

    public $_readFds = [];
    public $_writeFds = [];

    public $_exptFds = [];

    public $_timeOut = 0;

    public function __construct(){
    }

    public function add($fd, $flag, $func, $arg = [])
    {
        switch ($flag){
            case self::EVENT_READ:
                $fdKey = (int)$fd;
                $this->_readFds[$fdKey] = $fd;
                $this->_allEvents[$fdKey][self::EVENT_READ] = [$func,[$fd,$flag,$arg]];

                return true;

            case self::EVENT_WRITE:

                $fdKey = (int)$fd;
                $this->_readFds[$fdKey] = $fd;
                $this->_allEvents[$fdKey][self::EVENT_WRITE] = [$func,[$fd,$flag,$arg]];

                return true;

            case self::EVENT_SIGNAL:

                return true;
        }
    }

    public function del($fd, $flag)
    {
        switch ($flag){
            case self::EVENT_READ:
                // [1][read] = event
                // [1][write] = event
                // _allEvents[1][read] = func
                // _allEvents[1][write] = func

                $fdKey = (int)$fd;

                unset($this->_allEvents[$fdKey][self::EVENT_READ]);
                unset($this->_readFds[$fdKey]);


                if (empty($this->_allEvents[$fdKey])){
                    unset($this->_allEvents[$fdKey]);
                }


              return true;

            case self::EVENT_WRITE:
                $fdKey = (int)$fd;

                unset($this->_allEvents[$fdKey][self::EVENT_WRITE]);
                unset($this->_writeFds[$fdKey]);

                if (empty($this->_allEvents[$fdKey])){
                    unset($this->_allEvents[$fdKey]);
                }


            return true;


            case self::EVENT_SIGNAL:

                return  true;
            break;
        }
    }

    public function loop1()
    {
        while (1) {

            $reads = $this->_readFds;
            $writes = $this->_writeFds;
            $expts = $this->_exptFds;

            set_error_handler(function (){});

//           这些是不可以重复的,重复了会出现好多奇怪的问题!!
//            print_r($reads);
//            print_r($writes);

            //  函数是 PHP 中用于多路复用的一个函数 它可以检查多个文件流（套接字、文件等）是否可读、可写或出现异常，并在有可读、可写或异常情况发生时返回相应的文件流。
            $ret = stream_select($reads, $writes, $expts, 0,$this->_timeOut);

            restore_error_handler();
            if ($ret === false) {
                break;
            }

            if ($reads){
                foreach ($reads as $fd) {
                    $fdkey = (int)$fd;

                    if (isset($this->_allEvents[$fdkey][self::EVENT_READ])){
                        $callback = $this->_allEvents[$fdkey][self::EVENT_READ];

                        call_user_func_array($callback[0],$callback[1]);

                    }
                }
            }

            if ($writes){
                foreach ($writes as $fd){
                    $fdkey = (int)$fd;

                    if (isset($this->_allEvents[$fdkey][self::EVENT_WRITE])){
                        $callback = $this->_allEvents[$fdkey][self::EVENT_WRITE];
                        call_user_func_array($callback[0],$callback[1]);
                    }
                }
            }
        }

        return true;
    }

    public function loop()
    {
            $reads = $this->_readFds;
            $writes = $this->_writeFds;
            $expts = $this->_exptFds;

            set_error_handler(function (){});

//           这些是不可以重复的,重复了会出现好多奇怪的问题!!
//            print_r($reads);
//            print_r($writes);

            //  函数是 PHP 中用于多路复用的一个函数 它可以检查多个文件流（套接字、文件等）是否可读、可写或出现异常，并在有可读、可写或异常情况发生时返回相应的文件流。
            $ret = stream_select($reads, $writes, $expts, 0,$this->_timeOut);

            restore_error_handler();
            if ($ret === false) {
                return false;
            }

            if ($reads){
                foreach ($reads as $fd) {
                    $fdkey = (int)$fd;

                    if (isset($this->_allEvents[$fdkey][self::EVENT_READ])){
                        $callback = $this->_allEvents[$fdkey][self::EVENT_READ];

                        call_user_func_array($callback[0],$callback[1]);

                    }
                }
            }

            if ($writes){
                foreach ($writes as $fd){
                    $fdkey = (int)$fd;

                    if (isset($this->_allEvents[$fdkey][self::EVENT_WRITE])){
                        $callback = $this->_allEvents[$fdkey][self::EVENT_WRITE];
                        call_user_func_array($callback[0],$callback[1]);
                    }
                }
            }

            return true;
    }

    public function clearTimer()
    {
        // TODO: Implement clearTimer() method.
    }

    public function clearSignalEvents()
    {
        // TODO: Implement clearSignalEvents() method.
    }
}