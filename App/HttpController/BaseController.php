<?php

namespace App\HttpController;

use EasySwoole\Core\Http\AbstractInterface\Controller;
use EasySwoole\Core\Http\Request;
use EasySwoole\Core\Http\Response;
use EasySwoole\Config;
use App\Util\MysqlPool2;
use EasySwoole\Core\Component\Pool\PoolManager;
use EasySwoole\Core\Component\Logger;

abstract class BaseController extends Controller
{
    protected $engine = null;
    protected $viewParam = [];

    public function __construct(string $actionName, Request $request, Response $response)
    {
        $tempPath   = Config::getInstance()->getConf('TEMP_DIR');
        $loader = new \Twig_Loader_Filesystem(EASYSWOOLE_ROOT .'/Views');
        $this->engine = new \Twig_Environment($loader);

        $function = new \Twig_SimpleFunction('base_url', function () use ($request) {
            $headers = $request->getHeaders();
            return  $headers['x-forwarded-proto'][0] . '://' . $headers['host'][0];
        });
        $this->engine->addFunction($function);

        parent::__construct($actionName, $request, $response);
    }

    public function onRequest($action):?bool
    {
        if (!$this->session()->isStart()) {
            $ret = $this->session()->sessionStart();
        }
        return true;
    }
    public function afterAction($actionName):void
    {
        if ($this->session()->isStart()) {
            $this->session()->close();
        }
    }

    protected function render(string $view, array $params = [])
    {
        $params = array_merge($params, $this->viewParam);

        $this->response()->write($this->engine->render($view, $params));
        $this->response()->end();

        return $this->response();
    }

    private function res_error(string $error = 'unknown', int $status = 500): Response
    {
        $this->response()->withStatus($status)
            ->withHeader('Content-type', 'application/json');
        $this->response()->write(json_encode(['error' => $error]));
        $this->response()->end();
        return $this->response();
    }


    protected function select_one($db, string $query, array $params = [])
    {
        $ret = $db->rawQuery($query, $params);

        if ($ret) {
            $ret = $ret[0];
        }
        return $ret;
    }

    protected function select_row($db, string $query, array $params = [])
    {
        $ret = $db->rawQuery($query, $params);
        if ($ret) {
            $ret = $ret[0];
        }
        return $ret;
    }
    protected function select_all($db, string $query, array $params = []): array
    {
        $ret = $db->rawQuery($query, $params);
        return $ret;
    }

    protected function lastInsertId($db)
    {
        $ret = $db->rawQuery('SELECT LAST_INSERT_ID() AS `id`');
        return $ret[0]['id'];
    }


    protected function get_login_user()
    {
        $user_id = $this->session()->get('user_id');
        if (null === $user_id) {
            return false;
        }
        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();
        $user = $this->select_row($db, 'SELECT id, nickname FROM users WHERE id = ?', [$user_id]);
        $pool->freeObj($db);

        if ($user) {
            $user['id'] = (int) $user['id'];
        }
        return $user;
    }

    protected function get_login_administrator()
    {
        $administrator_id = $this->session()->get('administrator_id');
        if (null === $administrator_id) {
            return false;
        }
        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();
    
        $administrator = $this->select_row($db, 'SELECT id, nickname FROM administrators WHERE id = ?', [$administrator_id]);
        $administrator['id'] = (int) $administrator['id'];

        $pool->freeObj($db);
        return $administrator;
    }

    protected function loginRequired()
    {
        $user = $this->get_login_user();
        if (!$user) {
            $this->res_error('login_required', 401);
            return false;
        }
        return true;
    }

    protected function fillinUser()
    {
        $user = $this->get_login_user();
        if ($user) {
            $this->viewParam = ['user' => $user];
        }
        return true;
    }

    protected function adminLoginRequired()
    {
        $administrator = $this->get_login_administrator();
        if (!$administrator) {
            $this->res_error('admin_login_required', 401);
            return false;
        }
        return true;
    }
    
    protected function fillinAdministrator()
    {
        $administrator = $this->get_login_administrator();
        if ($administrator) {
            $this->viewParam = ['administrator' => $administrator];
        }
        return true;
    }

