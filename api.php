<?php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once 'settings_manager.php';
$settings = get_settings();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['bot_id'])) {
    echo json_encode(['error' => 'Datos inválidos.']);
    exit;
}

$bot_id = preg_replace('/[^a-zA-Z0-9_]/', '', $input['bot_id']);
if (!isset($settings['bots'][$bot_id])) {
    echo json_encode(['error' => 'Chat no configurado.']);
    exit;
}

$bot = $settings['bots'][$bot_id];
$session_id = trim($input['session_id'] ?? '');

// ========================================================
// 1. RECEPTOR DE LEADS Y REENVÍO A WEBHOOK / PANELES EXTERNOS
// ========================================================
if (isset($input['action']) && $input['action'] === 'save_lead') {
    $name = trim($input['name'] ?? '');
    $phone = trim($input['phone'] ?? '');
    
    if (!empty($name) || !empty($phone)) {
        $lead = save_lead($bot_id, $name, $phone);
        
        // Registrar en historial de la conversación
        if (function_exists('save_chat_interaction') && !empty($session_id)) {
            save_chat_interaction($bot_id, $session_id, "👤 Contacto: $name ($phone)", "Lead capturado", 'web', ['user_name' => $name, 'user_phone' => $phone]);
        }
        
        // Reenvío inmediato a Webhook externo (n8n, CRM, LeadsEvolution, etc.)
        if (!empty($bot['webhook_url'])) {
            $webhook_url = trim($bot['webhook_url']);
            $ch_wh = curl_init();
            curl_setopt($ch_wh, CURLOPT_URL, $webhook_url);
            curl_setopt($ch_wh, CURLOPT_POST, true);
            curl_setopt($ch_wh, CURLOPT_POSTFIELDS, json_encode([
                'event' => 'new_lead',
                'bot_id' => $bot_id,
                'bot_name' => $bot['name'] ?? '',
                'name' => $name,
                'phone' => $phone,
                'date' => $lead['date'],
                'ip' => $lead['ip'],
                'source' => 'web_chat_preform'
            ], JSON_UNESCAPED_UNICODE));
            curl_setopt($ch_wh, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch_wh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_wh, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch_wh, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch_wh, CURLOPT_SSL_VERIFYPEER, false);
            @curl_exec($ch_wh);
            curl_close($ch_wh);
        }
        
        echo json_encode(['status' => 'success', 'lead' => $lead]);
        exit;
    }
    echo json_encode(['error' => 'Datos de contacto incompletos.']);
    exit;
}

// ========================================================
// 2. CONVERSACIÓN CON GEMINI
// ========================================================
if (empty($input['message'])) {
    echo json_encode(['error' => 'Mensaje vacío.']);
    exit;
}

$api_key = $bot['api_key'] ?? '';
$model = !empty($bot['model']) ? $bot['model'] : 'gemini-3.5-flash';
$system_prompt = $bot['prompt'] ?? '';
$fallback_msg = $bot['fallback_msg'] ?? 'Error de conexión con IA.';
$user_msg = $input['message'];
$history = $input['history'] ?? [];

// MANEJO DE PDF A GOOGLE (Vencimiento de 48h)
$pdf_path = __DIR__ . '/uploads/' . $bot_id . '.pdf';
$keys_list = parse_api_keys($api_key);

if (file_exists($pdf_path) && !empty($keys_list)) {
    if (empty($bot['file_uri']) || time() >= ($bot['file_expiration'] ?? 0)) {
        foreach ($keys_list as $k_try) {
            $ch_up = curl_init();
            curl_setopt($ch_up, CURLOPT_URL, "https://generativelanguage.googleapis.com/upload/v1beta/files?uploadType=media&key=" . urlencode($k_try));
            curl_setopt($ch_up, CURLOPT_POST, true);
            curl_setopt($ch_up, CURLOPT_POSTFIELDS, file_get_contents($pdf_path));
            curl_setopt($ch_up, CURLOPT_HTTPHEADER, ['Content-Type: application/pdf']);
            curl_setopt($ch_up, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_up, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch_up, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch_up, CURLOPT_TIMEOUT, 20);
            $res_up = curl_exec($ch_up);
            curl_close($ch_up);
            $up_data = json_decode($res_up, true);
            
            if (isset($up_data['file']['uri'])) {
                $bot['file_uri'] = $up_data['file']['uri'];
                if (isset($up_data['file']['expirationTime'])) {
                    $bot['file_expiration'] = strtotime($up_data['file']['expirationTime']) - 3600;
                } else {
                    $bot['file_expiration'] = time() + 169200; 
                }
                $settings['bots'][$bot_id] = $bot;
                save_settings($settings);
                break;
            }
        }
    }
}

// CONSTRUIR HISTORIAL PARA GOOGLE
$contents = [];

// Si hay un PDF válido, lo inyectamos como el primer mensaje del historial
if (file_exists($pdf_path) && !empty($bot['file_uri'])) {
    $contents[] = [
        'role' => 'user',
        'parts' => [
            [
                'fileData' => [
                    'mimeType' => 'application/pdf',
                    'fileUri' => $bot['file_uri']
                ]
            ],
            [
                'text' => 'Por favor, lee este documento PDF. Úsalo como contexto y fuente principal de información para responder a todas mis preguntas a partir de ahora.'
            ]
        ]
    ];
    $contents[] = [
        'role' => 'model',
        'parts' => [
            [
                'text' => 'Entendido. He leído el documento y lo usaré como contexto para responder a tus consultas.'
            ]
        ]
    ];
}

foreach ($history as $msg) {
    if ($msg['role'] === 'user' || $msg['role'] === 'model') {
        $contents[] = [
            'role' => $msg['role'],
            'parts' => [['text' => $msg['text']]]
        ];
    }
}

$contents[] = [
    'role' => 'user',
    'parts' => [['text' => $user_msg]]
];

$payload = [
    'systemInstruction' => [
        'parts' => [['text' => $system_prompt]]
    ],
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.7,
        'maxOutputTokens' => 1024
    ]
];

$gemini_result = call_gemini_multikey($api_key, $model, $payload, $bot_id);

if ($gemini_result['success']) {
    $text = $gemini_result['text'];
    
    // Registrar conversación con geolocalización
    if (function_exists('save_chat_interaction')) {
        save_chat_interaction($bot_id, $session_id, $user_msg, $text, 'web');
    }
    
    // Parse Markdown links to Purple buttons
    $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank" class="gemini-btn-link">$1</a>', $text);
    // Parse Bold
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    // Parse Italic
    $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text);
    // Line breaks
    $text = nl2br($text);
    
    echo json_encode(['reply' => $text]);
    exit;
} else {
    log_bot_error($bot_id, "Todas las API Keys fallaron: " . ($gemini_result['error'] ?? 'Error desconocido'));
    
    $fallback_html = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank" class="gemini-btn-link">$1</a>', $fallback_msg);
    $fallback_html = nl2br($fallback_html);
    
    // Registrar también la interacción de fallback
    if (function_exists('save_chat_interaction')) {
        save_chat_interaction($bot_id, $session_id, $user_msg, $fallback_html, 'web');
    }
    
    echo json_encode(['reply' => $fallback_html]);
    exit;
}

