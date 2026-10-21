<?php
/**
 * Motor de Flujos Anidados y Conector de WhatsApp
 * Funciona tanto para WhatsApp Webhook (Evolution API / Baileys / Meta) como simulador interno
 */
require_once __DIR__ . '/settings_manager.php';

// Limpieza de texto de entrada
function wa_clean_text($text) {
    return mb_strtolower(trim((string)$text), 'UTF-8');
}

// Analizador de Árbol de Opciones Anidadas
// Formato soportado en el textarea:
// [1] Planes de Hosting :: Contamos con planes anuales desde $999 MXN en https://dscloud.mx/hosting-web
//   [1.1] Hosting Compartido :: Ideal para webs básicas: https://dscloud.mx/hosting-web
//   [1.2] Hosting WordPress :: Optimizado para WP: https://dscloud.mx/hosting-wordpress
// [2] Horarios y Ubicación :: Atendemos de Lunes a Viernes de 8:00 a 17:00.
// [3] Contactar Asesor Humano :: [HUMAN_ESCALATE]
// [4] Preguntar a la IA :: [GEMINI]
function parse_nested_menu($menu_text) {
    $lines = explode("\n", str_replace("\r", "", (string)$menu_text));
    $menu = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        
        if (preg_match('/^\[(.*?)\]\s*(.*?)::(.*)$/u', $line, $matches)) {
            $code = trim($matches[1]); // Ej: 1, 1.1, 2, A, B
            $title = trim($matches[2]); // Título del botón u opción
            $response = trim($matches[3]); // Respuesta o instrucción especial
            
            // Determinar si es hijo de alguien (ej: 1.1 es hijo de 1)
            $parent_code = '';
            if (strpos($code, '.') !== false) {
                $last_dot = strrpos($code, '.');
                $parent_code = substr($code, 0, $last_dot);
            }
            
            $menu[$code] = [
                'code' => $code,
                'parent' => $parent_code,
                'title' => $title,
                'response' => $response
            ];
        }
    }
    return $menu;
}

// Almacén de Estado de Conversación (Sesión por Teléfono)
function get_wa_session($instance_id, $phone) {
    $wa_settings = get_wa_settings();
    return $wa_settings['sessions'][$instance_id][$phone] ?? [
        'current_code' => '',
        'history' => [],
        'human_mode' => false,
        'last_interaction' => time()
    ];
}

function save_wa_session($instance_id, $phone, $session) {
    $wa_settings = get_wa_settings();
    if (!isset($wa_settings['sessions'])) $wa_settings['sessions'] = [];
    if (!isset($wa_settings['sessions'][$instance_id])) $wa_settings['sessions'][$instance_id] = [];
    
    $session['last_interaction'] = time();
    $wa_settings['sessions'][$instance_id][$phone] = $session;
    
    // Limitar tamaño de sesiones guardadas (máximo 500 números)
    if (count($wa_settings['sessions'][$instance_id]) > 500) {
        $oldest = key($wa_settings['sessions'][$instance_id]);
        unset($wa_settings['sessions'][$instance_id][$oldest]);
    }
    
    save_wa_settings($wa_settings);
}

// Alternar modo humano (pausar o reanudar IA) para un contacto específico
function toggle_wa_contact_human_mode($instance_id, $phone) {
    $session = get_wa_session($instance_id, $phone);
    $session['human_mode'] = empty($session['human_mode']);
    save_wa_session($instance_id, $phone, $session);
    return $session['human_mode'];
}

