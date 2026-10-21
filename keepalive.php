<?php
/**
 * Keep-Alive Cron para Render / Evolution API
 * Evita que el contenedor gratuito de Render se duerma por inactividad.
 * Configúralo en cPanel -> Trabajos de Cron cada 10 minutos:
 * curl -s https://davidicaza.oncestudiomadrid.com/keepalive.php > /dev/null 2>&1
 * O añádelo como monitor gratuito en cron-job.org / UptimeRobot.
 */
require_once __DIR__ . '/settings_manager.php';

$wa_settings = get_wa_settings();
$checked = [];

if (!empty($wa_settings['instances'])) {
    foreach ($wa_settings['instances'] as $id => $inst) {
        if (($inst['engine'] ?? '') === 'evolution' && !empty($inst['server_url'])) {
            $base = rtrim($inst['server_url'], '/');
            $name = $inst['instance_name'] ?? $id;
            $token = $inst['server_token'] ?? '';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "{$base}/instance/connectionState/{$name}");
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $token]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $checked[$name] = [
                'http_code' => $code,
                'response' => json_decode($res, true) ?? $res
            ];
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'message' => 'Keep-alive ejecutado exitosamente.',
    'timestamp' => date('Y-m-d H:i:s'),
    'instances' => $checked
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
