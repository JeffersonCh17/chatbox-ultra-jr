<?php
header('Content-Type: application/javascript; charset=utf-8');
header("Access-Control-Allow-Origin: *");
require_once 'settings_manager.php';
$settings = get_settings();

$bot_id = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['id']) : '';
$is_fullscreen = isset($_GET['fs']) && $_GET['fs'] == '1';

if (empty($bot_id) || !isset($settings['bots'][$bot_id])) {
    echo "console.error('Gemini Chat: Error, el ID de bot proporcionado no existe en la configuración.');";
    exit;
}

$bot = $settings['bots'][$bot_id];

$color = !empty($bot['color']) ? $bot['color'] : '#2563eb';
$color2 = !empty($bot['color2']) ? $bot['color2'] : '#9333ea';
$btn_design = $bot['btn_design'] ?? 'classic';

$tooltip = $bot['tooltip'] ?? ''; 
$require_form = isset($bot['require_form']) ? $bot['require_form'] : true;

$quick_replies_raw = isset($bot['quick_replies']) ? explode("\n", str_replace("\r", "", $bot['quick_replies'])) : [];
$clean_quick_replies = [];
foreach($quick_replies_raw as $qr) {
    $qr = trim($qr);
    if($qr !== '') {
        if (preg_match('/^\[(.*?)\]\((.*?)\)$/', $qr, $matches)) {
            $clean_quick_replies[] = [
                'type' => 'link',
                'label' => trim($matches[1]),
                'url' => trim($matches[2])
            ];
        } 
        elseif (strpos($qr, '::') !== false) {
            list($label, $response) = explode('::', $qr, 2);
            $clean_quick_replies[] = [
                'type' => 'static',
                'label' => trim($label),
                'response' => trim($response)
            ];
        } 
        else {
            $clean_quick_replies[] = [
                'type' => 'ai',
                'label' => $qr
            ];
        }
    }
}

$base_url = get_system_base_url();
$bot_name = !empty($bot['name']) ? $bot['name'] : 'Asistente IA';

// Generar SVG según diseño
$svg_icon = '<svg viewBox="0 0 24 24"><path d="M20 2H4C2.9 2 2 2.9 2 4V22L6 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2M20 16H6.8L4 18.8V4H20V16Z"></path></svg>';
if ($btn_design === 'robot') {
    $svg_icon = '<svg viewBox="0 0 24 24"><path d="M12,2A2,2 0 0,1 14,4C14,4.74 13.6,5.39 13,5.73V7H14A7,7 0 0,1 21,14H22A1,1 0 0,1 23,15V18A1,1 0 0,1 22,19H21V20A2,2 0 0,1 19,22H5A2,2 0 0,1 3,20V19H2A1,1 0 0,1 1,18V15A1,1 0 0,1 2,14H3A7,7 0 0,1 10,7H11V5.73C10.4,5.39 10,4.74 10,4A2,2 0 0,1 12,2M7.5,13A2.5,2.5 0 0,0 5,15.5A2.5,2.5 0 0,0 7.5,18A2.5,2.5 0 0,0 10,15.5A2.5,2.5 0 0,0 7.5,13M16.5,13A2.5,2.5 0 0,0 14,15.5A2.5,2.5 0 0,0 16.5,18A2.5,2.5 0 0,0 19,15.5A2.5,2.5 0 0,0 16.5,13Z"></path></svg>';
}

