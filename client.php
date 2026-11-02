<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/settings_manager.php';
$base_url = get_system_base_url();

$bot_id = isset($_GET['bot']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['bot']) : '';
if (empty($bot_id) && isset($_POST['bot_id'])) {
    $bot_id = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['bot_id']);
}

if (empty($bot_id)) {
    die("<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Error</title><style>body{font-family:sans-serif;padding:40px;text-align:center;background:#f8fafc;color:#1e293b;}</style></head><body><h2>&#9888;&#65039; Chat no especificado</h2><p>Debes acceder usando un enlace con el identificador del chat (ej: <code>client.php?bot=bot_xxxx</code>).</p></body></html>");
}

$bot = get_bot_or_instance($bot_id);
if (!$bot) {
    die("<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Error</title><style>body{font-family:sans-serif;padding:40px;text-align:center;background:#f8fafc;color:#1e293b;}</style></head><body><h2>&#10060; Chat no encontrado</h2><p>El bot solicitado no existe o fue eliminado.</p></body></html>");
}

$bot_name = $bot['name'] ?? 'Mi Asistente';
$c1 = !empty($bot['color']) ? $bot['color'] : '#2563eb';
$c2 = !empty($bot['color2']) ? $bot['color2'] : '#9333ea';
$client_pwd = trim($bot['client_password'] ?? '');

// CERRAR SESION
if (isset($_GET['logout'])) {
    unset($_SESSION['client_auth_' . $bot_id]);
    header("Location: client.php?bot=" . urlencode($bot_id));
    exit;
}

// PROCESAR FORMULARIO DE LOGIN
$login_error = '';
if (isset($_POST['login_client'])) {
    $pwd_input = trim($_POST['password'] ?? '');
    if (empty($client_pwd)) {
        $login_error = "El administrador a&uacute;n no ha configurado una clave de acceso para este chat. Contacta al administrador.";
    } elseif ($pwd_input === $client_pwd) {
        $_SESSION['client_auth_' . $bot_id] = true;
        header("Location: client.php?bot=" . urlencode($bot_id));
        exit;
    } else {
        $login_error = "Clave de acceso incorrecta. Int&eacute;ntalo nuevamente.";
    }
}

// VERIFICAR AUTENTICACION (SOLO LA SESION DEL CLIENTE ES VALIDA)
$is_authenticated = !empty($_SESSION['client_auth_' . $bot_id]);

// ACCIONES: EXPORTAR LEADS
if ($is_authenticated && isset($_GET['export_leads'])) {
    $all_leads = get_leads();
    $leads = $all_leads[$bot_id] ?? [];
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=contactos_' . $bot_id . '_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Nombre', 'Teléfono', 'Fecha', 'IP']);
    foreach ($leads as $l) {
        fputcsv($out, [$l['name'] ?? '', $l['phone'] ?? '', $l['date'] ?? '', $l['ip'] ?? '']);
    }
    fclose($out);
    exit;
}

// ACCIONES: EXPORTAR CONVERSACIONES
if ($is_authenticated && isset($_GET['export_convs'])) {
    $all_convs = get_conversations();
    $sessions = $all_convs[$bot_id] ?? [];
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=conversaciones_' . $bot_id . '_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['ID Sesión', 'Fecha Inicio', 'Última Actividad', 'País', 'Ciudad', 'IP/Teléfono', 'Nombre', 'Total Mensajes', 'Transcripción']);
    foreach ($sessions as $s_id => $c) {
        $tr = '';
        foreach (($c['messages'] ?? []) as $m) {
            $snd = ($m['sender'] === 'user') ? 'Usuario' : (($m['sender'] === 'agent') ? 'Asesor' : 'Bot');
            $tr .= "[{$m['time']} $snd]: " . str_replace(["\r", "\n"], ' ', $m['text']) . " | ";
        }
        fputcsv($out, [
            $s_id,
            $c['started_at'] ?? '',
            $c['last_activity'] ?? '',
            $c['country'] ?? '',
            $c['city'] ?? '',
            !empty($c['user_phone']) ? $c['user_phone'] : ($c['ip'] ?? ''),
            $c['user_name'] ?? '',
            count($c['messages'] ?? []),
            $tr
        ]);
    }
    fclose($out);
    exit;
}

// ALTERNAR PAUSA GLOBAL DEL BOT DESDE EL PANEL
if ($is_authenticated && isset($_GET['toggle_bot_pause'])) {
    $cur = !empty($bot['is_paused']);
    set_bot_global_pause($bot_id, !$cur);
    header("Location: client.php?bot=" . urlencode($bot_id));
    exit;
}

// CARGA DE DATOS PARA EL DASHBOARD
$active_tab = $_GET['tab'] ?? 'metrics';
$selected_sess_id = $_GET['sess'] ?? '';

$all_convs = get_conversations();
$bot_convs = $all_convs[$bot_id] ?? [];

$all_leads = get_leads();
$bot_leads = $all_leads[$bot_id] ?? [];

// CALCULAR METRICAS
$total_convs = count($bot_convs);
$total_messages = 0;
$unique_visitors = [];
$countries = [];

