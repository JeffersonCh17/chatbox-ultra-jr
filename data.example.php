<?php
// Plantilla de ejemplo para data.php
// Copia este archivo como data.php en tu servidor
return [
    'bots' => [
        'bot_demo' => [
            'name' => 'Asistente Web Demo',
            'prompt' => 'Eres un asistente cordial y experto en atención al cliente.',
            'color' => '#2563eb',
            'color2' => '#9333ea',
            'btn_design' => 'classic',
            'tooltip' => '¿Tienes alguna duda? ¡Chatea con nosotros!',
            'api_key' => 'TU_GEMINI_API_KEY_AQUI',
            'require_form' => false,
            'webhook_url' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'model' => 'gemini-3.5-flash',
            'fallback_msg' => 'En este momento nuestros servidores están saturados. Por favor intenta más tarde.',
            'quick_replies' => "Quiero cotizar\nHorarios::De 8am a 5pm\n[WhatsApp](https://wa.me/)"
        ]
    ],
    'errors' => []
];