$border_radius = ($btn_design === 'square') ? '16px' : '50%';
$is_camouflaged = ($btn_design === 'camouflaged');
?>
(function() {
    function initGeminiChatWidget() {
        // PREVENIR EJECUCIÓN DOBLE SI EL USUARIO PEGA EL SCRIPT 2 VECES
        if (window.geminiChatWidgetInitialized) return;
        window.geminiChatWidgetInitialized = true;

        const primaryColor = '<?php echo $color; ?>';
        const secondaryColor = '<?php echo $color2; ?>';
        const tooltipText = <?php echo json_encode($tooltip); ?>;
        const apiUrl = '<?php echo $base_url; ?>/api.php';
        const botId = '<?php echo $bot_id; ?>';
        const botTitle = <?php echo json_encode($bot_name); ?>;
        const requireForm = <?php echo $require_form ? 'true' : 'false'; ?>;
        const quickReplies = <?php echo json_encode($clean_quick_replies); ?>;
        const isFullscreen = <?php echo $is_fullscreen ? 'true' : 'false'; ?>;
        const fullscreenUrl = '<?php echo $base_url; ?>/chat.php?id=' + encodeURIComponent(botId);

        const style = document.createElement('style');
        style.innerHTML = `
            #gemini-chat-widget-container {
                position: fixed; bottom: 20px; right: 20px; z-index: 2147483647;
                display: flex; align-items: center; gap: 15px;
                font-family: system-ui, -apple-system, sans-serif;
            }

            #gemini-chat-tooltip {
                background: linear-gradient(135deg, ${secondaryColor}, ${primaryColor});
                color: white; padding: 10px 18px; border-radius: 20px;
                font-weight: 600; font-size: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                position: relative; animation: gemini-bounce 2s infinite;
                cursor: pointer; white-space: nowrap;
            }
            #gemini-chat-tooltip::after {
                content: ''; position: absolute; right: -6px; top: 50%; transform: translateY(-50%);
                border-width: 6px 0 6px 6px; border-style: solid;
                border-color: transparent transparent transparent ${primaryColor}; 
            }

            @keyframes gemini-bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }

            #gemini-chat-widget-btn {
                width: <?php echo $is_camouflaged ? '32px' : '65px'; ?>;
                height: <?php echo $is_camouflaged ? '32px' : '65px'; ?>;
                background: <?php echo $is_camouflaged ? 'rgba(30, 41, 59, 0.45)' : "linear-gradient(135deg, \${primaryColor}, \${secondaryColor})"; ?>;
                border-radius: <?php echo $border_radius; ?>;
                box-shadow: <?php echo $is_camouflaged ? '0 2px 6px rgba(0,0,0,0.2)' : '0 4px 15px rgba(0,0,0,0.3)'; ?>;
                opacity: <?php echo $is_camouflaged ? '0.35' : '1'; ?>;
                cursor: pointer; display: flex; align-items: center; justify-content: center;
                transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
                backdrop-filter: blur(4px);
            }
            #gemini-chat-widget-btn:hover {
                transform: scale(<?php echo $is_camouflaged ? '1.15' : '1.05'; ?>);
                opacity: 1;
            }
            #gemini-chat-widget-btn svg {
                fill: white;
                width: <?php echo $is_camouflaged ? '16px' : '35px'; ?>;
                height: <?php echo $is_camouflaged ? '16px' : '35px'; ?>;
            }
            
            #gemini-chat-window {
                position: fixed; bottom: 95px; right: 20px;
                width: 360px; height: 550px; max-height: calc(100vh - 120px);
                background: white; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.2);
                z-index: 2147483647; display: none; flex-direction: column; overflow: hidden;
                font-family: system-ui, -apple-system, sans-serif; border: 1px solid #eaeaea; opacity: 0;
                transition: opacity 0.3s ease;
            }
            #gemini-chat-window.show { display: flex; opacity: 1; }
            
            /* MODO PANTALLA COMPLETA */
            .gemini-chat-fullscreen {
                position: fixed !important;
                top: 0 !important; left: 0 !important; bottom: 0 !important; right: 0 !important;
                width: 100vw !important; height: 100vh !important; height: 100dvh !important;
                max-height: 100% !important; max-width: 100% !important;
                border-radius: 0 !important; border: none !important;
                box-shadow: none !important; margin: 0 !important;
                z-index: 2147483647 !important;
            }
            
            /* OCULTAR EL BOTÓN LANZADOR SI LA VENTANA DEL CHAT ESTÁ ABIERTA */
            #gemini-chat-window.show ~ #gemini-chat-widget-container,
            #gemini-chat-widget-container.gemini-hidden {
                display: none !important;
            }

            #gemini-chat-header {
                background: linear-gradient(135deg, ${primaryColor}, ${secondaryColor}); 
                color: white; padding: 16px; font-weight: bold; font-size: 16px;
                display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                flex-shrink: 0; z-index: 20;
            }
            #gemini-chat-close { cursor: pointer; font-size: 24px; line-height: 1; opacity: 0.8; }
            #gemini-chat-close:hover { opacity: 1; }
            .gemini-fs-toggle { cursor: pointer; opacity: 0.8; display: inline-flex; align-items: center; text-decoration: none; color: white; }
            .gemini-fs-toggle:hover { opacity: 1; }

            /* FORMULARIO PREVIO */
            #gemini-chat-preform { display: flex; flex-direction: column; padding: 25px 20px; flex-grow: 1; background: #f8fafc; overflow-y: auto; }
            #gemini-chat-preform h4 { margin-top: 0; color: #1e293b; font-size: 16px; margin-bottom: 20px; }
            .gemini-input-group { margin-bottom: 18px; }

        .gemini-input-group label { display: block; font-size: 13px; color: #475569; margin-bottom: 6px; font-weight: bold; }
        .gemini-input-group input { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; font-size: 14px; box-sizing: border-box; outline: none; }
        .gemini-input-group input:focus { border-color: ${primaryColor}; }
        #gemini-start-chat-btn {
            background: linear-gradient(135deg, ${primaryColor}, ${secondaryColor}); 
            color: white; border: none; padding: 14px; border-radius: 8px; font-weight: bold; cursor: pointer; 
            width: 100%; font-size: 15px; margin-top: 10px; transition: opacity 0.2s; flex-shrink: 0;
        }
        #gemini-start-chat-btn:hover { opacity: 0.9; }

        /* CHAT PRINCIPAL */
        #gemini-chat-main { 
            display: none; 
            flex-direction: column; 
            flex-grow: 1; 
            height: 100%; 
            min-height: 0; 
            overflow: hidden; 
        }
        #gemini-chat-messages { 
            flex: 1 1 auto; 
            padding: 16px; 
            overflow-y: auto; 
            -webkit-overflow-scrolling: touch; 
            background: #f8fafc; 
            display: flex; 
            flex-direction: column; 
            gap: 12px; 
            scroll-behavior: smooth; 
            min-height: 0; 
        }
        .gemini-msg { max-width: 85%; padding: 12px 16px; border-radius: 16px; font-size: 14.5px; line-height: 1.5; word-wrap: break-word; }
        .gemini-msg.user { background: ${primaryColor}; color: white; align-self: flex-end; border-bottom-right-radius: 4px; }
        .gemini-msg.bot { background: white; color: #1e293b; align-self: flex-start; border-bottom-left-radius: 4px; border: 1px solid #e2e8f0; }
        .gemini-msg.error { background: #fee2e2; color: #991b1b; align-self: flex-start; border: 1px solid #f87171; border-bottom-left-radius: 4px; }
        
        /* BOTONES IA Y ENLACES */
        .gemini-btn-link {
            display: inline-block; background: ${secondaryColor}; color: white !important; 
            padding: 8px 14px; border-radius: 20px; text-decoration: none; font-weight: bold;
            font-size: 13.5px; margin-top: 8px; margin-bottom: 2px; text-align: center; 
            box-shadow: 0 3px 6px rgba(0,0,0,0.15); border: 1px solid rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .gemini-btn-link:hover { opacity: 0.95; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .gemini-msg.user .gemini-btn-link { background: white; color: ${secondaryColor} !important; }

        .gemini-quick-replies-container { display: flex; flex-direction: column; gap: 8px; margin-top: 5px; align-self: flex-start; max-width: 100%; width: 100%; }
        .gemini-qr-btn {
            background: white; border: 1px solid ${secondaryColor}; color: ${secondaryColor};
            padding: 10px 14px; border-radius: 8px; font-size: 13.5px; font-weight: 500;
            cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            text-align: left; display: block; width: 100%; text-decoration: none;
        }
        .gemini-qr-btn:hover { background: ${secondaryColor}; color: white; }

        /* BARRA INFERIOR DE MENSAJES (FIJA AL SCROLLEAR) */
        #gemini-chat-input-area { 
            display: flex; 
            align-items: center; 
            padding: 12px 14px; 
            background: white; 
            border-top: 1px solid #e2e8f0; 
            flex-shrink: 0; 
            position: relative; 
            z-index: 20; 
            box-sizing: border-box; 
            box-shadow: 0 -2px 8px rgba(0,0,0,0.03); 
            padding-bottom: max(12px, env(safe-area-inset-bottom));
        }
        #gemini-chat-input { 
            flex-grow: 1; 
            border: 1px solid #cbd5e1; 
            border-radius: 24px; 
            padding: 10px 16px; 
            outline: none; 
            font-size: 15px; 
            background: #f8fafc; 
            min-width: 0; 
            box-sizing: border-box; 
        }
        #gemini-chat-input:focus { border-color: ${primaryColor}; background: white; }
        
        #gemini-chat-send {
            background: linear-gradient(135deg, ${primaryColor}, ${secondaryColor}); 
            color: white; border: none; border-radius: 50%; width: 42px; height: 42px;
            margin-left: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            transition: transform 0.15s ease, opacity 0.15s ease;
        }
        #gemini-chat-send:active { transform: scale(0.92); }
        #gemini-chat-send svg { fill: white; width: 20px; height: 20px; margin-left: 2px; }
        
        .typing-indicator { display: inline-flex; align-items: center; gap: 4px; padding: 12px 16px; }
        .typing-dot { width: 6px; height: 6px; background: #94a3b8; border-radius: 50%; animation: typing 1.4s infinite ease-in-out both; }
        .typing-dot:nth-child(1) { animation-delay: -0.32s; } .typing-dot:nth-child(2) { animation-delay: -0.16s; }
        @keyframes typing { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }
        
        /* ADAPTACIÓN EN DISPOSITIVOS MÓVILES */
        @media (max-width: 480px) {
            #gemini-chat-window:not(.gemini-chat-fullscreen) { 
                position: fixed !important; 
                top: 0 !important; 
                left: 0 !important; 
                right: 0 !important; 
                bottom: 0 !important; 
                width: 100vw !important; 
                height: 100% !important; 
                height: 100dvh !important; 
                max-height: 100dvh !important; 
                max-width: 100vw !important; 
                border-radius: 0 !important; 
                border: none !important; 
                margin: 0 !important;
                z-index: 2147483647 !important;
            }
        }
    `;
    document.head.appendChild(style);

    const widgetContainer = document.createElement('div');
    
    let tooltipHtml = '';
    if (tooltipText && !isFullscreen) {
        tooltipHtml = `<div id="gemini-chat-tooltip">${tooltipText}</div>`;
    }

    widgetContainer.innerHTML = `
        <div id="gemini-chat-window" class="${isFullscreen ? 'gemini-chat-fullscreen show' : ''}">
            <div id="gemini-chat-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span>💬</span>
                    <span style="font-size:15px; font-weight:600;">${botTitle}</span>
                </div>
                <div style="display:flex; align-items:center; gap:12px;">
                    ${!isFullscreen ? `
                    <a href="${fullscreenUrl}" target="_blank" title="Abrir en pantalla completa" class="gemini-fs-toggle">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="white"><path d="M5 5h5V3H3v7h2V5zm14-2h-7v2h5v5h2V3zm-2 14v-5h-2v7h7v-2h-5zM5 14H3v7h7v-2H5v-5z"/></svg>
                    </a>` : ''}
                    <span id="gemini-chat-close" style="${isFullscreen ? 'display:none;' : ''}">&times;</span>
                </div>
            </div>
            
            <div id="gemini-chat-preform">
                <h4>Déjanos tus datos para comenzar</h4>
                <div class="gemini-input-group">
                    <label>Tu Nombre:</label>
                    <input type="text" id="gemini-user-name" placeholder="Ej: Juan Pérez" autocomplete="off">
                </div>
                <div class="gemini-input-group">
                    <label>Número de WhatsApp / Teléfono:</label>
                    <input type="text" id="gemini-user-phone" placeholder="Ej: +34 600 000 000" autocomplete="off">
                </div>
                <button id="gemini-start-chat-btn">Comenzar a chatear</button>
            </div>

            <div id="gemini-chat-main">
                <div id="gemini-chat-messages">
                    <div class="gemini-msg bot">¡Hola! Ya estoy listo para ayudarte. ¿En qué te puedo asesorar hoy?</div>
                </div>
                <div id="gemini-chat-input-area">
                    <input type="text" id="gemini-chat-input" placeholder="Escribe tu mensaje aquí..." autocomplete="off">
                    <button id="gemini-chat-send"><svg viewBox="0 0 24 24"><path d="M2,21L23,12L2,3V10L17,12L2,14V21Z"></path></svg></button>
                </div>
            </div>
        </div>
        
        ${!isFullscreen ? `
        <div id="gemini-chat-widget-container">
            ${tooltipHtml}
            <div id="gemini-chat-widget-btn"><?php echo $svg_icon; ?></div>
        </div>
        ` : ''}
    `;
    document.body.appendChild(widgetContainer);

    const btn = document.getElementById('gemini-chat-widget-btn');
    const tooltip = document.getElementById('gemini-chat-tooltip');
    const widgetBtnContainer = document.getElementById('gemini-chat-widget-container');
    const windowEl = document.getElementById('gemini-chat-window');
    const closeBtn = document.getElementById('gemini-chat-close');
    const preform = document.getElementById('gemini-chat-preform');
    const mainChat = document.getElementById('gemini-chat-main');
    const startBtn = document.getElementById('gemini-start-chat-btn');
    const userNameInput = document.getElementById('gemini-user-name');
    const userPhoneInput = document.getElementById('gemini-user-phone');
    const input = document.getElementById('gemini-chat-input');
    const sendBtn = document.getElementById('gemini-chat-send');
    const messages = document.getElementById('gemini-chat-messages');

    let chatHistory = [];
    let isOpen = isFullscreen;
    let userHasStarted = false;
    let qrRendered = false;

    if (isFullscreen) {
        if (!requireForm) {
            userHasStarted = true;
            preform.style.display = 'none';
            mainChat.style.display = 'flex';
            renderQuickReplies();
            setTimeout(() => { if (input) input.focus(); }, 150);
        } else {
            setTimeout(() => { if (userNameInput) userNameInput.focus(); }, 150);
        }
    }

    function renderQuickReplies() {
        if (qrRendered || quickReplies.length === 0) return;
        qrRendered = true;
        
        const qrContainer = document.createElement('div');
        qrContainer.className = 'gemini-quick-replies-container';
        
        quickReplies.forEach(qr => {
            if (qr.type === 'link') {
                const a = document.createElement('a');
                a.className = 'gemini-qr-btn';
                a.innerText = qr.label;
                a.href = qr.url;
                a.target = '_blank';
                qrContainer.appendChild(a);
            } else {
                const btn = document.createElement('button');
                btn.className = 'gemini-qr-btn';
                btn.innerText = qr.label;
                btn.onclick = () => {
                    qrContainer.style.display = 'none'; 
                    
                    if (qr.type === 'static') {
                        addMessage(qr.label, 'user');
                        
                        setTimeout(() => {
                            let replyHtml = qr.response.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank" class="gemini-btn-link">$1</a>');
                            replyHtml = replyHtml.replace(/\n/g, '<br>');
                            addMessage(replyHtml, 'bot');
                            
                            chatHistory.push({ role: 'user', text: qr.label });
                            chatHistory.push({ role: 'model', text: qr.response });
                        }, 400);

                    } else if (qr.type === 'ai') {
                        input.value = qr.label;
                        sendMessage();
                    }
                };
                qrContainer.appendChild(btn);
            }
        });
        messages.appendChild(qrContainer);
        messages.scrollTop = messages.scrollHeight;
    }

    function toggleChat() {
        if (isFullscreen) return;
        isOpen = !isOpen;
        
        if (tooltip) tooltip.style.display = 'none';

        if (isOpen) { 
            windowEl.classList.add('show'); 
            if (widgetBtnContainer) widgetBtnContainer.style.display = 'none';
            if (btn) btn.style.display = 'none';
            
            if (!requireForm && !userHasStarted) {
                userHasStarted = true;
                preform.style.display = 'none';
                mainChat.style.display = 'flex';
                renderQuickReplies();
                setTimeout(() => input.focus(), 100);
            } else {
                if (userHasStarted) {
                    setTimeout(() => input.focus(), 100); 
                } else {
                    setTimeout(() => userNameInput.focus(), 100); 
                }
            }
        } 
        else { 
            windowEl.classList.remove('show'); 
            if (widgetBtnContainer) widgetBtnContainer.style.display = 'flex';
            if (btn) btn.style.display = 'flex';
        }
    }

    if (btn) btn.addEventListener('click', toggleChat);
    if (tooltip) tooltip.addEventListener('click', toggleChat);
    if (closeBtn) closeBtn.addEventListener('click', toggleChat);

    startBtn.addEventListener('click', () => {
        const name = userNameInput.value.trim();
        const phone = userPhoneInput.value.trim();
        if (!name || !phone) { alert('Por favor, completa ambos campos.'); return; }
        userHasStarted = true;
        preform.style.display = 'none';
        mainChat.style.display = 'flex';
        renderQuickReplies();
        input.focus();
        
        // Guardar Lead en segundo plano y disparar Webhook
        try {
            fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_lead',
                    bot_id: botId,
                    name: name,
                    phone: phone
                })
            }).catch(e => console.log('Lead sync notice'));
        } catch(e) {}
        
        chatHistory.push({
            role: 'user', 
            text: `[SISTEMA INTERNO: El usuario se llama "${name}" y su teléfono es "${phone}". Llámalo por su nombre al responder y ten en cuenta su número si requiere contacto.]`
        });
        chatHistory.push({
            role: 'model',
            text: 'Entendido. Usaré esta información de forma natural.'
        });
    });

    function addMessage(text, typeClass) {
        const msgDiv = document.createElement('div');
        msgDiv.className = 'gemini-msg ' + typeClass;
        if (typeClass === 'user') {
            msgDiv.innerHTML = text.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
        } else {
            msgDiv.innerHTML = text; 
        }
        messages.appendChild(msgDiv);
        messages.scrollTop = messages.scrollHeight;
    }

    async function sendMessage() {
        const text = input.value.trim();
        if (!text) return;
        
        const qrContainer = document.querySelector('.gemini-quick-replies-container');
        if (qrContainer) qrContainer.style.display = 'none';

        addMessage(text, 'user');
        input.value = '';

        const typingDiv = document.createElement('div');
        typingDiv.className = 'gemini-msg bot typing-indicator';
        typingDiv.innerHTML = '<div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div>';
        messages.appendChild(typingDiv);
        messages.scrollTop = messages.scrollHeight;

        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text, history: chatHistory, bot_id: botId })
            });
            const data = await response.json();
            messages.removeChild(typingDiv);

            if (data.error) {
                addMessage('⚠️ ' + data.error, 'error');
            } else {
                addMessage(data.reply, 'bot');
                chatHistory.push({ role: 'user', text: text });
                chatHistory.push({ role: 'model', text: data.reply });
            }
        } catch (err) {
            messages.removeChild(typingDiv);
            addMessage('⚠️ Error de red. Revisa tu conexión a internet.', 'error');
        }
    }
    sendBtn.addEventListener('click', sendMessage);
    input.addEventListener('keypress', function(e) { if (e.key === 'Enter') sendMessage(); });
    } // Fin initGeminiChatWidget

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGeminiChatWidget);
    } else {
        initGeminiChatWidget();
    }
})();

