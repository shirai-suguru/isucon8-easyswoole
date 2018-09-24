<?php

namespace App\HttpController\Api\Events;

use EasySwoole\Core\Http\AbstractInterface\Controller;
use App\Util\MysqlPool2;
use EasySwoole\Core\Component\Pool\PoolManager;
use EasySwoole\Core\Component\Logger;

class Actions extends \App\HttpController\BaseController
{
    public function index()
    {
    }

    public function reserve()
    {
        if (!$this->loginRequired()) {
            return;
        }

        $content = $this->request()->getBody()->__toString();
        $raw_array = json_decode($content, true);

        $event_id = $this->request()->getQueryParam('id');
        $rank = $raw_array['sheet_rank'];
    
        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();

        $user = $this->get_login_user();
        $event = $this->get_event($db, $event_id, $user['id']);
    
        if (empty($event) || !$event['public']) {
            $pool->freeObj($db);
            $this->res_error('invalid_event', 404);
            return;
        }
    
        if (!$this->validate_rank($db, $rank)) {
            $pool->freeObj($db);
            $this->res_error('invalid_rank', 400);
            return;
        }

        $sheet = null;
        $reservation_id = null;
        while (true) {
            $sheet = $this->select_row($db, 'SELECT * FROM sheets WHERE id NOT IN (SELECT sheet_id FROM reservations WHERE event_id = ? AND canceled_at IS NULL FOR UPDATE) AND `rank` = ? ORDER BY RAND() LIMIT 1', [$event['id'], $rank]);
            if (!$sheet) {
                $pool->freeObj($db);
                $this->res_error('sold_out', 409);
                return;
            }
    
            $db->begin();
            try {
                $db->rawQuery('INSERT INTO reservations (event_id, sheet_id, user_id, reserved_at) VALUES (?, ?, ?, ?)', [$event['id'], $sheet['id'], $user['id'], (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]);
                $reservation_id = (int) $this->lastInsertId($db);
    
                $db->commit();
            } catch (\Exception $e) {
                $db->rollback();
                continue;
            }
            $pool->freeObj($db);
            break;
        }
        $this->response()->withStatus(202);
        $this->response()->write(json_encode([
            'id' => $reservation_id,
            'sheet_rank' => $rank,
            'sheet_num' => $sheet['num'],
        ], JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function reservation()
    {
        if (!$this->loginRequired()) {
            return;
        }

        $event_id = $this->request()->getQueryParam('id');
        $rank = $this->request()->getQueryParam('ranks');
        $num = $this->request()->getQueryParam('num');
    
        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();

        $user = $this->get_login_user();
        $event = $this->get_event($db, $event_id, $user['id']);

        if (empty($event) || !$event['public']) {
            $pool->freeObj($db);
            $this->res_error('invalid_event', 404);
            return;
        }

        if (!$this->validate_rank($db, $rank)) {
            $pool->freeObj($db);
            $this->res_error('invalid_rank', 404);
            return;
        }

        $sheet = $this->select_row($db, 'SELECT * FROM sheets WHERE `rank` = ? AND num = ?', [$rank, $num]);
        if (!$sheet) {
            $pool->freeObj($db);
            $this->res_error('invalid_sheet', 404);
            return;
        }

        $db->begin();
        try {
            $reservation = $this->select_row($db, 'SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled_at IS NULL GROUP BY event_id HAVING reserved_at = MIN(reserved_at) FOR UPDATE', [$event['id'], $sheet['id']]);
            if (!$reservation) {
                $db->rollback();
                $pool->freeObj($db);
                $this->res_error('not_reserved', 400);
                return;
            }

            if ($reservation['user_id'] != $user['id']) {
                $db->rollback();
                $pool->freeObj($db);

                $this->res_error('not_permitted', 403);
                return;
            }

            $db->rawQuery('UPDATE reservations SET canceled_at = ? WHERE id = ?', [(new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), $reservation['id']]);
            $db->commit();
            $pool->freeObj($db);
        } catch (\Exception $e) {
            $db->rollback();
            $pool->freeObj($db);

            $this->res_error();
        }

        $this->response()->withStatus(204);
        $this->response()->end();

    }
}