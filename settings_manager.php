<?php
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) { die('Acceso denegado'); }

function get_settings() {
    $file = __DIR__ . '/data.php';
    if (file_exists($file)) {
        return include $file;
    }
    return ['bots' => [], 'errors' => []];
}

function save_settings($data) {
    $file = __DIR__ . '/data.php';
    $content = "<?php\n// Archivo generado automáticamente. NO EDITAR DIRECTAMENTE.\nreturn " . var_export($data, true) . ";\n";
    file_put_contents($file, $content);
}

function log_bot_error($bot_id, $error_msg) {
    $settings = get_settings();
    if (!isset($settings['errors'])) { $settings['errors'] = []; }
    if (!isset($settings['errors'][$bot_id])) { $settings['errors'][$bot_id] = []; }
    
    // Establecer zona horaria si es necesario
    array_unshift($settings['errors'][$bot_id], date('Y-m-d H:i:s') . ' - ' . $error_msg);
    
    // Guardar máximo los últimos 5 errores por chat
    if (count($settings['errors'][$bot_id]) > 5) {
        array_pop($settings['errors'][$bot_id]);
    }
    save_settings($settings);
}

// Extrae array de API keys (admite comas, saltos de línea o espacios)
function parse_api_keys($raw) {
    if (is_array($raw)) {
        return array_values(array_filter(array_map('trim', $raw)));
    }
    $raw = str_replace(["\r\n", "\r", "\n", ";"], ',', (string)$raw);
    $parts = explode(',', $raw);
    $keys = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if (!empty($p)) {
            $keys[] = $p;
        }
    }
    return array_values(array_unique($keys));
}

// Invocación a Gemini con rotación automática entre múltiples API Keys
function call_gemini_multikey($api_keys, $model, $payload, $bot_id = 'gemini') {
    $keys = parse_api_keys($api_keys);
    if (empty($keys)) {
        return ['success' => false, 'error' => 'No se configuró ninguna API Key de Gemini.'];
    }
    
    // Respetar estrictamente el modelo ingresado por el usuario (ej: gemini-3.5-flash)
    $clean_model = trim($model ?? '');
    $requested_model = !empty($clean_model) ? $clean_model : 'gemini-3.5-flash';
    
    $last_error = '';
    foreach ($keys as $idx => $key) {
        // Cola de modelos: Primero prueba el que tú configuraste (ej: gemini-3.5-flash)
        $models_queue = [$requested_model];
        foreach (['gemini-3.5-flash', 'gemini-flash-latest', 'gemini-2.5-flash', 'gemini-pro-latest'] as $fb_m) {
            if (!in_array($fb_m, $models_queue)) {
                $models_queue[] = $fb_m;
            }
        }
        
        foreach ($models_queue as $current_model) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($current_model) . ":generateContent?key=" . urlencode($key));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            // FORZAR IPv4 para evitar cuelgues de 30s en cURL de HostGator
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($ch);
            curl_close($ch);

            if ($httpcode === 200 && $response !== false) {
                $data = json_decode($response, true);
                if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    return [
                        'success' => true,
                        'text' => $data['candidates'][0]['content']['parts'][0]['text'],
                        'used_key_index' => $idx,
                        'used_model' => $current_model,
                        'used_key_preview' => substr($key, 0, 8) . '...' . substr($key, -4)
                    ];
                }
            }
            
            $json_err = json_decode($response, true);
            $err_msg = $json_err['error']['message'] ?? ($response ? $response : $curl_err);
            $err_detail = "Key #" . ($idx + 1) . " (" . substr($key, 0, 6) . "...) fallo HTTP $httpcode con modelo $current_model: " . $err_msg;

            // Si es un error de autenticación (clave inválida o bloqueada), no seguir probando otros modelos con la misma clave
            if ($httpcode === 400 || $httpcode === 401 || $httpcode === 403) {
                $last_error = "API Key #" . ($idx + 1) . " no válida o rechazada por Google (HTTP $httpcode): " . $err_msg;
                log_bot_error($bot_id, $last_error);
                break; // Romper loop de modelos y pasar a la siguiente API Key
            }

            // Si el modelo falló y aún quedan modelos en la cola, probar el siguiente modelo
            $last_model_in_queue = end($models_queue);
            if ($current_model !== $last_model_in_queue) {
                log_bot_error($bot_id, "Modelo $current_model arrojó $httpcode. Probando siguiente modelo de respaldo...");
                continue;
            }
            
            $last_error = $err_detail;
            log_bot_error($bot_id, "Agotados modelos para Key #" . ($idx + 1) . ": " . $err_detail);
            break; // Pasar a la siguiente API Key
        }
    }
    
    return ['success' => false, 'error' => $last_error];
}

// ==========================================
// GESTIÓN DE CONFIGURACIÓN WHATSAPP
// ==========================================
function get_wa_settings() {
    $file = __DIR__ . '/whatsapp_data.php';
    if (file_exists($file)) {
        return include $file;
    }
    return ['instances' => [], 'errors' => [], 'sessions' => []];
}

function save_wa_settings($data) {
    $file = __DIR__ . '/whatsapp_data.php';
    $content = "<?php\n// Archivo de WhatsApp generado automáticamente. NO EDITAR DIRECTAMENTE.\nreturn " . var_export($data, true) . ";\n";
    file_put_contents($file, $content);
}

function log_wa_error($instance_id, $error_msg) {
    $settings = get_wa_settings();
    if (!isset($settings['errors'])) { $settings['errors'] = []; }
    if (!isset($settings['errors'][$instance_id])) { $settings['errors'][$instance_id] = []; }
    
    array_unshift($settings['errors'][$instance_id], date('Y-m-d H:i:s') . ' - ' . $error_msg);
    if (count($settings['errors'][$instance_id]) > 5) {
        array_pop($settings['errors'][$instance_id]);
    }
    save_wa_settings($settings);
}

// ==========================================
// GESTIÓN DE LEADS (Contactos capturados)
// ==========================================
function get_leads() {
    $file = __DIR__ . '/leads.json';
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        $data = json_decode($content, true);
        if (is_array($data)) return $data;
    }
    return [];
}

function save_lead($bot_id, $name, $phone, $extra = []) {
    $file = __DIR__ . '/leads.json';
    $leads = get_leads();
    if (!isset($leads[$bot_id]) || !is_array($leads[$bot_id])) {
        $leads[$bot_id] = [];
    }
    $lead_data = [
        'id' => 'lead_' . substr(md5(uniqid(mt_rand(), true)), 0, 8),
        'name' => trim($name),
        'phone' => trim($phone),
        'date' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ];
    if (!empty($extra)) {
        $lead_data = array_merge($lead_data, $extra);
    }
    array_unshift($leads[$bot_id], $lead_data);
    
    // Conservar máximo 200 leads por bot
    if (count($leads[$bot_id]) > 200) {
        array_pop($leads[$bot_id]);
    }
    file_put_contents($file, json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $lead_data;
}

function clear_bot_leads($bot_id) {
    $file = __DIR__ . '/leads.json';
    $leads = get_leads();
    if (isset($leads[$bot_id])) {
        unset($leads[$bot_id]);
        file_put_contents($file, json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function get_system_base_url() {
    $is_https = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1))
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && $_SERVER['HTTP_FRONT_END_HTTPS'] === 'on')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $protocol = $is_https ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = dirname($_SERVER['PHP_SELF']);
    $dir = ($dir === '/' || $dir === '\\') ? '' : rtrim($dir, '/\\');
    return $protocol . "://" . $host . $dir;
}
