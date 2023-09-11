<?php

namespace Jtar;


namespace Jtar;

use Jtar\Event\Epoll;
use Jtar\Event\Event;
use Jtar\Event\Select;
use Jtar\Protocols\Stream;
use Jtar\Protocols\Text;

class Server
{
    const STATUS_SHUTDOWN = 3;
    const STATUS_RUNNING=2;

    const STATUS_STARTING = 1;
    public static $_pidFile;
    public static $_logFile;
    public static $_startFile;
    public static $_status;

    private $_mainSocket;
    private $_local_socket;
    static public $_connections = [];

    public $_events = [];

    public $_protocol = null;


    public $_protocol_layout;

    public $_setting = [];
    /**
     * @var mixed
     */
    public $_pidMap = [];


    public function setting($setting){
        $this->_setting = $setting;
    }

    static public $_eventLoop;

    public $_protocols = [
        'stream'    =>  Stream::class,
        "text"      =>  Text::class,
        "ws"        =>  "",
        "http"      =>  "",
        "mqtt"      =>  ""
    ];

    static public $_clientNum = 0; //  客户端连接数量
    static public $_recvNum = 0;  // 执行recv/fread调用多少次， 1秒内
    static public $_msgNum = 0;    // 1秒中内接收了多少消息

    public $_startTime = 0;

    public function on($eventName,$eventCall){
        $this->_events[$eventName] = $eventCall;
    }

    public function __construct($local_socket = "tcp://0.0.0.0:12345")
    {
        list($protocol,$ip, $port) = explode(":", $local_socket);
        if ( isset($this->_protocols[$protocol])){
            
            $this->_protocol = new $this->_protocols[$protocol]();
        }

        $this->_startTime = time();

        $this->_local_socket = "tcp:" . $ip . ":" . $port;

    }

    public function statistics()
    {
//        var_dump("statistics");
        $nowTime = time();
        $diffTime = $nowTime - $this->_startTime;

        $this->_startTime = $nowTime;

        if ($diffTime >= 1){
            fprintf(STDOUT, "time:%s--socket<%d>--<clientNum:%d>--<recvNum:%d>--<msgNum:%d>\r\n",$diffTime, (int)$this->_mainSocket, static::$_clientNum, static::$_recvNum, static::$_msgNum);

            static::$_recvNum = 0;
            static::$_msgNum = 0;
        }
    }


    public function listen()
    {
        $flag = STREAM_SERVER_LISTEN|STREAM_SERVER_BIND;
        $option['socket']['backlog'] = 102400;

        //  创建并返回一个资源流上下文，该资源流中包含了 options 中提前设定的所有参数的值。
        //  https://www.php.net/manual/zh/function.stream-context-create.php
        //  https://www.php.net/manual/zh/context.php
        $content = stream_context_create($option);

        //  在指定 address 上创建 stream 或者数据包套接字（datagram socket）。
        //  此函数仅创建套接字，并使用 stream_socket_accept() 开始接受连接。
        $this->_mainSocket = stream_socket_server($this->_local_socket, $errno, $errstr, $flag, $content);

        // 设置为非阻塞IO
        stream_set_blocking($this->_mainSocket,0);
        if (!is_resource($this->_mainSocket)) {
            fprintf(STDOUT, "server create fail:%s\n", $errstr);
            exit(0);
        }

        fprintf(STDOUT, "listen on:%s\n", $this->_local_socket);
    }


    public function eventLoop(){
        static::$_eventLoop->loop();
    }

    public function onClientJoin(){
        ++static::$_clientNum;
    }

    public function checkHeartTime()
    {
//        var_dump("checkHeartTime");

        foreach (static::$_connections as $idx => $connection){

            if ($connection->checkHeartTime()){
                $connfd = $connection->connfd();

                // 超出心跳时间,关闭掉这个客户端
                $this->removeConnection($connfd);
                $this->runEventCallBack('close',[(int)$connfd, $connection]);
            }
        }
    }

    public function onRecv(){
        ++static::$_recvNum;
    }

    public function onMsg(){
        ++static::$_msgNum;
    }


    public function accept()
    {
        set_error_handler(function (){});

        $connfd = stream_socket_accept($this->_mainSocket, -1,$peername);
        restore_error_handler();

        if (is_resource($connfd)) {

            $connection = new TcpConnection($connfd, $peername,$this);
            $this->onClientJoin();
            
            static::$_connections[(int)$connfd] = $connection;

            $this->runEventCallBack('connect',[$connection]);

//            echo "接受到客户端连接\r\n";
        }
    }

    public function runEventCallBack($eventName,$args = []){
        if (isset($this->_events[$eventName]) && is_callable($this->_events[$eventName])) {
            $this->_events[$eventName]($this,...$args);
        }
    }


