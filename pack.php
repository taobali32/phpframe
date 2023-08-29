<?php

$data = "hello";
$totalLen = strlen($data) + 6;

// 这个 "1" 是命令 2个字节 . 拼接数据

$bin = pack("Nn", $totalLen,"1");
var_dump(unpack("Na/nb", $bin));
