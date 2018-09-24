<?php

namespace App\HttpController\Admin\Api\Events;

use EasySwoole\Core\Http\AbstractInterface\Controller;
use App\Util\MysqlPool2;
use EasySwoole\Core\Component\Pool\PoolManager;
use EasySwoole\Core\Component\Logger;

class Index extends \App\HttpController\BaseController
{
    public function index()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }

        $events = $this->get_events(function ($event) { return $event; });

        Logger::getInstance()->console(var_export($events, true));
        
        $this->response()->write(json_encode($events, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function new()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }

        $content = $this->request()->getBody()->__toString();
        $raw_array = json_decode($content, true);

        $title = $raw_array['title'];
        $public = $raw_array['public'] ? 1 : 0;
        $price = $raw_array['price'];
    
        $event_id = null;
    
        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();

        $db->begin();
        try {
            $db->rawQuery('INSERT INTO events (title, public_fg, closed_fg, price) VALUES (?, ?, 0, ?)', [$title, $public, $price]);
            $event_id = $this->lastInsertId($db);
            $db->commit();
        } catch (\Exception $e) {
            $db->rollback();
        }
    
        $event = $this->get_event($db, $event_id);

        $pool->freeObj($db);
    
        $this->response()->write(json_encode($event, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function detail()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }
        $event_id = $this->request()->getQueryParam('id');

        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();

        $event = $this->get_event($db, $event_id);
        if (empty($event)) {
            $pool->freeObj($db);
            $this->res_error('not_found', 404);
            return;
        }
    
        $this->response()->write(json_encode($event, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function detailSales()
    {
        $event_id = $this->request()->getQueryParam('id');

        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();
        $event = $this->get_event($db, $event_id);
    
        $reports = [];
    
        $reservations = $this->select_all($db, 'SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.event_id = ? ORDER BY reserved_at ASC FOR UPDATE', [$event['id']]);
        $pool->freeObj($db);
        foreach ($reservations as $reservation) {
            $report = [
                'reservation_id' => $reservation['id'],
                'event_id' => $reservation['event_id'],
                'rank' => $reservation['sheet_rank'],
                'num' => $reservation['sheet_num'],
                'user_id' => $reservation['user_id'],
                'sold_at' => (new \DateTime("{$reservation['reserved_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
                'canceled_at' => $reservation['canceled_at'] ? (new \DateTime("{$reservation['canceled_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
                'price' => $reservation['event_price'] + $reservation['sheet_price'],
            ];
    
            array_push($reports, $report);
        }
    
        return $this->render_report_csv($reports);
    }

    public function sales()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }

        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();

        $reports = [];
        $reservations = $this->select_all($db, 'SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.id AS event_id, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id ORDER BY reserved_at ASC FOR UPDATE');
        $pool->freeObj($db);

        foreach ($reservations as $reservation) {
            $report = [
                'reservation_id' => $reservation['id'],
                'event_id' => $reservation['event_id'],
                'rank' => $reservation['sheet_rank'],
                'num' => $reservation['sheet_num'],
                'user_id' => $reservation['user_id'],
                'sold_at' => (new \DateTime("{$reservation['reserved_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
                'canceled_at' => $reservation['canceled_at'] ? (new \DateTime("{$reservation['canceled_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
                'price' => $reservation['event_price'] + $reservation['sheet_price'],
            ];
    
            array_push($reports, $report);
        }
    
        return $this->render_report_csv($reports);
    }
}