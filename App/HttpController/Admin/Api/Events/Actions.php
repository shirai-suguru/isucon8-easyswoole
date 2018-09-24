<?php

namespace App\HttpController\Admin\Api\Events;

use EasySwoole\Core\Http\AbstractInterface\Controller;
use App\Util\MysqlPool2;
use EasySwoole\Core\Component\Pool\PoolManager;
use EasySwoole\Core\Component\Logger;

class Actions extends \App\HttpController\BaseController
{
    public function index()
    {
    }

    public function edit()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }
        $content = $this->request()->getBody()->__toString();
        $raw_array = json_decode($content, true);

        $event_id = $this->request()->getQueryParam('id');
        $public = $raw_array['public'] ? 1 : 0;
        $closed = $raw_array['closed'] ? 1 : 0;
    
        if ($closed) {
            $public = 0;
        }

        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();
    
        $event = $this->get_event($db, $event_id);
        if (empty($event)) {
            $pool->freeObj($db);
            return $this->res_error('not_found', 404);
        }
    
        if ($event['closed']) {
            $pool->freeObj($db);
            return $this->res_error('cannot_edit_closed_event', 400);
        } elseif ($event['public'] && $closed) {
            $pool->freeObj($db);
            return $this->res_error('cannot_close_public_event', 400);
        }
    
        $db->begin();
        try {
            $db->rawQuery('UPDATE events SET public_fg = ?, closed_fg = ? WHERE id = ?', [$public, $closed, $event['id']]);
            $db->commit();
        } catch (\Exception $e) {
            $db->rollback();
        }
    
        $event = $this->get_event($db, $event_id);

        $pool->freeObj($db);
    
        $this->response()->write(json_encode($event, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }
}