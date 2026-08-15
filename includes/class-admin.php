<?php
if (!defined('ABSPATH')) exit;

/**
 * WP AI 智能客服 - 后台管理
 *
 * @author zerozhao
 * @since 1.1.0
 */
class WP_AI_CS_Admin {

    /**
     * 默认 Tab
     */
    const DEFAULT_TAB = 'dashboard';

    /**
     * 可用 Tab 列表
     */
    private $tabs = array(
        'dashboard' => '仪表盘',
        'general'   => '常规设置',
        'appearance'=> '外观设置',
        'advanced'  => '高级设置',
        'logs'      => '日志管理',
        'about'     => '关于',
    );

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'maybe_show_woocommerce_notice'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_wp_ai_cs_download_logs', array($this, 'download_logs'));
        add_action('wp_ajax_wp_ai_cs_clear_logs', array($this, 'clear_logs'));
    }

    /**
     * 注册独立顶级菜单
     */
    public function add_menu_page() {
        add_menu_page(
            __('AI 智能客服', 'wp-ai-cs'),
            __('AI 智能客服', 'wp-ai-cs'),
            'manage_options',
            'wp-ai-cs',
            array($this, 'render_admin_page'),
            'dashicons-format-chat',
            26
        );
    }

    /**
     * 加载后台样式
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'wp-ai-cs') === false) {
            return;
        }
        // 内联样式，避免新增文件
        $css = '
        .wp-ai-cs-wrap { max-width: 1100px; margin-top: 10px; }
        .wp-ai-cs-tabs { display: flex; border-bottom: 1px solid #c3c4c7; margin: 15px 0 20px; flex-wrap: wrap; }
        .wp-ai-cs-tabs a { display: inline-block; padding: 8px 16px; text-decoration: none; color: #50575e; border: 1px solid transparent; border-bottom: none; background: #f6f7f7; margin-right: 3px; border-radius: 4px 4px 0 0; font-size: 13px; }
        .wp-ai-cs-tabs a.nav-tab-active { background: #fff; border-color: #c3c4c7; border-bottom: 1px solid #fff; color: #000; font-weight: 600; margin-bottom: -1px; }
        .wp-ai-cs-tabs a:hover:not(.nav-tab-active) { background: #fff; color: #2271b1; }
        .wp-ai-cs-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px; margin: 15px 0; }
        .wp-ai-cs-card { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 15px 20px; border-radius: 4px; }
        .wp-ai-cs-card .label { color: #646970; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .wp-ai-cs-card .value { font-size: 28px; font-weight: 700; color: #1d2327; margin: 5px 0; }
        .wp-ai-cs-card .sub { color: #646970; font-size: 12px; }
        .wp-ai-cs-card.success { border-left-color: #00a32a; }
        .wp-ai-cs-card.warning { border-left-color: #dba617; }
        .wp-ai-cs-card.danger { border-left-color: #d63638; }
        .wp-ai-cs-log-list { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; max-height: 450px; overflow-y: auto; font-family: Consolas, Monaco, monospace; font-size: 12px; }
        .wp-ai-cs-log-list table { width: 100%; border-collapse: collapse; }
        .wp-ai-cs-log-list th { background: #f6f7f7; padding: 8px 10px; text-align: left; border-bottom: 1px solid #c3c4c7; position: sticky; top: 0; font-size: 11px; text-transform: uppercase; }
        .wp-ai-cs-log-list td { padding: 6px 10px; border-bottom: 1px solid #f0f0f1; }
        .wp-ai-cs-log-list tr:hover { background: #f6f7f7; }
        .wp-ai-cs-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .wp-ai-cs-badge.ok { background: #edfaef; color: #007017; }
        .wp-ai-cs-badge.fail { background: #fcf0f1; color: #8c1517; }
        .wp-ai-cs-badge.mobile { background: #e6f0fa; color: #0a4b78; }
        .wp-ai-cs-badge.desktop { background: #f0ede1; color: #6d5600; }
        .wp-ai-cs-footer { margin-top: 30px; padding: 15px 0; border-top: 1px solid #c3c4c7; color: #646970; font-size: 13px; text-align: center; }
        .wp-ai-cs-footer a { color: #2271b1; text-decoration: none; }
        .wp-ai-cs-about { background: #fff; border: 1px solid #c3c4c7; padding: 30px; border-radius: 4px; }
        .wp-ai-cs-about h3 { margin-top: 0; }
        .wp-ai-cs-stat-bar { background: #f0f0f1; border-radius: 3px; height: 8px; overflow: hidden; margin-top: 6px; }
        .wp-ai-cs-stat-bar > div { height: 100%; background: #2271b1; border-radius: 3px; }
        ';
        wp_add_inline_style('wp-admin', $css);
    }

    /**
     * 注册设置项
     */
    public function register_settings() {
        register_setting('wp_ai_cs_options', 'wp_ai_cs_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_api_url', array(
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://api.deepseek.com/chat/completions',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_model', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'deepseek-chat',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_system_prompt', array(
            'sanitize_callback' => 'sanitize_textarea_field',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_initial_message', array(
            'sanitize_callback' => 'sanitize_textarea_field',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_temperature', array(
            'sanitize_callback' => array($this, 'sanitize_float_0_2'),
            'default' => '0.7',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_max_tokens', array(
            'sanitize_callback' => array($this, 'sanitize_int_100_8000'),
            'default' => '2000',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_primary_color', array(
            'sanitize_callback' => array($this, 'sanitize_hex_color'),
            'default' => '#0066ff',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_chat_position', array(
            'sanitize_callback' => array($this, 'sanitize_chat_position'),
            'default' => 'bottom-right',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_enable_log', array(
            'sanitize_callback' => array($this, 'sanitize_yes_no'),
            'default' => 'yes',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_enable_knowledge', array(
            'sanitize_callback' => array($this, 'sanitize_yes_no'),
            'default' => 'yes',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_enable_quick_replies', array(
            'sanitize_callback' => array($this, 'sanitize_yes_no'),
            'default' => 'yes',
        ));
        register_setting('wp_ai_cs_options', 'wp_ai_cs_enable_debug_log', array(
            'sanitize_callback' => array($this, 'sanitize_yes_no'),
            'default' => 'no',
        ));
    }

    public function sanitize_float_0_2($value) {
        $value = floatval($value);
        if ($value < 0) $value = 0;
        if ($value > 2) $value = 2;
        return strval(round($value, 1));
    }

    public function sanitize_int_100_8000($value) {
        $value = intval($value);
        if ($value < 100) $value = 100;
        if ($value > 8000) $value = 8000;
        return strval($value);
    }

    public function sanitize_hex_color($value) {
        $value = sanitize_text_field($value);
        return preg_match('/^#[a-f0-9]{6}$/i', $value) ? $value : '#0066ff';
    }

    public function sanitize_chat_position($value) {
        $allowed = array('bottom-right', 'bottom-left', 'top-right', 'top-left');
        return in_array($value, $allowed, true) ? $value : 'bottom-right';
    }

    public function sanitize_yes_no($value) {
        return $value === 'yes' ? 'yes' : 'no';
    }

    /**
     * 渲染后台页面（带 Tab 路由）
     */
    public function render_admin_page() {
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : self::DEFAULT_TAB;
        if (!isset($this->tabs[$current_tab])) {
            $current_tab = self::DEFAULT_TAB;
        }

        // 设置页使用统一的 options.php 表单，其他页直接渲染
        $needs_form = in_array($current_tab, array('general', 'appearance', 'advanced'), true);

        // 将当前 tab 传给模板
        $GLOBALS['wp_ai_cs_current_tab'] = $current_tab;
        $GLOBALS['wp_ai_cs_tabs'] = $this->tabs;
        $GLOBALS['wp_ai_cs_admin'] = $this;

        require_once WP_AI_CS_PATH . 'templates/admin-settings.php';
    }

    /**
     * 获取当前 Tab
     */
    public function get_current_tab() {
        return isset($GLOBALS['wp_ai_cs_current_tab']) ? $GLOBALS['wp_ai_cs_current_tab'] : self::DEFAULT_TAB;
    }

    /**
     * 获取 Tab 列表
     */
    public function get_tabs() {
        return $this->tabs;
    }

    /**
     * 生成 Tab 导航 URL
     */
    public function tab_url($tab) {
        return add_query_arg(array(
            'page' => 'wp-ai-cs',
            'tab'  => $tab,
        ), admin_url('admin.php'));
    }

    /**
     * WooCommerce 缺失提醒
     */
    public function maybe_show_woocommerce_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        // 仅在插件相关页面显示
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'wp-ai-cs') === false) {
            return;
        }
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
               . esc_html__('WP AI 智能客服：未检测到 WooCommerce 已激活，商品与订单的 RAG 上下文功能将不可用（其他功能不受影响）。', 'wp-ai-cs')
               . '</p></div>';
        }
    }

    // ===== 统计方法 =====

    /**
     * 读取并解析聊天日志
     *
     * @param int $days 最近天数 (0=全部)
     * @return array 日志条目数组
     */
    public function parse_chat_logs($days = 0) {
        $log_dir = WP_AI_CS_PATH . 'logs';
        $files = (array) glob($log_dir . '/chat_*.log');
        $files = array_filter($files);
        sort($files);

        // 按天数过滤文件
        if ($days > 0) {
            $threshold = date('Y-m-d', time() - ($days * DAY_IN_SECONDS));
            $files = array_filter($files, function($f) use ($threshold) {
                $basename = basename($f);
                // chat_YYYY-MM-DD.log
                $date = substr($basename, 5, 10);
                return $date >= $threshold;
            });
        }

        $entries = array();
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) continue;
            $lines = explode("\n", trim($content));
            foreach ($lines as $line) {
                if (empty($line)) continue;
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    $entry['_file'] = basename($file);
                    $entries[] = $entry;
                }
            }
        }
        return $entries;
    }

    /**
     * 获取仪表盘统计数据
     *
     * @return array 统计数据
     */
    public function get_dashboard_stats() {
        $entries = $this->parse_chat_logs(0);

        $total = count($entries);
        $success = 0;
        $fail = 0;
        $mobile = 0;
        $desktop = 0;
        $today_count = 0;
        $today_date = date('Y-m-d');

        foreach ($entries as $e) {
            if (isset($e['success']) && $e['success']) {
                $success++;
            } else {
                $fail++;
            }
            if (isset($e['device'])) {
                if ($e['device'] === 'mobile') {
                    $mobile++;
                } else {
                    $desktop++;
                }
            }
            if (isset($e['time']) && strpos($e['time'], $today_date) === 0) {
                $today_count++;
            }
        }

        $success_rate = $total > 0 ? round(($success / $total) * 100, 1) : 0;

        // 最近10条
        $recent = array_slice(array_reverse($entries), 0, 10);

        // 按日期统计（最近7天）
        $daily = array();
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', time() - ($i * DAY_IN_SECONDS));
            $daily[$date] = 0;
        }
        foreach ($entries as $e) {
            if (isset($e['time'])) {
                $date = substr($e['time'], 0, 10);
                if (isset($daily[$date])) {
                    $daily[$date]++;
                }
            }
        }
        $daily_max = max(1, max($daily));

        return array(
            'total'         => $total,
            'success'       => $success,
            'fail'          => $fail,
            'success_rate'  => $success_rate,
            'mobile'        => $mobile,
            'desktop'       => $desktop,
            'today_count'   => $today_count,
            'recent'        => $recent,
            'daily'         => $daily,
            'daily_max'     => $daily_max,
        );
    }

    /**
     * 获取日志文件列表
     */
    public function get_log_files() {
        $log_dir = WP_AI_CS_PATH . 'logs';
        $chat_files = (array) glob($log_dir . '/chat_*.log');
        $debug_files = (array) glob($log_dir . '/debug_*.log');
        $archived = (array) glob($log_dir . '/*_archived.log');

        $all = array_merge($chat_files, $debug_files, $archived);
        $all = array_filter($all);

        $result = array();
        foreach ($all as $file) {
            $result[] = array(
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'size_kb' => round(filesize($file) / 1024, 1),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
                'type' => strpos(basename($file), 'debug') === 0 ? 'debug' : (strpos(basename($file), 'archived') !== false ? 'archived' : 'chat'),
            );
        }
        // 按修改时间倒序
        usort($result, function($a, $b) {
            return strcmp($b['modified'], $a['modified']);
        });
        return $result;
    }

    /**
     * 读取指定日志文件内容（尾部）
     */
    public function read_log_tail($file, $lines = 50) {
        $log_dir = WP_AI_CS_PATH . 'logs';
        $realpath = realpath($file);
        $log_realpath = realpath($log_dir);
        if (!$realpath || !$log_realpath || strpos($realpath, $log_realpath) !== 0) {
            return array();
        }
        if (!file_exists($file)) {
            return array();
        }
        $content = @file_get_contents($file);
        if ($content === false) return array();
        $all_lines = explode("\n", trim($content));
        return array_slice($all_lines, -$lines);
    }

    // ===== 日志下载/清空 =====

    public function download_logs() {
        if (!current_user_can('manage_options')) {
            wp_die(__('您没有权限执行此操作。', 'wp-ai-cs'));
        }
        $nonce = isset($_GET['wp_ai_cs_logs_nonce']) ? sanitize_text_field($_GET['wp_ai_cs_logs_nonce']) : '';
        if (!wp_verify_nonce($nonce, 'wp_ai_cs_logs')) {
            wp_die(__('安全验证失败。', 'wp-ai-cs'));
        }

        $log_dir = WP_AI_CS_PATH . 'logs';
        $files = array_merge(
            (array) glob($log_dir . '/chat_*.log'),
            (array) glob($log_dir . '/debug_*.log')
        );
        $files = array_filter($files);
        sort($files);
        $content = '';
        foreach ($files as $file) {
            $content .= "===== " . basename($file) . " =====\n" . file_get_contents($file) . "\n\n";
        }

        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="wp-ai-cs-logs-' . date('Y-m-d') . '.txt"');
        echo $content;
        exit;
    }

    public function clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_die(__('您没有权限执行此操作。', 'wp-ai-cs'));
        }
        $nonce = isset($_GET['wp_ai_cs_logs_nonce']) ? sanitize_text_field($_GET['wp_ai_cs_logs_nonce']) : '';
        if (!wp_verify_nonce($nonce, 'wp_ai_cs_logs')) {
            wp_die(__('安全验证失败。', 'wp-ai-cs'));
        }

        $log_dir = WP_AI_CS_PATH . 'logs';
        $files = array_merge(
            (array) glob($log_dir . '/chat_*.log'),
            (array) glob($log_dir . '/debug_*.log')
        );
        foreach ($files as $file) {
            $realpath = realpath($file);
            $log_realpath = realpath($log_dir);
            if ($realpath && $log_realpath && strpos($realpath, $log_realpath) === 0) {
                @unlink($file);
            }
        }

        wp_safe_redirect(add_query_arg(array('page' => 'wp-ai-cs', 'tab' => 'logs', 'wp_ai_cs_logs_cleared' => '1'), admin_url('admin.php')));
        exit;
    }
}