    protected function sanitize_event(array $event): array
    {
        unset($event['price']);
        unset($event['public']);
        unset($event['closed']);
    
        return $event;
    }

    protected function get_events(?callable $where = null): array
    {
        if (null === $where) {
            $where = function (array $event) {
                return $event['public_fg'];
            };
        }
    
        $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
        $db = $pool->getObj();
        $db->begin();
    
        $events = [];
        $event_ids = array_map(function (array $event) {
            return $event['id'];
        }, array_filter($this->select_all($db, 'SELECT * FROM events ORDER BY id ASC'), $where));
    
        foreach ($event_ids as $event_id) {
            $event = $this->get_event($db, $event_id);
    
            foreach (array_keys($event['sheets']) as $rank) {
                unset($event['sheets'][$rank]['detail']);
            }
    
            array_push($events, $event);
        }
    
        $db->commit();
        $pool->freeObj($db);
    
        return $events;
    }

    protected function get_event($db, int $event_id, ?int $login_user_id = null): array
    {
        $event = $this->select_row($db, 'SELECT * FROM events WHERE id = ?', [$event_id]);
    
        if (!$event) {
            return [];
        }
    
        $event['id'] = (int) $event['id'];
    
        // zero fill
        $event['total'] = 0;
        $event['remains'] = 0;
    
        foreach (['S', 'A', 'B', 'C'] as $rank) {
            $event['sheets'][$rank]['total'] = 0;
            $event['sheets'][$rank]['remains'] = 0;
        }
    
        $sheets = $this->select_all($db, 'SELECT * FROM sheets ORDER BY `rank`, num');
        foreach ($sheets as $sheet) {
            $event['sheets'][$sheet['rank']]['price'] = $event['sheets'][$sheet['rank']]['price'] ?? $event['price'] + $sheet['price'];
    
            ++$event['total'];
            ++$event['sheets'][$sheet['rank']]['total'];
    
            $reservation = $this->select_row($db, 'SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled_at IS NULL GROUP BY event_id, sheet_id HAVING reserved_at = MIN(reserved_at)', [$event['id'], $sheet['id']]);
            if ($reservation) {
                $sheet['mine'] = $login_user_id && $reservation['user_id'] == $login_user_id;
                $sheet['reserved'] = true;
                $sheet['reserved_at'] = (new \DateTime("{$reservation['reserved_at']}", new \DateTimeZone('UTC')))->getTimestamp();
            } else {
                ++$event['remains'];
                ++$event['sheets'][$sheet['rank']]['remains'];
            }
    
            $sheet['num'] = $sheet['num'];
            $rank = $sheet['rank'];
            unset($sheet['id']);
            unset($sheet['price']);
            unset($sheet['rank']);
    
            if (false === isset($event['sheets'][$rank]['detail'])) {
                $event['sheets'][$rank]['detail'] = [];
            }
    
            array_push($event['sheets'][$rank]['detail'], $sheet);
        }
    
        $event['public'] = $event['public_fg'] ? true : false;
        $event['closed'] = $event['closed_fg'] ? true : false;
    
        unset($event['public_fg']);
        unset($event['closed_fg']);
    
        return $event;
    }

    protected function validate_rank($db, $rank)
    {
        $ret = $this->select_one($db, 'SELECT COUNT(*) AS cnt FROM sheets WHERE `rank` = ?', [$rank]);

        return $ret['cnt'];
    }

    protected function render_report_csv(array $reports): Response
    {
        usort($reports, function ($a, $b) { return $a['sold_at'] > $b['sold_at']; });

        $keys = ['reservation_id', 'event_id', 'rank', 'num', 'price', 'user_id', 'sold_at', 'canceled_at'];
        $body = implode(',', $keys);
        $body .= "\n";
        foreach ($reports as $report) {
            $data = [];
            foreach ($keys as $key) {
                $data[] = $report[$key];
            }
            $body .= implode(',', $data);
            $body .= "\n";
        }

        $this->response()->withHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->response()->withHeader('Content-Disposition', 'attachment; filename="report.csv"');
        $this->response()->write($body);
        $this->response()->end();
        return $this->response();
    }
}