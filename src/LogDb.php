<?php
// +----------------------------------------------------------------------
// | Author: kitty.cheng <450038893@qq.com>
// +----------------------------------------------------------------------

namespace think\log\driver;

use think\App;
use think\Db;
use think\facade\Config;

/**
 * thinkphp5日志扩展，可以写入到数据库
 * @package think\log\driver
 */
class LogDb
{
    protected $config = [
        'time_format' => 'c',
        'single'      => false,
        'file_size'   => 2097152,
        'path'        => '',
        'apart_level' => [],
        'max_files'   => 0,
        'json'        => false,
    ];

    protected $app;

    // 实例化并传入参数
    public function __construct(App $app, $config = [])
    {
        $this->app = $app;

        if (is_array($config)) {
            $this->config = array_merge($this->config, $config);
        }

        if (empty($this->config['path'])) {
            $this->config['path'] = $this->app->getRuntimePath() . 'log' . DIRECTORY_SEPARATOR;
        } elseif (substr($this->config['path'], -1) != DIRECTORY_SEPARATOR) {
            $this->config['path'] .= DIRECTORY_SEPARATOR;
        }
    }

    /**
     * 日志写入接口
     * @access public
     * @param  array    $log    日志信息
     * @param  bool     $append 是否追加请求信息
     * @return bool
     */
    public function save(array $log = [], $append = false)
    {
        $this->writeDb($log);
        $destination = $this->getMasterLogFile();

        $path = dirname($destination);
        !is_dir($path) && mkdir($path, 0755, true);

        $info = [];

        foreach ($log as $type => $val) {

            foreach ($val as $msg) {
                if (!is_string($msg)) {
                    $msg = var_export($msg, true);
                }

                $info[$type][] = $this->config['json'] ? $msg : '[ ' . $type . ' ] ' . $msg;
            }

            if (!$this->config['json'] && (true === $this->config['apart_level'] || in_array($type, $this->config['apart_level']))) {
                // 独立记录的日志级别
                $filename = $this->getApartLevelFile($path, $type);

                $this->write($info[$type], $filename, true, $append);

                unset($info[$type]);
            }
        }

        if ($info) {
            return $this->write($info, $destination, false, $append);
        }

        return true;
    }

    /**
     * 日志写入
     * @access protected
     * @param  array     $message 日志信息
     * @param  string    $destination 日志文件
     * @param  bool      $apart 是否独立文件写入
     * @param  bool      $append 是否追加请求信息
     * @return bool
     */
    protected function write($message, $destination, $apart = false, $append = false)
    {
        // 检测日志文件大小，超过配置大小则备份日志文件重新生成
        $this->checkLogSize($destination);

        // 日志信息封装
        $info['timestamp'] = date($this->config['time_format']);

        foreach ($message as $type => $msg) {
            $msg = is_array($msg) ? implode(PHP_EOL, $msg) : $msg;
            if (PHP_SAPI == 'cli') {
                $info['msg']  = $msg;
                $info['type'] = $type;
            } else {
                $info[$type] = $msg;
            }
        }

        if (PHP_SAPI == 'cli') {
            $message = $this->parseCliLog($info);
        } else {
            // 添加调试日志
            $this->getDebugLog($info, $append, $apart);

            $message = $this->parseLog($info);
        }

        return error_log($message, 3, $destination);
    }

    protected function writeDb($message)
    {
        if (PHP_SAPI == 'cli') return '';
        if(!isset($message['sql'])) return '';

        $log_db_connect = Config::get('log.log_db_connect','default');
        if(!$db_connect = Config::get('database.'.$log_db_connect)) return '';

        $module = $this->app->request->module();
        $controller = $this->app->request->controller();
        $action = $this->app->request->action();

        //忽略操作
        if(in_array($module.'/'.$controller.'/'.$action,Config::get('log.log_action_filters',[]))){
            return '';
        }

        $sql = [];
        $runtime_max = 0;
        foreach($message['sql'] as $k => $v){
            $db_k = 0;
            if(0 === strpos($v,'[ DB ]')) {
                $db_k = $k;
            }
            if(0 === strpos($v,'[ SQL ]')){
                if(0 === strpos($v,'[ SQL ] SHOW COLUMNS')){
                    continue;
                }
                $runtime = floatval(substr($v,strrpos($v,'RunTime:')+8,-3));
                if($runtime >= Config::get('log.slow_sql_time',0.5)) {
                    $sql[] = [
                        'db'     => substr($message['sql'][$db_k],37),
                        'sql'     => strstr(substr($v, 8), ' [', true),
                        'runtime' => $runtime,
                    ];
                    $runtime_max < $runtime && $runtime_max = $runtime;
                }

            }
        }

        if(!$sql) return '';

        $time = time();

        $info = [
            'ip'     => $this->app['request']->ip(),
            'method' => $this->app['request']->method(),
            'host'   => $this->app['request']->host(),
            'uri'    => $this->app['request']->url(),
            'module' => $module,
            'controller' => $controller,
            'action' => $action,
            'create_time' => $time,
            'create_date' => date('Y-m-d H:i:s'),
            'runtime' => $runtime_max,
        ];
        if($db_connect['type'] == '\think\mongo\Connection') {
            $info['sql_list'] = $sql;
            $info['sql_source'] = $message['sql'];
        }else{
            $info['sql_list'] = json_encode($sql);
            $info['sql_source'] = json_encode($message['sql']);
        }

        $log_table = Config::get('log.log_table','slow_sql');

        $msg = 'success';
        if($log_db_connect === 'default'){
            try{
                Db::name($log_table)->insert($info);
            }catch(Exception $e){
                $msg = $e;
            }
        }else{
            try{
                Db::connect($log_db_connect)->name($log_table)->insert($info);
            }catch(Exception $e){
                $msg = $e;
            }
        }
        
        return $msg;
    }

