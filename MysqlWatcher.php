<?php

class MysqlWatcher
{
    /**
     * @var PDO
     */
    protected $pdo;


    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function collect(): array
    {
        $result = [];

        $result[] = $this->queryVersion();
        $result[] = $this->queryBlocking();
        $result[] = $this->queryThreadsRunning();
        $result[] = $this->queryMaxUsedConnections();
        $result[] = $this->queryCurrentConnections();
        $result[] = $this->queryQps();
        $result[] = $this->queryTps();
        $result[] = $this->queryCacheHits();

        return $result;
    }

    public function queryVersion(): array
    {
        $sql = 'select @@version';

        $sth = $this->pdo->prepare($sql);
        $sth->execute();
        $row = $sth->fetch();

        return [
            'single_line' => true,
            'caption' => '版本号',
            'head' => '',
            'body' => [
                'Variable_name' => '@@version',
                'Value' => $row['@@version'],
            ],
            'foot' => '',
        ];
    }

    public function queryBlocking(int $blockingTime = 10): array
    {
        $sql = 'select 
                    waiting_pid, 
                    waiting_query, 
                    blocking_pid, 
                    blocking_query, 
                    wait_age, 
                    sql_kill_blocking_query 
                from 
                    sys.innodb_lock_waits 
                where 
                    (unix_timestamp() - unix_timestamp(wait_started)) > ?';

        $sth = $this->pdo->prepare($sql);
        $sth->execute([$blockingTime]);
        $rows = $sth->fetchAll();

        $head =[
            [
                'en' => 'waiting_pid',
                'zh' => '被阻塞线程',
            ],
            [
                'en' => 'waiting_query',
                'zh' => '被阻塞sql',
            ],
            [
                'en' => 'blocking_pid',
                'zh' => '阻塞线程',
            ],
            [
                'en' => 'blocking_query',
                'zh' => '阻塞sql',
            ],
            [
                'en' => 'wait_age',
                'zh' => '已阻塞时间',
            ],
            [
                'en' => 'sql_kill_blocking_query',
                'zh' => '建议执行',
            ],
        ];

        $body = [];
        foreach ($rows as $row) {
            $body[] = array_values($row);
        }

        return [
            'single_line' => false,
            'caption' => '当前阻塞状态',
            'head' => $head,
            'body' => $body,
            'foot' => '',
        ];
    }

    public function queryThreadsRunning(): array
    {
        $sql = 'show global status where variable_name = "Threads_running"';

        $sth = $this->pdo->prepare($sql);
        $sth->execute();
        $row = $sth->fetch();

        return [
            'single_line' => true,
            'caption' => '并发数',
            'head' => '',
            'body' => $row,
            'foot' => 'MySQL当前并行处理的会话数量，反映了此刻MySQL繁忙程度',
        ];
    }

    public function queryMaxUsedConnections(): array
    {
        $sql = 'show global status where variable_name = "Max_used_connections"';

        $sth = $this->pdo->prepare($sql);
        $sth->execute();
        $row = $sth->fetch();

        return [
            'single_line' => true,
            'caption' => '有史以来最大连接数',
            'head' => '',
            'body' => $row,
            'foot' => '如果该值达到max_connections的80%，建议调大max_connections',
        ];
    }

    public function queryCurrentConnections(): array
    {
        $sql = 'show global status where variable_name = "Threads_connected"';

        $sth = $this->pdo->prepare($sql);
        $sth->execute();
        $row = $sth->fetch();

        return [
            'single_line' => true,
            'caption' => '当前连接数',
            'head' => '',
            'body' => $row,
            'foot' => '',
        ];
    }

    public function queryQps(): array
    {
        $sql = 'show global status where variable_name in ("Queries", "Uptime")';

        $sth = $this->pdo->prepare($sql);
        $sth->execute();
        $rows = $sth->fetchAll();

        return [
            'single_line' => true,
            'caption' => '平均每秒处理请求的数量',
            'head' => '',
            'body' => [
                'Variable_name' => 'Qps',
                'Value' => sprintf('%.2f', $rows[0]['Value'] / $rows[1]['Value']),
            ],
            'foot' => '统计对象包括DML、DDL等',
        ];
    }

    public function queryTps(): array
    {
        $sql = 'show global status where variable_name in ("Com_insert", "Com_delete", "Com_update", "Uptime")';

        $sth = $this->pdo->prepare($sql);
        $sth->execute();
        $rows = $sth->fetchAll();

        return [
            'single_line' => true,
            'caption' => '平均每秒处理事务的数量',
            'head' => '',
            'body' => [
                'Variable_name' => 'Tps',
                'Value' => sprintf('%.2f', ($rows[0]['Value'] + $rows[1]['Value'] + $rows[2]['Value']) / $rows[3]['Value']),
            ],
            'foot' => '统计对象包括增、删、改等写操作',
        ];
    }

    public function queryCacheHits()
    {
        $sql = 'show global status where variable_name in ("Innodb_buffer_pool_read_requests", "Innodb_buffer_pool_reads")';

        $sth = $this->pdo->prepare($sql);
        $sth->execute();
        $rows = $sth->fetchAll();

        return [
            'single_line' => true,
            'caption' => '缓存命中率',
            'head' => '',
            'body' => [
                'Variable_name' => 'Cache_hits',
                'Value' => sprintf("%.2f", (($rows[0]['Value'] - $rows[1]['Value']) / $rows[0]['Value'])),
            ],
            'foot' => '如果缓存命中率低于95%，建议调大"innodb_buffer_pool_size"',
        ];
    }
}
