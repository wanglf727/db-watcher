<?php

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;

class Server
{
    const ROOT = __DIR__ ;
    const WEBROOT = __DIR__ . '/web';
    protected $server;
    protected $appOption;
    protected $dbOption;

    public function __construct()
    {
        $config = parse_ini_file(self::ROOT . '/.env');
        $this->appOption = [
            'app_host' => $config['APP_HOST'] ?? '0.0.0.0',
            'app_port' => $config['APP_PORT'] ?? '9501',
        ];
        $this->dbOption = [
            'db_host' => $config['DB_HOST'] ?? '127.0.0.1',
            'db_name' => $config['DB_NAME'] ?? 'localhost',
            'db_user' => $config['DB_USER'] ?? 'root',
            'db_pass' => $config['DB_PASS'] ?? 'root',
            'pool_size' => $config['POOL_SIZE'] ?? 8,
        ];
    }

    public function onWorkerStart(WsServer $ws, int $workerId)
    {
        if ($workerId == 0) {
            $this->server->tick(5000, [$this, 'onTick']);
        }

        $this->server->pdoPool = new PdoPool($this->dbOption);
    }

    public function onTick()
    {
        $this->publish();
    }

    protected function publish(int $fd = 0)
    {
        $pdo = $this->server->pdoPool->get();
        $watcher = new MysqlWatcher($pdo);
        $result = json_encode($watcher->collect());
        $this->server->pdoPool->put($pdo);

        if ($fd) {
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $result);
            }
        } else {
            foreach ($this->server->connections as $fd) {
                var_dump('?'.$fd);
                if ($this->server->isEstablished($fd)) {
                    var_dump('='.$fd);
                    $this->server->push($fd, $result);
                }
            }
        }
    }

    public function onRequest(Request $request, Response $response)
    {
        $path = $request->server['request_uri'];

        if ($path == "/") {
            $response->sendfile(self::WEBROOT . '/index.html');
        } else {
            $file = realpath(self::WEBROOT . $path);
            if (false === $file) {
                $response->status(404);
                $response->end('<h3>404 Not Found</h3>');
                return;
            }
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $response->end($this->getPhpFile($file));
                return;
            }
            if (isset($request->header['if-modified-since']) && !empty($if_modified_since = $request->header['if-modified-since'])) {
                $info = stat($file);
                $modified_time = $info ?: date('D, d M Y H:i:s', $info['mtime']) . ' ' . date_default_timezone_get();
                if ($modified_time === $if_modified_since) {
                    $response->status(304);
                    $response->end();
                    return;
                }
            }
            $response->sendfile($file);
        }
    }

    protected function getPhpFile($file)
    {
        ob_start();
        try {
            include $file;
        } catch (Exception $e) {
            echo $e;
        }
        return ob_get_clean();
    }

    public function onOpen(WsServer $ws, Request $request)
    {
        $this->publish($request->fd);
    }

    public function onMessage(WsServer $ws, Frame $frame)
    {

    }

    public function onClose(WsServer $server, int $fd, int $reactorId)
    {
        echo "client $fd closed" . PHP_EOL;
    }

    public function run()
    {
        $server = new WsServer($this->appOption['app_host'], $this->appOption['app_port'], SWOOLE_PROCESS);
        $server->set([
            'worker_num' => swoole_cpu_num(),
            'hook_flags' => SWOOLE_HOOK_ALL,
            'heartbeat_check_interval' => 10,
        ]);
        $server->on('workerstart', [$this, 'onWorkerStart']);
        $server->on('request', [$this, 'onRequest']);
        $server->on('open', [$this, 'onOpen']);
        $server->on('message', [$this, 'onMessage']);
        $server->on('close', [$this, "onClose"]);
        $this->server = $server;
        $server->start();
    }
}