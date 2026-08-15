<?php
/**
 * Plugin Name: WP AI 智能客服系统
 * Plugin URI: https://github.com/zerozhao
 * Description: 基于 DeepSeek API 的 WooCommerce AI 智能客服，支持PC和移动端，多语言自动识别，RAG订单/商品上下文
 * Version: 1.2.0
 * Author: zerozhao
 * Author URI: https://github.com/zerozhao
 * License: GPL v2 or later
 * Text Domain: wp-ai-cs
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 4.0
 * WC tested up to: 11.0
 */

if (!defined('ABSPATH')) {
    exit; // 防止直接访问
}

// 定义插件常量
define('WP_AI_CS_VERSION', '1.2.0');
define('WP_AI_CS_PATH', plugin_dir_path(__FILE__));
define('WP_AI_CS_URL', plugin_dir_url(__FILE__));
define('WP_AI_CS_BASENAME', plugin_basename(__FILE__));

// 加载必要的文件
require_once WP_AI_CS_PATH . 'includes/class-i18n.php';
require_once WP_AI_CS_PATH . 'includes/class-api-handler.php';
require_once WP_AI_CS_PATH . 'includes/class-woo-integration.php';
require_once WP_AI_CS_PATH . 'includes/class-admin.php';
require_once WP_AI_CS_PATH . 'includes/class-chat-logger.php';
require_once WP_AI_CS_PATH . 'includes/class-local-knowledge.php';

/**
 * 主插件类
 */
class WP_AI_Customer_Service {

    private static $instance = null;
    private $i18n;
    private $api_handler;
    private $woo_integration;
    private $admin;
    private $logger;
    private $knowledge;

    /**
     * 单例模式
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        $this->init_hooks();
        $this->init_components();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks() {
        // 激活/停用钩子
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // WordPress 初始化
        add_action('init', array($this, 'init'));

        // 前端脚本
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // 前台显示聊天窗口
        add_action('wp_footer', array($this, 'render_chat_widget'));

        // 移动端 viewport 修复（确保不缩放）
        add_action('wp_head', array($this, 'add_mobile_viewport'), 99);

        // AJAX 路由
        add_action('wp_ajax_ai_chat_request', array($this, 'handle_chat_request'));
        add_action('wp_ajax_nopriv_ai_chat_request', array($this, 'handle_chat_request'));
    }

    /**
     * 初始化组件
     */
    private function init_components() {
        $this->i18n = WP_AI_CS_I18n::get_instance();
        $this->logger = new WP_AI_CS_Logger();
        $this->knowledge = new WP_AI_CS_Local_Knowledge();
        $this->api_handler = new WP_AI_CS_API_Handler($this->logger);
        $this->woo_integration = new WP_AI_CS_Woo_Integration($this->logger);
        $this->admin = new WP_AI_CS_Admin();
    }

    /**
     * 激活插件
     */
    public function activate() {
        // 创建日志目录并保护，禁止直接访问
        $log_dir = WP_AI_CS_PATH . 'logs';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // index.php: 404 静默,防止目录列表
        if (!file_exists($log_dir . '/index.php')) {
            file_put_contents($log_dir . '/index.php',
                '<?php if (!defined(\'ABSPATH\')) { http_response_code(404); exit; }',
                LOCK_EX);
        }

        // .htaccess: 多层防护,禁止所有访问
        if (!file_exists($log_dir . '/.htaccess')) {
            $htaccess = "# WP AI 客服日志目录访问保护\n"
                      . "Deny from all\n"
                      . "Options -Indexes\n"
                      . "<FilesMatch \"\\.(log|txt|bak|archived)\$\">\n"
                      . "    Order allow,deny\n"
                      . "    Deny from all\n"
                      . "</FilesMatch>\n"
                      . "<FilesMatch \"\\.(php|phtml|phar|sh|py)\$\">\n"
                      . "    Order allow,deny\n"
                      . "    Deny from all\n"
                      . "</FilesMatch>\n";
            file_put_contents($log_dir . '/.htaccess', $htaccess, LOCK_EX);
        }

        // 设置默认选项 (生产环境安全默认值 + 英文默认)
        $defaults = array(
            'api_key' => '',
            'api_url' => 'https://api.deepseek.com/chat/completions',
            'model' => 'deepseek-chat',
            'system_prompt' => '', // 使用动态多语言提示词,无需手动设置
            'enable_log' => 'yes',
            'enable_knowledge' => 'yes',
            'enable_quick_replies' => 'yes',
            // 生产环境默认: 关闭调试日志,避免磁盘膨胀和敏感信息泄露
            'enable_debug_log' => 'no',
            'temperature' => '0.7',
            'max_tokens' => '2000',
            'chat_position' => 'bottom-right',
            'primary_color' => '#0066ff',
            'initial_message' => '', // 使用动态多语言欢迎语
        );

        foreach ($defaults as $key => $value) {
            if (!get_option('wp_ai_cs_' . $key)) {
                add_option('wp_ai_cs_' . $key, $value);
            }
        }
    }

