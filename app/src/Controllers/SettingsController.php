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
            'mqtt_enabled' => $this->db->getSetting('mqtt_enabled', '0'),
            'mqtt_host' => $this->db->getSetting('mqtt_host', getenv('MQTT_HOST') ?: ''),
            'mqtt_port' => $this->db->getSetting('mqtt_port', getenv('MQTT_PORT') ?: '1883'),
            'mqtt_user' => $this->db->getSetting('mqtt_user', getenv('MQTT_USER') ?: ''),
            'mqtt_password' => '',
            'mqtt_topic_prefix' => $this->db->getSetting('mqtt_topic_prefix', getenv('MQTT_TOPIC_PREFIX') ?: 'banking'),
            'mqtt_auto_publish_enabled' => $this->db->getSetting('mqtt_auto_publish_enabled', '1'),
            'mqtt_auto_publish_interval' => $this->db->getSetting('mqtt_auto_publish_interval', '1'),
            'mqtt_last_publish' => $this->db->getSetting('mqtt_last_publish', ''),
            'auto_sync_enabled' => $this->db->getSetting('auto_sync_enabled', '0'),
            'auto_sync_interval' => $this->db->getSetting('auto_sync_interval', '30'),
            'auto_sync_last_run' => $this->db->getSetting('auto_sync_last_run', ''),
        ];

        return $this->view->render($response, 'settings.twig', [
            'title' => 'Einstellungen',
            'settings' => $settings,
            'success' => $request->getQueryParams()['success'] ?? null
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        foreach (['mqtt_port' => [1, 65535], 'auto_sync_interval' => [1, 10080], 'mqtt_auto_publish_interval' => [1, 10080]] as $key => [$min, $max]) {
            if (isset($data[$key]) && filter_var($data[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
                $response->getBody()->write('Ungültiger Wert für ' . $key);
                return $response->withStatus(400)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
        }
        if (isset($data['mqtt_topic_prefix']) && (!is_string($data['mqtt_topic_prefix']) || trim($data['mqtt_topic_prefix'], "/ \t\n\r\0\x0B") === '' || preg_match('/[+#\x00-\x1f]/', $data['mqtt_topic_prefix']))) {
            $response->getBody()->write('Ungültiges MQTT-Topic-Präfix');
            return $response->withStatus(400)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        // Save FinTS settings
        if (isset($data['fints_product_id'])) {
            $this->db->setSetting('fints_product_id', trim($data['fints_product_id']));
        }

        // Save MQTT settings
        $this->db->setSetting('mqtt_enabled', isset($data['mqtt_enabled']) ? '1' : '0');
        if (isset($data['mqtt_host'])) {
            $this->db->setSetting('mqtt_host', trim($data['mqtt_host']));
        }
        if (isset($data['mqtt_port'])) {
            $this->db->setSetting('mqtt_port', trim($data['mqtt_port']));
        }
        if (isset($data['mqtt_user'])) {
            $this->db->setSetting('mqtt_user', trim($data['mqtt_user']));
        }
        if (isset($data['mqtt_password']) && $data['mqtt_password'] !== '') {
            $this->db->setSetting('mqtt_password', $data['mqtt_password']);
        }
        if (!empty($data['mqtt_password_clear'])) {
            $this->db->setSetting('mqtt_password', '');
        }
        if (isset($data['mqtt_topic_prefix'])) {
            $this->db->setSetting('mqtt_topic_prefix', trim($data['mqtt_topic_prefix']));
        }
        $this->db->setSetting('mqtt_auto_publish_enabled', isset($data['mqtt_auto_publish_enabled']) ? '1' : '0');
        if (isset($data['mqtt_auto_publish_interval'])) {
            $this->db->setSetting('mqtt_auto_publish_interval', trim($data['mqtt_auto_publish_interval']));
        }

        // Save Auto-sync settings
        $this->db->setSetting('auto_sync_enabled', isset($data['auto_sync_enabled']) ? '1' : '0');
        if (isset($data['auto_sync_interval'])) {
            $this->db->setSetting('auto_sync_interval', trim($data['auto_sync_interval']));
        }

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $url = $routeParser->urlFor('settings') . '?success=1';
        
        return $response
            ->withHeader('Location', $url)
            ->withStatus(302);
    }
}
