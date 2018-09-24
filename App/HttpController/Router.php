<?php

namespace App\HttpController;

use EasySwoole\Core\Http\Request;
use EasySwoole\Core\Http\Response;
use FastRoute\RouteCollector;
use EasySwoole\Config;
use \PDO;
use EasySwoole\Core\Http\Session\Session;
use App\Util\MysqlPool2;
use EasySwoole\Core\Component\Pool\PoolManager;
use EasySwoole\Core\Component\Logger;

class Router extends \EasySwoole\Core\Http\AbstractInterface\Router
{
    private $session = null;
    private $viewParam = [];

    /**
     *
     * @param string $view
     * @param array  $params
     */
    protected function render(Request $request, string $view, array $params = [])
    {
        $tempPath   = Config::getInstance()->getConf('TEMP_DIR');
        $loader = new \Twig_Loader_Filesystem(EASYSWOOLE_ROOT .'/Views');
        $engine = new \Twig_Environment($loader);

        $function = new \Twig_SimpleFunction('base_url', function () use ($request) {
            $headers = $request->getHeaders();
            return  $headers['x-forwarded-proto'][0] . '://' . $headers['host'][0];
        });
        $engine->addFunction($function);
        
        $params = array_merge($params, $this->viewParam);
        $content = $engine->render($view, $params);
        return $content;
    }

    protected function session(Request $request, Response $response)
    {
        if ($this->session == null) {
            $this->session = new Session($request, $response);
            $this->session->sessionStart();
        }
        return $this->session;
    }
    private function select_one($db, string $query, array $params = [])
    {
        $ret = $db->rawQuery($query, $params);

        if ($ret) {
            $ret = $ret[0];
        }
        return $ret;
    }

    private function select_row($db, string $query, array $params = [])
    {
        $ret = $db->rawQuery($query, $params);
        if ($ret) {
            $ret = $ret[0];
        }
        return $ret;
    }

    private function select_all($db, string $query, array $params = []): array
    {
        $ret = $db->rawQuery($query, $params);
        return $ret;
    }

    private function lastInsertId($db)
    {
        $ret = $db->rawQuery('SELECT LAST_INSERT_ID() AS `id`');
        return $ret[0]['id'];
    }

