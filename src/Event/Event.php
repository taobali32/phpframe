<?php

namespace Jtar\Event;

interface Event
{
    const EVENT_READ = 10;

    const EVENT_WRITE = 11;

    const EVENT_SIGNAL = 12;

    const EVENT_TIMER = 13;

    const EVENT_TIMER_ONCE = 14;



    public function add($fd,$flag,$func,$args);

    public function del($fd,$flag);

    public function loop();

    public function clearTimer();

    public function clearSignalEvents();
}