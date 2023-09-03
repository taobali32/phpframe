<?php

$eventBase = new EventBase();

echo "start:" . time() . "\r\n";

//  https://www.php.net/manual/zh/event.construct.php
//第一个参数 EventBase $base
// fd 定时器 给 -1,
// what 事件读还是写 超时,信号
// callable 事件的信号.

// 之后后立即按ctrl+c则立即触发!
$event = new Event($eventBase,2,Event::SIGNAL,function ($fd,$what,$arg){
    echo "中断信号执行了\n";

    var_dump($fd);
    echo "\r\n";
    var_dump($what);
    echo "\r\n";

    var_dump($arg);
    echo "\r\n";

},['a'=>1,'b'=>2]);

$event->add(2);

$events[] = $event;

$eventBase->dispatch();

