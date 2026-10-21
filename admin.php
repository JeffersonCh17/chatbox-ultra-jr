<?php
session_start();
require_once 'settings_manager.php';
require_once __DIR__ . '/whatsapp_engine.php';
$password = '283febrero'; 

if (isset($_POST['login'])) {
    if ($_POST['password'] === $password) {
        $_SESSION['logged_in'] = true;
    } else {
        $error = "Contraseña incorrecta.";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

$settings = get_settings();
if (!isset($settings['bots'])) { $settings['bots'] = []; }
if (!isset($settings['errors'])) { $settings['errors'] = []; }
$leads = get_leads();
$success = '';

if (!is_dir(__DIR__ . '/uploads')) {
    mkdir(__DIR__ . '/uploads', 0755, true);
}

// Limpiar Errores
if (isset($_SESSION['logged_in']) && isset($_GET['clear_errors'])) {
    $id = $_GET['clear_errors'];
    if (isset($settings['errors'][$id])) {
        unset($settings['errors'][$id]);
        save_settings($settings);
        header("Location: admin.php?opened=" . urlencode($id));
        exit;
    }
}

// Limpiar Leads de un Bot
if (isset($_SESSION['logged_in']) && isset($_GET['clear_leads'])) {
    $id = $_GET['clear_leads'];
    clear_bot_leads($id);
    header("Location: admin.php?opened=" . urlencode($id));
    exit;
}

// Exportar Leads a CSV
if (isset($_SESSION['logged_in']) && isset($_GET['export_leads'])) {
    $id = $_GET['export_leads'];
    $bot_leads = $leads[$id] ?? [];
    $bot_name = $settings['bots'][$id]['name'] ?? $id;
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leads_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $bot_name) . '_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');
    // BOM UTF-8 para Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['ID', 'Fecha y Hora', 'Nombre', 'Teléfono', 'IP']);
    foreach ($bot_leads as $l) {
        fputcsv($output, [$l['id'] ?? '', $l['date'] ?? '', $l['name'] ?? '', $l['phone'] ?? '', $l['ip'] ?? '']);
    }
    fclose($output);
    exit;
}

// Crear Bot
if (isset($_SESSION['logged_in']) && isset($_POST['create_bot'])) {
    $bot_id = 'bot_' . substr(md5(uniqid()), 0, 8);
    $settings['bots'][$bot_id] = [
        'name' => trim($_POST['bot_name']),
        'prompt' => trim($_POST['bot_prompt']),
        'color' => !empty($_POST['bot_color']) ? $_POST['bot_color'] : '#2563eb',
        'color2' => !empty($_POST['bot_color2']) ? $_POST['bot_color2'] : '#9333ea',
        'btn_design' => $_POST['btn_design'] ?? 'classic',
        'tooltip' => trim($_POST['bot_tooltip'] ?? ''),
        'api_key' => trim($_POST['bot_api_key']),
        'require_form' => isset($_POST['require_form']) ? true : false,
        'webhook_url' => trim($_POST['webhook_url'] ?? ''),
        'created_at' => date('Y-m-d H:i:s'),
        'model' => trim($_POST['bot_model']),
        'fallback_msg' => trim($_POST['fallback_msg']),
        'quick_replies' => trim($_POST['quick_replies'])
    ];
    
    if (isset($_FILES['bot_pdf']) && $_FILES['bot_pdf']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['bot_pdf']['tmp_name'];
        if (mime_content_type($tmp_name) === 'application/pdf') {
            move_uploaded_file($tmp_name, __DIR__ . '/uploads/' . $bot_id . '.pdf');
        }
    }
    
    save_settings($settings);
    header("Location: admin.php?success=created&opened=" . urlencode($bot_id));
    exit;
}

