<?php

namespace App\HttpController;

use EasySwoole\Core\Http\AbstractInterface\Controller;

class Index extends BaseController
{
    public function index()
    {
        $this->fillinUser();

        $events = array_map(function (array $event) {
            return $this->sanitize_event($event);
        }, $this->get_events());

        $this->render('index.twig', [
            'events' => $events
        ]);
    }

    public function admin()
    {
        $this->fillinAdministrator();

        $events = $this->get_events(function ($event) { return $event; });

        $this->render('admin.twig', [
            'events' => $events
        ]);
    }
}
