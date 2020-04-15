<?php

require 'PdoPool.php';
require 'MysqlWatcher.php';
require 'Server.php';

$server = new Server();
$server->run();