// Editar Bot
if (isset($_SESSION['logged_in']) && isset($_POST['update_bot'])) {
    $id = $_POST['bot_id'];
    if (isset($settings['bots'][$id])) {
        $settings['bots'][$id]['name'] = trim($_POST['bot_name']);
        $settings['bots'][$id]['prompt'] = trim($_POST['bot_prompt']);
        $settings['bots'][$id]['color'] = !empty($_POST['bot_color']) ? $_POST['bot_color'] : '#2563eb';
        $settings['bots'][$id]['color2'] = !empty($_POST['bot_color2']) ? $_POST['bot_color2'] : '#9333ea';
        $settings['bots'][$id]['btn_design'] = $_POST['btn_design'] ?? 'classic';
        $settings['bots'][$id]['tooltip'] = trim($_POST['bot_tooltip'] ?? '');
        $settings['bots'][$id]['api_key'] = trim($_POST['bot_api_key']);
        $settings['bots'][$id]['require_form'] = isset($_POST['require_form']) ? true : false;
        $settings['bots'][$id]['webhook_url'] = trim($_POST['webhook_url'] ?? '');
        $settings['bots'][$id]['model'] = trim($_POST['bot_model']);
        $settings['bots'][$id]['fallback_msg'] = trim($_POST['fallback_msg']);
        $settings['bots'][$id]['quick_replies'] = trim($_POST['quick_replies']);
        
        if (empty($settings['bots'][$id]['created_at'])) {
            $settings['bots'][$id]['created_at'] = date('Y-m-d H:i:s');
        }
        
        // Manejo de PDF
        if (isset($_POST['delete_pdf']) && $_POST['delete_pdf'] == '1') {
            if (file_exists(__DIR__ . '/uploads/' . $id . '.pdf')) unlink(__DIR__ . '/uploads/' . $id . '.pdf');
            unset($settings['bots'][$id]['file_uri']);
            unset($settings['bots'][$id]['file_expiration']);
        } elseif (isset($_FILES['bot_pdf']) && $_FILES['bot_pdf']['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['bot_pdf']['tmp_name'];
            if (mime_content_type($tmp_name) === 'application/pdf') {
                move_uploaded_file($tmp_name, __DIR__ . '/uploads/' . $id . '.pdf');
                unset($settings['bots'][$id]['file_uri']);
                unset($settings['bots'][$id]['file_expiration']);
            }
        }
        
        save_settings($settings);
        header("Location: admin.php?success=updated&opened=" . urlencode($id));
        exit;
    }
}

// Eliminar Bot
if (isset($_SESSION['logged_in']) && isset($_GET['delete_bot'])) {
    $id = $_GET['delete_bot'];
    if (isset($settings['bots'][$id])) {
        unset($settings['bots'][$id]);
        unset($settings['errors'][$id]); 
        clear_bot_leads($id);
        if (file_exists(__DIR__ . '/uploads/' . $id . '.pdf')) unlink(__DIR__ . '/uploads/' . $id . '.pdf');
        save_settings($settings);
        header("Location: admin.php");
        exit;
    }
}

$wa_settings = get_wa_settings();
if (!isset($wa_settings['instances'])) { $wa_settings['instances'] = []; }
if (!isset($wa_settings['errors'])) { $wa_settings['errors'] = []; }

// Limpiar Errores WhatsApp
if (isset($_SESSION['logged_in']) && isset($_GET['clear_wa_errors'])) {
    $id = $_GET['clear_wa_errors'];
    if (isset($wa_settings['errors'][$id])) {
        unset($wa_settings['errors'][$id]);
        save_wa_settings($wa_settings);
        header("Location: admin.php?tab=whatsapp&opened_wa=" . urlencode($id));
        exit;
    }
}

// Crear Instancia WhatsApp
if (isset($_SESSION['logged_in']) && isset($_POST['create_wa_instance'])) {
    $wa_id = 'wa_' . substr(md5(uniqid()), 0, 8);
    $wa_settings['instances'][$wa_id] = [
        'id' => $wa_id,
        'name' => trim($_POST['wa_name']),
        'phone_number' => trim($_POST['wa_phone_number'] ?? ''),
        'engine' => $_POST['wa_engine'] ?? 'evolution',
        // Conexión Evolution API
        'server_url' => trim($_POST['wa_server_url'] ?? ''),
        'server_token' => trim($_POST['wa_server_token'] ?? ''),
        'instance_name' => trim($_POST['wa_instance_name'] ?? $wa_id),
        // Conexión Meta Cloud API
        'meta_phone_id' => trim($_POST['meta_phone_id'] ?? ''),
        'meta_token' => trim($_POST['meta_token'] ?? ''),
        'meta_verify_token' => trim($_POST['meta_verify_token'] ?? 'gemini_wa_' . substr(md5(uniqid()), 0, 6)),
        // Modo de respuesta y Lógica
        'bot_mode' => $_POST['wa_bot_mode'] ?? 'hybrid',
        'welcome_msg' => trim($_POST['wa_welcome_msg']),
        'nested_menu' => trim($_POST['wa_nested_menu']),
        // Gemini
        'api_key' => trim($_POST['wa_api_key']),
        'model' => trim($_POST['wa_model'] ?? 'gemini-3.0-flash'),
        'prompt' => trim($_POST['wa_prompt']),
        'fallback_msg' => trim($_POST['wa_fallback_msg']),
        'webhook_url' => trim($_POST['wa_webhook_url'] ?? ''),
        'is_active' => true,
        'created_at' => date('Y-m-d H:i:s')
    ];
    save_wa_settings($wa_settings);
    header("Location: admin.php?tab=whatsapp&success=wa_created&opened_wa=" . urlencode($wa_id));
    exit;
}

// Editar Instancia WhatsApp
if (isset($_SESSION['logged_in']) && isset($_POST['update_wa_instance'])) {
    $wa_id = $_POST['wa_id'];
    if (isset($wa_settings['instances'][$wa_id])) {
        $wa_settings['instances'][$wa_id]['name'] = trim($_POST['wa_name']);
        $wa_settings['instances'][$wa_id]['phone_number'] = trim($_POST['wa_phone_number'] ?? '');
        $wa_settings['instances'][$wa_id]['engine'] = $_POST['wa_engine'] ?? 'evolution';
        $wa_settings['instances'][$wa_id]['server_url'] = trim($_POST['wa_server_url'] ?? '');
        $wa_settings['instances'][$wa_id]['server_token'] = trim($_POST['wa_server_token'] ?? '');
        $wa_settings['instances'][$wa_id]['instance_name'] = trim($_POST['wa_instance_name'] ?? $wa_id);
        $wa_settings['instances'][$wa_id]['meta_phone_id'] = trim($_POST['meta_phone_id'] ?? '');
        $wa_settings['instances'][$wa_id]['meta_token'] = trim($_POST['meta_token'] ?? '');
        $wa_settings['instances'][$wa_id]['meta_verify_token'] = trim($_POST['meta_verify_token'] ?? 'gemini_wa_secret');
        $wa_settings['instances'][$wa_id]['bot_mode'] = $_POST['wa_bot_mode'] ?? 'hybrid';
        $wa_settings['instances'][$wa_id]['welcome_msg'] = trim($_POST['wa_welcome_msg']);
        $wa_settings['instances'][$wa_id]['nested_menu'] = trim($_POST['wa_nested_menu']);
        $wa_settings['instances'][$wa_id]['api_key'] = trim($_POST['wa_api_key']);
        $wa_settings['instances'][$wa_id]['model'] = trim($_POST['wa_model'] ?? 'gemini-3.0-flash');
        $wa_settings['instances'][$wa_id]['prompt'] = trim($_POST['wa_prompt']);
        $wa_settings['instances'][$wa_id]['fallback_msg'] = trim($_POST['wa_fallback_msg']);
        $wa_settings['instances'][$wa_id]['webhook_url'] = trim($_POST['wa_webhook_url'] ?? '');
        $wa_settings['instances'][$wa_id]['is_active'] = isset($_POST['wa_is_active']);
        
        save_wa_settings($wa_settings);
        header("Location: admin.php?tab=whatsapp&success=wa_updated&opened_wa=" . urlencode($wa_id));
        exit;
    }
}

// Alternar Bot Activo / Pausado para una Instancia de WhatsApp
if (isset($_SESSION['logged_in']) && isset($_GET['toggle_wa_active'])) {
    $wa_id = $_GET['toggle_wa_active'];
    if (isset($wa_settings['instances'][$wa_id])) {
        $cur = $wa_settings['instances'][$wa_id]['is_active'] ?? true;
        $wa_settings['instances'][$wa_id]['is_active'] = !$cur;
        save_wa_settings($wa_settings);
        header("Location: admin.php?tab=whatsapp&opened_wa=" . urlencode($wa_id));
        exit;
    }
}

// Alternar Modo Humano (Pausar/Reanudar IA) para un contacto específico
if (isset($_SESSION['logged_in']) && isset($_GET['toggle_wa_contact']) && isset($_GET['phone'])) {
    $wa_id = $_GET['toggle_wa_contact'];
    $phone = $_GET['phone'];
    require_once __DIR__ . '/whatsapp_engine.php';
    toggle_wa_contact_human_mode($wa_id, $phone);
    header("Location: admin.php?tab=whatsapp&opened_wa=" . urlencode($wa_id));
    exit;
}

// Eliminar Instancia WhatsApp
if (isset($_SESSION['logged_in']) && isset($_GET['delete_wa'])) {
    $wa_id = $_GET['delete_wa'];
    if (isset($wa_settings['instances'][$wa_id])) {
        unset($wa_settings['instances'][$wa_id]);
        unset($wa_settings['errors'][$wa_id]);
        unset($wa_settings['sessions'][$wa_id]);
        clear_bot_leads($wa_id);
        save_wa_settings($wa_settings);
        header("Location: admin.php?tab=whatsapp");
        exit;
    }
}

$active_tab = $_GET['tab'] ?? 'web';
$opened_bot_id = $_GET['opened'] ?? '';
$opened_wa_id = $_GET['opened_wa'] ?? '';
$action_success = $_GET['success'] ?? '';
if ($action_success === 'created') $success = "¡Chat Web creado exitosamente!";
if ($action_success === 'updated') $success = "¡Configuración de Chat Web actualizada!";
if ($action_success === 'wa_created') $success = "¡Número / Instancia de WhatsApp creada exitosamente!";
if ($action_success === 'wa_updated') $success = "¡Configuración de WhatsApp actualizada exitosamente!";

$base_url = get_system_base_url();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Chats Gemini</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 1fr; gap: 25px; }
        @media(min-width: 900px) { .container { grid-template-columns: 380px 1fr; } }
        
        .card { background: var(--card-bg); padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid var(--border); }
        .card h2, .card h3 { margin-top: 0; color: #0f172a; }
        
        input[type="text"], input[type="password"], textarea, select, input[type="file"] { 
            width: 100%; padding: 10px 12px; margin: 6px 0 14px; 
            border: 1px solid var(--border); border-radius: 6px; box-sizing: border-box; 
            font-size: 14px; background: #fff; transition: border-color 0.2s;
        }
        input:focus, textarea:focus, select:focus { outline: none; border-color: var(--primary); }
        
        input[type="color"] { width: 100%; height: 42px; padding: 2px; margin: 6px 0 14px; border: 1px solid var(--border); border-radius: 6px; cursor: pointer; }
        
        .checkbox-label { display: flex; align-items: center; gap: 8px; font-weight: 500; margin-bottom: 12px; cursor: pointer; font-size: 14px; }
        .checkbox-label input { width: auto; margin: 0; }
        
        button { background: var(--primary); color: white; border: none; padding: 10px 16px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: background 0.2s, transform 0.1s; }
        button:hover { background: var(--primary-hover); }
        button:active { transform: scale(0.98); }
        .btn-danger { background: #ef4444; } .btn-danger:hover { background: #dc2626; }
        .btn-secondary { background: #e2e8f0; color: #334155; } .btn-secondary:hover { background: #cbd5e1; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-wa { background: #25D366; color: white; text-decoration: none; padding: 5px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 4px; }
        .btn-wa:hover { background: #1ebc59; }

        .alert { padding: 12px 16px; margin-bottom: 20px; border-radius: 8px; font-weight: 500; font-size: 14px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        
        code { background: #0f172a; color: #38bdf8; padding: 10px 14px; display: block; border-radius: 6px; font-size: 13px; margin: 6px 0 14px; overflow-x: auto; word-break: break-all; }
        .help-text { font-size: 12px; color: var(--text-muted); margin-top: -4px; margin-bottom: 10px; display: block; line-height: 1.4; }
        
        /* ESTILOS DEL BUSCADOR Y ORDENACIÓN */
        .search-panel { margin-bottom: 20px; display: flex; flex-direction: column; gap: 12px; }
        .search-box { position: relative; width: 100%; }
        .search-box input { padding-left: 38px; margin: 0; height: 44px; border-radius: 8px; font-size: 14px; width: 100%; }
        .search-box-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 16px; color: #94a3b8; pointer-events: none; }
        .clear-search-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; font-size: 16px; cursor: pointer; padding: 4px; display: none; }
        
        .search-tools-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .search-count { font-size: 13px; font-weight: 600; color: var(--text-muted); }
        .action-btns-group { display: flex; gap: 8px; }

        /* TARJETAS COLAPSABLES (ACORDEÓN) */
        .bot-accordion-item {
            border: 1px solid var(--border); border-radius: 10px; margin-bottom: 15px; 
            background: #ffffff; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.02);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .bot-accordion-item:hover { border-color: #cbd5e1; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        
        .bot-accordion-header {
            padding: 16px 20px; background: #fff; cursor: pointer;
            display: flex; justify-content: space-between; align-items: center; gap: 15px;
            user-select: none;
        }
        .bot-accordion-header:hover { background: #f8fafc; }
        
        .bot-header-info { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; flex-grow: 1; }
        .bot-color-circle { width: 18px; height: 18px; border-radius: 50%; display: inline-block; flex-shrink: 0; box-shadow: 0 0 4px rgba(0,0,0,0.2); }
        .bot-title-text { font-size: 16px; font-weight: 700; color: #0f172a; }
        
        .badge {
            font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 12px; 
            display: inline-flex; align-items: center; gap: 3px; white-space: nowrap;
        }
        .badge-id { background: #f1f5f9; color: #475569; }
        .badge-date { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; }
        .badge-pdf { background: #dcfce7; color: #166534; }
        .badge-webhook { background: #f3e8ff; color: #7e22ce; }
        .badge-leads { background: #dbeafe; color: #1e40af; }
        .badge-err { background: #fef3c7; color: #b45309; }

        .bot-toggle-btn {
            font-size: 13px; font-weight: 600; color: var(--primary);
            display: flex; align-items: center; gap: 6px; flex-shrink: 0;
        }
        .arrow-rotate { transition: transform 0.2s ease; display: inline-block; }
        .bot-accordion-item.open .arrow-rotate { transform: rotate(180deg); }

        .bot-accordion-body {
            display: none; padding: 20px; background: #fbfcfd; border-top: 1px solid var(--border);
        }
        .bot-accordion-item.open .bot-accordion-body { display: block; }

        /* REGISTRO DE LEADS */
        .leads-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 15px; margin: 15px 0; }
        .leads-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .leads-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border-radius: 6px; overflow: hidden; }
        .leads-table th { background: #e2e8f0; text-align: left; padding: 8px 12px; color: #334155; }
        .leads-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }

        .error-log { background: #fffbeb; border: 1px solid #fef08a; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .error-log h5 { margin: 0 0 10px 0; color: #b45309; font-size: 14px; }
        .error-log ul { margin: 0; padding-left: 20px; font-size: 13px; color: #92400e; }
        
        .webhook-highlight { background: #faf5ff; border: 1px solid #e9d5ff; padding: 12px; border-radius: 8px; margin: 10px 0; }
        .webhook-highlight label { color: #6b21a8; font-weight: 700; font-size: 13px; }

        .code-box-wrapper { position: relative; margin: 6px 0 15px 0; }
        .code-box-wrapper code { display: block; padding: 12px 85px 12px 14px; background: #0f172a; color: #e2e8f0; border-radius: 8px; font-size: 12px; word-break: break-all; white-space: pre-wrap; line-height: 1.4; }
        .code-box-wrapper .btn-copy { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: #334155; color: #f8fafc; border: 1px solid #475569; padding: 6px 12px; border-radius: 6px; font-size: 11px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
        .code-box-wrapper .btn-copy:hover { background: #475569; }
        .fs-action-pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; border: 1px solid #cbd5e1; background: #f1f5f9; color: #1e293b; cursor: pointer; transition: all 0.2s; }
        .fs-action-pill:hover { background: #e2e8f0; }
        .fs-action-pill.primary { background: #0284c7; color: white; border-color: #0284c7; }
        .fs-action-pill.primary:hover { background: #0369a1; }

        /* PESTAÑAS PRINCIPALES (CHAT WEB vs WHATSAPP) */
        .nav-tabs {
            max-width: 1200px; margin: 0 auto 20px; display: flex; gap: 8px; border-bottom: 2px solid #e2e8f0; padding-bottom: 0;
        }
        .nav-tab-btn {
            padding: 12px 24px; font-size: 15px; font-weight: 700; text-decoration: none; border-radius: 8px 8px 0 0;
            color: #64748b; background: transparent; border: 2px solid transparent; border-bottom: none;
            display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease; cursor: pointer;
        }
        .nav-tab-btn:hover { color: #0f172a; background: #f1f5f9; }
        .nav-tab-btn.active {
            color: #2563eb; background: #ffffff; border-color: #e2e8f0; border-bottom: 2px solid #ffffff;
            margin-bottom: -2px; box-shadow: 0 -2px 6px rgba(0,0,0,0.03);
        }
        .nav-tab-btn.wa-tab.active {
            color: #15803d; border-color: #bbf7d0;
        }
        .tab-badge {
            font-size: 11px; padding: 2px 7px; border-radius: 10px; background: #e2e8f0; color: #475569;
        }
        .nav-tab-btn.active .tab-badge {
            background: #dbeafe; color: #1e40af;
        }
        .nav-tab-btn.wa-tab.active .tab-badge {
            background: #dcfce7; color: #166534;
        }

        /* Simulador WhatsApp */
        .simulator-box {
            background: #efeae2; border: 1px solid #d1d7db; border-radius: 12px; padding: 15px;
            max-width: 500px; margin-top: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .sim-header {
            background: #008069; color: white; padding: 10px 14px; border-radius: 8px; font-weight: 700;
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 14px;
        }
        .sim-chat-area {
            background: #ffffff; border-radius: 8px; height: 260px; overflow-y: auto; padding: 12px;
            display: flex; flex-direction: column; gap: 8px; font-size: 13.5px; border: 1px solid #e2e8f0;
        }
        .sim-bubble {
            max-width: 85%; padding: 8px 12px; border-radius: 8px; line-height: 1.4; word-break: break-word; white-space: pre-wrap;
        }
        .sim-bubble.user { background: #d9fdd3; align-self: flex-end; }
        .sim-bubble.bot { background: #f0f2f5; align-self: flex-start; }
        .sim-input-row { display: flex; gap: 8px; margin-top: 10px; }
        .sim-input-row input { margin: 0; }
    </style>
</head>
<body>

<?php if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
    <div style="max-width: 400px; margin: 100px auto;" class="card">
        <h2>🔒 Iniciar Sesión</h2>
        <?php if(isset($error)) echo "<div class='alert alert-error'>$error</div>"; ?>
        <form method="post">
            <label>Contraseña Maestra:</label>
            <input type="password" name="password" required autofocus>
            <button type="submit" name="login" style="width: 100%">Entrar al Panel</button>
        </form>
    </div>
<?php else: ?>

    <div style="max-width: 1200px; margin: 0 auto 20px; display:flex; justify-content:space-between; align-items:center;">
        <h1 style="margin:0; font-size:24px;">🤖 Gestor de Chats Gemini & WhatsApp</h1>
        <a href="?logout=1" style="color:#64748b; font-weight:600; text-decoration:none;">Cerrar Sesión ➔</a>
    </div>

    <!-- PESTAÑAS PRINCIPALES: CHATS WEB vs WHATSAPP -->
    <div class="nav-tabs">
        <a href="?tab=web" class="nav-tab-btn <?php echo $active_tab === 'web' ? 'active' : ''; ?>">
            🌐 Chats Web (Widget)
            <span class="tab-badge"><?php echo count($settings['bots']); ?></span>
        </a>
        <a href="?tab=whatsapp" class="nav-tab-btn wa-tab <?php echo $active_tab === 'whatsapp' ? 'active' : ''; ?>">
            📱 WhatsApp (Menús & Gemini)
            <span class="tab-badge" style="<?php echo !empty($wa_settings['instances']) ? 'background:#bbf7d0; color:#166534;' : ''; ?>"><?php echo count($wa_settings['instances']); ?></span>
        </a>
    </div>

    <?php if($success): ?>
        <div style="max-width: 1200px; margin: 0 auto 15px;" class="alert alert-success">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if ($active_tab === 'web'): ?>
    <div class="container">
        
        <!-- ========================================== -->
        <!-- COLUMNA IZQUIERDA: CREAR CHAT             -->
        <!-- ========================================== -->
        <div>
            <div class="card">
                <h3>➕ Crear Nuevo Chat</h3>
                <form method="post" enctype="multipart/form-data">
                    <label>Nombre identificador:</label>
                    <input type="text" name="bot_name" placeholder="Ej: Consultorio Legal Venezuela" required>
                    
                    <label>API Key(s) de Gemini:</label>
                    <div class="help-text">Puedes colocar una o varias separadas por comas. Si una falla o se satura, el sistema rotará automáticamente a la siguiente:</div>
                    <input type="text" name="bot_api_key" placeholder="AIzaSyKey1, AIzaSyKey2..." required>

                    <label>Modelo de Gemini:</label>
                    <input type="text" name="bot_model" value="gemini-3.5-flash" required>

                    <label class="checkbox-label">
                        <input type="checkbox" name="require_form" value="1" checked id="new_bot_req_form" onchange="document.getElementById('new_bot_wh_box').style.display = this.checked ? 'block' : 'none';">
                        Pedir Nombre y Teléfono al inicio
                    </label>

                    <!-- CONEXIÓN A WEBHOOK / PANELES -->
                    <div id="new_bot_wh_box" class="webhook-highlight">
                        <label>🌐 Conectar a Webhook / Panel de Leads (Opcional):</label>
                        <div class="help-text">Envía los contactos capturados en tiempo real a cualquier panel web, n8n, CRM o LeadsEvolution.</div>
                        <input type="text" name="webhook_url" placeholder="https://chamiculinary.com/webhook/...">
                    </div>
                    
                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

                    <label>📁 Adjuntar Documento Base (PDF):</label>
                    <div class="help-text">Opcional. Sube un PDF para que la IA lo lea y use de contexto en cada respuesta.</div>
                    <input type="file" name="bot_pdf" accept="application/pdf">

                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

                    <label>Menú de Botones Iniciales (Un botón por línea):</label>
                    <div class="help-text" style="background:#f1f5f9; padding:8px 10px; border-radius:4px; font-size:11px;">
                        • Responder con IA: <code>Quiero cotizar</code><br>
                        • Respuesta Directa: <code>Horarios::De 8am a 5pm</code><br>
                        • Abrir Enlace: <code>[WhatsApp](https://wa.me/58424...)</code>
                    </div>
                    <textarea name="quick_replies" rows="3" placeholder="Quiero cotizar&#10;Horarios::De 8am a 5pm&#10;[WhatsApp](https://wa.me/584245747100)"></textarea>

                    <label style="margin-top:10px;">Instrucciones de la IA (Prompt):</label>
                    <textarea name="bot_prompt" rows="4" placeholder="Eres el asistente oficial..." required></textarea>
                    
                    <label>Mensaje de Respaldo (Fallback):</label>
                    <textarea name="fallback_msg" rows="2" required>En este momento mis servidores están saturados. Por favor, comunícate con un asesor: [WhatsApp](https://wa.me/584245747100)</textarea>
                    
                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">
                    <h4 style="margin:0 0 10px 0;">🎨 Diseño del Chat</h4>
                    
                    <label>Diseño del Botón Flotante:</label>
                    <select name="btn_design">
                        <option value="classic">Clásico (Redondo, Ícono Chat)</option>
                        <option value="robot">Robot (Redondo, Ícono Robot)</option>
                        <option value="square">Cuadrado (Bordes redondeados)</option>
                        <option value="camouflaged">Camuflado / Muy Pequeño (32px, discreto y semi-transparente)</option>
                    </select>

                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;">
                            <label>Color Principal:</label>
                            <input type="color" name="bot_color" value="#1D3557">
                        </div>
                        <div style="flex:1;">
                            <label>Color Secundario:</label>
                            <input type="color" name="bot_color2" value="#C8A165">
                        </div>
                    </div>
                    
                    <label>Mensaje Flotante / Tooltip:</label>
                    <input type="text" name="bot_tooltip" placeholder="¡Consulta aquí con nuestro asistente!">

                    <button type="submit" name="create_bot" style="width: 100%; margin-top:10px;">Crear Chat</button>
                </form>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- COLUMNA DERECHA: LISTA ORDENADA Y BUSCADOR -->
        <!-- ========================================== -->
        <div>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                    <h3 style="margin:0;">💬 Chats Activos (<?php echo count($settings['bots']); ?>)</h3>
                </div>

                <!-- BUSCADOR POR NOMBRE Y FECHA -->
                <div class="search-panel">
                    <div class="search-box">
                        <span class="search-box-icon">🔍</span>
                        <input type="text" id="botSearchInput" placeholder="Buscar por nombre, fecha (ej: 2026-10 o 11/10) o ID..." oninput="filterAndRenderBots()">
                        <button type="button" id="clearSearchBtn" class="clear-search-btn" onclick="clearSearchInput()">✕</button>
                    </div>
                    <div class="search-tools-row">
                        <span id="botSearchCounter" class="search-count">Mostrando <?php echo count($settings['bots']); ?> chats</span>
                        <div class="action-btns-group">
                            <button type="button" class="btn-secondary btn-sm" onclick="expandAllBots()">Expandir Todos</button>
                            <button type="button" class="btn-secondary btn-sm" onclick="collapseAllBots()">Colapsar Todos</button>
                        </div>
                    </div>
                </div>

                <?php if(empty($settings['bots'])): ?>
                    <p style="color:#64748b; font-style:italic;">No tienes chats creados todavía. Crea uno en la columna izquierda.</p>
                <?php endif; ?>

                <div id="botsAccordionList">
                    <?php 
                    // Preparar y ordenar los bots
                    $bots_ordered = [];
                    foreach ($settings['bots'] as $b_id => $b_data) {
                        $c_date = $b_data['created_at'] ?? '2026-10-10 12:00:00';
                        $bots_ordered[$b_id] = array_merge($b_data, ['_id' => $b_id, '_created_at' => $c_date]);
                    }
                    
                    // Ordenar por fecha de creación (más reciente primero)
                    uasort($bots_ordered, function($a, $b) {
                        return strtotime($b['_created_at']) <=> strtotime($a['_created_at']);
                    });

                    foreach ($bots_ordered as $id => $bot): 
                        $req_form = isset($bot['require_form']) ? $bot['require_form'] : true; 
                        $c1 = !empty($bot['color']) ? $bot['color'] : '#2563eb';
                        $c2 = !empty($bot['color2']) ? $bot['color2'] : '#9333ea';
                        $btn_d = $bot['btn_design'] ?? 'classic';
                        $has_pdf = file_exists(__DIR__ . '/uploads/' . $id . '.pdf');
                        $has_webhook = !empty($bot['webhook_url']);
                        $has_errors = !empty($settings['errors'][$id]);
                        $bot_leads = $leads[$id] ?? [];
                        $lead_count = count($bot_leads);
                        $date_formatted = date('d/m/Y', strtotime($bot['_created_at']));
                        $date_raw = $bot['_created_at'];
                        $is_open_by_default = ($id === $opened_bot_id);
                    ?>
                        <div class="bot-accordion-item <?php echo $is_open_by_default ? 'open' : ''; ?>" 
                             id="bot_item_<?php echo $id; ?>"
                             data-id="<?php echo strtolower($id); ?>"
                             data-name="<?php echo strtolower(htmlspecialchars($bot['name'])); ?>"
                             data-date="<?php echo $date_formatted; ?>"
                             data-dateraw="<?php echo $date_raw; ?>"
                             data-model="<?php echo strtolower($bot['model'] ?? ''); ?>">
                            
                            <!-- CABECERA DEL CHAT (CLICKEABLE) -->
                            <div class="bot-accordion-header" onclick="toggleBotItem('<?php echo $id; ?>')">
                                <div class="bot-header-info">
                                    <span class="bot-color-circle" style="background: linear-gradient(135deg, <?php echo $c1; ?>, <?php echo $c2; ?>);"></span>
                                    <span class="bot-title-text"><?php echo htmlspecialchars($bot['name']); ?></span>
                                    
                                    <span class="badge badge-id">#<?php echo $id; ?></span>
                                    <span class="badge badge-date">📅 <?php echo $date_formatted; ?></span>

                                    <?php if ($has_pdf): ?>
                                        <span class="badge badge-pdf">📄 PDF Activo</span>
                                    <?php endif; ?>

                                    <?php if ($has_webhook): ?>
                                        <span class="badge badge-webhook">⚡ Webhook</span>
                                    <?php endif; ?>

                                    <?php if ($lead_count > 0): ?>
                                        <span class="badge badge-leads">👥 <?php echo $lead_count; ?> Leads</span>
                                    <?php endif; ?>

                                    <?php if ($has_errors): ?>
                                        <span class="badge badge-err">⚠️ Error API</span>
                                    <?php endif; ?>
                                </div>
                                <div class="bot-toggle-btn">
                                    <span>Configurar</span>
                                    <span class="arrow-rotate">▼</span>
                                </div>
                            </div>

                            <!-- CUERPO DESPLEGABLE CON LA CONFIGURACIÓN COMPLETA -->
                            <div class="bot-accordion-body" id="bot_body_<?php echo $id; ?>">
                                
                                <strong style="font-size:13px; color:#1e293b;">1. Widget Flotante para Sitios Web:</strong>
                                <div class="help-text">Pégalo antes de cerrar &lt;/body&gt; en cualquier web donde quieras mostrar el chat flotante:</div>
                                <div class="code-box-wrapper">
                                    <code>&lt;script src="<?php echo $base_url; ?>/widget.php?id=<?php echo $id; ?>" defer&gt;&lt;/script&gt;</code>
                                    <button type="button" class="btn-copy" onclick="copyText('&lt;script src=&quot;<?php echo $base_url; ?>/widget.php?id=<?php echo $id; ?>&quot; defer&gt;&lt;/script&gt;', this)">Copiar</button>
                                </div>

                                <strong style="font-size:13px; color:#1e293b;">2. Enlace Directo a Pantalla Completa:</strong>
                                <div class="help-text">URL independiente para abrir el chat en toda la pantalla, compartir por WhatsApp o redes:</div>
                                <div style="display:flex; gap:10px; align-items:center; margin:6px 0 16px 0; flex-wrap:wrap;">
                                    <code style="margin:0; flex-grow:1; background:#0f172a; color:#38bdf8; padding:10px 14px; border-radius:8px; font-size:12px; word-break:break-all;"><a href="<?php echo $base_url; ?>/chat.php?id=<?php echo $id; ?>" target="_blank" style="color:#38bdf8; text-decoration:none;"><?php echo $base_url; ?>/chat.php?id=<?php echo $id; ?></a></code>
                                    <a href="<?php echo $base_url; ?>/chat.php?id=<?php echo $id; ?>" target="_blank" class="fs-action-pill primary">Probar Pantalla Completa ↗</a>
                                    <button type="button" class="fs-action-pill" onclick="copyText('<?php echo $base_url; ?>/chat.php?id=<?php echo $id; ?>', this)">📋 Copiar Link</button>
                                </div>

                                <strong style="font-size:13px; color:#1e293b;">3. Botón Camuflado / Muy Pequeño (Para el Dominio del Cliente):</strong>
                                <div class="help-text">Pega este código en la web o landing del cliente para que tenga un botón camuflado (28px, discreto y semi-transparente) que abre el chat en Pantalla Completa:</div>
                                <div class="code-box-wrapper">
                                    <code>&lt;!-- Botón Flotante Camuflado --&gt;&#10;&lt;a href="<?php echo $base_url; ?>/chat.php?id=<?php echo $id; ?>" target="_blank" title="Asistente" style="position:fixed;bottom:12px;right:12px;z-index:99999;width:28px;height:28px;border-radius:50%;background:rgba(30,41,59,0.35);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;color:#fff;text-decoration:none;opacity:0.35;transition:all .3s ease;box-shadow:0 2px 6px rgba(0,0,0,0.15);" onmouseover="this.style.opacity='1';this.style.transform='scale(1.15)';" onmouseout="this.style.opacity='0.35';this.style.transform='scale(1)';"&gt;&lt;svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"&gt;&lt;path d="M20 2H4C2.9 2 2 2.9 2 4V22L6 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2M20 16H6.8L4 18.8V4H20V16Z"/&gt;&lt;/svg&gt;&lt;/a&gt;</code>
                                    <button type="button" class="btn-copy" onclick="copyText('&lt;a href=&quot;<?php echo $base_url; ?>/chat.php?id=<?php echo $id; ?>&quot; target=&quot;_blank&quot; title=&quot;Asistente&quot; style=&quot;position:fixed;bottom:12px;right:12px;z-index:99999;width:28px;height:28px;border-radius:50%;background:rgba(30,41,59,0.35);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;color:#fff;text-decoration:none;opacity:0.35;transition:all .3s ease;box-shadow:0 2px 6px rgba(0,0,0,0.15);&quot; onmouseover=&quot;this.style.opacity=\'1\';this.style.transform=\'scale(1.15)\';&quot; onmouseout=&quot;this.style.opacity=\'0.35\';this.style.transform=\'scale(1)\';&quot;&gt;&lt;svg width=&quot;14&quot; height=&quot;14&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;currentColor&quot;&gt;&lt;path d=&quot;M20 2H4C2.9 2 2 2.9 2 4V22L6 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2M20 16H6.8L4 18.8V4H20V16Z&quot;/&gt;&lt;/svg&gt;&lt;/a&gt;', this)">Copiar Botón</button>
                                </div>

                                <!-- SECCIÓN DE LEADS CAPTURADOS -->
                                <?php if ($lead_count > 0): ?>
                                    <div class="leads-box">
                                        <div class="leads-header">
                                            <div>
                                                <strong style="color:#166534; font-size:14px;">👥 Contactos Recibidos en este Chat (<?php echo $lead_count; ?>)</strong>
                                                <div style="font-size:11px; color:#15803d;">Personas que completaron el formulario inicial de nombre y teléfono.</div>
                                            </div>
                                            <div style="display:flex; gap:8px;">
                                                <a href="?export_leads=<?php echo $id; ?>" class="btn-sm btn-secondary" style="text-decoration:none;">📥 Descargar CSV</a>
                                                <a href="?clear_leads=<?php echo $id; ?>" onclick="return confirm('¿Seguro que deseas borrar la lista de leads de este chat?');" class="btn-sm" style="background:#fee2e2; color:#991b1b; text-decoration:none;">🗑️ Limpiar</a>
                                            </div>
                                        </div>
                                        <div style="max-height: 180px; overflow-y: auto;">
                                            <table class="leads-table">
                                                <thead>
                                                    <tr>
                                                        <th>Fecha</th>
                                                        <th>Nombre</th>
                                                        <th>Teléfono</th>
                                                        <th>Acción</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach(array_slice($bot_leads, 0, 15) as $l): 
                                                        $wa_clean = preg_replace('/[^0-9]/', '', $l['phone'] ?? '');
                                                    ?>
                                                        <tr>
                                                            <td style="color:#64748b;"><?php echo htmlspecialchars($l['date']); ?></td>
                                                            <td><strong><?php echo htmlspecialchars($l['name']); ?></strong></td>
                                                            <td><?php echo htmlspecialchars($l['phone']); ?></td>
                                                            <td>
                                                                <?php if (!empty($wa_clean)): ?>
                                                                    <a href="https://wa.me/<?php echo $wa_clean; ?>" target="_blank" class="btn-wa">💬 WhatsApp</a>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- VISOR DE ERRORES DE API -->
                                <?php if (!empty($settings['errors'][$id])): ?>
                                    <div class="error-log">
                                        <h5>⚠️ Registro de Errores Recientes (Google API):</h5>
                                        <ul>
                                            <?php foreach($settings['errors'][$id] as $err): ?>
                                                <li><?php echo htmlspecialchars($err); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <a href="?clear_errors=<?php echo $id; ?>" style="display:inline-block; margin-top:10px; font-size: 12px; color: #b45309; text-decoration: underline;">Limpiar registro de errores</a>
                                    </div>
                                <?php endif; ?>

                                <!-- FORMULARIO DE EDICIÓN DEL CHAT -->
                                <form method="post" enctype="multipart/form-data" style="margin-top: 15px; background: white; padding: 20px; border-radius: 8px; border: 1px solid var(--border);">
                                    <input type="hidden" name="bot_id" value="<?php echo $id; ?>">
                                    
                                    <label>Nombre identificador:</label>
                                    <input type="text" name="bot_name" value="<?php echo htmlspecialchars($bot['name']); ?>" required>
                                    
                                    <label>API Key(s) de Gemini (Rotación Multi-Key):</label>
                                    <div class="help-text">Puedes poner varias llaves separadas por coma para respaldo automático si una se agota:</div>
                                    <input type="text" name="bot_api_key" value="<?php echo htmlspecialchars($bot['api_key'] ?? ''); ?>" placeholder="AIzaSy1, AIzaSy2..." required>
                                    
                                    <label>Modelo de Gemini:</label>
                                    <input type="text" name="bot_model" value="<?php echo htmlspecialchars($bot['model'] ?? 'gemini-3.5-flash'); ?>" required>

                                    <label class="checkbox-label" style="margin-top: 10px;">
                                        <input type="checkbox" name="require_form" value="1" <?php if($req_form) echo 'checked'; ?> onchange="document.getElementById('wh_box_<?php echo $id; ?>').style.display = this.checked ? 'block' : 'none';">
                                        Pedir Nombre y Teléfono al inicio
                                    </label>

                                    <!-- CONEXIÓN WEBHOOK EDIT -->
                                    <div id="wh_box_<?php echo $id; ?>" class="webhook-highlight" style="<?php echo $req_form ? 'display:block;' : 'display:none;'; ?>">
                                        <label>🌐 Conectar a Webhook / Panel de Leads (Opcional):</label>
                                        <div class="help-text">URL de tu CRM, n8n, LeadsEvolution o panel web para enviar el lead en tiempo real por HTTP POST.</div>
                                        <input type="text" name="webhook_url" value="<?php echo htmlspecialchars($bot['webhook_url'] ?? ''); ?>" placeholder="https://chamiculinary.com/webhook/...">
                                    </div>
                                    
                                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

                                    <label>📁 Reemplazar Documento Base (PDF):</label>
                                    <input type="file" name="bot_pdf" accept="application/pdf">
                                    
                                    <?php if ($has_pdf): ?>
                                        <div style="font-size:12px; color:#166534; font-weight:bold; background:#dcfce7; padding:6px 12px; border-radius:4px; display:inline-block; margin-bottom:8px;">✅ PDF Cargado y Activo en este Chat</div><br>
                                        <label class="checkbox-label" style="color:#991b1b;">
                                            <input type="checkbox" name="delete_pdf" value="1"> 🗑️ Eliminar el PDF actual
                                        </label>
                                    <?php endif; ?>

                                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

                                    <label>Menú de Botones Iniciales (Un botón por línea):</label>
                                    <textarea name="quick_replies" rows="4"><?php echo htmlspecialchars($bot['quick_replies'] ?? ''); ?></textarea>

                                    <label style="margin-top:10px;">Prompt (Instrucciones de la IA):</label>
                                    <textarea name="bot_prompt" rows="5" required><?php echo htmlspecialchars($bot['prompt']); ?></textarea>
                                    
                                    <label>Mensaje de Respaldo (Si falla la API):</label>
                                    <textarea name="fallback_msg" rows="3" required><?php echo htmlspecialchars($bot['fallback_msg'] ?? 'Error de conexión.'); ?></textarea>

                                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">
                                    <h4 style="margin:0 0 10px 0;">🎨 Diseño del Chat</h4>
                                    
                                    <label>Diseño del Botón Flotante:</label>
                                    <select name="btn_design">
                                        <option value="classic" <?php echo $btn_d === 'classic' ? 'selected' : ''; ?>>Clásico (Redondo, Ícono Chat)</option>
                                        <option value="robot" <?php echo $btn_d === 'robot' ? 'selected' : ''; ?>>Robot (Redondo, Ícono Robot)</option>
                                        <option value="square" <?php echo $btn_d === 'square' ? 'selected' : ''; ?>>Cuadrado (Bordes redondeados)</option>
                                        <option value="camouflaged" <?php echo $btn_d === 'camouflaged' ? 'selected' : ''; ?>>Camuflado / Muy Pequeño (32px, discreto y semi-transparente)</option>
                                    </select>

                                    <div style="display:flex; gap:10px;">
                                        <div style="flex:1;">
                                            <label>Color Principal:</label>
                                            <input type="color" name="bot_color" value="<?php echo htmlspecialchars($c1); ?>">
                                        </div>
                                        <div style="flex:1;">
                                            <label>Color Secundario:</label>
                                            <input type="color" name="bot_color2" value="<?php echo htmlspecialchars($c2); ?>">
                                        </div>
                                    </div>
                                    
                                    <label>Mensaje Flotante / Tooltip:</label>
                                    <input type="text" name="bot_tooltip" value="<?php echo htmlspecialchars($bot['tooltip'] ?? ''); ?>" placeholder="¡Tu asistente, consulta aquí!">
                                    
                                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                                        <button type="submit" name="update_bot" style="flex-grow:1;">💾 Guardar Cambios</button>
                                        <a href="?delete_bot=<?php echo $id; ?>" onclick="return confirm('¿Seguro que deseas eliminar este chat por completo?');" style="text-decoration: none;">
                                            <button type="button" class="btn-danger">🗑️ Borrar Chat</button>
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
    </div>
    <?php endif; // Fin if active_tab === 'web' ?>

    <!-- ======================================================== -->
    <!-- PESTAÑA 2: GESTOR DE INSTANCIAS DE WHATSAPP              -->
    <!-- ======================================================== -->
    <?php if ($active_tab === 'whatsapp'): ?>
    <div class="container">
        
        <!-- COLUMNA IZQUIERDA: CREAR INSTANCIA WHATSAPP -->
        <div>
            <div class="card" style="border-top: 4px solid #16a34a;">
                <h3 style="color:#15803d;">📱 Conectar Nuevo WhatsApp</h3>
                <form method="post">
                    <label>Nombre del Bot / Número:</label>
                    <input type="text" name="wa_name" placeholder="Ej: WhatsApp Ventas Principal" required>

                    <label>Número de WhatsApp (Opcional, referencia):</label>
                    <input type="text" name="wa_phone_number" placeholder="Ej: +58 424 5747100 o +52 33 39521804">

                    <label>Motor de Conexión:</label>
                    <select name="wa_engine" id="wa_new_engine" onchange="toggleWaEngineFields(this.value, 'new')">
                        <option value="evolution" selected>📲 Código QR (Evolution API en Render / Railway / VPS)</option>
                        <option value="meta">🏢 Meta WhatsApp Cloud API (Oficial sin QR / SMS)</option>
                    </select>

                    <!-- CAMPOS EVOLUTION API (QR) -->
                    <div id="wa_new_evo_fields" class="webhook-highlight" style="background:#f0fdf4; border-color:#bbf7d0;">
                        <label style="color:#166534;">🔗 Conexión Evolution API (Motor QR Gratuito):</label>
                        <div class="help-text">URL de tu instancia en Render, Railway o VPS (ej: https://mi-evolution.onrender.com):</div>
                        <input type="text" name="wa_server_url" placeholder="https://mi-evolution.onrender.com">
                        
                        <label style="color:#166534;">API Key / Token del Servidor:</label>
                        <input type="text" name="wa_server_token" placeholder="Tu Global API Key o Token">

                        <label style="color:#166534;">Nombre de la Instancia en Evolution:</label>
                        <input type="text" name="wa_instance_name" placeholder="Ej: ventas_1 (sin espacios)">
                    </div>

                    <!-- CAMPOS META CLOUD API -->
                    <div id="wa_new_meta_fields" class="webhook-highlight" style="display:none; background:#f0fdf4; border-color:#bbf7d0;">
                        <label style="color:#166534;">Phone Number ID (Meta):</label>
                        <input type="text" name="meta_phone_id" placeholder="1048291048201...">

                        <label style="color:#166534;">Token de Acceso Permanente (Meta):</label>
                        <input type="text" name="meta_token" placeholder="EAABwz...">

                        <label style="color:#166534;">Verify Token (Invéntate una clave):</label>
                        <input type="text" name="meta_verify_token" value="gemini_wa_<?php echo substr(md5(uniqid()), 0, 6); ?>">
                    </div>

                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

                    <label>Modo de Funcionamiento del Bot:</label>
                    <select name="wa_bot_mode">
                        <option value="hybrid" selected>⚡ Híbrido (Menú Anidado + Gemini si escribe duda libre)</option>
                        <option value="menu_only">📋 Solo Menú de Opciones Anidadas (Sin IA)</option>
                        <option value="gemini_only">🤖 Solo Gemini IA (Conversación libre continua)</option>
                    </select>

                    <label>Mensaje de Bienvenida / Saludo Inicial:</label>
                    <textarea name="wa_welcome_msg" rows="3" required>¡Hola! 👋 Bienvenido a nuestro canal de WhatsApp. ¿En qué podemos asesorarte hoy?</textarea>

                    <label>Árbol de Opciones Anidadas (Menú Multinivel):</label>
                    <div class="help-text" style="background:#f1f5f9; padding:8px 10px; border-radius:4px; font-size:11px;">
                        Formato: <code>[Código] Título :: Respuesta</code><br>
                        • <code>[1] Servicios :: Tenemos Hosting y VPS</code><br>
                        • <code>[1.1] Hosting Web :: Planes en https://dscloud.mx/hosting-web</code><br>
                        • <code>[1.2] VPS :: Servidores en https://dscloud.mx/vps</code><br>
                        • <code>[2] Horarios :: Lunes a Viernes 8am a 5pm</code><br>
                        • <code>[3] Asesor :: [HUMAN_ESCALATE]</code> (Pausa el bot para que hable un humano)<br>
                        • <code>[4] Consultar a la IA :: [GEMINI]</code>
                    </div>
                    <textarea name="wa_nested_menu" rows="6" placeholder="[1] Ver Servicios :: Tenemos Hosting y VPS&#10;  [1.1] Hosting Web :: Planes desde $999 MXN: https://dscloud.mx/hosting-web&#10;  [1.2] VPS :: Planes mensuales y anuales: https://dscloud.mx/vps&#10;[2] Horarios :: Lunes a Viernes de 8:00 a 17:00&#10;[3] Asesor Humano :: [HUMAN_ESCALATE]&#10;[4] Preguntarle a la IA :: [GEMINI]"></textarea>

                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

                    <h4 style="margin:0 0 10px 0; color:#0f172a;">🤖 Inteligencia Artificial (Gemini Multi-Key)</h4>
                    <label>API Key(s) de Gemini (Rotación automática):</label>
                    <div class="help-text">Puedes colocar varias separadas por coma. Si una falla o se satura, el bot usará la siguiente:</div>
                    <input type="text" name="wa_api_key" placeholder="AIzaSyKey1, AIzaSyKey2..." required>

                    <label>Modelo de Gemini:</label>
                    <input type="text" name="wa_model" value="gemini-3.0-flash" required>

                    <label>Prompt para WhatsApp:</label>
                    <textarea name="wa_prompt" rows="4" placeholder="Eres el asistente oficial de WhatsApp. Responde de forma breve y cordial..." required></textarea>

                    <label>Mensaje de Respaldo (Fallback):</label>
                    <textarea name="wa_fallback_msg" rows="2" required>En este momento nuestros servidores están saturados. Por favor comunícate en unos minutos.</textarea>

                    <label>Webhook Externo de Leads (Opcional):</label>
                    <input type="text" name="wa_webhook_url" placeholder="https://chamiculinary.com/webhook/...">

                    <button type="submit" name="create_wa_instance" style="width: 100%; margin-top:10px; background:#16a34a;">📱 Guardar Número WhatsApp</button>
                </form>
            </div>
        </div>

        <!-- COLUMNA DERECHA: LISTA DE NÚMEROS Y SIMULADOR -->
        <div>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                    <h3 style="margin:0;">📱 Números Conectados (<?php echo count($wa_settings['instances']); ?>)</h3>
                </div>

                <?php if(empty($wa_settings['instances'])): ?>
                    <div style="background:#f8fafc; border:2px dashed #cbd5e1; border-radius:12px; padding:35px 20px; text-align:center; color:#64748b;">
                        <div style="font-size:42px; margin-bottom:10px;">📲</div>
                        <h4 style="margin:0 0 8px 0; color:#334155;">No tienes números de WhatsApp configurados</h4>
                        <p style="font-size:13.5px; max-width:450px; margin:0 auto; line-height:1.5;">
                            Crea tu primera instancia en la columna izquierda. Puedes conectarla por <strong>Código QR gratuito</strong> (Evolution API en Render) o por <strong>Meta Cloud API</strong>.
                        </p>
                    </div>
                <?php endif; ?>

                <div id="waAccordionList">
                    <?php foreach ($wa_settings['instances'] as $wa_id => $wa): 
                        $wa_engine = $wa['engine'] ?? 'evolution';
                        $wa_mode = $wa['bot_mode'] ?? 'hybrid';
                        $wa_leads = $leads[$wa_id] ?? [];
                        $wa_lead_count = count($wa_leads);
                        $has_wa_err = !empty($wa_settings['errors'][$wa_id]);
                        $webhook_endpoint = $base_url . "/whatsapp_webhook.php?id=" . $wa_id;
                        $is_open_wa = ($wa_id === $opened_wa_id) || (count($wa_settings['instances']) === 1);
                        $is_wa_active = $wa['is_active'] ?? true;
                    ?>
                        <div class="bot-accordion-item <?php echo $is_open_wa ? 'open' : ''; ?>" id="wa_item_<?php echo $wa_id; ?>">
                            <div class="bot-accordion-header" onclick="toggleWaItem('<?php echo $wa_id; ?>')">
                                <div class="bot-header-info">
                                    <span class="bot-color-circle" style="background:<?php echo $is_wa_active ? '#25D366' : '#94a3b8'; ?>;"></span>
                                    <span class="bot-title-text"><?php echo htmlspecialchars($wa['name']); ?></span>
                                    <?php if(!empty($wa['phone_number'])): ?>
                                        <span class="badge" style="background:#e0f2fe; color:#0369a1;">📞 <?php echo htmlspecialchars($wa['phone_number']); ?></span>
                                    <?php endif; ?>
                                    <span class="badge" style="background:<?php echo $is_wa_active ? '#dcfce7' : '#fee2e2'; ?>; color:<?php echo $is_wa_active ? '#166534' : '#991b1b'; ?>;">
                                        <?php echo $is_wa_active ? '🟢 Bot Activo' : '⏸️ Bot Desactivado'; ?>
                                    </span>
                                    <span class="badge" style="background:<?php echo $wa_engine === 'evolution' ? '#f0fdf4' : '#eff6ff'; ?>; color:<?php echo $wa_engine === 'evolution' ? '#166534' : '#1e40af'; ?>;">
                                        <?php echo $wa_engine === 'evolution' ? '📲 QR Evolution' : '🏢 Meta Cloud'; ?>
                                    </span>
                                    <span class="badge badge-id">#<?php echo $wa_id; ?></span>
                                    <?php if ($wa_lead_count > 0): ?>
                                        <span class="badge badge-leads">👥 <?php echo $wa_lead_count; ?> Contactos</span>
                                    <?php endif; ?>
                                    <?php if ($has_wa_err): ?>
                                        <span class="badge badge-err">⚠️ Error Reciente</span>
                                    <?php endif; ?>
                                    <a href="?tab=whatsapp&toggle_wa_active=<?php echo $wa_id; ?>" onclick="event.stopPropagation();" class="btn-sm" style="background:<?php echo $is_wa_active ? '#fee2e2' : '#dcfce7'; ?>; color:<?php echo $is_wa_active ? '#991b1b' : '#166534'; ?>; text-decoration:none; margin-left:8px; padding:3px 8px; font-weight:600;">
                                        <?php echo $is_wa_active ? '⏸️ Desactivar' : '▶️ Activar'; ?>
                                    </a>
                                </div>
                                <div class="bot-toggle-btn" style="color:#16a34a;">
                                    <span>Gestionar</span>
                                    <span class="arrow-rotate">▼</span>
                                </div>
                            </div>

                            <div class="bot-accordion-body" id="wa_body_<?php echo $wa_id; ?>">
                                
                                <!-- DATOS DEL WEBHOOK PARA CONECTAR -->
                                <strong style="font-size:13px; color:#1e293b;">1. URL de Webhook para recibir mensajes en HostGator:</strong>
                                <div class="help-text">Pega esta URL en tu panel de Evolution API o en la configuración de Webhook de Meta:</div>
                                <div class="code-box-wrapper">
                                    <code><?php echo $webhook_endpoint; ?></code>
                                    <button type="button" class="btn-copy" onclick="copyText('<?php echo $webhook_endpoint; ?>', this)">Copiar URL</button>
                                </div>

                                <!-- VISOR QR / CÓDIGO DE EMPAREJAMIENTO EVOLUTION -->
                                <?php if ($wa_engine === 'evolution' && !empty($wa['server_url'])): ?>
                                    <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:15px; margin:15px 0;">
                                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                                            <div>
                                                <strong style="color:#0f172a; font-size:14px;">📲 Vincular WhatsApp (Código o QR)</strong>
                                                <div style="font-size:12px; color:#64748b;">Instancia: <code><?php echo htmlspecialchars($wa['instance_name'] ?? $wa_id); ?></code></div>
                                            </div>
                                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                                <button type="button" class="btn-sm" style="background:#2563eb;" onclick="fetchWaPairingCode('<?php echo $wa_id; ?>', '<?php echo htmlspecialchars($wa['server_url']); ?>', '<?php echo htmlspecialchars($wa['instance_name'] ?? $wa_id); ?>', '<?php echo htmlspecialchars($wa['server_token']); ?>', '<?php echo htmlspecialchars($webhook_endpoint); ?>', '<?php echo htmlspecialchars($wa['phone_number'] ?? ''); ?>')">
                                                    🔑 Código de 8 Dígitos (Sin Cámara)
                                                </button>
                                                <button type="button" class="btn-sm" style="background:#16a34a;" onclick="fetchWaQr('<?php echo $wa_id; ?>', '<?php echo htmlspecialchars($wa['server_url']); ?>', '<?php echo htmlspecialchars($wa['instance_name'] ?? $wa_id); ?>', '<?php echo htmlspecialchars($wa['server_token']); ?>', '<?php echo htmlspecialchars($webhook_endpoint); ?>')">
                                                    📷 Código QR
                                                </button>
                                            </div>
                                        </div>
                                        <div id="qr_box_<?php echo $wa_id; ?>" style="text-align:center; padding:15px; display:none; background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
                                            <div id="qr_img_container_<?php echo $wa_id; ?>"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- SIMULADOR INTERNO DE PRUEBA -->
                                <div class="simulator-box">
                                    <div class="sim-header">
                                        <span>💬 Probador en Vivo de este WhatsApp</span>
                                        <span style="font-size:11px; opacity:0.9;">Prueba menús y Gemini sin gastar mensajes</span>
                                    </div>
                                    <div class="sim-chat-area" id="sim_chat_<?php echo $wa_id; ?>">
                                        <div class="sim-bubble bot"><?php echo nl2br(htmlspecialchars($wa['welcome_msg'])); ?></div>
                                    </div>
                                    <div class="sim-input-row">
                                        <input type="text" id="sim_input_<?php echo $wa_id; ?>" placeholder="Escribe 'hola', '1', o una duda libre..." onkeypress="if(event.key==='Enter') testWaChat('<?php echo $wa_id; ?>')">
                                        <button type="button" style="background:#008069; padding:8px 14px;" onclick="testWaChat('<?php echo $wa_id; ?>')">Enviar</button>
                                    </div>
                                </div>

                                <!-- VISOR DE ERRORES -->
                                <?php if (!empty($wa_settings['errors'][$wa_id])): ?>
                                    <div class="error-log">
                                        <h5>⚠️ Registro de Errores de este WhatsApp:</h5>
                                        <ul>
                                            <?php foreach($wa_settings['errors'][$wa_id] as $err): ?>
                                                <li><?php echo htmlspecialchars($err); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <a href="?clear_wa_errors=<?php echo $wa_id; ?>" style="display:inline-block; margin-top:10px; font-size: 12px; color: #b45309; text-decoration: underline;">Limpiar registro de errores</a>
                                    </div>
                                <?php endif; ?>

                                <!-- MONITOR EN VIVO DE MENSAJES WHATSAPP -->
                                <?php 
                                $dbg_file = __DIR__ . '/whatsapp_debug.log';
                                $dbg_entries = [];
                                if (file_exists($dbg_file)) {
                                    $dbg_raw = array_filter(explode("\n", @file_get_contents($dbg_file)));
                                    foreach (array_slice(array_reverse($dbg_raw), 0, 10) as $line) {
                                        $entry = json_decode($line, true);
                                        if ($entry && ($entry['instance'] ?? '') === $wa_id) {
                                            $dbg_entries[] = $entry;
                                        }
                                    }
                                }
                                ?>
                                <div style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin:15px 0;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <strong style="font-size:13px; color:#334155;">📡 Registro en Vivo de Mensajes Recibidos:</strong>
                                        <a href="?tab=whatsapp&opened_wa=<?php echo $wa_id; ?>" class="btn-sm" style="text-decoration:none; background:#0284c7; padding:2px 8px; font-size:11px;">🔄 Refrescar</a>
                                    </div>
                                    <?php if (empty($dbg_entries)): ?>
                                        <div style="font-size:12px; color:#64748b; font-style:italic;">No se han registrado eventos recientes en este webhook. Escribe al número desde otro teléfono o usa el probador de arriba.</div>
                                    <?php else: ?>
                                        <div style="font-family:monospace; font-size:11.5px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:8px; max-height:160px; overflow-y:auto;">
                                            <?php foreach($dbg_entries as $d): ?>
                                                <div style="border-bottom:1px solid #f1f5f9; padding:4px 0;">
                                                    <span style="color:#64748b;">[<?php echo htmlspecialchars($d['time'] ?? ''); ?>]</span>
                                                    <strong style="color:#0f172a;">+<?php echo htmlspecialchars($d['phone'] ?? ''); ?>:</strong>
                                                    <span>"<?php echo htmlspecialchars($d['text'] ?? ''); ?>"</span> ➔ 
                                                    <span class="badge" style="font-size:10px; padding:2px 5px; background:<?php echo ($d['status'] ?? '') === 'ENVIADO_OK' || ($d['status'] ?? '') === 'SIMULADOR_OK' ? '#dcfce7; color:#166534;' : '#fee2e2; color:#991b1b;'; ?>">
                                                        <?php echo htmlspecialchars($d['status'] ?? ''); ?>
                                                    </span>
                                                    <?php if (!empty($d['extra'])): ?>
                                                        <span style="color:#64748b; font-size:11px;">(<?php echo htmlspecialchars($d['extra']); ?>)</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- TABLA DE CONTACTOS / LEADS DE ESTE WHATSAPP -->
                                <?php if ($wa_lead_count > 0): ?>
                                    <div class="leads-box" style="margin-top:20px;">
                                        <div class="leads-header">
                                            <div>
                                                <strong style="color:#166534; font-size:14px;">👥 Conversaciones / Contactos de este WhatsApp (<?php echo $wa_lead_count; ?>)</strong>
                                                <div class="help-text">Puedes silenciar la IA para clientes individuales si deseas atenderlos manualmente:</div>
                                            </div>
                                            <div>
                                                <a href="?export_leads=<?php echo $wa_id; ?>" class="btn-sm btn-secondary" style="text-decoration:none;">📥 CSV</a>
                                                <a href="?clear_leads=<?php echo $wa_id; ?>" onclick="return confirm('¿Seguro que deseas borrar la lista de contactos?');" class="btn-sm" style="background:#fee2e2; color:#991b1b; text-decoration:none;">🗑️ Limpiar</a>
                                            </div>
                                        </div>
                                        <div style="overflow-x:auto;">
                                            <table class="leads-table">
                                                <thead>
                                                    <tr>
                                                        <th>Contacto</th>
                                                        <th>Teléfono</th>
                                                        <th>Último Mensaje</th>
                                                        <th>Estado IA</th>
                                                        <th>Acción</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach(array_slice($wa_leads, 0, 25) as $l): 
                                                        $c_phone = preg_replace('/[^0-9]/', '', $l['phone'] ?? '');
                                                        $c_session = get_wa_session($wa_id, $c_phone);
                                                        $is_human = !empty($c_session['human_mode']);
                                                    ?>
                                                        <tr>
                                                            <td><strong><?php echo htmlspecialchars($l['name'] ?? 'Cliente'); ?></strong></td>
                                                            <td><a href="https://wa.me/<?php echo $c_phone; ?>" target="_blank" style="color:#0284c7; text-decoration:none; font-weight:600;">+<?php echo $c_phone; ?> ↗</a></td>
                                                            <td style="color:#64748b; font-size:12px;"><?php echo htmlspecialchars($l['date'] ?? ''); ?></td>
                                                            <td>
                                                                <?php if ($is_human): ?>
                                                                    <span class="badge" style="background:#fef3c7; color:#92400e; font-size:11px;">👨‍💻 Humano Atendiendo (IA Pausada)</span>
                                                                <?php else: ?>
                                                                    <span class="badge" style="background:#dcfce7; color:#166534; font-size:11px;">🟢 IA Activa</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <a href="?tab=whatsapp&toggle_wa_contact=<?php echo $wa_id; ?>&phone=<?php echo $c_phone; ?>&opened_wa=<?php echo $wa_id; ?>" 
                                                                   class="btn-sm" 
                                                                   style="text-decoration:none; font-size:12px; padding:4px 8px; background:<?php echo $is_human ? '#dcfce7' : '#fee2e2'; ?>; color:<?php echo $is_human ? '#166534' : '#991b1b'; ?>;">
                                                                    <?php echo $is_human ? '▶️ Reactivar IA' : '⏸️ Silenciar IA'; ?>
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- FORMULARIO EDITAR INSTANCIA WHATSAPP -->
                                <form method="post" style="margin-top: 20px; background: white; padding: 20px; border-radius: 8px; border: 1px solid var(--border);">
                                    <input type="hidden" name="wa_id" value="<?php echo $wa_id; ?>">

                                    <label class="checkbox-label" style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #cbd5e1; font-weight:700; margin-bottom:15px; display:flex; align-items:center; gap:8px;">
                                        <input type="checkbox" name="wa_is_active" value="1" <?php echo $is_wa_active ? 'checked' : ''; ?>>
                                        🤖 Responder automáticamente con este Bot (Desmarca para desactivar la IA en este número y atender manualmente)
                                    </label>

                                    <label>Nombre del Bot / Número:</label>
                                    <input type="text" name="wa_name" value="<?php echo htmlspecialchars($wa['name']); ?>" required>

                                    <label>Número de WhatsApp (Referencia):</label>
                                    <input type="text" name="wa_phone_number" value="<?php echo htmlspecialchars($wa['phone_number'] ?? ''); ?>">

                                    <label>Motor de Conexión:</label>
                                    <select name="wa_engine" onchange="toggleWaEngineFields(this.value, '<?php echo $wa_id; ?>')">
                                        <option value="evolution" <?php echo $wa_engine === 'evolution' ? 'selected' : ''; ?>>📲 Código QR (Evolution API en Render / Railway / VPS)</option>
                                        <option value="meta" <?php echo $wa_engine === 'meta' ? 'selected' : ''; ?>>🏢 Meta WhatsApp Cloud API (Oficial sin QR / SMS)</option>
                                    </select>

                                    <!-- CAMPOS EVOLUTION EDIT -->
                                    <div id="wa_evo_fields_<?php echo $wa_id; ?>" class="webhook-highlight" style="display:<?php echo $wa_engine === 'evolution' ? 'block' : 'none'; ?>; background:#f0fdf4; border-color:#bbf7d0;">
                                        <label style="color:#166534;">URL Servidor Evolution API:</label>
                                        <input type="text" name="wa_server_url" value="<?php echo htmlspecialchars($wa['server_url'] ?? ''); ?>" placeholder="https://mi-evolution.onrender.com">

                                        <label style="color:#166534;">API Key / Token del Servidor:</label>
                                        <input type="text" name="wa_server_token" value="<?php echo htmlspecialchars($wa['server_token'] ?? ''); ?>">

                                        <label style="color:#166534;">Nombre de Instancia Evolution:</label>
                                        <input type="text" name="wa_instance_name" value="<?php echo htmlspecialchars($wa['instance_name'] ?? $wa_id); ?>">
                                    </div>

                                    <!-- CAMPOS META EDIT -->
                                    <div id="wa_meta_fields_<?php echo $wa_id; ?>" class="webhook-highlight" style="display:<?php echo $wa_engine === 'meta' ? 'block' : 'none'; ?>; background:#f0fdf4; border-color:#bbf7d0;">
                                        <label style="color:#166534;">Phone Number ID (Meta):</label>
                                        <input type="text" name="meta_phone_id" value="<?php echo htmlspecialchars($wa['meta_phone_id'] ?? ''); ?>">

                                        <label style="color:#166534;">Token de Acceso Permanente (Meta):</label>
                                        <input type="text" name="meta_token" value="<?php echo htmlspecialchars($wa['meta_token'] ?? ''); ?>">

                                        <label style="color:#166534;">Verify Token:</label>
                                        <input type="text" name="meta_verify_token" value="<?php echo htmlspecialchars($wa['meta_verify_token'] ?? ''); ?>">
                                    </div>

                                    <label style="margin-top:12px;">Modo de Funcionamiento:</label>
                                    <select name="wa_bot_mode">
                                        <option value="hybrid" <?php echo $wa_mode === 'hybrid' ? 'selected' : ''; ?>>⚡ Híbrido (Menú Anidado + Gemini si escribe duda libre)</option>
                                        <option value="menu_only" <?php echo $wa_mode === 'menu_only' ? 'selected' : ''; ?>>📋 Solo Menú de Opciones Anidadas (Sin IA)</option>
                                        <option value="gemini_only" <?php echo $wa_mode === 'gemini_only' ? 'selected' : ''; ?>>🤖 Solo Gemini IA (Conversación libre)</option>
                                    </select>

                                    <label>Mensaje de Bienvenida:</label>
                                    <textarea name="wa_welcome_msg" rows="3" required><?php echo htmlspecialchars($wa['welcome_msg'] ?? ''); ?></textarea>

                                    <label>Árbol de Opciones Anidadas:</label>
                                    <textarea name="wa_nested_menu" rows="6"><?php echo htmlspecialchars($wa['nested_menu'] ?? ''); ?></textarea>

                                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

                                    <label>API Key(s) de Gemini (Rotación Multi-Key):</label>
                                    <div class="help-text">Puedes colocar varias separadas por coma para respaldo automático si una se agota:</div>
                                    <input type="text" name="wa_api_key" value="<?php echo htmlspecialchars($wa['api_key'] ?? ''); ?>" placeholder="AIzaSy1, AIzaSy2..." required>

                                    <label>Modelo de Gemini:</label>
                                    <input type="text" name="wa_model" value="<?php echo htmlspecialchars($wa['model'] ?? 'gemini-3.0-flash'); ?>" required>

                                    <label>Prompt para WhatsApp:</label>
                                    <textarea name="wa_prompt" rows="4" required><?php echo htmlspecialchars($wa['prompt'] ?? ''); ?></textarea>

                                    <label>Mensaje de Respaldo (Fallback):</label>
                                    <textarea name="wa_fallback_msg" rows="2" required><?php echo htmlspecialchars($wa['fallback_msg'] ?? ''); ?></textarea>

                                    <label>Webhook Externo de Leads:</label>
                                    <input type="text" name="wa_webhook_url" value="<?php echo htmlspecialchars($wa['webhook_url'] ?? ''); ?>">

                                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                                        <button type="submit" name="update_wa_instance" style="flex-grow:1; background:#16a34a;">💾 Guardar Cambios WhatsApp</button>
                                        <a href="?tab=whatsapp&delete_wa=<?php echo $wa_id; ?>" onclick="return confirm('¿Seguro que deseas eliminar esta instancia de WhatsApp?');" style="text-decoration: none;">
                                            <button type="button" class="btn-danger">🗑️ Borrar Número</button>
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>
    <?php endif; // Fin if active_tab === 'whatsapp' ?>

    <!-- JAVASCRIPT DEL BUSCADOR Y ACORDEÓN -->
    <script>
        function toggleBotItem(botId) {
            const item = document.getElementById('bot_item_' + botId);
            if (item) {
                item.classList.toggle('open');
            }
        }

        function expandAllBots() {
            document.querySelectorAll('.bot-accordion-item').forEach(el => el.classList.add('open'));
        }

        function collapseAllBots() {
            document.querySelectorAll('.bot-accordion-item').forEach(el => el.classList.remove('open'));
        }

        function filterAndRenderBots() {
            const q = document.getElementById('botSearchInput').value.trim().toLowerCase();
            const clearBtn = document.getElementById('clearSearchBtn');
            clearBtn.style.display = q ? 'block' : 'none';

            const items = document.querySelectorAll('.bot-accordion-item');
            let visibleCount = 0;

            items.forEach(item => {
                const name = item.getAttribute('data-name') || '';
                const id = item.getAttribute('data-id') || '';
                const date = item.getAttribute('data-date') || '';
                const dateRaw = item.getAttribute('data-dateraw') || '';
                const model = item.getAttribute('data-model') || '';

                if (!q || name.includes(q) || id.includes(q) || date.includes(q) || dateRaw.includes(q) || model.includes(q)) {
                    item.style.display = 'block';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            document.getElementById('botSearchCounter').innerText = `Mostrando ${visibleCount} de ${items.length} chats`;
        }

        function clearSearchInput() {
            document.getElementById('botSearchInput').value = '';
            filterAndRenderBots();
            document.getElementById('botSearchInput').focus();
        }

        function copyText(str, btn) {
            navigator.clipboard.writeText(str).then(() => {
                const orig = btn.innerText;
                btn.innerText = '¡Copiado! ✓';
                btn.style.background = '#22c55e';
                btn.style.color = '#fff';
                setTimeout(() => {
                    btn.innerText = orig;
                    btn.style.background = '';
                    btn.style.color = '';
                }, 2000);
            }).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = str;
                document.body.appendChild(ta);
                ta.select();
                // clipboard fallback;
                document.body.removeChild(ta);
                btn.innerText = '¡Copiado! ✓';
                setTimeout(() => { btn.innerText = 'Copiar'; }, 2000);
            });
        }

        function toggleWaItem(waId) {
            const item = document.getElementById('wa_item_' + waId);
            if (item) item.classList.toggle('open');
        }

        function toggleWaEngineFields(engine, context) {
            const evoBox = document.getElementById(context === 'new' ? 'wa_new_evo_fields' : 'wa_evo_fields_' + context);
            const metaBox = document.getElementById(context === 'new' ? 'wa_new_meta_fields' : 'wa_meta_fields_' + context);
            if (evoBox && metaBox) {
                evoBox.style.display = (engine === 'evolution') ? 'block' : 'none';
                metaBox.style.display = (engine === 'meta') ? 'block' : 'none';
            }
        }

        async function fetchWaPairingCode(waId, serverUrl, instanceName, serverToken, webhookUrl = '', defaultPhone = '') {
            const qrBox = document.getElementById('qr_box_' + waId);
            const imgContainer = document.getElementById('qr_img_container_' + waId);
            qrBox.style.display = 'block';

            let phone = defaultPhone || '';
            if (!phone) {
                let phoneInput = document.querySelector('input[name="wa_phone_number"]');
                if (phoneInput && phoneInput.value.trim()) {
                    phone = phoneInput.value.trim();
                }
            }
            phone = prompt('Ingresa o confirma tu número de WhatsApp con código de país (Ej: 593979138745):', phone || '593979138745');
            if (!phone) {
                qrBox.style.display = 'none';
                return;
            }
            const cleanPhone = phone.replace(/[^0-9]/g, '');

            imgContainer.innerHTML = '<div style="padding:20px; color:#2563eb;">⏳ Generando código de vinculación para +' + cleanPhone + '...</div>';

            try {
                const cleanUrl = serverUrl.replace(/\/+$/, '');
                
                // 1. Intentar conectar solicitando pairingCode con el número
                let res = await fetch(`${cleanUrl}/instance/connect/${encodeURIComponent(instanceName)}?number=${cleanPhone}`, {
                    headers: { 'apikey': serverToken }
                });
                let data = await res.json();

                // 2. Si no existe la instancia, crearla con pairing
                if (res.status === 404 || (data.response && JSON.stringify(data.response).includes('does not exist'))) {
                    imgContainer.innerHTML = '<div style="padding:20px; color:#64748b;">⚙️ Creando instancia "' + instanceName + '"...</div>';
                    await fetch(`${cleanUrl}/instance/create`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'apikey': serverToken },
                        body: JSON.stringify({
                            instanceName: instanceName,
                            qrcode: false,
                            number: cleanPhone
                        })
                    });
                    
                    if (webhookUrl) {
                        try {
                            await fetch(`${cleanUrl}/webhook/set/${encodeURIComponent(instanceName)}`, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'apikey': serverToken },
                                body: JSON.stringify({
                                    url: webhookUrl,
                                    enabled: true,
                                    webhook_by_events: false,
                                    events: ['MESSAGES_UPSERT']
                                })
                            });
                        } catch(e) {}
                    }

                    res = await fetch(`${cleanUrl}/instance/connect/${encodeURIComponent(instanceName)}?number=${cleanPhone}`, {
                        headers: { 'apikey': serverToken }
                    });
                    data = await res.json();
                }

                // Si la instancia estaba en espera de QR previo, reiniciar socket para emitir pairingCode
                if (!data.pairingCode && !((data.instance && data.instance.state === 'open') || data.status === 'open' || data.state === 'open')) {
                    imgContainer.innerHTML = '<div style="padding:20px; color:#2563eb;">⏳ Solicitando código directo a WhatsApp para +' + cleanPhone + '...</div>';
                    try {
                        await fetch(`${cleanUrl}/instance/logout/${encodeURIComponent(instanceName)}`, {
                            method: 'DELETE',
                            headers: { 'apikey': serverToken }
                        });
                    } catch(logoutErr) {}
                    await new Promise(r => setTimeout(r, 1500));
                    res = await fetch(`${cleanUrl}/instance/connect/${encodeURIComponent(instanceName)}?number=${cleanPhone}`, {
                        headers: { 'apikey': serverToken }
                    });
                    data = await res.json();
                }

                const pairingCode = data.pairingCode || (data.code && data.code.length <= 10 ? data.code : null);
                if (pairingCode) {
                    imgContainer.innerHTML = `
                        <div style="background:#eff6ff; border:2px dashed #3b82f6; border-radius:12px; padding:20px; max-width:440px; margin:0 auto;">
                            <div style="font-weight:700; color:#1e40af; font-size:16px; margin-bottom:8px;">🔑 Código de Vinculación</div>
                            <div style="font-size:32px; font-weight:800; letter-spacing:4px; color:#1d4ed8; background:#fff; padding:12px 20px; border-radius:8px; border:1px solid #bfdbfe; display:inline-block; margin:10px 0; font-family:monospace;">
                                ${pairingCode}
                            </div>
                            <div style="margin:10px 0;">
                                <button type="button" class="btn-sm" style="background:#2563eb;" onclick="navigator.clipboard.writeText('${pairingCode}'); alert('¡Código ${pairingCode} copiado al portapapeles!');">📋 Copiar Código</button>
                            </div>
                            <div style="text-align:left; background:#fff; padding:12px; border-radius:8px; font-size:13px; color:#334155; margin-top:12px; line-height:1.6;">
                                <strong>Pasos en tu teléfono:</strong><br>
                                1. Abre WhatsApp ➔ Ajustes (o 3 puntos) ➔ <strong>Dispositivos vinculados</strong>.<br>
                                2. Toca <strong>Vincular un dispositivo</strong>.<br>
                                3. En la parte inferior, toca: <span style="color:#2563eb; font-weight:700;">"Vincular con el número de teléfono"</span>.<br>
                                4. Escribe el código <strong>${pairingCode}</strong>.
                            </div>
                        </div>
                    `;
                } else if ((data.instance && (data.instance.state === 'open' || data.instance.status === 'open')) || data.status === 'open' || data.state === 'open') {
                    imgContainer.innerHTML = '<div style="background:#dcfce7; color:#166534; padding:15px; border-radius:8px; font-weight:700;">✅ ¡WhatsApp ya se encuentra conectado y activo!</div>';
                } else {
                    imgContainer.innerHTML = `
                        <div style="color:#b45309; padding:15px; background:#fef3c7; border-radius:8px;">
                            ⚠️ El servidor no devolvió código numérico en este intento. Puedes vincular mediante el botón <strong>"📷 Código QR"</strong>.
                            <br><br>
                            <button type="button" class="btn-sm" style="background:#2563eb;" onclick="fetchWaPairingCode('${waId}', '${serverUrl}', '${instanceName}', '${serverToken}', '${webhookUrl}', '${cleanPhone}')">Reintentar Código</button>
                            <button type="button" class="btn-sm" style="background:#16a34a; margin-left:8px;" onclick="fetchWaQr('${waId}', '${serverUrl}', '${instanceName}', '${serverToken}', '${webhookUrl}')">📷 Usar Código QR</button>
                        </div>
                    `;
                }
            } catch(e) {
                imgContainer.innerHTML = `<div style="color:#dc2626; padding:15px; background:#fee2e2; border-radius:8px;">❌ Error: ${e.message}</div>`;
            }
        }

        async function fetchWaQr(waId, serverUrl, instanceName, serverToken, webhookUrl = '') {
            const qrBox = document.getElementById('qr_box_' + waId);
            const imgContainer = document.getElementById('qr_img_container_' + waId);
            qrBox.style.display = 'block';
            imgContainer.innerHTML = '<div style="padding:20px; color:#64748b;">⏳ Conectando con Evolution API para generar QR...</div>';
            
            try {
                const cleanUrl = serverUrl.replace(/\/+$/, '');
                
                // 1. Intentar conectar
                let res = await fetch(`${cleanUrl}/instance/connect/${encodeURIComponent(instanceName)}`, {
                    headers: { 'apikey': serverToken }
                });
                let data = await res.json();
                
                // 2. Si no existe la instancia (404), la creamos automáticamente
                if (res.status === 404 || (data.response && JSON.stringify(data.response).includes('does not exist'))) {
                    imgContainer.innerHTML = '<div style="padding:20px; color:#64748b;">⚙️ Creando instancia "' + instanceName + '" en el servidor...</div>';
                    const createRes = await fetch(`${cleanUrl}/instance/create`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'apikey': serverToken },
                        body: JSON.stringify({
                            instanceName: instanceName,
                            qrcode: true
                        })
                    });
                    data = await createRes.json();
                    
                    // Si se indicó webhook, registrarlo automáticamente
                    if (webhookUrl) {
                        try {
                            await fetch(`${cleanUrl}/webhook/set/${encodeURIComponent(instanceName)}`, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'apikey': serverToken },
                                body: JSON.stringify({
                                    url: webhookUrl,
                                    enabled: true,
                                    webhook_by_events: false,
                                    events: ['MESSAGES_UPSERT']
                                })
                            });
                        } catch(wErr) {
                            console.warn('Webhook auto-set warning:', wErr);
                        }
                    }
                }
                
                // 3. Renderizar imagen QR o estado
                const b64 = data.base64 || (data.qrcode && data.qrcode.base64);
                if (b64) {
                    imgContainer.innerHTML = `
                        <div style="margin-bottom:10px; font-weight:600; color:#15803d;">¡Código QR generado con éxito!</div>
                        <img src="${b64}" style="max-width:260px; border-radius:10px; border:3px solid #22c55e; box-shadow:0 4px 15px rgba(0,0,0,0.15);" alt="QR WhatsApp">
                        <div style="margin-top:10px;">
                            <button type="button" class="btn-sm" style="background:#0284c7;" onclick="fetchWaQr('${waId}', '${serverUrl}', '${instanceName}', '${serverToken}', '${webhookUrl}')">🔄 Refrescar QR</button>
                        </div>
                    `;
                } else if ((data.instance && (data.instance.state === 'open' || data.instance.status === 'open')) || data.status === 'open' || data.state === 'open') {
                    imgContainer.innerHTML = '<div style="background:#dcfce7; color:#166534; padding:15px; border-radius:8px; font-weight:700;">✅ ¡WhatsApp ya se encuentra conectado y activo!</div>';
                } else {
                    imgContainer.innerHTML = `
                        <div style="color:#b45309; padding:15px; background:#fef3c7; border-radius:8px;">
                            ⚠️ Respuesta del servidor: ${JSON.stringify(data)}
                            <br><br>
                            <button type="button" class="btn-sm" style="background:#0284c7;" onclick="fetchWaQr('${waId}', '${serverUrl}', '${instanceName}', '${serverToken}', '${webhookUrl}')">Reintentar</button>
                        </div>
                    `;
                }
            } catch(e) {
                imgContainer.innerHTML = `<div style="color:#dc2626; padding:15px; background:#fee2e2; border-radius:8px;">❌ Error al conectar con ${serverUrl}: ${e.message}. Verifica que el contenedor en Render no esté dormido.</div>`;
            }
        }

        async function testWaChat(waId) {
            const input = document.getElementById('sim_input_' + waId);
            const chatArea = document.getElementById('sim_chat_' + waId);
            const msg = input.value.trim();
            if (!msg) return;
            
            // Agregar mensaje del usuario
            const userBubble = document.createElement('div');
            userBubble.className = 'sim-bubble user';
            userBubble.innerText = msg;
            chatArea.appendChild(userBubble);
            input.value = '';
            chatArea.scrollTop = chatArea.scrollHeight;
            
            // Indicador de escribiendo
            const typing = document.createElement('div');
            typing.className = 'sim-bubble bot';
            typing.innerText = '✍️ Escribiendo...';
            chatArea.appendChild(typing);
            chatArea.scrollTop = chatArea.scrollHeight;
            
            try {
                const res = await fetch(`whatsapp_webhook.php?id=${encodeURIComponent(waId)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        test_phone: '584240000000',
                        test_message: msg
                    })
                });
                const data = await res.json();
                typing.remove();
                
                const botBubble = document.createElement('div');
                botBubble.className = 'sim-bubble bot';
                botBubble.innerText = data.reply || (data.status === 'no_reply_needed' ? '(El bot no envió respuesta automática)' : '⚠️ Sin respuesta');
                chatArea.appendChild(botBubble);
                chatArea.scrollTop = chatArea.scrollHeight;
            } catch(e) {
                typing.remove();
                const errBubble = document.createElement('div');
                errBubble.className = 'sim-bubble bot';
                errBubble.style.color = '#dc2626';
                errBubble.innerText = '❌ Error de conexión al probar el bot.';
                chatArea.appendChild(errBubble);
            }
        }
    </script>

<?php endif; ?>
</body>
</html>
