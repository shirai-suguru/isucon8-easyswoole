<?php

namespace App\HttpController\Api;

use EasySwoole\Core\Http\AbstractInterface\Controller;
use EasySwoole\Core\Component\Logger;
use App\Util\MysqlPool2;
use EasySwoole\Core\Component\Pool\PoolManager;

class Users extends \App\HttpController\BaseController
{
    public function index()
    {
        if (!$this->loginRequired()) {
            return;
        }
        $id = $this->request()->getQueryParam('id');

        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();

        $user = $this->select_row($db, 'SELECT id, nickname FROM users WHERE id = ?', [$id]);
        $user['id'] = (int) $user['id'];
        if (!$user || $user['id'] !== $this->get_login_user()['id']) {
            return $this->res_error('forbidden', 403);
        }

        $recent_reservations = [];

        $rows = $this->select_all($db, 'SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id WHERE r.user_id = ? ORDER BY IFNULL(r.canceled_at, r.reserved_at) DESC LIMIT 5', [$user['id']]);
        foreach ($rows as $row) {
            $event = $this->get_event($db, $row['event_id']);
            $price = $event['sheets'][$row['sheet_rank']]['price'];
            unset($event['sheets']);
            unset($event['total']);
            unset($event['remains']);

            $reservation = [
                'id' => $row['id'],
                'event' => $event,
                'sheet_rank' => $row['sheet_rank'],
                'sheet_num' => $row['sheet_num'],
                'price' => $price,
                'reserved_at' => (new \DateTime("{$row['reserved_at']}", new \DateTimeZone('UTC')))->getTimestamp(),
            ];

            if ($row['canceled_at']) {
                $reservation['canceled_at'] = (new \DateTime("{$row['canceled_at']}", new \DateTimeZone('UTC')))->getTimestamp();
            }

            array_push($recent_reservations, $reservation);
        }
    
        $user['recent_reservations'] = $recent_reservations;
        $user['total_price'] = $this->select_one($db, 'SELECT IFNULL(SUM(e.price + s.price), 0) FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND r.canceled_at IS NULL', [$user['id']]);

        $recent_events = [];

        $rows = $this->select_all($db, 'SELECT event_id FROM reservations WHERE user_id = ? GROUP BY event_id ORDER BY MAX(IFNULL(canceled_at, reserved_at)) DESC LIMIT 5', [$user['id']]);
        foreach ($rows as $row) {
            $event = $this->get_event($db, $row['event_id']);
            foreach (array_keys($event['sheets']) as $rank) {
                unset($event['sheets'][$rank]['detail']);
            }
            array_push($recent_events, $event);
        }
        $pool->freeObj($db);

        $user['recent_events'] = $recent_events;

        $this->response()->write(json_encode($user, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }
}