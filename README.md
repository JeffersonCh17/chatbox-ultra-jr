# 🚀 Chatbox Ultra JR

**Chatbox Ultra JR** es una plataforma integral de chatbots inteligentes omnicanal (Web + WhatsApp) impulsada por **Google Gemini AI (`gemini-3.5-flash`)** y un potente motor de **Menús Anidados Interactivos (0 Tokens)**.

Desarrollada en PHP nativo ultra liviano, compatible con cualquier servidor compartido (cPanel, HostGator, Apache/Nginx), VPS o Docker, sin dependencias pesadas ni frameworks lentos.

---

## ✨ Características Principales

### 🌐 Chat Web Inteligente
* **Widget Flotante Incrustable:** Conexión con una sola línea de código en cualquier sitio web o HTML.
* **Integración con Google Gemini (`gemini-3.5-flash`):** Respuestas contextuales ultrarrápidas, streaming fluido y soporte de prompts personalizados por bot.
* **Rotación Multi-Key Automática:** Configura varias API Keys de Gemini separadas por coma. Si una se satura o agota cuota, el sistema conmuta a la siguiente sin interrupciones.
* **Carga y Lectura de Documentos PDF:** Sube un manual o catálogo en PDF; la IA lo absorbe como base de conocimiento para responder a los usuarios.
* **Captura de Leads Pre-chat:** Formulario opcional para capturar Nombre y Teléfono antes de chatear, con reenvío instantáneo por Webhook (CRM, n8n, LeadsEvolution).

### 📱 Chatbot para WhatsApp (Dual-Engine)
* **Motor Evolution API (Código QR y Código de 8 Dígitos):** Vinculación gratuita vía Render, Railway o VPS utilizando Baileys.
* **Motor Meta Cloud API:** Conexión oficial de WhatsApp Cloud API mediante Token y Phone ID de Meta for Developers.
* **3 Modos de Operación:**
  1. `Solo Menú Anidado (0 Tokens)`: Flujo guiado de opciones numeradas (`[1]`, `[1.1]`, etc.) sin costo de IA ni gasto de tokens.
  2. `Híbrido`: Menú interactivo para opciones clave y respuesta con IA de Gemini para preguntas libres.
  3. `Solo IA Gemini`: Conversación abierta guiada por el prompt del sistema.
* **Detección Anti-Bucle y Filtro de Ecos:** Previene bucles infinitos en chats propios y detecta automáticamente cuando un humano interviene para pausar el bot de forma inteligente.
* **Escalamiento a Asesor Humano (`[HUMAN_ESCALATE]`):** Pausa el bot y transfiere el chat a un asesor humano en tiempo real.
* **Simulador de Pruebas Integrado:** Prueba el flujo y respuestas en tiempo real desde el propio panel de administración antes de lanzar a clientes.

### ⚙️ Panel de Administración Unificado
* **Diseño Moderno y Responsivo:** Gestor visual intuitivo con tabs para Chats Web y Canales de WhatsApp.
* **Monitor de Webhook en Vivo:** Visor de eventos entrantes y salientes en tiempo real para diagnóstico inmediato.
* **Keepalive Automático (`keepalive.php`):** Script para tareas programadas (Cron) que mantiene despiertos contenedores gratuitos (ej. Render) 24/7.

---

## 📁 Estructura del Proyecto

```text
├── admin.php               # Panel de control maestro y configurador visual
├── api.php                 # Endpoint receptor para el widget web
├── chat.php                # Interfaz completa de chat web a pantalla completa
├── keepalive.php           # Script para Cron Job de mantenimiento (Render Keepalive)
├── settings_manager.php    # Núcleo de rotación de claves y llamadas a Gemini API
├── whatsapp_engine.php     # Procesador del menú anidado y despachador de WhatsApp
├── whatsapp_webhook.php    # Webhook universal receptor de eventos WhatsApp
├── widget.php              # Widget embebible con burbuja flotante para páginas web
├── data.example.php        # Plantilla de configuración de bots web
├── whatsapp_data.example.php # Plantilla de configuración de instancias de WhatsApp
├── .gitignore              # Protección de datos sensibles, logs y credenciales
└── README.md               # Documentación oficial
```

---

## 🚀 Instalación Rápida

### 1. Clonar el repositorio
```bash
git clone https://github.com/JeffersonCh17/chatbox-ultra-jr.git
cd chatbox-ultra-jr
```

### 2. Subir a tu Servidor Web / Hosting
Sube los archivos a la carpeta pública (`public_html` o similar) de tu hosting Apache/Nginx con soporte PHP 7.4 o superior (incluyendo PHP 8.x).

### 3. Permisos
Asegúrate de que la carpeta raíz tenga permisos de escritura (`755` para carpetas, `644` para archivos) para permitir la persistencia automática de configuraciones en `data.php` y `whatsapp_data.php`.

### 4. Acceder al Panel
Ingresa en tu navegador a:
```text
https://tudominio.com/admin.php
```
* **Contraseña Maestra por Defecto:** `283febrero` *(puedes personalizarla en `admin.php`)*.

---

## 🤖 Modelos de Gemini Soportados

El motor está optimizado para la generación actual de **Google Gemini**:
* `gemini-3.5-flash` *(Recomendado: velocidad ultra rápida y cuota amplia)*
* `gemini-flash-latest`
* `gemini-2.5-flash`
* `gemini-pro-latest`

Consigue tus API Keys gratuitas en [Google AI Studio](https://aistudio.google.com/app/apikey).

---

## 👨‍💻 Autor
Desarrollado por **JeffersonCh17** — Soluciones Tecnológicas & Automatización Digital.

---

## 📄 Licencia
Este proyecto está bajo la Licencia MIT. Siéntete libre de usarlo, modificarlo y desplegarlo en tus proyectos comerciales y personales.