// Procesador central de Mensaje Entrante para WhatsApp
function process_wa_incoming_message($instance, $phone, $incoming_text) {
    $instance_id = $instance['id'];
    $session = get_wa_session($instance_id, $phone);
    $clean_input = wa_clean_text($incoming_text);
    
    // 1. Si está en modo Humano y el usuario escribe "menu" o "salir", reactivar bot
    if (!empty($session['human_mode'])) {
        if (in_array($clean_input, ['menu', 'inicio', 'volver', 'bot', 'reiniciar', '0'])) {
            $session['human_mode'] = false;
            $session['current_code'] = '';
            save_wa_session($instance_id, $phone, $session);
            // Continuar hacia el menú
        } else {
            // El bot se mantiene en silencio mientras atiende un humano
            return null;
        }
    }
    
    // 2. Si el usuario escribe reset / menu / hola / inicio
    if (in_array($clean_input, ['menu', 'inicio', 'hola', 'buenas', 'hi', 'volver', '0', 'reiniciar'])) {
        $session['current_code'] = '';
    }
    
    $menu = parse_nested_menu($instance['nested_menu'] ?? '');
    $welcome_msg = trim($instance['welcome_msg'] ?? "¡Hola! Bienvenido. ¿En qué podemos ayudarte?");
    
    // 3. Buscar si el input coincide con alguna opción del menú actual o submenú
    $matched_node = null;
    $current_code = $session['current_code'] ?? '';
    
    // Primero buscar entre las opciones hijas del nodo actual
    foreach ($menu as $code => $node) {
        if ($node['parent'] === $current_code) {
            $node_code_clean = wa_clean_text($node['code']);
            $node_title_clean = wa_clean_text($node['title']);
            
            if ($clean_input === $node_code_clean || $clean_input === $node_title_clean || 
                (strlen($clean_input) >= 3 && strpos($node_title_clean, $clean_input) !== false)) {
                $matched_node = $node;
                break;
            }
        }
    }
    
    // Si no encontró en hijos, buscar en cualquier código del menú raíz si el input es un número directo
    if (!$matched_node) {
        foreach ($menu as $code => $node) {
            if (wa_clean_text($code) === $clean_input) {
                $matched_node = $node;
                break;
            }
        }
    }
    
    // 4. Si coincidió con un nodo del menú anidado
    if ($matched_node) {
        $code = $matched_node['code'];
        $resp = $matched_node['response'];
        
        // ¿Pide escalar a asesor humano?
        if (stripos($resp, '[HUMAN_ESCALATE]') !== false) {
            $session['human_mode'] = true;
            $session['current_code'] = '';
            save_wa_session($instance_id, $phone, $session);
            
            $text = str_ireplace('[HUMAN_ESCALATE]', '', $resp);
            if (empty(trim($text))) {
                $text = "👨‍💻 Te estamos transfiriendo con un asesor humano. En breve te responderá.";
            }
            return $text . "\n\n*(Escribe 'MENU' en cualquier momento para volver al asistente)*";
        }
        
        // ¿Pide activar Gemini?
        if (stripos($resp, '[GEMINI]') !== false) {
            $session['current_code'] = '';
            save_wa_session($instance_id, $phone, $session);
            return "🤖 Cuéntame tu duda detalladamente y con gusto te asesoro:";
        }
        
        // Revisar si este nodo tiene sub-opciones hijas
        $children = [];
        foreach ($menu as $c => $n) {
            if ($n['parent'] === $code) {
                $children[] = $n;
            }
        }
        
        $session['current_code'] = $code;
        save_wa_session($instance_id, $phone, $session);
        
        $out = $resp;
        if (!empty($children)) {
            $out .= "\n\n*Selecciona una opción:*";
            foreach ($children as $ch) {
                $out .= "\n[" . $ch['code'] . "] " . $ch['title'];
            }
            $out .= "\n\n*(Escribe 0 para volver al Menú Principal)*";
        } else {
            $out .= "\n\n*(Escribe 'MENU' para ver más opciones o escríbenos tu duda)*";
        }
        
        return $out;
    }
    
    // 5. Si no coincide con ninguna opción y el modo es "Solo Menú"
    $mode = $instance['bot_mode'] ?? 'hybrid'; // 'menu_only', 'gemini_only', 'hybrid'
    if ($mode === 'menu_only') {
        // Enviar menú principal
        $out = $welcome_msg . "\n\n*Menú Principal:*";
        $has_roots = false;
        foreach ($menu as $code => $node) {
            if (empty($node['parent'])) {
                $out .= "\n[" . $node['code'] . "] " . $node['title'];
                $has_roots = true;
            }
        }
        if (!$has_roots && !empty($menu)) {
            foreach ($menu as $code => $node) {
                $out .= "\n[" . $node['code'] . "] " . $node['title'];
            }
        }
        return $out;
    }
    
    // 6. Modo Híbrido o Gemini Only: Responder con IA usando Multi-Key
    $main_settings = function_exists('get_settings') ? get_settings() : [];
    $api_keys = !empty(trim($instance['api_key'] ?? '')) ? $instance['api_key'] : ($main_settings['api_keys'] ?? '');
    $model = !empty($instance['model']) ? trim($instance['model']) : 'gemini-3.5-flash';
    $system_prompt = $instance['prompt'] ?? 'Eres un asistente cordial y experto en WhatsApp.';
    
    // Agregar contexto del menú al system prompt para que la IA sepa los servicios
    $menu_context = "";
    if (!empty($menu)) {
        $menu_context = "\n\nINFORMACIÓN DE SERVICIOS Y ENLACES OFICIALES:\n";
        foreach ($menu as $m) {
            $menu_context .= "- {$m['title']}: {$m['response']}\n";
        }
    }
    
    $history_messages = $session['history'] ?? [];
    $contents = [];
    
    foreach (array_slice($history_messages, -6) as $hm) {
        $contents[] = [
            'role' => $hm['role'],
            'parts' => [['text' => $hm['text']]]
        ];
    }
    
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $incoming_text]]
    ];
    
    $payload = [
        'systemInstruction' => [
            'parts' => [['text' => $system_prompt . $menu_context]]
        ],
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 600
        ]
    ];
    
    $gemini_result = call_gemini_multikey($api_keys, $model, $payload, 'wa_' . $instance_id);
    
    if ($gemini_result['success']) {
        $reply = $gemini_result['text'];
        
        // Guardar en historial de sesión
        $session['history'][] = ['role' => 'user', 'text' => $incoming_text];
        $session['history'][] = ['role' => 'model', 'text' => $reply];
        if (count($session['history']) > 12) {
            $session['history'] = array_slice($session['history'], -12);
        }
        save_wa_session($instance_id, $phone, $session);
        
        return $reply;
    } else {
        log_wa_error($instance_id, "Fallo de Gemini: " . ($gemini_result['error'] ?? ''));
        return !empty($instance['fallback_msg']) ? $instance['fallback_msg'] : "En este momento nuestros servidores están saturados. Por favor escribe más tarde.";
    }
}

