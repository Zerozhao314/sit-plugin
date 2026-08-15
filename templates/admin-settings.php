<?php if (!defined('ABSPATH')) exit;

/**
 * WP AI 智能客服 - 后台管理模板
 *
 * @author zerozhao
 * @since 1.1.0
 */

$admin = isset($GLOBALS['wp_ai_cs_admin']) ? $GLOBALS['wp_ai_cs_admin'] : null;
$tabs = $admin ? $admin->get_tabs() : array();
$current_tab = $admin ? $admin->get_current_tab() : 'dashboard';

// 生成 nonce URL
$download_url = wp_nonce_url(
    admin_url('admin-ajax.php?action=wp_ai_cs_download_logs'),
    'wp_ai_cs_logs',
    'wp_ai_cs_logs_nonce'
);
$clear_url = wp_nonce_url(
    admin_url('admin-ajax.php?action=wp_ai_cs_clear_logs'),
    'wp_ai_cs_logs',
    'wp_ai_cs_logs_nonce'
);
?>
<div class="wrap wp-ai-cs-wrap">
    <h1>
        <span class="dashicons dashicons-format-chat" style="font-size:28px;width:28px;height:28px;vertical-align:middle;color:#2271b1;"></span>
        <?php _e('AI 智能客服', 'wp-ai-cs'); ?>
        <span style="font-size:13px;color:#646970;font-weight:normal;margin-left:10px;">v<?php echo WP_AI_CS_VERSION; ?></span>
    </h1>

    <?php if (isset($_GET['wp_ai_cs_logs_cleared']) && $_GET['wp_ai_cs_logs_cleared'] === '1'): ?>
        <div class="notice notice-success is-dismissible"><p><?php _e('日志已清空。', 'wp-ai-cs'); ?></p></div>
    <?php endif; ?>

    <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true'): ?>
        <div class="notice notice-success is-dismissible"><p><?php _e('设置已保存。', 'wp-ai-cs'); ?></p></div>
    <?php endif; ?>

    <!-- Tab 导航 -->
    <h2 class="wp-ai-cs-tabs nav-tab-wrapper">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="<?php echo esc_url($admin->tab_url($key)); ?>"
               class="nav-tab <?php echo $current_tab === $key ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <?php
    switch ($current_tab) {
        case 'dashboard':
            render_dashboard_tab($admin);
            break;
        case 'general':
            render_general_tab();
            break;
        case 'appearance':
            render_appearance_tab();
            break;
        case 'advanced':
            render_advanced_tab();
            break;
        case 'logs':
            render_logs_tab($admin, $download_url, $clear_url);
            break;
        case 'about':
            render_about_tab();
            break;
    }
    ?>

    <!-- 页脚署名 -->
    <div class="wp-ai-cs-footer">
        <p>
            <strong>WP AI 智能客服系统</strong> v<?php echo WP_AI_CS_VERSION; ?> &nbsp;|&nbsp;
            <?php _e('作者', 'wp-ai-cs'); ?>:
            <a href="https://github.com/zerozhao" target="_blank">zerozhao</a> &nbsp;|&nbsp;
            <?php _e('基于 DeepSeek API + WooCommerce RAG', 'wp-ai-cs'); ?>
        </p>
    </div>
</div>

<?php
// ===== Tab 渲染方法 =====

/**
 * 仪表盘 Tab
 */