    /**
     * 停用插件
     */
    public function deactivate() {
        // 清理临时文件
    }

    /**
     * 初始化
     */
    public function init() {
        // 加载翻译
        load_plugin_textdomain('wp-ai-cs', false, dirname(WP_AI_CS_BASENAME) . '/languages');
    }

    /**
     * 加载前端脚本
     */
    public function enqueue_scripts() {
        if (!is_admin() && get_option('wp_ai_cs_api_key')) {
            wp_enqueue_style(
                'wp-ai-cs-style',
                WP_AI_CS_URL . 'assets/css/chat-widget.css',
                array(),
                WP_AI_CS_VERSION
            );

            // Default UI language: English (auto-detect from conversation for AI responses)
            $browser_lang = 'en';
            $i18n_data = $this->i18n->get_frontend_data($browser_lang);

            wp_enqueue_script(
                'wp-ai-cs-script',
                WP_AI_CS_URL . 'assets/js/chat-widget.js',
                array(),
                WP_AI_CS_VERSION,
                true
            );

            // Pass AJAX URL, settings, and i18n to frontend
            wp_localize_script('wp-ai-cs-script', 'wpAICs', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wp_ai_cs_chat'),
                'settings' => array(
                    'primaryColor' => get_option('wp_ai_cs_primary_color', '#0066ff'),
                    'enableQuickReplies' => get_option('wp_ai_cs_enable_quick_replies', 'yes') === 'yes',
                ),
                'i18n' => $i18n_data,
            ));
        }
    }

    /**
     * 移动端 viewport 修复：确保输入框不触发 iOS 缩放
     */
    public function add_mobile_viewport() {
        if (!get_option('wp_ai_cs_api_key')) {
            return;
        }
        // Remove existing viewport meta and add our own
        echo '<script>(function(){var m=document.querySelector(\'meta[name="viewport"]\');if(m)m.setAttribute("content","width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no");})();</script>' . "\n";
    }

    /**
     * 渲染聊天窗口
     */
    public function render_chat_widget() {
        if (!get_option('wp_ai_cs_api_key')) {
            return;
        }

        $primary_color = get_option('wp_ai_cs_primary_color', '#0066ff');
        $position = get_option('wp_ai_cs_chat_position', 'bottom-right');

        // Default UI language: English
        $lang = 'en';
        $strings = $this->i18n->get_ui_strings($lang);

        // 位置样式
        $position_styles = array(
            'bottom-right' => 'bottom: 20px; right: 20px;',
            'bottom-left' => 'bottom: 20px; left: 20px;',
            'top-right' => 'top: 20px; right: 20px;',
            'top-left' => 'top: 20px; left: 20px;',
        );

        $position_style = isset($position_styles[$position]) ? $position_styles[$position] : $position_styles['bottom-right'];

        // 快速回复选项 (基于检测到的语言)
        $quick_replies = apply_filters('wp_ai_cs_quick_replies', $this->i18n->get_quick_replies($lang));
        ?>
        <!-- Customer Service Chat Widget -->
        <div id="wp-ai-cs-widget" style="<?php echo esc_attr($position_style . ' --wp-ai-cs-primary: ' . $primary_color . ';'); ?>">
            <!-- Toggle Button -->
            <div id="wp-ai-cs-toggle" onclick="WP_AICS.toggleChat()" style="background: <?php echo esc_attr($primary_color); ?>;">
                <span class="toggle-icon">💬</span>
                <span class="toggle-text"><?php echo esc_html($strings['toggle_text']); ?></span>
                <span class="badge" id="wp-ai-cs-badge" style="display:none;">1</span>
            </div>

            <!-- Chat Window -->
            <div id="wp-ai-cs-box" style="display:none;">
                <!-- Header -->
                <div id="wp-ai-cs-header" style="background: <?php echo esc_attr($primary_color); ?>;">
                    <div class="header-left">
                        <div class="avatar">👤</div>
                        <div class="title-group">
                            <span class="title"><?php echo esc_html($strings['widget_title']); ?></span>
                            <span class="status">
                                <span class="dot"></span> <?php echo esc_html($strings['status_online']); ?>
                            </span>
                        </div>
                    </div>
                    <span class="close-btn" onclick="WP_AICS.toggleChat()">✕</span>
                </div>

                <!-- Message List -->
                <div id="wp-ai-cs-messages">
                    <div class="msg bot">
                        <?php echo wp_kses_post($this->i18n->get_initial_message($lang)); ?>
                        <span class="time"><?php echo date_i18n('H:i'); ?></span>
                    </div>
                </div>

                <?php if (get_option('wp_ai_cs_enable_quick_replies', 'yes') === 'yes'): ?>
                <!-- Quick Replies -->
                <div id="wp-ai-cs-quick-replies">
                    <?php foreach ($quick_replies as $reply): ?>
                        <button onclick="WP_AICS.quickReply('<?php echo esc_js($reply); ?>')">
                            <?php echo esc_html($reply); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Input Area -->
                <div id="wp-ai-cs-input-area">
                    <input type="text" id="wp-ai-cs-input"
                           placeholder="<?php echo esc_attr($strings['input_placeholder']); ?>"
                           onkeypress="if(event.key==='Enter') WP_AICS.sendMessage()">
                    <button onclick="WP_AICS.sendMessage()" style="background: <?php echo esc_attr($primary_color); ?>;">
                        <?php echo esc_html($strings['send_button']); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 处理聊天请求（AJAX）
     */
    public function handle_chat_request() {
        // 验证 nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_ai_cs_chat')) {
            wp_send_json_error(array('error' => 'Security verification failed'));
            return;
        }

        $text = isset($_POST['text']) ? sanitize_text_field($_POST['text']) : '';
        $history = isset($_POST['history']) ? json_decode(wp_unslash($_POST['history']), true) : array();
        $client_language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';
        $browser_language = isset($_POST['browser_language']) ? sanitize_text_field($_POST['browser_language']) : '';
        $message_language = isset($_POST['message_language']) ? sanitize_text_field($_POST['message_language']) : '';

        if (empty($text)) {
            wp_send_json_error(array('error' => 'Please enter your question'));
            return;
        }

        // 文本长度限制
        if (mb_strlen($text, 'UTF-8') > 4000) {
            wp_send_json_error(array('error' => 'Text too long (max 4000 characters)'));
            return;
        }

        // 检测设备
        $is_mobile = wp_is_mobile();

        // 自动检测语言 (优先级: 消息语言 > 前端传入 > 消息内容 > 浏览器 > 默认英文)
        $cjk_languages = array('zh', 'ja', 'ko');
        if (!empty($message_language) && in_array($message_language, $cjk_languages)) {
            $detected_language = $message_language;
        } else {
            $detected_language = $this->i18n->detect_language($text, $client_language);
        }

        // 检测是否要求人工客服 (支持多语言关键词)
        $need_human = $this->detect_human_request($text, $detected_language);

        if ($need_human) {
            if (get_option('wp_ai_cs_enable_log', 'yes') === 'yes') {
                $this->logger->log($text, true, $is_mobile, 'human-transfer', '');
            }
            wp_send_json_success(array(
                'ok' => true,
                'content' => $this->i18n->get_human_message($detected_language),
                'detected_language' => $detected_language,
                'reply_source' => 'human_transfer',
            ));
            return;
        }

        // 获取 WooCommerce 上下文（RAG：将商品/订单信息注入提示词）
        $woo_context = $this->woo_integration->get_context($text);

        // ===== 第1层：AI幻觉硬拦截 =====
        // 当 RAG 返回明确的权限拒绝 / 未登录 / 空订单时，直接返回标准话术，
        // 完全不调用 AI API，从根源消除 AI "编造详情" 的幻觉风险。
        $hard_reply = $this->i18n->get_woo_context_standard_reply($woo_context, $detected_language);
        if (!empty($hard_reply)) {
            // 记录日志（不消耗 API token）
            if (get_option('wp_ai_cs_enable_log', 'yes') === 'yes') {
                $this->logger->log($text, true, $is_mobile, 'rag-hard-reply', '');
            }
            wp_send_json_success(array(
                'ok' => true,
                'content' => $hard_reply,
                'detected_language' => $detected_language,
                'reply_source' => 'woo_standard_reply', // 标记来源，便于前端/日志识别
            ));
            return;
        }

        // ===== 第1.5层：真实订单数据格式化直出（防"把有说成没有"的反向幻觉）=====
        // 如果 RAG 中出现了具体的订单号行（"- Order #NNN"），说明数据库里查到了
        // 真订单。此时我们直接按用户语言把订单头信息翻成客服风格话术 + 原始明细
        // 表格输出，**完全不调用 LLM**，100% 保真。AI 最容易在这种"有 vs 没"、
        // "已发货 vs 处理中"的二分类上出错，格式化直出可以彻底封死。
        if (preg_match_all('/^\h*-\h*Order\h*#(\d+)\h*\|\h*Status:\h*([^|]+?)\h*\|\h*Total:\h*(.+?)\h*\|\h*Items:\h*(.+?)\h*$/m', $woo_context, $order_matches, PREG_SET_ORDER)) {
            $formatted = $this->i18n->format_orders_as_reply($order_matches, $detected_language);
            if (!empty($formatted)) {
                if (get_option('wp_ai_cs_enable_log', 'yes') === 'yes') {
                    $this->logger->log($text, true, $is_mobile, 'rag-order-formatted', '');
                }
                wp_send_json_success(array(
                    'ok' => true,
                    'content' => $formatted,
                    'detected_language' => $detected_language,
                    'reply_source' => 'woo_order_formatted',
                ));
                return;
            }
        }

        // 构建动态多语言系统提示词
        $custom_prompt = sanitize_textarea_field(get_option('wp_ai_cs_system_prompt', ''));
        $system_prompt = $this->i18n->get_system_prompt($detected_language, $woo_context);

        // 如果管理员设置了自定义提示词,追加到系统提示词
        if (!empty($custom_prompt)) {
            $system_prompt .= "\n\n" . $custom_prompt;
        }

        // 移动端附加指令
        if ($is_mobile) {
            $system_prompt .= $this->i18n->get_mobile_instruction($detected_language);
        }

        // 本地知识库作为补充上下文注入提示词（不再截断 AI 回答）
        if (get_option('wp_ai_cs_enable_knowledge', 'yes') === 'yes') {
            $local_answer = $this->knowledge->get_answer($text);
            if ($local_answer) {
                $system_prompt .= "\n\n[Knowledge Base Reference]\n" . $local_answer;
            }
        }

        $messages = array(
            array('role' => 'system', 'content' => $system_prompt)
        );

        // 校验历史记录结构，仅允许 user/assistant 角色，防止 prompt 注入
        if (!empty($history) && is_array($history)) {
            $allowed_roles = array('user' => true, 'assistant' => true);
            foreach (array_slice($history, -10) as $msg) {
                if (!is_array($msg) || empty($msg['role']) || empty($msg['content'])) {
                    continue;
                }
                $role = $msg['role'];
                if (!isset($allowed_roles[$role])) {
                    continue;
                }
                $messages[] = array(
                    'role' => $role,
                    'content' => sanitize_text_field($msg['content'])
                );
            }
        }
        $messages[] = array('role' => 'user', 'content' => $text);

        // 调用 API
        $result = $this->api_handler->call_api(
            $messages,
            floatval(get_option('wp_ai_cs_temperature', '0.7')),
            intval(get_option('wp_ai_cs_max_tokens', '2000'))
        );

        // 记录日志
        if (get_option('wp_ai_cs_enable_log', 'yes') === 'yes') {
            $this->logger->log($text, $result['ok'], $is_mobile, $result['ok'] ? 'api' : 'error', $result['error'] ?? '');
        }

        // 返回结果 + 检测到的语言 (供前端更新 UI)
        $result['detected_language'] = $detected_language;
        wp_send_json_success($result);
    }

    /**
     * 检测是否需要人工客服 (多语言关键词)
     */
    private function detect_human_request($text, $language) {
        $patterns = array(
            'zh' => '/(人工|真人|转接|客服电话|投诉|找老板|转人工|人[工客]服)/u',
            'en' => '/\b(human|agent|transfer|real.person|complaint|manager|speak.to|representative)\b/i',
            'ja' => '/(人[工]|転[eé]|オペレ[ーé]タ[ー]|苦情|担当者|話したい|直接)/u',
            'ko' => '/(상담원|연결|전화|불만|매니저|직접|사람)/u',
            'es' => '/\b(humano|agente|transferir|queja|gerente|hablar.con|persona.real)\b/i',
            'fr' => '/\b(humain|agent|transférer|réclamation|directeur|parler.à|personne.réelle)\b/i',
            'de' => '/\b(mensch|agent|übertragen|beschwerde|leiter|sprechen|echte.person)\b/i',
            // 俄语: 不使用 \b 边界 (俄语有屈折变化,词根匹配更可靠)
            'ru' => '/(оператор|агент|перевод|человек|жалоб|менеджер|поговорить|представител|живой|реальн)/iu',
        );

        if (isset($patterns[$language])) {
            return (bool) preg_match($patterns[$language], $text);
        }

        // Fallback: 英文
        return (bool) preg_match($patterns['en'], $text);
    }
}

// 启动插件
function wp_ai_customer_service() {
    return WP_AI_Customer_Service::get_instance();
}

// 在 WordPress 加载完成后启动
add_action('plugins_loaded', 'wp_ai_customer_service');

// 声明 WooCommerce HPOS (High-Performance Order Storage) 兼容性
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('orders_cache', __FILE__, true);
    }
});
