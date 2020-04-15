<?php

use Swoole\Coroutine\Channel;

class PdoPool
{
    /**
     * @var \Swoole\Coroutine\Channel
     */
    protected $pool;

    public function __construct(array $config)
    {
        $size = $config['pool_size'] ?? 8;
        $this->pool = new Channel($size);
        for ($i=0; $i<$size; $i++) {
            while (true) {
                try {
                    $dbHost = $config['db_host'] ?? '127.0.0.1';
                    $dbName = $config['db_name'] ?? 'localhost';
                    $dbUser = $config['db_user'] ?? 'root';
                    $dbPass = $config['db_pass'] ?? 'root';
                    $dsn = "mysql:host=$dbHost;dbname=$dbName";
                    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_PERSISTENT => true]);
                    $this->put($pdo);
                    break;
                } catch (PDOException $e) {
                    var_dump('数据库连接失败');
                    usleep(1000);
                    continue;
                }
            }
        }
    }

    public function get()
    {
        return $this->pool->pop();
    }

    public function put(PDO $pdo)
    {
        $this->pool->push($pdo);
    }

    public function close()
    {
        $this->pool->close();
        $this->pool = null;
    }
}