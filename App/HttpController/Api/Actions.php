<?php

namespace App\HttpController\Api;

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
        $user = $this->select_row($db, 'SELECT * FROM users WHERE login_name = ?', [$login_name]);
        $pass_hash = $this->select_one($db, 'SELECT SHA2(?, 256) AS pass_hash', [$password]);

        if (!$user || $pass_hash['pass_hash'] != $user['pass_hash']) {
            $pool->freeObj($db);
            return $this->res_error('authentication_failed', 401);
        }
        $pool->freeObj($db);

        $this->session()->set('user_id', $user['id']);
        $user = $this->get_login_user();
    
        $this->response()->write(json_encode($user, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function logout()
    {
        if (!$this->loginRequired()) {
            return;
        }

        $this->session()->delete('user_id');
        $this->session()->destroy();

        $this->response()->withStatus(204);
        $this->response()->end();
    }
}