foreach ($bot_convs as $s_id => $sess) {
    $msgs = $sess['messages'] ?? [];
    $total_messages += count($msgs);
    
    $v_key = !empty($sess['user_phone']) ? $sess['user_phone'] : (!empty($sess['ip']) ? $sess['ip'] : $s_id);
    $unique_visitors[$v_key] = true;
    
    $c_name = !empty($sess['country']) ? $sess['country'] : 'Desconocido';
    $c_flag = !empty($sess['flag']) ? $sess['flag'] : '🌐';
    if (!isset($countries[$c_name])) {
        $countries[$c_name] = ['count' => 0, 'flag' => $c_flag, 'cities' => []];
    }
    $countries[$c_name]['count']++;
    if (!empty($sess['city']) && !in_array($sess['city'], $countries[$c_name]['cities'])) {
        $countries[$c_name]['cities'][] = $sess['city'];
    }
}

uasort($countries, function($a, $b) {
    return $b['count'] <=> $a['count'];
});

// ORDENAR CONVERSACIONES POR ULTIMA ACTIVIDAD
$conv_list = [];
foreach ($bot_convs as $s_id => $c) {
    $conv_list[] = array_merge($c, ['_session_id' => $s_id]);
}
usort($conv_list, function($a, $b) {
    return strtotime($b['last_activity'] ?? $b['started_at'] ?? '2000-01-01') <=> strtotime($a['last_activity'] ?? $a['started_at'] ?? '2000-01-01');
});

// SELECCIONAR CONVERSACION ACTIVA
$active_conv = null;
if (!empty($selected_sess_id)) {
    foreach ($conv_list as $ci) {
        if ($ci['_session_id'] === $selected_sess_id) {
            $active_conv = $ci;
            break;
        }
    }
}
if (!$active_conv && !empty($conv_list)) {
    $active_conv = $conv_list[0];
}