    public function removeConnection($connfd)
    {
        if (isset(static::$_connections[(int)$connfd])) {
            unset(static::$_connections[(int)$connfd]);

            --static::$_clientNum;
        }
    }



    public function init()
    {
        // 创建日志文件和pid保存文件
        $trace = debug_backtrace();
        $startFile = array_pop($trace)['file'];

        static::$_startFile = $startFile;

        static::$_pidFile = pathinfo($startFile)['filename'] . ".pid";

        static::$_logFile  = pathinfo($startFile)['filename'] . ".log";

        if (!file_exists(static::$_logFile)){
            file_put_contents(static::$_logFile, "");

            chown(static::$_logFile, posix_getuid());
        }
    }



    public function worker()
    {
        static::$_status = self::STATUS_RUNNING;

//        cli_set_process_title("Te/worker");

        if (DIRECTORY_SEPARATOR == "/"){
            static::$_eventLoop = new Epoll();
        }else{
            static::$_eventLoop = new Select();
        }
//        static::$_eventLoop = new Select();

        // 子进程安装信号!
        // 用事件循环的信号,所以先忽略下
        pcntl_signal(SIGINT, SIG_IGN,false);  //  忽略 ctrl+c
        pcntl_signal(SIGTERM, SIG_IGN,false); //  忽略 请求进程终止
        pcntl_signal(SIGQUIT, SIG_IGN,false); //  忽略 请求进程终止并生成核心转储（core dump）
        //  该信号在与一个已关闭的写入端点的管道通信时发生。当您尝试向一个已关闭的管道写入数据时
//        pcntl_signal(SIGPIPE, SIG_IGN,false);

        static::$_eventLoop->add(SIGINT,Event::EVENT_SIGNAL,[$this,"sigHandler"]);
        static::$_eventLoop->add(SIGTERM,Event::EVENT_SIGNAL,[$this,"sigHandler"]);
        static::$_eventLoop->add(SIGQUIT,Event::EVENT_SIGNAL,[$this,"sigHandler"]);

        static::$_eventLoop->add($this->_mainSocket,Event::EVENT_READ,[$this,"accept"]);
//        static::$_eventLoop->add(2,Event::EVENT_TIMER,[$this,"checkHeartTime"]);
//        static::$_eventLoop->add(1,Event::EVENT_TIMER,[$this,"statistics"]);

//        static::$_eventLoop->add(2,Event::EVENT_TIMER,function ($timerId,$arg){
//            echo posix_getpid() . "定时\r\n";
//        });


        $this->runEventCallBack("workerStart",[$this]);


        $this->eventLoop();
        $this->runEventCallBack("workerStop",[$this]);

        exit(0);
    }

    public function saveMasterPid()
    {
        $pid = posix_getpid();

        file_put_contents(static::$_pidFile, $pid);
    }


    public function forkWorker()
    {
        $workerNum = $this->_setting['workerNum'] ?? 1;

        for ($i = 0; $i < $workerNum; $i++){
            $pid = pcntl_fork();

            if ($pid == 0){
                $this->worker();
            }else{
                $this->_pidMap[$pid] = $pid;
            }
        }
    }

    public function reloadWorker(){
        $pid = pcntl_fork();

        if ($pid == 0){
            $this->worker();
        }else {
            $this->_pidMap[$pid] = $pid;
        }
    }

    public function masterWork()
    {
       while (1) {
        //在给定的代码片段中，pcntl_signal_dispatch()函数被调用两次。
        //第一次调用用于处理可能在之前被挂起的信号，
        //第二次调用用于处理pcntl_wait()函数返回的子进程状态。这样可以确保在等待子进程结束期间，其他挂起的信号能够得到及时处理。
           pcntl_signal_dispatch();
            $pid = pcntl_wait($status);
           pcntl_signal_dispatch();

           // 这3行回收子进程

            if ($pid > 0) {
                unset($this->_pidMap[$pid]);

                if (self::STATUS_SHUTDOWN != static::$_status){
                    $this->reloadWorker();
                }
            }

            if (empty($this->_pidMap)) {
                break;
            }
        }

        $this->runEventCallBack("masterShutdown",[$this]);

        exit(0);
    }

