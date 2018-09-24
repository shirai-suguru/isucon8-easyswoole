<?php

namespace App\HttpController\Admin\Api;

use EasySwoole\Core\Http\AbstractInterface\Controller;
use App\Util\MysqlPool2;
use EasySwoole\Core\Component\Pool\PoolManager;
use EasySwoole\Core\Component\Logger;

class Actions extends \App\HttpController\BaseController
{
    public function index()
    {
    }

    public function login()
    {
        $content = $this->request()->getBody()->__toString();
        $raw_array = json_decode($content, true);

        $login_name = $raw_array['login_name'];
        $password = $raw_array['password'];
    
        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();

        $administrator = $this->select_row($db, 'SELECT * FROM administrators WHERE login_name = ?', [$login_name]);
        $pass_hash = $this->select_one($db, 'SELECT SHA2(?, 256) AS pass_hash', [$password]);
        $pool->freeObj($db);

        if (!$administrator || $pass_hash['pass_hash'] != $administrator['pass_hash']) {
            $this->res_error('authentication_failed', 401);
            return;
        }
    
        $this->session()->set('administrator_id', $administrator['id']);
    
        $this->response()->write(json_encode($administrator, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }
    public function logout()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }
        $this->session()->delete('administrator_id');
        $this->session()->destroy();

        $this->response()->withStatus(204);
        $this->response()->end();
}
}