$is_bot_globally_paused = !empty($bot['is_paused']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control - <?php echo htmlspecialchars($bot_name); ?></title>
    <style>
        :root {
            --primary: <?php echo $c1; ?>;
            --primary-end: <?php echo $c2; ?>;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 0; }
        
        /* HEADER PRINCIPAL */
        .header {
            background: #ffffff; border-bottom: 1px solid var(--border);
            padding: 14px 24px; position: sticky; top: 0; z-index: 100;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .header-brand { display: flex; align-items: center; gap: 12px; }
        .bot-circle {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-end));
            display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; font-weight: bold;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15); flex-shrink: 0;
        }
        .header-title { font-size: 18px; font-weight: 700; color: var(--text-main); margin: 0; }
        .header-sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

        .header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        
        .pill-btn {
            display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px;
            border-radius: 8px; font-size: 12.5px; font-weight: 600; text-decoration: none;
            cursor: pointer; transition: all 0.2s; border: 1px solid transparent;
        }
        .pill-btn-pause { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
        .pill-btn-pause:hover { background: #fecaca; }
        .pill-btn-resume { background: #dcfce7; color: #166534; border-color: #86efac; }
        .pill-btn-resume:hover { background: #bbf7d0; }
        .pill-btn-sec { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
        .pill-btn-sec:hover { background: #e2e8f0; }

        /* TABS DE NAVEGACION */
        .tabs-nav {
            max-width: 1200px; margin: 20px auto 0; padding: 0 20px;
            display: flex; gap: 8px; border-bottom: 2px solid var(--border);
        }
        .tab-btn {
            padding: 12px 20px; font-size: 14px; font-weight: 700; text-decoration: none;
            color: #64748b; border-radius: 8px 8px 0 0; border: 2px solid transparent; border-bottom: none;
            display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;
        }
        .tab-btn:hover { color: var(--text-main); background: #f1f5f9; }
        .tab-btn.active {
            color: var(--primary); background: #ffffff; border-color: var(--border);
            border-bottom: 2px solid #ffffff; margin-bottom: -2px;
        }
        .tab-badge {
            font-size: 11px; padding: 2px 7px; border-radius: 10px; background: #e2e8f0; color: #475569;
        }
        .tab-btn.active .tab-badge { background: #dbeafe; color: #1e40af; }

        .main-container { max-width: 1200px; margin: 20px auto 40px; padding: 0 20px; }

        /* KPI CARDS */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .kpi-card {
            background: #ffffff; border: 1px solid var(--border); border-radius: 12px;
            padding: 20px; display: flex; align-items: center; gap: 16px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }
        .kpi-icon {
            width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center;
            justify-content: center; font-size: 24px; flex-shrink: 0;
        }
        .kpi-title { font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; }
        .kpi-val { font-size: 26px; font-weight: 800; color: #0f172a; line-height: 1.1; }
        .kpi-sub { font-size: 11.5px; color: #64748b; margin-top: 4px; }

        /* SECCIONES Y TARJETAS */
        .card {
            background: #ffffff; border: 1px solid var(--border); border-radius: 12px;
            padding: 22px; margin-bottom: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .card-title { font-size: 16px; font-weight: 700; margin: 0; color: #0f172a; }

        /* DISTRIBUCION DE PAISES */
        .country-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .country-chip {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 20px;
            padding: 6px 14px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;
        }

        /* TABLA DE LEADS */
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        th { text-align: left; padding: 12px 14px; background: #f8fafc; color: #475569; font-weight: 600; border-bottom: 1px solid var(--border); }
        td { padding: 12px 14px; border-bottom: 1px solid var(--border); color: #1e293b; }
        tr:hover td { background: #fafbfc; }

        /* SPLIT-SCREEN CHAT EXPLORER */
        .chat-explorer {
            display: grid; grid-template-columns: 360px 1fr; gap: 0;
            background: #ffffff; border: 1px solid var(--border); border-radius: 12px;
            overflow: hidden; height: 680px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }
        @media(max-width: 850px) { .chat-explorer { grid-template-columns: 1fr; height: auto; } }

        .chat-left-pane { border-right: 1px solid var(--border); display: flex; flex-direction: column; background: #fafbfc; height: 100%; }
        .chat-left-header { padding: 14px; border-bottom: 1px solid var(--border); background: #ffffff; }
        .search-input {
            width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px;
            font-size: 13px; outline: none; background: #f8fafc;
        }
        .search-input:focus { border-color: var(--primary); background: #fff; }

        .chat-list-scroll { flex: 1; overflow-y: auto; padding: 10px; display: flex; flex-direction: column; gap: 8px; }
        .conv-card {
            background: #ffffff; border: 1px solid var(--border); border-radius: 8px;
            padding: 12px 14px; cursor: pointer; text-decoration: none; color: inherit; display: block;
            transition: all 0.15s ease;
        }
        .conv-card:hover { border-color: #cbd5e1; background: #f8fafc; }
        .conv-card.active { border-color: var(--primary); background: #eff6ff; box-shadow: 0 0 0 1.5px var(--primary); }

        .chat-right-pane { display: flex; flex-direction: column; background: #f8fafc; height: 100%; }
        .chat-view-header {
            padding: 14px 20px; background: #ffffff; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
        }
        .chat-view-messages {
            flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px;
        }
        
        .bubble-row { display: flex; flex-direction: column; max-width: 80%; }
        .bubble-row.user { align-self: flex-start; }
        .bubble-row.bot { align-self: flex-start; }
        .bubble-row.agent { align-self: flex-end; }

        .bubble {
            padding: 10px 14px; border-radius: 12px; font-size: 13.5px; line-height: 1.5;
            word-break: break-word; white-space: pre-wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .bubble-row.user .bubble { background: #ffffff; color: #0f172a; border: 1px solid #e2e8f0; border-bottom-left-radius: 2px; }
        .bubble-row.bot .bubble { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; border-bottom-left-radius: 2px; }
        .bubble-row.agent .bubble { background: #16a34a; color: #ffffff; border-bottom-right-radius: 2px; }
        
        .bubble-meta { font-size: 11px; color: #94a3b8; margin-top: 3px; padding: 0 4px; }
        .bubble-row.agent .bubble-meta { text-align: right; color: #64748b; }

        .chat-reply-bar {
            padding: 12px 16px; background: #ffffff; border-top: 1px solid var(--border);
            display: flex; gap: 10px; align-items: center;
        }
        .chat-reply-input {
            flex: 1; padding: 12px 16px; border: 1px solid var(--border); border-radius: 24px;
            font-size: 14px; outline: none; background: #f8fafc;
        }
        .chat-reply-input:focus { border-color: var(--primary); background: #ffffff; }
        .chat-reply-send {
            background: #16a34a; color: white; border: none; border-radius: 24px;
            padding: 12px 20px; font-size: 14px; font-weight: 700; cursor: pointer;
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
        }
        .chat-reply-send:hover { background: #15803d; }
        .chat-reply-send:active { transform: scale(0.97); }

        /* LOGIN FORM */
        .login-card {
            max-width: 440px; margin: 80px auto; background: #ffffff; border: 1px solid var(--border);
            border-radius: 16px; padding: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); text-align: center;
        }
        .login-input {
            width: 100%; padding: 12px 14px; margin: 15px 0 20px; border: 1px solid var(--border);
            border-radius: 8px; font-size: 15px; box-sizing: border-box; text-align: center;
        }
        .login-input:focus { border-color: var(--primary); outline: none; }
        .login-btn {
            width: 100%; padding: 13px; background: linear-gradient(135deg, var(--primary), var(--primary-end));
            color: white; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer;
            transition: opacity 0.2s;
        }
        .login-btn:hover { opacity: 0.92; }
        .alert-box { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 15px; text-align: left; }
        .alert-box.err { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    </style>
</head>
<body>

<?php if (!$is_authenticated): ?>
    <!-- ========================================== -->
    <!-- PANTALLA DE ACCESO PROTEGIDO (LOGIN)       -->
    <!-- ========================================== -->
    <div class="login-card">
        <div class="bot-circle" style="margin: 0 auto 15px; width:64px; height:64px; font-size:28px;">&#128172;</div>
        <h2 style="margin:0 0 6px 0; color:#0f172a; font-size:22px;"><?php echo htmlspecialchars($bot_name); ?></h2>
        <div style="font-size:13.5px; color:#64748b; margin-bottom:20px;">Panel de Gesti&oacute;n y M&eacute;tricas para Clientes</div>

        <?php if (!empty($login_error)): ?>
            <div class="alert-box err"><?php echo $login_error; ?></div>
        <?php endif; ?>

        <?php if (empty($client_pwd)): ?>
            <div class="alert-box err" style="text-align:center;">
                &#128274; Este chat a&uacute;n no tiene una <strong>clave de acceso</strong> configurada en el panel de administraci&oacute;n. Por favor solicita al administrador que defina una clave para tu chat.
            </div>
        <?php else: ?>
            <form method="post" action="client.php?bot=<?php echo urlencode($bot_id); ?>">
                <input type="hidden" name="bot_id" value="<?php echo htmlspecialchars($bot_id); ?>">
                <label style="font-size:13px; font-weight:600; color:#475569; display:block; text-align:left;">Ingresa la clave de acceso de tu chat:</label>
                <input type="password" name="password" class="login-input" placeholder="••••••••" autofocus required>
                <button type="submit" name="login_client" class="login-btn">Entrar al Panel &#10148;</button>
            </form>
        <?php endif; ?>
    </div>

<?php else: ?>

    <!-- ========================================== -->
    <!-- PANEL PRINCIPAL DEL CLIENTE               -->
    <!-- ========================================== -->
    <header class="header">
        <div class="header-brand">
            <div class="bot-circle"><?php echo mb_substr($bot_name, 0, 1); ?></div>
            <div>
                <h1 class="header-title"><?php echo htmlspecialchars($bot_name); ?></h1>
                <div class="header-sub">Panel de Control &amp; Atenci&oacute;n en Vivo &bull; ID: <code>#<?php echo htmlspecialchars($bot_id); ?></code></div>
            </div>
        </div>

        <div class="header-actions">
            <!-- BOTON DE PAUSA GENERAL DEL BOT -->
            <?php if ($is_bot_globally_paused): ?>
                <a href="?bot=<?php echo urlencode($bot_id); ?>&toggle_bot_pause=1" class="pill-btn pill-btn-resume" title="Haz clic para que la IA responda automáticamente">
                    &#9654;&#65039; Bot Pausado (Activar IA)
                </a>
            <?php else: ?>
                <a href="?bot=<?php echo urlencode($bot_id); ?>&toggle_bot_pause=1" class="pill-btn pill-btn-pause" title="Haz clic para desactivar la IA en todos los chats">
                    &#9208;&#65039; Bot en Modo IA (Pausar IA)
                </a>
            <?php endif; ?>

            <a href="chat.php?id=<?php echo urlencode($bot_id); ?>" target="_blank" class="pill-btn pill-btn-sec">
                &#128065; Probar Chat
            </a>

            <a href="?bot=<?php echo urlencode($bot_id); ?>&logout=1" class="pill-btn pill-btn-sec" style="color:#ef4444; font-weight:700;">
                Cerrar Sesi&oacute;n &rarr;
            </a>
        </div>
    </header>

    <!-- NAVEGACION DE PESTAÑAS -->
    <div class="tabs-nav">
        <a href="?bot=<?php echo urlencode($bot_id); ?>&tab=metrics" class="tab-btn <?php echo $active_tab === 'metrics' ? 'active' : ''; ?>">
            &#128202; M&eacute;tricas del Chat
        </a>
        <a href="?bot=<?php echo urlencode($bot_id); ?>&tab=chats" class="tab-btn <?php echo $active_tab === 'chats' ? 'active' : ''; ?>">
            &#128172; Conversaciones en Vivo
            <span class="tab-badge"><?php echo $total_convs; ?></span>
        </a>
        <a href="?bot=<?php echo urlencode($bot_id); ?>&tab=leads" class="tab-btn <?php echo $active_tab === 'leads' ? 'active' : ''; ?>">
            &#128101; Contactos Capturados
            <span class="tab-badge"><?php echo count($bot_leads); ?></span>
        </a>
    </div>

    <main class="main-container">

        <!-- ==================================================== -->
        <!-- PESTAÑA 1: MÉTRICAS Y ANALÍTICAS                    -->
        <!-- ==================================================== -->
        <?php if ($active_tab === 'metrics'): ?>
            
            <?php if ($is_bot_globally_paused): ?>
                <div style="background:#fef3c7; border:1px solid #fde68a; color:#92400e; padding:12px 18px; border-radius:10px; margin-bottom:20px; font-size:13.5px; display:flex; align-items:center; gap:10px;">
                    <span style="font-size:20px;">&#9208;&#65039;</span>
                    <div>
                        <strong>Modo Humano Global Activado:</strong> La IA de Gemini est&aacute; pausada para este bot. Los visitantes no recibir&aacute;n respuestas autom&aacute;ticas para que puedas atenderlos personalmente.
                    </div>
                </div>
            <?php endif; ?>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#eff6ff; color:#2563eb;">&#128101;</div>
                    <div>
                        <div class="kpi-title">Visitantes &Uacute;nicos</div>
                        <div class="kpi-val"><?php echo count($unique_visitors); ?></div>
                        <div class="kpi-sub">Por IP y Tel&eacute;fono</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#f0fdf4; color:#16a34a;">&#128172;</div>
                    <div>
                        <div class="kpi-title">Conversaciones</div>
                        <div class="kpi-val"><?php echo $total_convs; ?></div>
                        <div class="kpi-sub">Sesiones de chat iniciadas</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#fef3c7; color:#d97706;">&#128140;</div>
                    <div>
                        <div class="kpi-title">Mensajes Totales</div>
                        <div class="kpi-val"><?php echo $total_messages; ?></div>
                        <div class="kpi-sub">Preguntas y Respuestas</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#fdf2f8; color:#db2777;">&#128203;</div>
                    <div>
                        <div class="kpi-title">Contactos (Leads)</div>
                        <div class="kpi-val"><?php echo count($bot_leads); ?></div>
                        <div class="kpi-sub">Formularios completados</div>
                    </div>
                </div>
            </div>

            <!-- DISTRIBUCION DE PAISES -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">&#127757; Procedencia Geogr&aacute;fica de tus Clientes</h3>
                    <span style="font-size:12px; color:#64748b;"><?php echo count($countries); ?> Pa&iacute;ses Detectados</span>
                </div>
                <?php if (empty($countries)): ?>
                    <div style="color:#94a3b8; font-size:13.5px; font-style:italic;">A&uacute;n no hay visitas registradas para este chat.</div>
                <?php else: ?>
                    <div class="country-chips">
                        <?php foreach ($countries as $c_name => $c_data): 
                            $pct = $total_convs > 0 ? round(($c_data['count'] / $total_convs) * 100) : 0;
                            $cities_str = !empty($c_data['cities']) ? ' • ' . implode(', ', array_slice($c_data['cities'], 0, 3)) : '';
                        ?>
                            <div class="country-chip">
                                <span style="font-size:18px;"><?php echo $c_data['flag']; ?></span>
                                <span><?php echo htmlspecialchars($c_name); ?><?php echo htmlspecialchars($cities_str); ?>:</span>
                                <strong style="color:#0f172a;"><?php echo $c_data['count']; ?></strong>
                                <span style="color:#64748b; font-size:11px;">(<?php echo $pct; ?>%)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ULTIMOS LEADS CAPTURADOS -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">&#128101; &Uacute;ltimos Contactos Registrados</h3>
                    <a href="?bot=<?php echo urlencode($bot_id); ?>&export_leads=1" class="pill-btn pill-btn-sec" style="font-size:12px;">
                        &#128229; Descargar CSV
                    </a>
                </div>
                <?php if (empty($bot_leads)): ?>
                    <div style="color:#94a3b8; font-size:13.5px; font-style:italic;">No hay contactos registrados todav&iacute;a.</div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Tel&eacute;fono / WhatsApp</th>
                                    <th>Fecha</th>
                                    <th>Direcci&oacute;n IP</th>
                                    <th>Acci&oacute;n</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice(array_reverse($bot_leads), 0, 8) as $lead): 
                                    $clean_tel = preg_replace('/[^0-9]/', '', $lead['phone'] ?? '');
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($lead['name'] ?? 'Anónimo'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($lead['phone'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($lead['date'] ?? '-'); ?></td>
                                        <td><code><?php echo htmlspecialchars($lead['ip'] ?? '-'); ?></code></td>
                                        <td>
                                            <?php if (!empty($clean_tel)): ?>
                                                <a href="https://wa.me/<?php echo $clean_tel; ?>" target="_blank" class="pill-btn pill-btn-resume" style="font-size:11.5px; padding:4px 10px;">
                                                    &#128241; Escribir por WhatsApp
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; // Fin if metrics ?>

        <!-- ==================================================== -->
        <!-- PESTAÑA 2: CHAT EN VIVO Y CONTROL HUMANO            -->
        <!-- ==================================================== -->
        <?php if ($active_tab === 'chats'): ?>
            <div class="chat-explorer">
                
                <!-- COLUMNA IZQUIERDA: LISTA DE SESIONES -->
                <div class="chat-left-pane">
                    <div class="chat-left-header">
                        <input type="text" id="clientConvSearch" class="search-input" placeholder="&#128269; Buscar por nombre, tel&eacute;fono, pa&iacute;s..." onkeyup="filterClientConversations()">
                    </div>

                    <div class="chat-list-scroll" id="clientConvList">
                        <?php if (empty($conv_list)): ?>
                            <div style="text-align:center; padding:30px 15px; color:#94a3b8; font-size:13px;">
                                No hay conversaciones iniciadas a&uacute;n.
                            </div>
                        <?php else: ?>
                            <?php foreach ($conv_list as $ci): 
                                $is_active = ($active_conv && $active_conv['_session_id'] === $ci['_session_id']);
                                $c_flag = !empty($ci['flag']) ? $ci['flag'] : '🌐';
                                $c_country = !empty($ci['country']) ? $ci['country'] : 'Desconocido';
                                $c_city = !empty($ci['city']) ? $ci['city'] : '';
                                $c_ip = $ci['ip'] ?? '';
                                $c_phone = $ci['user_phone'] ?? '';
                                $c_name = $ci['user_name'] ?? '';
                                $c_msgs = $ci['messages'] ?? [];
                                $last_msg = !empty($c_msgs) ? end($c_msgs)['text'] : 'Sin mensajes';
                                $last_msg_short = mb_strimwidth($last_msg, 0, 50, '...');
                                $last_time = date('d/m H:i', strtotime($ci['last_activity'] ?? $ci['started_at']));
                                $is_paused_sess = !empty($ci['is_paused']) || $is_bot_globally_paused;
                                
                                $title_disp = !empty($c_name) ? htmlspecialchars($c_name) : (!empty($c_phone) ? htmlspecialchars($c_phone) : (!empty($c_ip) ? htmlspecialchars($c_ip) : 'Visitante'));
                                $kw = strtolower($c_name . ' ' . $c_phone . ' ' . $c_ip . ' ' . $c_country . ' ' . $c_city . ' ' . $last_msg);
                            ?>
                                <a href="?bot=<?php echo urlencode($bot_id); ?>&tab=chats&sess=<?php echo urlencode($ci['_session_id']); ?>" 
                                   class="conv-card <?php echo $is_active ? 'active' : ''; ?>"
                                   data-kw="<?php echo htmlspecialchars($kw); ?>"
                                   id="conv_card_<?php echo htmlspecialchars($ci['_session_id']); ?>">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <span style="font-size:15px;"><?php echo $c_flag; ?></span>
                                            <strong style="font-size:12.5px; color:#0f172a;"><?php echo htmlspecialchars($c_country); ?></strong>
                                        </div>
                                        <span style="font-size:11px; color:#94a3b8;"><?php echo $last_time; ?></span>
                                    </div>

                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                        <div style="font-size:13px; font-weight:700; color:#1e293b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:200px;">
                                            <?php echo $title_disp; ?>
                                        </div>
                                        <span style="font-size:10.5px; padding:1px 6px; border-radius:8px; font-weight:700; background:<?php echo $is_paused_sess ? '#fee2e2' : '#dcfce7'; ?>; color:<?php echo $is_paused_sess ? '#991b1b' : '#166534'; ?>;">
                                            <?php echo $is_paused_sess ? '&#9208;&#65039; Humano' : '&#9989; IA'; ?>
                                        </span>
                                    </div>

                                    <div style="font-size:12px; color:#64748b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" id="conv_preview_<?php echo htmlspecialchars($ci['_session_id']); ?>">
                                        <?php echo htmlspecialchars($last_msg_short); ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- COLUMNA DERECHA: VISOR Y RESPUESTA HUMANA EN VIVO -->
                <div class="chat-right-pane">
                    <?php if ($active_conv): 
                        $ac_flag = !empty($active_conv['flag']) ? $active_conv['flag'] : '🌐';
                        $ac_country = !empty($active_conv['country']) ? $active_conv['country'] : 'Desconocido';
                        $ac_city = !empty($active_conv['city']) ? $active_conv['city'] : '';
                        $ac_ip = $active_conv['ip'] ?? '';
                        $ac_phone = $active_conv['user_phone'] ?? '';
                        $ac_name = $active_conv['user_name'] ?? '';
                        $ac_msgs = $active_conv['messages'] ?? [];
                        $ac_sess_id = $active_conv['_session_id'];
                        $is_paused_this = is_session_or_bot_paused($bot_id, $ac_sess_id);
                    ?>
                        <!-- HEADER DEL CHAT ACTIVO -->
                        <div class="chat-view-header">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div style="font-size:30px;"><?php echo $ac_flag; ?></div>
                                <div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <h3 style="margin:0; font-size:16px; color:#0f172a;">
                                            <?php echo !empty($ac_name) ? htmlspecialchars($ac_name) : (!empty($ac_phone) ? htmlspecialchars($ac_phone) : 'Visitante'); ?>
                                        </h3>
                                        <span id="sessionStatusBadge" style="font-size:11px; padding:2px 8px; border-radius:10px; font-weight:700; background:<?php echo $is_paused_this ? '#fee2e2' : '#dcfce7'; ?>; color:<?php echo $is_paused_this ? '#991b1b' : '#166534'; ?>;">
                                            <?php echo $is_paused_this ? '&#9208;&#65039; Atenci&oacute;n Humana (IA Pausada)' : '&#9989; IA en Control'; ?>
                                        </span>
                                    </div>
                                    <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                        📍 <strong><?php echo htmlspecialchars($ac_country); ?></strong> <?php echo !empty($ac_city) ? '• ' . htmlspecialchars($ac_city) : ''; ?>
                                        <?php if (!empty($ac_phone)): ?> • Tel: <strong><?php echo htmlspecialchars($ac_phone); ?></strong><?php endif; ?>
                                        <?php if (!empty($ac_ip)): ?> • IP: <code><?php echo htmlspecialchars($ac_ip); ?></code><?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- BOTON DE PAUSA DE LA SESION -->
                            <div>
                                <button type="button" id="togglePauseBtn" onclick="toggleCurrentSessionPause()" class="pill-btn <?php echo $is_paused_this ? 'pill-btn-resume' : 'pill-btn-pause'; ?>">
                                    <?php echo $is_paused_this ? '&#9654;&#65039; Reanudar IA (Bot Autom&aacute;tico)' : '&#9208;&#65039; Pausar IA (Tomar Control)'; ?>
                                </button>
                            </div>
                        </div>

                        <!-- BANNER INFORMATIVO SEGUN MODO -->
                        <div id="pauseNoticeBanner" style="display:<?php echo $is_paused_this ? 'flex' : 'none'; ?>; background:#fef3c7; border-bottom:1px solid #fde68a; color:#92400e; padding:8px 16px; font-size:12.5px; align-items:center; gap:8px;">
                            <span>&#9208;&#65039;</span>
                            <span><strong>La IA est&aacute; pausada para este visitante.</strong> Escribe abajo para responder t&uacute; mismo en tiempo real.</span>
                        </div>

                        <!-- BURBUJAS DE MENSAJES -->
                        <div class="chat-view-messages" id="clientMessagesContainer">
                            <?php foreach ($ac_msgs as $m): 
                                $snd = $m['sender'] ?? 'user';
                                $m_time = $m['time'] ?? '';
                            ?>
                                <div class="bubble-row <?php echo $snd; ?>">
                                    <div class="bubble">
                                        <?php echo nl2br(htmlspecialchars($m['text'])); ?>
                                    </div>
                                    <div class="bubble-meta">
                                        <?php 
                                            if ($snd === 'agent') echo '&#128100; T&uacute; (Asesor Humano)';
                                            elseif ($snd === 'bot') echo '&#129302; Bot IA';
                                            else echo 'Visitante';
                                        ?> • <?php echo htmlspecialchars($m_time); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- BARRA DE RESPUESTA HUMANA -->
                        <form id="agentReplyForm" class="chat-reply-bar" onsubmit="sendAgentReply(event)">
                            <input type="text" id="agentInputText" class="chat-reply-input" placeholder="Escribe tu mensaje para responder al visitante en vivo..." autocomplete="off">
                            <button type="submit" class="chat-reply-send">
                                <span>Enviar</span>
                                <span>&#10148;</span>
                            </button>
                        </form>

                    <?php else: ?>
                        <div style="margin:auto; text-align:center; color:#94a3b8; padding:30px;">
                            Selecciona una conversaci&oacute;n a la izquierda para ver el di&aacute;logo y responder.
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        <?php endif; // Fin if chats ?>

        <!-- ==================================================== -->
        <!-- PESTAÑA 3: LEADS / CONTACTOS COMPLETOS              -->
        <!-- ==================================================== -->
        <?php if ($active_tab === 'leads'): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">&#128101; Listado de Contactos Capturados (<?php echo count($bot_leads); ?>)</h3>
                    <a href="?bot=<?php echo urlencode($bot_id); ?>&export_leads=1" class="pill-btn pill-btn-sec">
                        &#128229; Descargar Todos en CSV
                    </a>
                </div>

                <?php if (empty($bot_leads)): ?>
                    <div style="text-align:center; padding:40px; color:#94a3b8;">
                        No hay contactos capturados todav&iacute;a con el formulario previo.
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Nombre del Cliente</th>
                                    <th>N&uacute;mero de WhatsApp</th>
                                    <th>Fecha y Hora</th>
                                    <th>Direcci&oacute;n IP</th>
                                    <th>Acci&oacute;n Directa</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $idx = count($bot_leads);
                                foreach (array_reverse($bot_leads) as $l): 
                                    $raw_p = preg_replace('/[^0-9]/', '', $l['phone'] ?? '');
                                ?>
                                    <tr>
                                        <td style="color:#94a3b8;"><?php echo $idx--; ?></td>
                                        <td><strong style="color:#0f172a;"><?php echo htmlspecialchars($l['name'] ?? 'Sin nombre'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($l['phone'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($l['date'] ?? '-'); ?></td>
                                        <td><code><?php echo htmlspecialchars($l['ip'] ?? '-'); ?></code></td>
                                        <td>
                                            <?php if (!empty($raw_p)): ?>
                                                <a href="https://wa.me/<?php echo $raw_p; ?>?text=<?php echo urlencode("Hola " . ($l['name'] ?? '') . ", te escribo respecto a tu consulta en nuestro sitio web."); ?>" target="_blank" class="pill-btn pill-btn-resume" style="padding:4px 10px; font-size:12px;">
                                                    &#128241; Abrir en WhatsApp
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- SCRIPTS EN TIEMPO REAL: POLLING, ENVIO Y PAUSA -->
    <script>
        const BOT_ID = <?php echo json_encode($bot_id); ?>;
        const ACTIVE_SESS = <?php echo json_encode($active_conv ? $active_conv['_session_id'] : ''); ?>;
        let isSessionPaused = <?php echo ($active_conv && is_session_or_bot_paused($bot_id, $active_conv['_session_id'])) ? 'true' : 'false'; ?>;
        let lastMsgCount = <?php echo ($active_conv ? count($active_conv['messages'] ?? []) : 0); ?>;

        function filterClientConversations() {
            const q = document.getElementById('clientConvSearch').value.trim().toLowerCase();
            const cards = document.querySelectorAll('.conv-card');
            cards.forEach(c => {
                const kw = c.getAttribute('data-kw') || '';
                if (!q || kw.includes(q)) {
                    c.style.display = 'block';
                } else {
                    c.style.display = 'none';
                }
            });
        }

        // Auto-scroll al final de mensajes
        function scrollToBottom() {
            const container = document.getElementById('clientMessagesContainer');
            if (container) container.scrollTop = container.scrollHeight;
        }
        window.addEventListener('DOMContentLoaded', scrollToBottom);

        // Sonido suave de notificacion (Web Audio API nativo sin archivos externos)
        function playChimeNotice() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(587.33, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.15);
                gain.gain.setValueAtTime(0.2, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
                osc.start();
                osc.stop(ctx.currentTime + 0.35);
            } catch(e) {}
        }

        // ALTERNAR PAUSA DE LA SESION EN VIVO VIA AJAX
        async function toggleCurrentSessionPause() {
            if (!ACTIVE_SESS) return;
            const btn = document.getElementById('togglePauseBtn');
            const badge = document.getElementById('sessionStatusBadge');
            const banner = document.getElementById('pauseNoticeBanner');

            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'toggle_pause',
                        bot_id: BOT_ID,
                        session_id: ACTIVE_SESS,
                        scope: 'session'
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    isSessionPaused = data.is_paused;
                    if (isSessionPaused) {
                        btn.className = 'pill-btn pill-btn-resume';
                        btn.innerHTML = '&#9654;&#65039; Reanudar IA (Bot Autom&aacute;tico)';
                        if (badge) {
                            badge.style.background = '#fee2e2';
                            badge.style.color = '#991b1b';
                            badge.innerHTML = '&#9208;&#65039; Atenci&oacute;n Humana (IA Pausada)';
                        }
                        if (banner) banner.style.display = 'flex';
                    } else {
                        btn.className = 'pill-btn pill-btn-pause';
                        btn.innerHTML = '&#9208;&#65039; Pausar IA (Tomar Control)';
                        if (badge) {
                            badge.style.background = '#dcfce7';
                            badge.style.color = '#166534';
                            badge.innerHTML = '&#9989; IA en Control';
                        }
                        if (banner) banner.style.display = 'none';
                    }
                }
            } catch(e) {
                alert('No se pudo modificar el estado de pausa.');
            }
        }

        // ENVIAR RESPUESTA HUMANA VIA AJAX
        async function sendAgentReply(e) {
            e.preventDefault();
            const input = document.getElementById('agentInputText');
            const text = input.value.trim();
            if (!text || !ACTIVE_SESS) return;

            input.value = '';

            // Renderizar inmediatamente la burbuja local
            const container = document.getElementById('clientMessagesContainer');
            const bubbleRow = document.createElement('div');
            bubbleRow.className = 'bubble-row agent';
            bubbleRow.innerHTML = `
                <div class="bubble">${text.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')}</div>
                <div class="bubble-meta">&#128100; T&uacute; (Asesor Humano) &bull; Justo ahora</div>
            `;
            container.appendChild(bubbleRow);
            scrollToBottom();

            // Actualizar vista previa en la tarjeta izquierda
            const prev = document.getElementById('conv_preview_' + ACTIVE_SESS);
            if (prev) prev.innerText = text;

            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'send_agent_message',
                        bot_id: BOT_ID,
                        session_id: ACTIVE_SESS,
                        message: text
                    })
                });
                const data = await res.json();
                
                // Si la conversacion no estaba pausada, pausarla automaticamente para que el humano tenga el control
                if (!isSessionPaused) {
                    isSessionPaused = true;
                    const btn = document.getElementById('togglePauseBtn');
                    const badge = document.getElementById('sessionStatusBadge');
                    const banner = document.getElementById('pauseNoticeBanner');
                    if (btn) {
                        btn.className = 'pill-btn pill-btn-resume';
                        btn.innerHTML = '&#9654;&#65039; Reanudar IA (Bot Autom&aacute;tico)';
                    }
                    if (badge) {
                        badge.style.background = '#fee2e2';
                        badge.style.color = '#991b1b';
                        badge.innerHTML = '&#9208;&#65039; Atenci&oacute;n Humana (IA Pausada)';
                    }
                    if (banner) banner.style.display = 'flex';
                }
                lastMsgCount++;
            } catch(e) {
                console.error('Error enviando respuesta humana:', e);
            }
        }

        // POLLING EN SEGUNDO PLANO (CADA 2.5 SEGUNDOS) PARA VER MENSAJES DEL VISITANTE EN TIEMPO REAL
        if (ACTIVE_SESS) {
            setInterval(async () => {
                try {
                    const res = await fetch('api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'poll',
                            bot_id: BOT_ID,
                            session_id: ACTIVE_SESS,
                            last_count: lastMsgCount
                        })
                    });
                    const data = await res.json();
                    
                    if (data.new_messages && data.new_messages.length > 0) {
                        const container = document.getElementById('clientMessagesContainer');
                        let hasNewVisitorMsg = false;

                        data.new_messages.forEach(m => {
                            if (m.sender === 'agent') return;

                            const bubbleRow = document.createElement('div');
                            bubbleRow.className = 'bubble-row ' + m.sender;
                            let senderLabel = (m.sender === 'bot') ? '&#129302; Bot IA' : 'Visitante';
                            bubbleRow.innerHTML = `
                                <div class="bubble">${m.text.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')}</div>
                                <div class="bubble-meta">${senderLabel} &bull; ${m.time || ''}</div>
                            `;
                            container.appendChild(bubbleRow);
                            if (m.sender === 'user') hasNewVisitorMsg = true;
                        });

                        lastMsgCount = data.total_messages;
                        scrollToBottom();

                        if (hasNewVisitorMsg) {
                            playChimeNotice();
                        }
                    }
                } catch(e) {}
            }, 2500);
        }
    </script>
<?php endif; ?>
</body>
</html>