    // 主进程和子进程收到中断信号执行
    public function sigHandler($sigNum)
    {

        var_dump("主收到sigHandler:" . $sigNum);

        $masterPid = file_get_contents(static::$_pidFile);
        switch ($sigNum){

            case SIGINT:
            case SIGTERM:
            case SIGQUIT:

                //主进程
                if ($masterPid==posix_getpid()){

                    print_r($this->_pidMap);

                    foreach ($this->_pidMap as $pid=>$pid){

                        posix_kill($pid,$sigNum);//SIGKILL 它是粗暴的关掉，不过子进程在干什么 SIGTERM,SIGQUIT
                    }
                    static::$_status = self::STATUS_SHUTDOWN;
                    
                    var_dump("主进程循环杀子信号");

                }else{
                    
                    static::$_eventLoop->exitLoop();
                    //子进程的 就要停掉现在的任务了
                    static::$_eventLoop->del($this->_mainSocket,Event::EVENT_READ);
                    set_error_handler(function (){});
                    fclose($this->_mainSocket);
                    restore_error_handler();
                    $this->_mainSocket = null;
                    foreach (static::$_connections as $fd=>$connection){

                        $connection->Close();
                    }
                    static::$_connections = [];

                    static::$_eventLoop->clearSignalEvents();
                    static::$_eventLoop->clearTimer();

                    if (static::$_eventLoop->exitLoop()){

                        fprintf(STDOUT, "<pid:%d> worker exit event loop success\r\n", posix_getpid());
//                        $this->echoLog("<pid:%d> worker exit event loop success\r\n",posix_getpid());


                    }



                }

                break;
        }
    }

    // 主进程安装信号.
    public function installSignalHandler()
    {
        /**
         * 在pcntl_signal(SIGINT,[$this,"sigHandler"],false)中，false参数表示信号处理函数是否可重入。
         *
         * 当设置为false时，表示信号处理函数sigHandler不可重入。这意味着如果在处理信号期间再次收到相同的信号，那么第二个信号将被忽略，直到第一个信号处理完毕。
         *
         * 如果将该参数设置为true，则表示信号处理函数可重入。这意味着如果在处理信号期间再次收到相同的信号，那么第二个信号不会被忽略，而是会立即触发信号处理函数。
         *
         * 通常情况下，将该参数设置为false是比较常见的做法，以避免信号处理函数的重入导致意外的行为或竞争条件
         */
        pcntl_signal(SIGINT, [$this,"sigHandler"],false);
        pcntl_signal(SIGTERM, [$this,"sigHandler"],false);
        pcntl_signal(SIGQUIT, [$this,"sigHandler"],false);

        // 读写socket文件时产生信号时候忽略, 主要是 对端关闭了,  你还在发,就会产生这信号
        pcntl_signal(SIGPIPE, SIG_IGN,false);

    }

    public function start()
    {
        // 开始运行标志
        static::$_status = self::STATUS_STARTING;

        $this->init();

        global $argv;

        $command = $argv[1] ?? '';

        switch ($command){
            case "start":

                if (is_file(static::$_pidFile)){
                    $masterPid = file_get_contents(static::$_pidFile);
                }else{
                    $masterPid = 0;
                }

                //  检测进程是否存在 ,posix_kill($masterPid, 0)   0
                /**
                 * posix_kill($masterPid, 0)：posix_kill() 函数用于发送信号给指定进程。在这里，我们使用信号编号为0的信号，它实际上并不发送给进程，而是用于检查进程是否存在。如果进程存在，该函数将返回 true，否则返回 false。
                 */
                $masterPidisAlive = $masterPid && posix_kill($masterPid, 0) && $masterPid != posix_getpid();

                /**
                 * $masterPid != posix_getpid()：这是一个额外的条件，
                 * 用于确保 $masterPid 不是当前进程的进程ID。这是为了避免将当前进程误判为主进程。
                 */

                if ($masterPidisAlive){
                    exit("server is running...\r\n");
                }

                
                $this->runEventCallBack("masterStart",[$this]);

                $this->saveMasterPid();
                $this->installSignalHandler();
                $this->listen();

                $this->forkWorker();

                static::$_status = self::STATUS_RUNNING;

                $this->masterWork();

                break;

            case "stop":
                $masterPid = file_get_contents(static::$_pidFile);
                if ($masterPid&&posix_kill($masterPid,0)){

                    //  给主进程发
                    posix_kill($masterPid,SIGINT);
                    echo "发送了SIGTERM信号了\r\n";
                    echo $masterPid."\r\n";
                    $timeout = 5;
                    $stopTime = time();
                    while (1){

                        $masterPidisAlive = $masterPid&& posix_kill($masterPid,0)&& $masterPid != posix_getpid();
                        if ($masterPidisAlive){

                            if (time()-$stopTime>=$timeout){

                                fprintf(STDOUT, "server stop failure\r\n");
                                break;
                            }
                            sleep(1);
                            continue;
                        }

                        fprintf(STDOUT, "server stop success\r\n");
//                        $this->echoLog("server stop success\r\n");
                        break;
                    }

                }else{
                    exit("server not exist...");
                }

                break;
            default:
                //php te.php start|stop
                $usage = "php ".pathinfo(static::$_startFile)['filename'].".php [start|stop]\r\n";
                exit($usage);
        }


//        $timerId = static::$_eventLoop->add(2,Event::EVENT_TIMER,function ($timerId,$arg){
//            print_r($arg);
//            print_r($timerId);
//            static::$_eventLoop->del($timerId,Event::EVENT_TIMER);
//        },['name' => 'xx']);

    }
}
