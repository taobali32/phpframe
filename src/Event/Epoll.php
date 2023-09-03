<?php

namespace Jtar\Event;

class Epoll implements Event
{
    public $_eventBase;

    public $_allEvents = [];

    public $_signalEvents = [];


    public function __construct(){
        $this->_eventBase = new \EventBase();
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

            case self::EVENT_SIGNAL:
                $event = new \Event($this->_eventBase, $fd, \Event::SIGNAL, $func,$args);

                if (!$event || !$event->add()){
                    return false;
                }

                $this->_signalEvents[(int)$fd]= $event;
                return true;
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

            return true;


            case self::EVENT_SIGNAL:
                if (isset( $this->_signalEvents[(int)$fd])){
                    $event = $this->_signalEvents[(int)$fd];
                    $event->del();
                    unset($this->_signalEvents[(int)$fd]);
                }
                return  true;
            break;
        }
    }

    public function loop()
    {
        $this->_eventBase->dispatch();
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