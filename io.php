<?php

//I/O事件
//多进程编程
$sockfd = stream_socket_pair(AF_UNIX,SOCK_STREAM,0);

// 设置为非阻塞!!
stream_set_blocking($sockfd[0],0);
stream_set_blocking($sockfd[1],0);

$pid = pcntl_fork();
//epoll
if ($pid==0){

//    while (1){
//
//        fwrite($sockfd[1],"hello");
//        sleep(1);
//    }
    $eventBase = new \EventBase();
//I/O事件
    $event = new \Event($eventBase,$sockfd[1],\Event::WRITE|\Event::PERSIST,function($fd,$what,$arg){

        echo fwrite($fd,"china");
        echo "\r\n";

    },['a'=>'b']);

    $event->add();

    $events[] = $event;

    $eventBase->dispatch();
}
else{

    $eventBase = new \EventBase();
    //I/O事件
    $event = new \Event($eventBase,$sockfd[0],\Event::READ|\Event::PERSIST,function($fd,$what,$arg){

        echo fread($fd,128);
        echo "\r\n";

    },['a'=>'b']);

    $event->add();

    $events[] = $event;

    $eventBase->dispatch();//内部会执行循环
}
