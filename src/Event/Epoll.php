<?php

namespace Jtar\Event;

class Epoll implements Event
{
    public static $_timerId = 0;
    public $_eventBase;

    public $_allEvents = [];

    public $_signalEvents = [];
    /**
     * @var mixed
     */
    public $_timers = [];


    public function __construct(){
        $this->_eventBase = new \EventBase();
    }

    public function timerCallBack($fd,$what,$args){
//        $param = [$func,$flag,$timerId,$args];

//            static::$_eventLoop->add(2,Event::EVENT_TIMER_ONCE,[$this,"checkHeartTime"],[]);
//            public function add($fd, $flag, $func, $args = [])
//            $event = new \Event($this->_eventBase, -1, \Event::TIMEOUT|\Event::PERSIST, [$this,"timerCallBack"],$param);
//        $param = [$func,$flag,static::$_timerId,$args];

        $func = $args[0];

        $flag = $args[1];

        $timerId = $args[2];

        $userArg = $args[3];
        

        if ($flag == Event::EVENT_TIMER_ONCE){
            $event = $this->_timers[$timerId][$flag];
            $event->del();

            unset($this->_timers[$timerId][$flag]);
        }

        call_user_func_array($func, [$userArg]);
    }

    public function add($fd, $flag, $func, $args = [])
    {
        switch ($flag){
            case self::EVENT_READ:
                // fd必须设置为非阻塞方式,因为epoll内部是使用非阻塞的文件描述符把他添加到内核事件表.
                $event = new \Event($this->_eventBase, $fd, \Event::READ|\Event::PERSIST, $func,$args);

                if (!$event || !$event->add()){
                    return false;
                }

                // 存起来后续用
                $this->_allEvents[(int)$fd][self::EVENT_READ] = $event;

                return true;

            case self::EVENT_WRITE:
                $event = new \Event($this->_eventBase, $fd, \Event::WRITE|\Event::PERSIST, $func,$args);

                if (!$event || !$event->add()){
                    return false;
                }

                $this->_allEvents[(int)$fd][self::EVENT_WRITE] = $event;

                return true;


            // 添加信号, 参考 event.php
            case self::EVENT_SIGNAL:
                $event = new \Event($this->_eventBase, $fd, \Event::SIGNAL, $func,$args);

                if (!$event || !$event->add()){
                    return false;
                }

                $this->_signalEvents[(int)$fd]= $event;
                return true;

            //  定时器添加
            case self::EVENT_TIMER:
            case self::EVENT_TIMER_ONCE:
                $timerId = static::$_timerId;
                $param = [$func,$flag,$timerId,$args];
                $event = new \Event($this->_eventBase,-1,\Event::TIMEOUT|\Event::PERSIST,[$this,"timerCallBack"],$param);
                if (!$event||!$event->add($fd)){
                    //echo "定时事件添加失败\r\n";
                    return false;
                }
                //echo "定时事件添加成功\r\n";
                $this->_timers[$timerId][$flag] = $event;
                ++static::$_timerId;
                return $timerId;
        }
    }

    public function del($fd, $flag)
    {
        switch ($flag){
            case self::EVENT_READ:
              if (isset( $this->_allEvents[(int)$fd][self::EVENT_READ])){
                  $event = $this->_allEvents[(int)$fd][self::EVENT_READ];
                  $event->del();
                  unset($this->_allEvents[(int)$fd][self::EVENT_READ]);
              }
              
              if (empty($this->_allEvents[(int)$fd])){
                  unset($this->_allEvents[(int)$fd]);
              }
              return true;

            case self::EVENT_WRITE:
                if (isset( $this->_allEvents[(int)$fd][self::EVENT_WRITE])){
                    $event = $this->_allEvents[(int)$fd][self::EVENT_WRITE];
                    $event->del();
                    unset($this->_allEvents[(int)$fd][self::EVENT_WRITE]);
                }

                if (empty($this->_allEvents[(int)$fd])){
                    unset($this->_allEvents[(int)$fd]);
                }
                break;

            case self::EVENT_SIGNAL:
                if (isset( $this->_signalEvents[(int)$fd])){
                    $event = $this->_signalEvents[(int)$fd];
                    $event->del();
                    unset($this->_signalEvents[(int)$fd]);
                }
            break;

//                定时器删除  $flag 一次还是多次的定时器!=>
            case self::EVENT_TIMER:
            case self::EVENT_TIMER_ONCE:
                if (isset($this->_timers[$fd][$flag])){
                    $this->_timers[$fd][$flag]->del();
                    unset($this->_timers[$fd][$flag]);
                }

            break;
        }
    }

    public function loop()
    {
        $this->_eventBase->dispatch();
    }

    public function exitLoop()
    {
        // TODO: Implement exitLoop() method.
        return $this->_eventBase->stop();
    }

    // 清理定时器
    public function clearTimer()
    {
        foreach ($this->_timers as $timerId=>$event){

            if(current($event)->del()){
            }
        }

        $this->_timers = [];
    }

    public function clearSignalEvents()
    {
        foreach ($this->_signalEvents as $fd=>$event){

            if($event->del()){
            }
        }

        $this->_signalEvents = [];
    }
}