    /**
     * 获取主日志文件名
     * @access public
     * @return string
     */
    protected function getMasterLogFile()
    {
        if ($this->config['max_files']) {
            $files = glob($this->config['path'] . '*.log');

            try {
                if (count($files) > $this->config['max_files']) {
                    unlink($files[0]);
                }
            } catch (\Exception $e) {
            }
        }

        $cli = PHP_SAPI == 'cli' ? '_cli' : '';

        if ($this->config['single']) {
            $name = is_string($this->config['single']) ? $this->config['single'] : 'single';

            $destination = $this->config['path'] . $name . $cli . '.log';
        } else {
            if ($this->config['max_files']) {
                $filename = date('Ymd') . $cli . '.log';
            } else {
                $filename = date('Ym') . DIRECTORY_SEPARATOR . date('d') . $cli . '.log';
            }

            $destination = $this->config['path'] . $filename;
        }

        return $destination;
    }

    /**
     * 获取独立日志文件名
     * @access public
     * @param  string $path 日志目录
     * @param  string $type 日志类型
     * @return string
     */
    protected function getApartLevelFile($path, $type)
    {
        $cli = PHP_SAPI == 'cli' ? '_cli' : '';

        if ($this->config['single']) {
            $name = is_string($this->config['single']) ? $this->config['single'] : 'single';
        } elseif ($this->config['max_files']) {
            $name = date('Ymd');
        } else {
            $name = date('d');
        }

        return $path . DIRECTORY_SEPARATOR . $name . '_' . $type . $cli . '.log';
    }

    /**
     * 检查日志文件大小并自动生成备份文件
     * @access protected
     * @param  string    $destination 日志文件
     * @return void
     */
    protected function checkLogSize($destination)
    {
        if (is_file($destination) && floor($this->config['file_size']) <= filesize($destination)) {
            try {
                rename($destination, dirname($destination) . DIRECTORY_SEPARATOR . time() . '-' . basename($destination));
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * CLI日志解析
     * @access protected
     * @param  array     $info 日志信息
     * @return string
     */
    protected function parseCliLog($info)
    {
        if ($this->config['json']) {
            $message = json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            $now = $info['timestamp'];
            unset($info['timestamp']);

            $message = implode(PHP_EOL, $info);

            $message = "[{$now}]" . $message . PHP_EOL;
        }

        return $message;
    }

    /**
     * 解析日志
     * @access protected
     * @param  array     $info 日志信息
     * @return string
     */
    protected function parseLog($info)
    {
        $requestInfo = [
            'ip'     => $this->app['request']->ip(),
            'method' => $this->app['request']->method(),
            'host'   => $this->app['request']->host(),
            'uri'    => $this->app['request']->url(),
        ];

        if ($this->config['json']) {
            $info = $requestInfo + $info;
            return json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }

        array_unshift($info, "---------------------------------------------------------------" . PHP_EOL . "\r\n[{$info['timestamp']}] {$requestInfo['ip']} {$requestInfo['method']} {$requestInfo['host']}{$requestInfo['uri']}");
        unset($info['timestamp']);

        return implode(PHP_EOL, $info) . PHP_EOL;
    }

    protected function getDebugLog(&$info, $append, $apart)
    {
        if ($this->app->isDebug() && $append) {

            if ($this->config['json']) {
                // 获取基本信息
                $runtime = round(microtime(true) - $this->app->getBeginTime(), 10);
                $reqs    = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';

                $memory_use = number_format((memory_get_usage() - $this->app->getBeginMem()) / 1024, 2);

                $info = [
                    'runtime' => number_format($runtime, 6) . 's',
                    'reqs'    => $reqs . 'req/s',
                    'memory'  => $memory_use . 'kb',
                    'file'    => count(get_included_files()),
                ] + $info;

            } elseif (!$apart) {
                // 增加额外的调试信息
                $runtime = round(microtime(true) - $this->app->getBeginTime(), 10);
                $reqs    = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';

                $memory_use = number_format((memory_get_usage() - $this->app->getBeginMem()) / 1024, 2);

                $time_str   = '[运行时间：' . number_format($runtime, 6) . 's] [吞吐率：' . $reqs . 'req/s]';
                $memory_str = ' [内存消耗：' . $memory_use . 'kb]';
                $file_load  = ' [文件加载：' . count(get_included_files()) . ']';

                array_unshift($info, $time_str . $memory_str . $file_load);
            }
        }
    }
}
