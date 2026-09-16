<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\MqttService;

$type = new ReflectionClass(MqttService::class);
$service = $type->newInstanceWithoutConstructor();
$method = $type->getMethod('authorizationReason');
$reasons = [
    'automatic_tan_prevention_unavailable', 'explicit_authorization_started', 'tan_confirmation_pending',
    'authorization_expired', 'authorization_poll_limit', 'authorization_cancelled', 'session_reset',
    'fints_operation_failed', 'authorization_operation_failed', 'not_authenticated',
    'local_authorization_expired', null,
];
foreach ($reasons as $reason) {
    if ($method->invoke($service, $reason) !== $reason) {
        throw new RuntimeException('Persisted authorization reason was not preserved: ' . ($reason ?? 'null'));
    }
}
foreach (['unexpected_secret_value', 'TAN 123456', ['challenge' => 'private'], 123456] as $reason) {
    if ($method->invoke($service, $reason) !== 'unspecified') {
        throw new RuntimeException('Unexpected authorization reason was not redacted');
    }
}
echo "PASS MQTT persisted authorization reason allowlist and sensitive-value redaction\n";