function render_dashboard_tab($admin) {
    $stats = $admin->get_dashboard_stats();
    $api_key = get_option('wp_ai_cs_api_key');
    $woo_active = class_exists('WooCommerce');
    ?>
    <h2><?php _e('运行概览', 'wp-ai-cs'); ?></h2>

    <!-- 状态卡片 -->
    <div class="wp-ai-cs-cards">
        <div class="wp-ai-cs-card <?php echo $api_key ? 'success' : 'danger'; ?>">
            <div class="label"><?php _e('API 状态', 'wp-ai-cs'); ?></div>
            <div class="value" style="font-size:18px;"><?php echo $api_key ? '已配置' : '未配置'; ?></div>
            <div class="sub"><?php echo $api_key ? 'Key: ' . esc_html(substr($api_key, 0, 6)) . '****' : '请前往常规设置配置'; ?></div>
        </div>
        <div class="wp-ai-cs-card <?php echo $woo_active ? 'success' : 'warning'; ?>">
            <div class="label"><?php _e('WooCommerce', 'wp-ai-cs'); ?></div>
            <div class="value" style="font-size:18px;"><?php echo $woo_active ? '已激活' : '未激活'; ?></div>
            <div class="sub"><?php echo $woo_active ? 'RAG 上下文可用' : '订单/商品功能受限'; ?></div>
        </div>
        <div class="wp-ai-cs-card">
            <div class="label"><?php _e('今日对话', 'wp-ai-cs'); ?></div>
            <div class="value"><?php echo number_format($stats['today_count']); ?></div>
            <div class="sub"><?php echo date('Y-m-d'); ?></div>
        </div>
        <div class="wp-ai-cs-card">
            <div class="label"><?php _e('总对话数', 'wp-ai-cs'); ?></div>
            <div class="value"><?php echo number_format($stats['total']); ?></div>
            <div class="sub"><?php _e('历史累计', 'wp-ai-cs'); ?></div>
        </div>
    </div>

    <!-- 统计卡片 -->
    <div class="wp-ai-cs-cards">
        <div class="wp-ai-cs-card success">
            <div class="label"><?php _e('成功', 'wp-ai-cs'); ?></div>
            <div class="value"><?php echo number_format($stats['success']); ?></div>
            <div class="sub"><?php _e('成功率', 'wp-ai-cs'); ?>: <?php echo $stats['success_rate']; ?>%</div>
        </div>
        <div class="wp-ai-cs-card <?php echo $stats['fail'] > 0 ? 'danger' : ''; ?>">
            <div class="label"><?php _e('失败', 'wp-ai-cs'); ?></div>
            <div class="value"><?php echo number_format($stats['fail']); ?></div>
            <div class="sub"><?php _e('API错误/超时', 'wp-ai-cs'); ?></div>
        </div>
        <div class="wp-ai-cs-card">
            <div class="label"><?php _e('移动端', 'wp-ai-cs'); ?></div>
            <div class="value"><?php echo number_format($stats['mobile']); ?></div>
            <div class="sub"><?php echo $stats['total'] > 0 ? round($stats['mobile']/$stats['total']*100) : 0; ?>%</div>
        </div>
        <div class="wp-ai-cs-card">
            <div class="label"><?php _e('桌面端', 'wp-ai-cs'); ?></div>
            <div class="value"><?php echo number_format($stats['desktop']); ?></div>
            <div class="sub"><?php echo $stats['total'] > 0 ? round($stats['desktop']/$stats['total']*100) : 0; ?>%</div>
        </div>
    </div>

    <!-- 7天趋势 -->
    <h3><?php _e('近 7 天对话趋势', 'wp-ai-cs'); ?></h3>
    <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;">
        <?php foreach ($stats['daily'] as $date => $count): ?>
            <div style="display:flex;align-items:center;margin-bottom:8px;">
                <span style="width:90px;font-size:12px;color:#646970;"><?php echo esc_html(substr($date, 5)); ?></span>
                <span style="width:40px;font-size:13px;font-weight:600;text-align:right;margin-right:10px;"><?php echo $count; ?></span>
                <div class="wp-ai-cs-stat-bar" style="flex:1;">
                    <div style="width:<?php echo $stats['daily_max'] > 0 ? round($count/$stats['daily_max']*100) : 0; ?>%;"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 最近活动 -->
    <h3><?php _e('最近对话记录', 'wp-ai-cs'); ?> <span style="font-size:12px;color:#646970;font-weight:normal;">(<?php _e('最多10条', 'wp-ai-cs'); ?>)</span></h3>
    <?php if (empty($stats['recent'])): ?>
        <p style="color:#646970;"><?php _e('暂无对话记录。', 'wp-ai-cs'); ?></p>
    <?php else: ?>
        <div class="wp-ai-cs-log-list">
            <table>
                <thead>
                    <tr>
                        <th><?php _e('时间', 'wp-ai-cs'); ?></th>
                        <th><?php _e('设备', 'wp-ai-cs'); ?></th>
                        <th><?php _e('状态', 'wp-ai-cs'); ?></th>
                        <th><?php _e('来源', 'wp-ai-cs'); ?></th>
                        <th><?php _e('问题预览', 'wp-ai-cs'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['recent'] as $entry): ?>
                        <tr>
                            <td><?php echo esc_html(isset($entry['time']) ? $entry['time'] : '-'); ?></td>
                            <td>
                                <?php if (isset($entry['device']) && $entry['device'] === 'mobile'): ?>
                                    <span class="wp-ai-cs-badge mobile">Mobile</span>
                                <?php else: ?>
                                    <span class="wp-ai-cs-badge desktop">Desktop</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (isset($entry['success']) && $entry['success']): ?>
                                    <span class="wp-ai-cs-badge ok">OK</span>
                                <?php else: ?>
                                    <span class="wp-ai-cs-badge fail">FAIL</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(isset($entry['source']) ? $entry['source'] : '-'); ?></td>
                            <td><?php echo esc_html(mb_substr(isset($entry['question']) ? $entry['question'] : '', 0, 60)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php
}

/**
 * 常规设置 Tab
 */
function render_general_tab() {
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('wp_ai_cs_options'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('DeepSeek API Key', 'wp-ai-cs'); ?></th>
                <td>
                    <input type="password" name="wp_ai_cs_api_key"
                           value="<?php echo esc_attr(get_option('wp_ai_cs_api_key')); ?>"
                           class="regular-text" required>
                    <p class="description">
                        <?php _e('在 DeepSeek 开放平台获取您的 API Key', 'wp-ai-cs'); ?>
                        <a href="https://platform.deepseek.com/" target="_blank"><?php _e('获取密钥', 'wp-ai-cs'); ?></a>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('API 地址', 'wp-ai-cs'); ?></th>
                <td>
                    <input type="url" name="wp_ai_cs_api_url"
                           value="<?php echo esc_attr(get_option('wp_ai_cs_api_url', 'https://api.deepseek.com/chat/completions')); ?>"
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('模型', 'wp-ai-cs'); ?></th>
                <td>
                    <select name="wp_ai_cs_model">
                        <option value="deepseek-chat" <?php selected(get_option('wp_ai_cs_model'), 'deepseek-chat'); ?>>
                            deepseek-chat (推荐，通用对话)
                        </option>
                        <option value="deepseek-reasoner" <?php selected(get_option('wp_ai_cs_model'), 'deepseek-reasoner'); ?>>
                            deepseek-reasoner (推理增强)
                        </option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('System Prompt', 'wp-ai-cs'); ?></th>
                <td>
                    <textarea name="wp_ai_cs_system_prompt" rows="5" class="large-text"><?php echo esc_textarea(get_option('wp_ai_cs_system_prompt', '')); ?></textarea>
                    <p class="description">
                        <?php _e('自定义系统提示词（可选）。留空则使用内置多语言提示词，自动检测用户语言。如填写，将追加到语言提示词之后。', 'wp-ai-cs'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('初始欢迎消息', 'wp-ai-cs'); ?></th>
                <td>
                    <textarea name="wp_ai_cs_initial_message" rows="3" class="large-text"><?php echo esc_textarea(get_option('wp_ai_cs_initial_message', '')); ?></textarea>
                    <p class="description">
                        <?php _e('聊天窗口打开时显示的欢迎语（可选）。留空则使用内置多语言欢迎语。', 'wp-ai-cs'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}

/**
 * 外观设置 Tab
 */
function render_appearance_tab() {
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('wp_ai_cs_options'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('主题颜色', 'wp-ai-cs'); ?></th>
                <td>
                    <input type="color" name="wp_ai_cs_primary_color"
                           value="<?php echo esc_attr(get_option('wp_ai_cs_primary_color', '#0066ff')); ?>">
                    <p class="description"><?php _e('聊天窗口头部背景色与按钮主色', 'wp-ai-cs'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('窗口位置', 'wp-ai-cs'); ?></th>
                <td>
                    <select name="wp_ai_cs_chat_position">
                        <option value="bottom-right" <?php selected(get_option('wp_ai_cs_chat_position'), 'bottom-right'); ?>><?php _e('右下角', 'wp-ai-cs'); ?></option>
                        <option value="bottom-left" <?php selected(get_option('wp_ai_cs_chat_position'), 'bottom-left'); ?>><?php _e('左下角', 'wp-ai-cs'); ?></option>
                        <option value="top-right" <?php selected(get_option('wp_ai_cs_chat_position'), 'top-right'); ?>><?php _e('右上角', 'wp-ai-cs'); ?></option>
                        <option value="top-left" <?php selected(get_option('wp_ai_cs_chat_position'), 'top-left'); ?>><?php _e('左上角', 'wp-ai-cs'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('快捷回复', 'wp-ai-cs'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wp_ai_cs_enable_quick_replies" value="yes"
                               <?php checked(get_option('wp_ai_cs_enable_quick_replies', 'yes'), 'yes'); ?>>
                        <?php _e('启用快捷回复按钮（订单查询/物流/退货/推荐/人工客服）', 'wp-ai-cs'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>

    <!-- 预览 -->
    <h3><?php _e('外观预览', 'wp-ai-cs'); ?></h3>
    <div style="background:<?php echo esc_attr(get_option('wp_ai_cs_primary_color', '#0066ff')); ?>;color:#fff;padding:12px 16px;border-radius:8px 8px 0 0;display:inline-block;min-width:300px;">
        <strong>Customer Service</strong><br>
        <small>● Online</small>
    </div>
    <div style="background:#f9f9f9;padding:16px;border:1px solid #ddd;border-radius:0 0 8px 8px;display:inline-block;min-width:300px;vertical-align:top;">
        <p style="margin:0 0 10px;color:#333;">Hello! How can I help you today?</p>
        <div style="text-align:right;">
            <span style="background:<?php echo esc_attr(get_option('wp_ai_cs_primary_color', '#0066ff')); ?>;color:#fff;padding:6px 12px;border-radius:12px;font-size:13px;">Send</span>
        </div>
    </div>
    <?php
}

/**
 * 高级设置 Tab
 */
function render_advanced_tab() {
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('wp_ai_cs_options'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('温度 (Temperature)', 'wp-ai-cs'); ?></th>
                <td>
                    <input type="number" name="wp_ai_cs_temperature"
                           value="<?php echo esc_attr(get_option('wp_ai_cs_temperature', '0.7')); ?>"
                           step="0.1" min="0" max="2" style="width:80px;">
                    <p class="description"><?php _e('0=精确回答，2=更具创造性。推荐 0.7', 'wp-ai-cs'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('最大 Tokens', 'wp-ai-cs'); ?></th>
                <td>
                    <input type="number" name="wp_ai_cs_max_tokens"
                           value="<?php echo esc_attr(get_option('wp_ai_cs_max_tokens', '2000')); ?>"
                           step="100" min="100" max="8000" style="width:100px;">
                    <p class="description"><?php _e('AI 回复的最大长度（100-8000）', 'wp-ai-cs'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('功能开关', 'wp-ai-cs'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wp_ai_cs_enable_log" value="yes"
                               <?php checked(get_option('wp_ai_cs_enable_log', 'yes'), 'yes'); ?>>
                        <?php _e('启用聊天日志记录', 'wp-ai-cs'); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="wp_ai_cs_enable_knowledge" value="yes"
                               <?php checked(get_option('wp_ai_cs_enable_knowledge', 'yes'), 'yes'); ?>>
                        <?php _e('启用本地知识库（RAG 上下文注入）', 'wp-ai-cs'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><span style="color:#d63638;"><?php _e('调试日志', 'wp-ai-cs'); ?></span></th>
                <td>
                    <label style="color:#d63638;">
                        <input type="checkbox" name="wp_ai_cs_enable_debug_log" value="yes"
                               <?php checked(get_option('wp_ai_cs_enable_debug_log', 'no'), 'yes'); ?>>
                        <?php _e('启用调试日志（排查问题时开启，平时关闭）', 'wp-ai-cs'); ?>
                    </label>
                    <p class="description" style="color:#666;">
                        <?php _e('开启后会在 logs/debug_YYYY-MM-DD.log 记录详细写入节点信息。会产生额外磁盘 IO，建议仅排查问题时启用。', 'wp-ai-cs'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}

/**
 * 日志管理 Tab
 */
function render_logs_tab($admin, $download_url, $clear_url) {
    $log_files = $admin->get_log_files();
    $view_file = isset($_GET['log_file']) ? sanitize_file_name($_GET['log_file']) : '';
    $log_tail = array();
    $viewing_file = '';
    if ($view_file) {
        $viewing_file = WP_AI_CS_PATH . 'logs/' . $view_file;
        $log_tail = $admin->read_log_tail($viewing_file, 100);
    }
    ?>
    <h2><?php _e('日志管理', 'wp-ai-cs'); ?></h2>

    <p>
        <a href="<?php echo esc_url($download_url); ?>" class="button button-primary">
            <span class="dashicons dashicons-download" style="vertical-align:middle;"></span>
            <?php _e('下载全部日志', 'wp-ai-cs'); ?>
        </a>
        <a href="<?php echo esc_url($clear_url); ?>" class="button" onclick="return confirm('<?php esc_attr_e('确定要清空所有日志吗？此操作不可恢复。', 'wp-ai-cs'); ?>')">
            <span class="dashicons dashicons-trash" style="vertical-align:middle;"></span>
            <?php _e('清空日志', 'wp-ai-cs'); ?>
        </a>
    </p>

    <!-- 日志文件列表 -->
    <h3><?php _e('日志文件', 'wp-ai-cs'); ?> <span style="font-size:12px;color:#646970;font-weight:normal;">(<?php echo count($log_files); ?>)</span></h3>
    <?php if (empty($log_files)): ?>
        <p style="color:#646970;"><?php _e('暂无日志文件。', 'wp-ai-cs'); ?></p>
    <?php else: ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php _e('文件名', 'wp-ai-cs'); ?></th>
                    <th width="80"><?php _e('类型', 'wp-ai-cs'); ?></th>
                    <th width="100"><?php _e('大小', 'wp-ai-cs'); ?></th>
                    <th width="160"><?php _e('修改时间', 'wp-ai-cs'); ?></th>
                    <th width="100"><?php _e('操作', 'wp-ai-cs'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log_files as $file): ?>
                    <tr>
                        <td><code><?php echo esc_html($file['name']); ?></code></td>
                        <td>
                            <?php
                            $type_labels = array('chat' => '聊天', 'debug' => '调试', 'archived' => '归档');
                            $type = isset($type_labels[$file['type']]) ? $type_labels[$file['type']] : $file['type'];
                            ?>
                            <span class="wp-ai-cs-badge <?php echo $file['type'] === 'debug' ? 'fail' : ($file['type'] === 'archived' ? 'desktop' : 'ok'); ?>"><?php echo esc_html($type); ?></span>
                        </td>
                        <td><?php echo esc_html($file['size_kb']); ?> KB</td>
                        <td><?php echo esc_html($file['modified']); ?></td>
                        <td>
                            <a href="<?php echo esc_url(add_query_arg(array('page' => 'wp-ai-cs', 'tab' => 'logs', 'log_file' => $file['name']), admin_url('admin.php'))); ?>" class="button button-small">
                                <?php _e('查看', 'wp-ai-cs'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- 日志内容查看 -->
    <?php if (!empty($log_tail)): ?>
        <h3><?php _e('查看日志', 'wp-ai-cs'); ?>: <code><?php echo esc_html($view_file); ?></code> <span style="font-size:12px;color:#646970;">(<?php _e('最后100行', 'wp-ai-cs'); ?>)</span></h3>
        <div class="wp-ai-cs-log-list" style="max-height:500px;">
            <pre style="margin:0;padding:10px;white-space:pre-wrap;word-break:break-all;"><?php
            foreach ($log_tail as $line) {
                // 尝试 JSON 美化
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    echo esc_html(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . "\n";
                } else {
                    echo esc_html($line) . "\n";
                }
            }
            ?></pre>
        </div>
    <?php elseif ($view_file): ?>
        <p style="color:#d63638;"><?php _e('无法读取该日志文件或文件不存在。', 'wp-ai-cs'); ?></p>
    <?php endif; ?>
    <?php
}

/**
 * 关于 Tab
 */
function render_about_tab() {
    $api_key = get_option('wp_ai_cs_api_key');
    $model = get_option('wp_ai_cs_model', 'deepseek-chat');
    $woo_active = class_exists('WooCommerce');
    $php_version = PHP_VERSION;
    $wp_version = get_bloginfo('version');
    ?>
    <div class="wp-ai-cs-about">
        <h3>WP AI 智能客服系统</h3>
        <p style="font-size:14px;color:#646970;">
            <?php _e('基于 DeepSeek API 的 WooCommerce AI 智能客服，支持 PC 和移动端，多语言自动识别，RAG 订单/商品上下文。', 'wp-ai-cs'); ?>
        </p>

        <table class="form-table" style="max-width:600px;">
            <tr>
                <th><?php _e('版本', 'wp-ai-cs'); ?></th>
                <td><strong>v<?php echo WP_AI_CS_VERSION; ?></strong></td>
            </tr>
            <tr>
                <th><?php _e('作者', 'wp-ai-cs'); ?></th>
                <td><strong>zerozhao</strong> &mdash; <a href="https://github.com/zerozhao" target="_blank">GitHub</a></td>
            </tr>
            <tr>
                <th><?php _e('许可证', 'wp-ai-cs'); ?></th>
                <td>GPL v2 or later</td>
            </tr>
            <tr>
                <th><?php _e('AI 引擎', 'wp-ai-cs'); ?></th>
                <td>DeepSeek API (<code><?php echo esc_html($model); ?></code>)</td>
            </tr>
        </table>

        <h3><?php _e('运行环境', 'wp-ai-cs'); ?></h3>
        <table class="form-table" style="max-width:600px;">
            <tr>
                <th>PHP</th>
                <td><code><?php echo esc_html($php_version); ?></code></td>
            </tr>
            <tr>
                <th>WordPress</th>
                <td><code><?php echo esc_html($wp_version); ?></code></td>
            </tr>
            <tr>
                <th>WooCommerce</th>
                <td>
                    <?php if ($woo_active): ?>
                        <span class="wp-ai-cs-badge ok"><?php _e('已激活', 'wp-ai-cs'); ?> v<?php echo WC()->version; ?></span>
                    <?php else: ?>
                        <span class="wp-ai-cs-badge fail"><?php _e('未激活', 'wp-ai-cs'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>DeepSeek API</th>
                <td>
                    <?php if ($api_key): ?>
                        <span class="wp-ai-cs-badge ok"><?php _e('已配置', 'wp-ai-cs'); ?></span>
                        <code style="margin-left:8px;"><?php echo esc_html(substr($api_key, 0, 6)); ?>****</code>
                    <?php else: ?>
                        <span class="wp-ai-cs-badge fail"><?php _e('未配置', 'wp-ai-cs'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <h3><?php _e('核心功能', 'wp-ai-cs'); ?></h3>
        <ul style="list-style:disc;padding-left:20px;">
            <li><?php _e('DeepSeek AI 对话（deepseek-chat / deepseek-reasoner）', 'wp-ai-cs'); ?></li>
            <li><?php _e('多语言自动识别（中/英/日/韩/西/法/德）', 'wp-ai-cs'); ?></li>
            <li><?php _e('WooCommerce RAG 上下文（订单查询/商品搜索/物流信息）', 'wp-ai-cs'); ?></li>
            <li><?php _e('快捷回复按钮（订单/物流/退货/推荐/人工客服）', 'wp-ai-cs'); ?></li>
            <li><?php _e('PC + 移动端自适应', 'wp-ai-cs'); ?></li>
            <li><?php _e('聊天日志 + 调试日志（敏感信息脱敏）', 'wp-ai-cs'); ?></li>
            <li><?php _e('HPOS 兼容（WooCommerce 9+ 高性能订单存储）', 'wp-ai-cs'); ?></li>
        </ul>

        <h3><?php _e('安全特性', 'wp-ai-cs'); ?></h3>
        <ul style="list-style:disc;padding-left:20px;">
            <li><?php _e('ABSPATH 保护，防止直接访问 PHP', 'wp-ai-cs'); ?></li>
            <li><?php _e('XSS 防护（createTextNode 替代 innerHTML）', 'wp-ai-cs'); ?></li>
            <li><?php _e('REST API 权限校验 + Nonce 验证', 'wp-ai-cs'); ?></li>
            <li><?php _e('订单查询鉴权（用户身份 + 订单归属验证）', 'wp-ai-cs'); ?></li>
            <li><?php _e('日志目录 .htaccess 禁止访问 + index.php 静默', 'wp-ai-cs'); ?></li>
            <li><?php _e('敏感信息脱敏（API Key/密码/手机号/邮箱/银行卡/JWT）', 'wp-ai-cs'); ?></li>
            <li><?php _e('文件写入 LOCK_EX 防并发冲突 + 路径遍历防护', 'wp-ai-cs'); ?></li>
        </ul>
    </div>
    <?php
}
