<?php
require_once 'settings_manager.php';
$id = $_GET['id'] ?? '';
$id = preg_replace('/[^a-zA-Z0-9_]/', '', $id);
$settings = get_settings();
$base_url = get_system_base_url();

if (empty($id) || !isset($settings['bots'][$id])) {
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat no encontrado</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; background:#f8fafc; color:#334155; }
        .box { text-align:center; padding:35px 25px; background:white; border-radius:16px; box-shadow:0 8px 30px rgba(0,0,0,0.06); max-width:400px; width:90%; border:1px solid #e2e8f0; }
        h2 { margin-top:0; color:#0f172a; font-size:20px; }
        p { color:#64748b; font-size:14px; line-height:1.5; margin-bottom:0; }
    </style>
</head>
<body>
    <div class="box">
        <div style="font-size:38px; margin-bottom:12px;">⚠️</div>
        <h2>Chat no encontrado</h2>
        <p>El identificador de chat proporcionado no existe o no se encuentra activo en el servidor.</p>
    </div>
</body>
</html>
    <?php
    exit;
}

$bot = $settings['bots'][$id];
$bot_name = !empty($bot['name']) ? $bot['name'] : 'Asistente IA';
$color = !empty($bot['color']) ? $bot['color'] : '#2563eb';
$color2 = !empty($bot['color2']) ? $bot['color2'] : '#9333ea';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="<?php echo htmlspecialchars($color); ?>">
    <title><?php echo htmlspecialchars($bot_name); ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23<?php echo ltrim($color, '#'); ?>'><path d='M20 2H4C2.9 2 2 2.9 2 4V22L6 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2M20 16H6.8L4 18.8V4H20V16Z'/></svg>">
    <style>
        * { box-sizing: border-box; }
        html, body { 
            margin: 0; padding: 0; width: 100%; height: 100%; 
            overflow: hidden; background: #f8fafc; font-family: system-ui, -apple-system, sans-serif; 
        }
        /* Spinner de carga inicial */
        #gemini-chat-page-loader {
            position: fixed; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: #f8fafc; z-index: 999999; color: #64748b; font-size: 14px; gap: 14px;
            transition: opacity 0.3s ease;
        }
        .gemini-loader-spinner {
            width: 38px; height: 38px; border: 3px solid #e2e8f0; border-top-color: <?php echo htmlspecialchars($color); ?>;
            border-radius: 50%; animation: gemini-spin 0.8s linear infinite;
        }
        @keyframes gemini-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div id="gemini-chat-page-loader">
        <div class="gemini-loader-spinner"></div>
        <span style="font-weight:500;">Iniciando asistente...</span>
    </div>

    <!-- Cargar Widget en modo Pantalla Completa con defer -->
    <script src="<?php echo $base_url; ?>/widget.php?id=<?php echo htmlspecialchars($id); ?>&fs=1" defer></script>
    
    <script>
        // Ocultar pantalla de carga una vez montado el chat
        (function() {
            var checkTimer = setInterval(function() {
                var chatWin = document.getElementById('gemini-chat-window');
                if (chatWin) {
                    var loader = document.getElementById('gemini-chat-page-loader');
                    if (loader) {
                        loader.style.opacity = '0';
                        setTimeout(function() { loader.remove(); }, 300);
                    }
                    clearInterval(checkTimer);
                }
            }, 60);

            // Timeout de seguridad (máximo 4 segundos de espera)
            setTimeout(function() {
                var loader = document.getElementById('gemini-chat-page-loader');
                if (loader) {
                    loader.style.opacity = '0';
                    setTimeout(function() { loader.remove(); }, 300);
                }
                clearInterval(checkTimer);
            }, 4000);
        })();
    </script>
</body>
</html>