// Envío HTTP de mensaje hacia Evolution API o Meta
function send_wa_outgoing_message($instance, $phone, $message_text) {
    if (empty($message_text)) return false;
    $engine = $instance['engine'] ?? 'evolution'; // 'evolution' o 'meta'
    
    if ($engine === 'evolution') {
        $base_api = rtrim($instance['server_url'] ?? '', '/');
        $instance_name = $instance['instance_name'] ?? $instance['id'];
        $api_token = $instance['server_token'] ?? '';
        
        if (empty($base_api)) return false;
        
        // Endpoint estándar de Evolution API v1 y v2
        $url = $base_api . "/message/sendText/" . urlencode($instance_name);
        
        // Asegurar que el número tenga formato internacional limpio sin '+'
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        
        $body = [
            'number' => $clean_phone,
            'text' => $message_text,
            'options' => [
                'delay' => 1200,
                'presence' => 'composing'
            ],
            'textMessage' => [
                'text' => $message_text
            ],
            'delay' => 1000,
            'options' => [
                'delay' => 1000,
                'presence' => 'composing'
            ]
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'apikey: ' . $api_token
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code < 200 || $code >= 300) {
            log_wa_error($instance['id'], "Envío Evolution HTTP $code: " . $res);
            return false;
        }
        register_bot_sent_message($message_text);
        return true;
        
    } elseif ($engine === 'meta') {
        $phone_id = $instance['meta_phone_id'] ?? '';
        $meta_token = $instance['meta_token'] ?? '';
        if (empty($phone_id) || empty($meta_token)) return false;
        
        $url = "https://graph.facebook.com/v19.0/{$phone_id}/messages";
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        
        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $clean_phone,
            'type' => 'text',
            'text' => ['body' => $message_text]
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $meta_token
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code < 200 || $code >= 300) {
            log_wa_error($instance['id'], "Envío Meta HTTP $code: " . $res);
            return false;
        }
        register_bot_sent_message($message_text);
        return true;
    }
    
    return false;
}

// ==========================================
// PROTECCIÓN ANTI-BUCLE / CONTROL DE ECOS
// ==========================================
function register_bot_sent_message($text) {
    if (empty($text)) return;
    $file = __DIR__ . '/whatsapp_bot_echoes.json';
    $echoes = file_exists($file) ? json_decode(@file_get_contents($file), true) : [];
    if (!is_array($echoes)) $echoes = [];
    $now = time();
    // Purgar mensajes viejos de más de 120 segundos
    foreach ($echoes as $h => $t) {
        if ($now - $t > 120) unset($echoes[$h]);
    }
    $clean = mb_strtolower(trim(preg_replace('/\s+/', ' ', $text)));
    $echoes[md5($clean)] = $now;
    if (mb_strlen($clean) > 40) {
        $echoes[md5(mb_substr($clean, 0, 40))] = $now;
    }
    @file_put_contents($file, json_encode($echoes), LOCK_EX);
}

function is_bot_echo_message($text) {
    if (empty($text)) return false;
    $file = __DIR__ . '/whatsapp_bot_echoes.json';
    if (!file_exists($file)) return false;
    $echoes = json_decode(@file_get_contents($file), true);
    if (!is_array($echoes)) return false;
    $clean = mb_strtolower(trim(preg_replace('/\s+/', ' ', $text)));
    if (isset($echoes[md5($clean)])) return true;
    if (mb_strlen($clean) > 40 && isset($echoes[md5(mb_substr($clean, 0, 40))])) return true;
    return false;
}

