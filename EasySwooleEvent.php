<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/1/9
 * Time: 下午1:04
 */

namespace EasySwoole;

use \EasySwoole\Core\AbstractInterface\EventInterface;
use \EasySwoole\Core\Swoole\ServerManager;
use \EasySwoole\Core\Swoole\EventRegister;
use \EasySwoole\Core\Http\Request;
use \EasySwoole\Core\Http\Response;
use duncan3dc\Laravel\BladeInstance;
use EasySwoole\Config;
use EasySwoole\Core\Component\SysConst;
use EasySwoole\Core\Component\Di;

Class EasySwooleEvent implements EventInterface {

    // protected $engine;

    public static function frameInitialize(): void
    {
        // TODO: Implement frameInitialize() method.
        date_default_timezone_set('Asia/Tokyo');
        Di::getInstance()->set(SysConst::HTTP_CONTROLLER_MAX_DEPTH, 5);
        // $tempPath   = Config::getInstance()->getConf('TEMP_DIR');    # 临时文件目录
        // $engine = new BladeInstance(EASYSWOOLE_ROOT . '/Views', "{$tempPath}/templates_c");
    }

    public static function mainServerCreate(ServerManager $server, EventRegister $register): void
    {
        // TODO: Implement mainServerCreate() method.
    }

    public static function onRequest(Request $request, Response $response): void
    {
        // TODO: Implement onRequest() method.
        // $reponse->engine = EasySwooleEvent::engine;
    }

    public static function afterAction(Request $request, Response $response): void
    {
        // TODO: Implement afterAction() method.
    }
}