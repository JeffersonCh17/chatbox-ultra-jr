<?php
// Plantilla de ejemplo para whatsapp_data.php
// Copia este archivo como whatsapp_data.php en tu servidor
return [
    'instances' => [
        'wa_demo' => [
            'id' => 'wa_demo',
            'name' => 'Canal WhatsApp Demo',
            'phone_number' => '593900000000',
            'engine' => 'evolution', // 'evolution' o 'meta'
            'server_url' => 'https://tu-evolution-api.onrender.com',
            'server_token' => 'TU_EVOLUTION_API_KEY',
            'instance_name' => 'ventas_demo',
            'bot_mode' => 'menu_only', // 'menu_only', 'hybrid', 'gemini_only'
            'is_active' => true,
            'api_key' => 'TU_GEMINI_API_KEY_AQUI',
            'model' => 'gemini-3.5-flash',
            'prompt' => 'Eres un asistente cordial y experto en WhatsApp.',
            'welcome_msg' => "¡Hola! 👋 Bienvenido a nuestro canal de WhatsApp. ¿En qué podemos asesorarte hoy?",
            'fallback_msg' => 'En este momento nuestros servidores están saturados. Por favor escribe más tarde.',
            'nested_menu' => "[1] Servicios :: Tenemos soluciones digitales a tu medida.\n[1.1] Páginas Web :: Diseñamos sitios modernos.\n[2] Horarios :: Lunes a Viernes 8am a 5pm\n[3] Hablar con Asesor :: [HUMAN_ESCALATE]"
        ]
    ],
    'sessions' => [],
    'errors' => []
];