    private function get_login_user(Request $request, Response $response)
    {
        $session = $this->session($request, $response);
        $user_id = $session->get('user_id');
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
    private function get_login_administrator(Request $request, Response $response)
    {
        $session = $this->session($request, $response);
        $administrator_id = $session->get('administrator_id');
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
    
    private function res_error(Response $response, string $error = 'unknown', int $status = 500): Response
    {
        $response->withStatus($status)
            ->withHeader('Content-type', 'application/json');
        $response->write(json_encode(['error' => $error]));
        $response->end();
        return $response;
    }
    
    private function loginRequired(Request $request, Response $response)
    {
        $user = $this->get_login_user($request, $response);
        if (!$user) {
            $this->res_error($response, 'login_required', 401);
            return false;
        }
        return true;
    }
    private function fillinUser(Request $request, Response $response)
    {
        $user = $this->get_login_user($request, $response);
        if ($user) {
            $this->viewParam = ['user' => $user];
        }
        return $response;
    }
    private function adminLoginRequired(Request $request, Response $response)
    {
        $administrator = $this->get_login_administrator($request, $response);
        if (!$administrator) {
            $this->res_error($response, 'admin_login_required', 401);
            return false;
        }
        return $response;
    }
    
    private function fillinAdministrator(Request $request, Response $response)
    {
        $administrator = $this->get_login_administrator($request, $response);
        if ($administrator) {
            $this->viewParam = ['administrator' => $administrator];
        }
        return $response;
    }
    
    private function sanitize_event(array $event): array
    {
        unset($event['price']);
        unset($event['public']);
        unset($event['closed']);
    
        return $event;
    }
        
    private function get_events(?callable $where = null): array
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

    private function get_event($db, int $event_id, ?int $login_user_id = null): array
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
    
    private function validate_rank($db, $rank)
    {
        $ret = $this->select_one($db, 'SELECT COUNT(*) AS cnt FROM sheets WHERE `rank` = ?', [$rank]);

        return $ret['cnt'];
    }
    
    public function register(RouteCollector $routeCollector)
    {
        date_default_timezone_set('Asia/Tokyo');

        $routeCollector->get('/', function (Request $request, Response $response) {
            $reponse = $this->fillinUser($request, $response);

            $events = array_map(function (array $event) {
                return $this->sanitize_event($event);
            }, $this->get_events());

            $response->write($this->render($request, 'index.twig', [
                'events' => $events
            ]));
            $response->end();
        });

        $routeCollector->get('/initialize', function (Request $request, Response $response) {
            exec('../../db/init.sh');

            $response->withStatus(204);
            $response->end();
        });

        $routeCollector->post('/api/users', function (Request $request, Response $response) {
            $content = $request->getBody()->__toString();
            $raw_array = json_decode($content, true);

            $nickname = $raw_array['nickname'];
            $login_name = $raw_array['login_name'];
            $password = $raw_array['password'];
        
            $user_id = null;

            $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
            $db = $pool->getObj();

            // Logger::getInstance()->console(var_export($db, true));
            $db->begin();

            try {
                $duplicated = $this->select_one($db, 'SELECT * FROM users WHERE login_name = ?', [$login_name]);
                if ($duplicated) {
                    $db->rollback();
                    $pool->freeObj($db);

                    return $this->res_error($response, 'duplicated', 409);
                }
                $ret = $db->rawQuery('INSERT INTO users (login_name, pass_hash, nickname) VALUES (?, SHA2(?, 256), ?)', [$login_name, $password, $nickname]);

                $user_id = $this->lastInsertId($db);
                $db->commit();
                $pool->freeObj($db);
            } catch (\Throwable $throwable) {
                $db->rollback();
                $pool->freeObj($db);

                return $this->res_error($response);
            }

            $response->write(json_encode([
                'id' => $user_id,
                'nickname' => $nickname
            ], JSON_NUMERIC_CHECK));
            $response->withStatus(201);
            $response->end();
        });

        $routeCollector->get('/api/users/{id}', function (Request $request, Response $response) {
            if (!$this->loginRequired($request, $response)) {
                return;
            }

            $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
            $db = $pool->getObj();

            $user = $this->select_row($db, 'SELECT id, nickname FROM users WHERE id = ?', [$id]);
            $user['id'] = (int) $user['id'];
            if (!$user || $user['id'] !== $this->get_login_user($request, $response)['id']) {
                return $this->res_error($response, 'forbidden', 403);
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
            $user['total_price'] = $db->select_one($db, 'SELECT IFNULL(SUM(e.price + s.price), 0) FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND r.canceled_at IS NULL', [$user['id']]);

            $recent_events = [];
    
            $rows = $db->select_all($db, 'SELECT event_id FROM reservations WHERE user_id = ? GROUP BY event_id ORDER BY MAX(IFNULL(canceled_at, reserved_at)) DESC LIMIT 5', [$user['id']]);
            foreach ($rows as $row) {
                $event = $this->get_event($db, $row['event_id']);
                foreach (array_keys($event['sheets']) as $rank) {
                    unset($event['sheets'][$rank]['detail']);
                }
                array_push($recent_events, $event);
            }
            $pool->freeObj($db);

            $user['recent_events'] = $recent_events;

            $response->write(json_encode($user, JSON_NUMERIC_CHECK));
            $response->end();
        });

        $routeCollector->post('/api/actions/login', function (Request $request, Response $response) {
            $content = $request->getBody()->__toString();
            $raw_array = json_decode($content, true);

            $login_name = $raw_array['login_name'];
            $password = $raw_array['password'];
        
            $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
            $db = $pool->getObj();
            $user = $this->select_row($db, 'SELECT * FROM users WHERE login_name = ?', [$login_name]);
            $pass_hash = $this->select_one($db, 'SELECT SHA2(?, 256) AS pass_hash', [$password]);

            if (!$user || $pass_hash['pass_hash'] != $user['pass_hash']) {
                $pool->freeObj($db);
                return $this->res_error($response, 'authentication_failed', 401);
            }
            $pool->freeObj($db);

            $session = $this->session($request, $response);
            $session->set('user_id', $user['id']);
        
            $user = $this->get_login_user($request, $response);
        
            $response->write(json_encode($user, JSON_NUMERIC_CHECK));
            $response->end();
         });

        $routeCollector->post('/api/actions/logout', function (Request $request, Response $response) {
            if (!$this->loginRequired($request, $response)) {
                return;
            }

            $session = $this->session($request, $response);
            $session->delete('user_id');

            $response->withStatus(204);
            $response->end();
        });

        $routeCollector->get('/api/events', function (Request $request, Response $response) {

            $events = array_map(function (array $event) {
                return $this->sanitize_event($event);
            }, $this->get_events());
       
            $response->write(json_encode($events, JSON_NUMERIC_CHECK));
            $response->end();
        });

        $routeCollector->get('/api/events/{id}', function (Request $request, Response $response, $id) {
            $event_id = $id;

            $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
            $db = $pool->getObj();

            $user = $this->get_login_user($request, $response);
            $event = $this->get_event($db, $event_id, $user['id']);

            $pool->freeObj($db);
        
            if (empty($event) || !$event['public']) {
                $this->res_error($response, 'not_found', 404);
                return $response;
            }
            $event = $this->sanitize_event($event);
        
            $response->write(json_encode($event, JSON_NUMERIC_CHECK));
            $response->end();
        });

        $routeCollector->post('/api/events/{id}/actions/reserve', function (Request $request, Response $response, $id) {
            if (!$this->loginRequired($request, $response)) {
                return;
            }

            $content = $request->getBody()->__toString();
            $raw_array = json_decode($content, true);

            $event_id = $id;
            $rank = $raw_array['sheet_rank'];
        
            $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
            $db = $pool->getObj();

            $user = $this->get_login_user($request, $response);
            $event = $this->get_event($db, $event_id, $user['id']);
        
            if (empty($event) || !$event['public']) {
                $pool->freeObj($db);
                $this->res_error($response, 'invalid_event', 404);
                return;
            }
        
            if (!$this->validate_rank($db, $rank)) {
                $pool->freeObj($db);
                $this->res_error($response, 'invalid_rank', 400);
                return;
            }

            $sheet = null;
            $reservation_id = null;
            while (true) {
                $sheet = $this->select_row($db, 'SELECT * FROM sheets WHERE id NOT IN (SELECT sheet_id FROM reservations WHERE event_id = ? AND canceled_at IS NULL FOR UPDATE) AND `rank` = ? ORDER BY RAND() LIMIT 1', [$event['id'], $rank]);
                if (!$sheet) {
                    $pool->freeObj($db);
                    $this->res_error($response, 'sold_out', 409);
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
            $response->withStatus(202);
            $response->write(json_encode([
                'id' => $reservation_id,
                'sheet_rank' => $rank,
                'sheet_num' => $sheet['num'],
            ], JSON_NUMERIC_CHECK));
            $response->end();
        });

        $routeCollector->delete('/api/events/{id}/sheets/{ranks}/{num}/reservation', function (Request $request, Response $response, $id, $ranks, $num) {
            if (!$this->loginRequired($request, $response)) {
                return;
            }

            $event_id = $id;
            $rank = $ranks;
            $num = $num;
        
            $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
            $db = $pool->getObj();

            $user = $this->get_login_user($request, $response);
            $event = $this->get_event($db, $event_id, $user['id']);

            if (empty($event) || !$event['public']) {
                $pool->freeObj($db);
                $this->res_error($response, 'invalid_event', 404);
                return;
            }

            if (!$this->validate_rank($db, $rank)) {
                $pool->freeObj($db);
                $this->res_error($response, 'invalid_rank', 404);
                return;
            }

            $sheet = $this->select_row($db, 'SELECT * FROM sheets WHERE `rank` = ? AND num = ?', [$rank, $num]);
            if (!$sheet) {
                $pool->freeObj($db);
                $this->res_error($response, 'invalid_sheet', 404);
                return;
            }

            $db->begin();
            try {
                $reservation = $this->select_row($db, 'SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled_at IS NULL GROUP BY event_id HAVING reserved_at = MIN(reserved_at) FOR UPDATE', [$event['id'], $sheet['id']]);
                if (!$reservation) {
                    $db->rollback();
                    $pool->freeObj($db);
                    $this->res_error($response, 'not_reserved', 400);
                    return;
                }

                if ($reservation['user_id'] != $user['id']) {
                    $db->rollback();
                    $pool->freeObj($db);

                    $this->res_error($response, 'not_permitted', 403);
                    return;
                }

                $db->rawQuery('UPDATE reservations SET canceled_at = ? WHERE id = ?', [(new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), $reservation['id']]);
                $db->commit();
                $pool->freeObj($db);
            } catch (\Exception $e) {
                $db->rollback();
                $pool->freeObj($db);

                $this->res_error($response);
            }

            $response->withStatus(204);
            $response->end();
        });

        $routeCollector->get('/admin', function (Request $request, Response $response) {
            $this->fillinAdministrator($request, $response);

            $events = $this->get_events(function ($event) { return $event; });

            $response->write($this->render($request, 'admin.twig', [
                'events' => $events
            ]));
            $response->end();
        });

        $routeCollector->post('/admin/api/actions/login', function (Request $request, Response $response) {
            $content = $request->getBody()->__toString();
            $raw_array = json_decode($content, true);

            $login_name = $raw_array['login_name'];
            $password = $raw_array['password'];
        
            $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
            $db = $pool->getObj();

            $administrator = $this->select_row($db, 'SELECT * FROM administrators WHERE login_name = ?', [$login_name]);
            $pass_hash = $this->select_one($db, 'SELECT SHA2(?, 256) AS pass_hash', [$password]);
            $pool->freeObj($db);

            if (!$administrator || $pass_hash['pass_hash'] != $administrator['pass_hash']) {
                $this->res_error($response, 'authentication_failed', 401);
                return;
            }
        
            $session = $this->session($request, $response);
            $session->set('administrator_id', $administrator['id']);
        
            $response->write(json_encode($administrator, JSON_NUMERIC_CHECK));
            $response->end();
        });

        $routeCollector->post('/admin/api/actions/logout', function (Request $request, Response $response) {
            if (!$this->adminLoginRequired($request, $response)) {
                return;
            }
            $session = $this->session($request, $response);
            $session->delete('administrator_id');

            $response->withStatus(204);
            $response->end();
        });

        $routeCollector->get('/admin/api/events', function (Request $request, Response $response) {
            if (!$this->adminLoginRequired($request, $response)) {
                return;
            }

            $events = $this->get_events(function ($event) { return $event; });

            $response->write(json_encode($events, JSON_NUMERIC_CHECK));
            $response->end();
        });

        $routeCollector->post('/admin/api/events', function (Request $request, Response $response) {
            if (!$this->adminLoginRequired($request, $response)) {
                return;
            }

            $content = $request->getBody()->__toString();
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
        
            $response->write(json_encode($event, JSON_NUMERIC_CHECK));
            $response->end();
        });

        $routeCollector->get('/admin/api/events/{id}', function (Request $request, Response $response, $id) {
            if (!$this->adminLoginRequired($request, $response)) {
                return;
            }
            $event_id = $id;

            $pool = PoolManager::getInstance()->getPool(MysqlPool2::class);
            $db = $pool->getObj();

            $event = $this->get_event($db, $event_id);
            if (empty($event)) {
                $pool->freeObj($db);
                $this->res_error($response, 'not_found', 404);
                return;
            }
        
            $response->write(json_encode($event, JSON_NUMERIC_CHECK));
            $response->end();
        });

        $routeCollector->post('/admin/api/events/{id}/actions/edit', function (Request $request, Response $response, $id) {
            if (!$this->adminLoginRequired($request, $response)) {
                return;
            }
            $content = $request->getBody()->__toString();
            $raw_array = json_decode($content, true);

            $event_id = $id;
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
                return $this->res_error($response, 'not_found', 404);
            }
        
            if ($event['closed']) {
                $pool->freeObj($db);
                return $this->res_error($response, 'cannot_edit_closed_event', 400);
            } elseif ($event['public'] && $closed) {
                $pool->freeObj($db);
                return $this->res_error($response, 'cannot_close_public_event', 400);
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
        
            $response->write(json_encode($event, JSON_NUMERIC_CHECK));
            $response->end();
        });

        $routeCollector->get('/admin/api/reports/events/{id}/sales', function (Request $request, Response $response, $id) {
            $event_id = $id;

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
        
            return $this->render_report_csv($response, $reports);
        });

        $routeCollector->get('/admin/api/reports/sales', function (Request $request, Response $response) {
            if (!$this->adminLoginRequired($request, $response)) {
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
        
            return $this->render_report_csv($response, $reports);
        
        });

    }

    private function render_report_csv(Response $response, array $reports): Response
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

        $response->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="report.csv"')
            ->write($body);
        $response->end();
        return $response;
    }

}
