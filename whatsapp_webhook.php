<?php
/**
 * Webhook Receptor Universal para WhatsApp
 * Recibe eventos de Evolution API (Render/Railway/VPS) o Meta Cloud API
 * URL: https://tudominio.com/whatsapp_webhook.php?id=wa_instance_id
 */
require_once __DIR__ . '/settings_manager.php';
require_once __DIR__ . '/whatsapp_engine.php';

// Verificación de Webhook para Meta (GET Challenge)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hub_mode = $_GET['hub_mode'] ?? '';
    $hub_challenge = $_GET['hub_challenge'] ?? '';
    $hub_verify_token = $_GET['hub_verify_token'] ?? '';
    $id = $_GET['id'] ?? '';
    
    $wa_settings = get_wa_settings();
    $instance = $wa_settings['instances'][$id] ?? null;
    
    $expected_token = $instance['meta_verify_token'] ?? 'gemini_whatsapp_secret';
    
    if ($hub_mode === 'subscribe' && $hub_verify_token === $expected_token) {
        header('Content-Type: text/plain');
        echo $hub_challenge;
        exit;
    }
    
    // Si entran por GET directo, mostrar estado del webhook
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'active',
        'message' => 'WhatsApp Webhook receptor listo para peticiones POST.',
        'instance_id' => $id,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Proceso de Notificación POST de Mensaje Entrante
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true);
    
    $instance_id = $_GET['id'] ?? '';
    $instance_id = preg_replace('/[^a-zA-Z0-9_]/', '', $instance_id);
    
    $wa_settings = get_wa_settings();
    $instance = $wa_settings['instances'][$instance_id] ?? null;
    
    // Si no viene ID por GET, buscar por nombre de instancia en payload de Evolution API
    if (!$instance && isset($data['instance'])) {
        foreach ($wa_settings['instances'] as $inst_id => $inst_data) {
            if (($inst_data['instance_name'] ?? '') === $data['instance']) {
                $instance = $inst_data;
                $instance_id = $inst_id;
                break;
            }
        }
    }
    
    if (!$instance) {
        http_response_code(400);
        echo json_encode(['error' => 'Instancia de WhatsApp no encontrada o ID ausente.']);
        exit;
    }
    
    // 1. EXTRAER MENSAJE SEGÚN EL MOTOR
    $sender_phone = '';
    $sender_name = '';
    $incoming_text = '';
    $from_me = false;
    
    // CASO A: Evolution API (v1 / v2)
    if (isset($data['event']) || isset($data['data'])) {
        $raw_event = $data['event'] ?? '';
        $event = strtolower(str_replace(['.', '-'], '_', $raw_event));
        
        // Buscar el objeto de mensaje en todas las variantes de Evolution API
        $msg_entry = null;
        if (!empty($data['data']['messages'][0])) {
            $msg_entry = $data['data']['messages'][0];
        } elseif (!empty($data['data']['message'])) {
            $msg_entry = $data['data'];
        } elseif (!empty($data['data']['key'])) {
            $msg_entry = $data['data'];
        } elseif (!empty($data['data'][0]['key'])) {
            $msg_entry = $data['data'][0];
        } elseif (!empty($data['data']) && is_array($data['data'])) {
            $msg_entry = $data['data'];
        }
        
        $key_obj = $msg_entry['key'] ?? [];
        $from_me = !empty($key_obj['fromMe']);
        $remote_jid = $key_obj['remoteJid'] ?? '';
        
        // Ignorar mensajes de grupos (@g.us) o newsletters/canales (@newsletter)
        if (strpos($remote_jid, '@g.us') !== false || strpos($remote_jid, '@newsletter') !== false) {
            echo json_encode(['status' => 'ignored_group_or_newsletter']);
            exit;
        }
        
        // Extraer el número real del remitente (en WhatsApp 1 a 1 remoteJid es el cliente)
        if (!empty($remote_jid) && strpos($remote_jid, '@s.whatsapp.net') !== false) {
            $sender_phone = explode('@', $remote_jid)[0];
        } elseif (!empty($key_obj['participant']) && strpos($key_obj['participant'], '@s.whatsapp.net') !== false) {
            $sender_phone = explode('@', $key_obj['participant'])[0];
        } elseif (!empty($data['sender']) && strpos($data['sender'], '@s.whatsapp.net') !== false && !$from_me) {
            $sender_phone = explode('@', $data['sender'])[0];
        }
        $sender_phone = preg_replace('/[^0-9]/', '', $sender_phone);
        
        $sender_name = $data['data']['pushName'] ?? $msg_entry['pushName'] ?? ('+' . $sender_phone);
        
        // Extraer texto del mensaje en todos los formatos posibles
        $inner_msg = $msg_entry['message'] ?? $msg_entry;
        $incoming_text = $inner_msg['conversation'] ?? 
                        $inner_msg['extendedTextMessage']['text'] ?? 
                        $inner_msg['buttonsResponseMessage']['selectedDisplayText'] ?? 
                        $inner_msg['listResponseMessage']['title'] ?? 
                        $inner_msg['imageMessage']['caption'] ?? 
                        $inner_msg['documentMessage']['caption'] ?? 
                        '';

        // Manejo inteligente de fromMe (mensajes que salen desde el número del bot)
        if ($from_me) {
            $bot_own_phone = preg_replace('/[^0-9]/', '', $instance['phone_number'] ?? '');
            
            // 1. Si es un eco de una respuesta ya enviada por el bot -> descartar para evitar bucle
            if (function_exists('is_bot_echo_message') && is_bot_echo_message($incoming_text)) {
                log_wa_debug_event($instance_id, $sender_phone, $incoming_text, 'IGNORADO_ECO', 'Eco de mensaje enviado por el bot');
                echo json_encode(['status' => 'ignored_bot_echo']);
                exit;
            }
            
            // 2. Si el chat no es con el propio número del bot (es decir, el dueño escribió a un cliente)
            if (!empty($bot_own_phone) && $sender_phone !== $bot_own_phone) {
                if (function_exists('get_wa_session') && function_exists('save_wa_session')) {
                    $session = get_wa_session($instance_id, $sender_phone);
                    $session['human_mode'] = true;
                    save_wa_session($instance_id, $sender_phone, $session);
                }
                log_wa_debug_event($instance_id, $sender_phone, $incoming_text, 'HUMANO_INTERVINO', 'El dueño respondió al cliente desde WhatsApp. Bot pausado para este chat.');
                echo json_encode(['status' => 'human_intervention_recorded']);
                exit;
            }
            // 3. Si sender_phone === bot_own_phone y no es eco: es el dueño enviando una prueba a su propio chat
        }
    }
    
    // CASO B: Meta Cloud API
    if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
        $meta_msg = $data['entry'][0]['changes'][0]['value']['messages'][0];
        $meta_contact = $data['entry'][0]['changes'][0]['value']['contacts'][0] ?? [];
        
        $sender_phone = $meta_msg['from'] ?? '';
        $sender_name = $meta_contact['profile']['name'] ?? $sender_phone;
        
        if ($meta_msg['type'] === 'text') {
            $incoming_text = $meta_msg['text']['body'] ?? '';
        } elseif ($meta_msg['type'] === 'button') {
            $incoming_text = $meta_msg['button']['text'] ?? '';
        } elseif ($meta_msg['type'] === 'interactive') {
            $incoming_text = $meta_msg['interactive']['button_reply']['title'] ?? 
                            $meta_msg['interactive']['list_reply']['title'] ?? '';
        }
    }
    
    // CASO C: Simulador interno de prueba (JSON simple)
    if (isset($data['test_phone']) && isset($data['test_message'])) {
        $sender_phone = $data['test_phone'];
        $sender_name = 'Usuario de Prueba';
        $incoming_text = $data['test_message'];
    }
    
    if (empty($sender_phone) || empty(trim($incoming_text))) {
        echo json_encode(['status' => 'no_text_or_sender']);
        exit;
    }

    $trimmed_incoming = trim($incoming_text);

    // 1. FILTRO ANTI-ECO / ANTI-BUCLE:
    // Si este mensaje coincide con un mensaje que el bot acaba de enviar a WhatsApp
    if (function_exists('is_bot_echo_message') && is_bot_echo_message($trimmed_incoming)) {
        log_wa_debug_event($instance_id, $sender_phone, $trimmed_incoming, 'IGNORADO_ECO', 'Mensaje idéntico al emitido por el bot');
        echo json_encode(['status' => 'ignored_bot_echo']);
        exit;
    }

    // 2. FILTRO MENSAJES DE ERROR / FALLBACK:
    // Evitar que el bot responda a sus propios mensajes de error si rebotan en el webhook
    if (stripos($trimmed_incoming, 'servidores están saturados') !== false || 
        stripos($trimmed_incoming, 'nuestros servidores') !== false ||
        (!empty($instance['fallback_msg']) && stripos($trimmed_incoming, trim($instance['fallback_msg'])) !== false)) {
        log_wa_debug_event($instance_id, $sender_phone, $trimmed_incoming, 'IGNORADO_FALLBACK', 'Rebote de mensaje de fallback');
        echo json_encode(['status' => 'ignored_fallback_bounce']);
        exit;
    }

    // 3. DEBOUNCE PARA CHAT CONSIGO MISMO:
    // Evita bucle si WhatsApp reporta el mismo mensaje varias veces en ráfaga
    $anti_loop_file = __DIR__ . '/wa_loop_' . md5($instance_id . '_' . $sender_phone) . '.json';
    $now_ts = microtime(true);
    if (file_exists($anti_loop_file)) {
        $last_loop = json_decode(@file_get_contents($anti_loop_file), true);
        if (is_array($last_loop)) {
            $diff = $now_ts - ($last_loop['time'] ?? 0);
            if ($diff < 3.5 && ($last_loop['text'] ?? '') === $trimmed_incoming) {
                log_wa_debug_event($instance_id, $sender_phone, $trimmed_incoming, 'IGNORADO_DEBOUNCE', 'Mensaje repetido en ' . round($diff, 1) . 's');
                echo json_encode(['status' => 'ignored_debounce']);
                exit;
            }
        }
    }
    @file_put_contents($anti_loop_file, json_encode(['time' => $now_ts, 'text' => $trimmed_incoming]), LOCK_EX);
    
    // Guardar Lead/Contacto automáticamente
    save_lead($instance_id, $sender_name, $sender_phone, ['channel' => 'whatsapp']);
    
    // Reenviar a Webhook de Leads si está configurado en la instancia
    if (!empty($instance['webhook_url'])) {
        $ch_wh = curl_init();
        curl_setopt($ch_wh, CURLOPT_URL, trim($instance['webhook_url']));
        curl_setopt($ch_wh, CURLOPT_POST, true);
        curl_setopt($ch_wh, CURLOPT_POSTFIELDS, json_encode([
            'event' => 'whatsapp_incoming_message',
            'instance_id' => $instance_id,
            'name' => $sender_name,
            'phone' => $sender_phone,
            'message' => $incoming_text,
            'date' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE));
        curl_setopt($ch_wh, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch_wh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_wh, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch_wh, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch_wh, CURLOPT_SSL_VERIFYPEER, false);
        @curl_exec($ch_wh);
        curl_close($ch_wh);
    }
    
    // Si el Bot de este número está desactivado/pausado desde el panel
    $is_active = $instance['is_active'] ?? true;
    if (!$is_active && !isset($data['test_phone'])) {
        log_wa_debug_event($instance_id, $sender_phone, $incoming_text, 'BOT_PAUSADO', 'Bot desactivado en panel');
        echo json_encode(['status' => 'bot_paused_by_admin', 'message' => 'El bot está desactivado temporalmente para este número.']);
        exit;
    }
    
    // 2. PROCESAR MENSAJE CON EL MOTOR (Menú anidado o Gemini Multi-Key)
    $reply_text = process_wa_incoming_message($instance, $sender_phone, $incoming_text);
    
    // 3. ENVIAR RESPUESTA SI CORRESPONDE
    if (!empty($reply_text)) {
        // Si es prueba desde el simulador web, devolver en la respuesta HTTP
        if (isset($data['test_phone'])) {
            log_wa_debug_event($instance_id, $sender_phone, $incoming_text, 'SIMULADOR_OK', mb_substr($reply_text, 0, 80));
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'reply' => $reply_text]);
            exit;
        }
        
        $sent = send_wa_outgoing_message($instance, $sender_phone, $reply_text);
        log_wa_debug_event($instance_id, $sender_phone, $incoming_text, $sent ? 'ENVIADO_OK' : 'ERROR_ENVIO', mb_substr($reply_text, 0, 80));
        echo json_encode(['status' => 'processed', 'sent' => $sent, 'reply_preview' => mb_substr($reply_text, 0, 50)]);
        exit;
    }
    
    log_wa_debug_event($instance_id, $sender_phone, $incoming_text, 'SIN_RESPUESTA', 'No se generó texto de respuesta');
    echo json_encode(['status' => 'no_reply_needed']);
    exit;
}

function log_wa_debug_event($instance_id, $phone, $text, $status, $extra = '') {
    $file = __DIR__ . '/whatsapp_debug.log';
    $entry = [
        'time' => date('H:i:s'),
        'date' => date('d/m/Y'),
        'instance' => $instance_id,
        'phone' => $phone,
        'text' => mb_substr($text, 0, 70),
        'status' => $status,
        'extra' => mb_substr($extra, 0, 100)
    ];
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
