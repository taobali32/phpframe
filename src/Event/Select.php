<?php

namespace Jtar\Event;

class Select implements Event
{
    public static $_timerId = 0;

    public $_allEvents = [];

    public $_signalEvents = [];

    public $_timers = [];

    public $_readFds = [];
    public $_writeFds = [];

    public $_exptFds = [];

    public $_timeOut = 0; // 100秒

    public $_run = true;

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
                $param = [$func,$arg];

                /**
                 * 在pcntl_signal($fd,[$this,"signalHandler"],false)中，false是用来设置信号是否可重入的参数。
                 *
                 * 当设置为false时，表示信号处理函数signalHandler不可重入。也就是说，如果在处理信号期间再次收到相同的信号，那么第二个信号将被忽略，直到第一个信号处理完毕。
                 *
                 * 如果将该参数设置为true，则表示信号处理函数可重入。这意味着如果在处理信号期间再次收到相同的信号，那么第二个信号不会被忽略，而是会立即触发信号处理函数。
                 *
                 * 通常情况下，将该参数设置为false是比较常见的做法，以避免信号处理函数的重入导致意外的行为或竞争条件。但在某些特定的应用场景下，可能需要将其设置为true来处理特定的需求。
                 */
                $this->_signalEvents[(int)$fd] = $param;
                
                pcntl_signal($fd,[$this,"signalHandler"],false);

                return true;

            case self::EVENT_TIMER:
            case self::EVENT_TIMER_ONCE:
                $timerId = static::$_timerId;

                $runTime = microtime(true) + $fd;

                $param = [$func,$runTime,$flag,$timerId,$fd,$arg];

                $this->_timers[$timerId] = $param;

                // $fd 微秒 转换为秒
                $selectTime = $fd * 1000000;

                if ($this->_timeOut >= $selectTime){
                    $this->_timeOut = $selectTime;
                }

                ++static::$_timerId;
                return $timerId;
        }
    }

    public function signalHandler($signo){
        
        var_dump("select_signalHandler");
        $callBack = $this->_signalEvents[$signo];

        if (is_callable($callBack[0])){
            call_user_func_array($callBack[0],[$signo]);
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
                if (isset($this->_signalEvents[$fd])){
                    unset($this->_signalEvents[$fd]);
                    pcntl_signal($fd, SIG_IGN);
                }
            break;

            case self::EVENT_TIMER:
            case self::EVENT_TIMER_ONCE:
                if (isset($this->_timers[$fd])){
                    unset($this->_timers[$fd]);
                }
            break;

        }
    }


    public function timerCallBack()
    {
        foreach ($this->_timers as $k=>$timer){

            $func = $timer[0];
            $runTime = $timer[1];
            $flag = $timer[2];
            $timerId = $timer[3];
            $fd = $timer[4];
            $arg = $timer[5];

            if ($runTime-microtime(true)<=0){

                if ($flag==Event::EVENT_TIMER_ONCE){
                    unset($this->_timers[$timerId]);
                }else{
                    $runTime = microtime(true)+$fd;//取得下一个时间点
                    $this->_timers[$k][1] = $runTime;
                }
                call_user_func_array($func,[$timerId,$arg]);
            }
        }
    }

    public function getIsRunning(){
        return $this->_run;
    }

    public function loop()
    {
        while ($this->_run){

            pcntl_signal_dispatch();

            $reads = $this->_readFds;
            $writes = $this->_writeFds;
            $expts = $this->_exptFds;// 发送紧急数据有点用，URG tcp头部结构的标志 FIN|RST|ACK|PSH|URG
            set_error_handler(function (){});
            $ret = stream_select($reads,$writes,$expts,0,$this->_timeOut);

            restore_error_handler();


            if (!empty($this->_timers)){
                $this->timerCallBack();
            }

            if (!$ret){
                continue;
            }

            if ($reads){
                foreach ($reads as $fd) {
                    $fdKey = (int)$fd;
                    if (isset($this->_allEvents[$fdKey][self::EVENT_READ])){
                        $callback = $this->_allEvents[$fdKey][self::EVENT_READ];
                        call_user_func_array($callback[0],$callback[1]);
                    }
                }

            }
            if ($writes){
                foreach ($writes as $fd) {
                    $fdKey = (int)$fd;
                    if (isset($this->_allEvents[$fdKey][self::EVENT_WRITE])){
                        $callback = $this->_allEvents[$fdKey][self::EVENT_WRITE];
                        call_user_func_array($callback[0],$callback[1]);
                    }
                }

            }
        }
    }



    public function exitLoop()
    {
        $this->_run=false;
        $this->_readFds=[];
        $this->_writeFds=[];
        $this->_exptFds=[];
        $this->_allEvents=[];
        return true;
    }

    public function clearSignalEvents()
    {
        foreach ($this->_signalEvents as $fd=>$arg){
            //  $fd 信号编号, SI_IGN 忽略
            //  指定当信号到达时系统调用重启是否可用。（译注：经查资料，此参数意为系统调用被信号打断时，系统调用是否从 开始处重新开始，

            // 清理的时候忽略掉,在Server stop的时候调用了
            pcntl_signal($fd,SIG_IGN,false);
        }
        $this->_signalEvents = [];
    }

    public function clearTimer()
    {
        $this->_timers = [];
    }
}