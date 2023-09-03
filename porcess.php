<?php

namespace MsqServer;


class Process{
    public $p_pid;

    public $_msqid;
}

class Server{
    public $_socketFile = "pool.sock";
    public $_porcessNum = 3;

    protected $_keyFile = "12.php";

    public $_idx;

    public $_process = [];

    public $socketFd;

    public $run = true;

    public $roll = 0;

    public function sigHandler($signo){
        $this->run = false;
    }

    public function __construct($num = 3)
    {
        pcntl_signal(SIGINT,[$this,"sigHandler"]); // ctrl + c

        $this->_porcessNum = $num;

        $this->forkWorker();

        $this->listen();

        $exitPid = [];
        while (1){
            $pid = pcntl_wait($status);

            if ($pid > 0){
                $exitPid[] = $pid;
            }

            if (count($exitPid) == $this->_porcessNum){
                break;
            }
        }

        foreach ($this->_process as $p){
            msg_remove_queue($p->_msqid);
        }

        fprintf(STDOUT,"master shutdown.\n");


    }

    public function listen()
    {
        $this->socketFd = socket_create(AF_UNIX,SOCK_STREAM,0);

        if (!is_resource($this->socketFd)){
            fprintf(STDOUT,"socket create fail1:%s\n",socket_strerror(socket_last_error($this->socketFd)));

//            exit(0);
        }

        if (file_exists($this->_socketFile)){
            unlink($this->_socketFile);
        }

        if (! socket_bind($this->socketFd,$this->_socketFile)){
            fprintf(STDOUT,"socket create fail2:%s\n",socket_strerror(socket_last_error($this->socketFd)));
        }

        socket_listen($this->socketFd,10);

        $this->eventLoop();
    }

    public function eventLoop()
    {
        $readFds = [$this->socketFd];
        $wrteFds = [$this->socketFd];
        $exFds = [$this->socketFd];

        while ($this->run){

            pcntl_signal_dispatch();

            // select I/O 复用函数
            $ret = socket_select($readFds,$wrteFds,$exFds,NULL,NULL);

            if (false == $ret){
                break;
            }elseif ($ret == 0){
                continue;
            }

            if ($readFds){
                foreach ($readFds as $fd){
                    if ($fd == $this->socketFd){
                        $connfd = socket_accept($fd);

                        $data = socket_read($connfd,1024);

                        if ($data){
                            $this->selectWorkder($data);
                        }
                        socket_write($connfd,"ok",2);
                        socket_close($connfd);
                    }
                }
            }
        }

        socket_close($this->socketFd);

        foreach ($this->_process as $p){
            if (msg_send($p->_msqid,1,"quit")){
                fprintf(STDOUT, "master send quit ok.\n");
            }
        }
    }

    public function selectWorkder($data){
        /**
         * @var Process $process
         */
        $process = $this->_process[$this->roll++ %$this->_porcessNum];


        $msgid = $process->_msqid;

        if (msg_send($msgid,1,$data,true,false)){
            fprintf(STDOUT, "send ok in pid=%d\n",posix_getpid());
        }
    }

    public function worker(){

        fprintf(STDOUT, "child pid=%d start \n",posix_getpid());

        /**
         * @var Process $process
         */
        $process = $this->_process[$this->_idx];

        $msgid = $process->_msqid;

        while (1){
            if (msg_receive($msgid,0,$msgType,1024,$msg)){
                fprintf(STDOUT, "child pid=%d recv:%s \n",posix_getpid(),$msg);

                if (strncasecmp($msg,"quit",4) == 0){
                    break;
                }
            }
        }

        fprintf(STDOUT, "child pid=%d shutdown \n",posix_getpid());

        exit(0);
    }

    public function forkWorker()
    {
        $processObj = new Process();

        for ($i = 0; $i < $this->_porcessNum;$i++){
            $key = ftok($this->_keyFile,(string)$i);

            $msgid = msg_get_queue($key);

            $process = clone $processObj;

            $process->_msqid = $msgid;

            $this->_process[$i] = $process;

            $this->_idx = $i;

            $this->_process[$i]->p_pid = pcntl_fork();

            if ($this->_process[$i]->p_pid == 0){
                $this->worker();
            }else{
                continue;
            }
        }
    }
}


(new Server(3));
