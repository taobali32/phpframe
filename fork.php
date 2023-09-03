<?php


function showPID()
{
    $pid = posix_getpid();
    fprintf(STDOUT,"pid=%d,ppid=%d,pgid=%d,sid=%d\n",$pid,posix_getppid(),posix_getpgid($pid),posix_getsid($pid));

}


showPID();

$pid = pcntl_fork();

if ($pid>0){
    exit(0);
}


var_dump(1111);