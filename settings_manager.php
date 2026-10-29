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
    // Si contiene "pro", evitar modelos Pro en tier gratuito porque Google les asigna límite = 0
    if (empty($clean_model) || stripos($clean_model, 'pro') !== false) {
        $requested_model = 'gemini-3.5-flash';
    } else {
        $requested_model = $clean_model;
    }
    
    $last_error = '';
    foreach ($keys as $idx => $key) {
        // Cola de modelos: Primero prueba el que tú configuraste (ej: gemini-3.5-flash)
        $models_queue = [$requested_model];
        // Solo modelos Flash con alta cuota gratuita (1,500 req/día)
        foreach (['gemini-3.5-flash', 'gemini-flash-latest', 'gemini-2.5-flash'] as $fb_m) {
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

            // Si es error 429 (Límite por minuto o cuota diaria alcanzada en esta clave), rotar a la siguiente clave
            if ($httpcode === 429) {
                $last_error = "API Key #" . ($idx + 1) . " excedió límite de Google (HTTP 429): " . $err_msg;
                log_bot_error($bot_id, $last_error);
                break; // Pasar inmediatamente a la siguiente API Key configurada
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

// ==========================================
// GEOLOCALIZACIÓN Y DETECCIÓN DE PAÍS
// ==========================================
function get_client_ip() {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return $ip;
}

function country_code_to_flag($code) {
    if (empty($code) || strlen($code) !== 2) return '🌐';
    $code = strtoupper($code);
    $first = mb_ord($code[0]) - 65 + 0x1F1E6;
    $second = mb_ord($code[1]) - 65 + 0x1F1E6;
    return mb_chr($first) . mb_chr($second);
}

function get_ip_country($ip) {
    if (empty($ip) || in_array($ip, ['127.0.0.1', '::1']) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return ['country' => 'Local / Red Privada', 'code' => 'LOCAL', 'city' => 'Local', 'flag' => '🏠'];
    }
    $cache_file = __DIR__ . '/ip_cache.json';
    $cache = file_exists($cache_file) ? json_decode(@file_get_contents($cache_file), true) : [];
    if (!is_array($cache)) $cache = [];
    
    if (isset($cache[$ip])) {
        return $cache[$ip];
    }
    
    $res = ['country' => 'Desconocido', 'code' => 'UN', 'city' => '', 'flag' => '🌐'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,country,countryCode,city");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    $raw = @curl_exec($ch);
    curl_close($ch);
    
    if ($raw) {
        $data = json_decode($raw, true);
        if (!empty($data['status']) && $data['status'] === 'success') {
            $code = $data['countryCode'] ?? '';
            $res = [
                'country' => $data['country'] ?? 'Desconocido',
                'code' => $code,
                'city' => $data['city'] ?? '',
                'flag' => country_code_to_flag($code)
            ];
        }
    }
    
    // Guardar en caché local
    $cache[$ip] = $res;
    if (count($cache) > 1000) {
        $oldest = key($cache);
        unset($cache[$oldest]);
    }
    @file_put_contents($cache_file, json_encode($cache), LOCK_EX);
    
    return $res;
}

function phone_to_country($phone) {
    $clean = preg_replace('/[^0-9]/', '', (string)$phone);
    $map = [
        '593' => ['country' => 'Ecuador', 'code' => 'EC'],
        '58'  => ['country' => 'Venezuela', 'code' => 'VE'],
        '34'  => ['country' => 'España', 'code' => 'ES'],
        '57'  => ['country' => 'Colombia', 'code' => 'CO'],
        '52'  => ['country' => 'México', 'code' => 'MX'],
        '54'  => ['country' => 'Argentina', 'code' => 'AR'],
        '56'  => ['country' => 'Chile', 'code' => 'CL'],
        '51'  => ['country' => 'Perú', 'code' => 'PE'],
        '507' => ['country' => 'Panamá', 'code' => 'PA'],
        '506' => ['country' => 'Costa Rica', 'code' => 'CR'],
        '502' => ['country' => 'Guatemala', 'code' => 'GT'],
        '503' => ['country' => 'El Salvador', 'code' => 'SV'],
        '504' => ['country' => 'Honduras', 'code' => 'HN'],
        '505' => ['country' => 'Nicaragua', 'code' => 'NI'],
        '591' => ['country' => 'Bolivia', 'code' => 'BO'],
        '595' => ['country' => 'Paraguay', 'code' => 'PY'],
        '598' => ['country' => 'Uruguay', 'code' => 'UY'],
        '55'  => ['country' => 'Brasil', 'code' => 'BR'],
        '1'   => ['country' => 'Estados Unidos', 'code' => 'US'],
        '39'  => ['country' => 'Italia', 'code' => 'IT'],
        '33'  => ['country' => 'Francia', 'code' => 'FR'],
        '49'  => ['country' => 'Alemania', 'code' => 'DE'],
        '44'  => ['country' => 'Reino Unido', 'code' => 'GB'],
    ];
    foreach ($map as $prefix => $info) {
        if (strpos($clean, $prefix) === 0) {
            $info['flag'] = country_code_to_flag($info['code']);
            return $info;
        }
    }
    return ['country' => 'Internacional', 'code' => 'INT', 'flag' => '📱'];
}

// ==========================================
// REGISTRO Y ANÁLISIS DE CONVERSACIONES
// ==========================================
function get_conversations() {
    $file = __DIR__ . '/conversations.json';
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        $data = json_decode($content, true);
        if (is_array($data)) return $data;
    }
    return [];
}

function save_chat_interaction($bot_id, $session_id, $user_msg, $bot_reply, $channel = 'web', $extra = []) {
    if (empty($bot_id) || empty($user_msg)) return;
    $file = __DIR__ . '/conversations.json';
    $convs = get_conversations();
    if (!isset($convs[$bot_id])) $convs[$bot_id] = [];
    
    $sess_key = !empty($session_id) ? preg_replace('/[^a-zA-Z0-9_\-\+]/', '', $session_id) : 'anon_' . md5(get_client_ip() . date('Y-m-d'));
    
    $ip = get_client_ip();
    if (!isset($convs[$bot_id][$sess_key])) {
        if ($channel === 'whatsapp') {
            $geo = phone_to_country($session_id);
            $geo['city'] = '';
        } else {
            $geo = get_ip_country($ip);
        }
        $convs[$bot_id][$sess_key] = [
            'id' => $sess_key,
            'started_at' => date('Y-m-d H:i:s'),
            'last_activity' => date('Y-m-d H:i:s'),
            'channel' => $channel,
            'ip' => $ip,
            'country' => $geo['country'],
            'country_code' => $geo['code'],
            'city' => $geo['city'] ?? '',
            'flag' => $geo['flag'],
            'messages_count' => 0,
            'user_name' => $extra['user_name'] ?? '',
            'user_phone' => $extra['user_phone'] ?? ($channel === 'whatsapp' ? $session_id : ''),
            'messages' => []
        ];
    }
    
    $convs[$bot_id][$sess_key]['last_activity'] = date('Y-m-d H:i:s');
    if (!empty($extra['user_name'])) $convs[$bot_id][$sess_key]['user_name'] = $extra['user_name'];
    if (!empty($extra['user_phone'])) $convs[$bot_id][$sess_key]['user_phone'] = $extra['user_phone'];
    
    $now_time = date('H:i');
    $convs[$bot_id][$sess_key]['messages'][] = [
        'sender' => 'user',
        'text' => trim($user_msg),
        'time' => $now_time,
        'date' => date('Y-m-d H:i:s')
    ];
    if (!empty($bot_reply)) {
        $clean_reply = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bot_reply));
        $convs[$bot_id][$sess_key]['messages'][] = [
            'sender' => 'bot',
            'text' => trim($clean_reply),
            'time' => $now_time,
            'date' => date('Y-m-d H:i:s')
        ];
    }
    
    $convs[$bot_id][$sess_key]['messages_count'] = count($convs[$bot_id][$sess_key]['messages']);
    
    // Máximo 60 mensajes por conversación
    if (count($convs[$bot_id][$sess_key]['messages']) > 60) {
        $convs[$bot_id][$sess_key]['messages'] = array_slice($convs[$bot_id][$sess_key]['messages'], -60);
    }
    
    // Máximo 250 conversaciones por bot
    if (count($convs[$bot_id]) > 250) {
        uasort($convs[$bot_id], function($a, $b) {
            return strcmp($b['last_activity'] ?? '', $a['last_activity'] ?? '');
        });
        $convs[$bot_id] = array_slice($convs[$bot_id], 0, 250, true);
    }
    
    @file_put_contents($file, json_encode($convs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function clear_bot_conversations($bot_id) {
    $file = __DIR__ . '/conversations.json';
    $convs = get_conversations();
    if (isset($convs[$bot_id])) {
        unset($convs[$bot_id]);
        @file_put_contents($file, json_encode($convs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
