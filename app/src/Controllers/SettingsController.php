<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;
use App\Services\DatabaseService;

class SettingsController
{
    public function __construct(
        private Twig $view,
        private DatabaseService $db
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $settings = [
            'fints_product_id' => $this->db->getSetting('fints_product_id', ''),
            'mqtt_host' => $this->db->getSetting('mqtt_host', ''),
            'mqtt_port' => $this->db->getSetting('mqtt_port', '1883'),
            'mqtt_user' => $this->db->getSetting('mqtt_user', ''),
            'mqtt_password' => $this->db->getSetting('mqtt_password', ''),
            'mqtt_topic_prefix' => $this->db->getSetting('mqtt_topic_prefix', 'banking'),
        ];

        return $this->view->render($response, 'settings.twig', [
            'title' => 'Einstellungen',
            'settings' => $settings,
            'success' => $request->getQueryParams()['success'] ?? null
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        // Save FinTS settings
        if (isset($data['fints_product_id'])) {
            $this->db->setSetting('fints_product_id', trim($data['fints_product_id']));
        }

        // Save MQTT settings
        if (isset($data['mqtt_host'])) {
            $this->db->setSetting('mqtt_host', trim($data['mqtt_host']));
        }
        if (isset($data['mqtt_port'])) {
            $this->db->setSetting('mqtt_port', trim($data['mqtt_port']));
        }
        if (isset($data['mqtt_user'])) {
            $this->db->setSetting('mqtt_user', trim($data['mqtt_user']));
        }
        if (isset($data['mqtt_password'])) {
            $this->db->setSetting('mqtt_password', $data['mqtt_password']);
        }
        if (isset($data['mqtt_topic_prefix'])) {
            $this->db->setSetting('mqtt_topic_prefix', trim($data['mqtt_topic_prefix']));
        }

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('settings') . '?success=1';
        
        return $response
            ->withHeader('Location', $url)
            ->withStatus(302);
    }
}
