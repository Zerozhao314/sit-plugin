<?php
/**
 * WP AI 智能客服插件 - 核心逻辑单元测试
 *
 * 无需 WordPress 运行环境，通过 stub WP 函数 + 反射访问私有方法，
 * 覆盖语言检测、订单号提取、商品关键词、知识库匹配、敏感信息脱敏、
 * 人工客服检测、后台 sanitize 等纯逻辑。
 *
 * 运行: php tests/test-core.php
 * 退出码: 0=全通过, 1=存在失败
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', 1);
mb_internal_encoding('UTF-8');

// ===== WordPress 环境模拟 (stub) =====
define('ABSPATH', true);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['test_options'] = array();

function get_option($key, $default = false) {
    global $test_options;
    return array_key_exists($key, $test_options) ? $test_options[$key] : $default;
}
function update_option($k, $v) { global $test_options; $test_options[$k] = $v; return true; }
function add_option($k, $v) { global $test_options; if (!isset($test_options[$k])) $test_options[$k] = $v; return true; }
function sanitize_text_field($v) { return is_string($v) ? trim(strip_tags($v)) : $v; }
function sanitize_textarea_field($v) { return $v; }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$v)); }
function esc_url_raw($v) { return $v; }
function apply_filters($tag, $value) { return $value; }
function add_action() {}
function add_filter() {}
function remove_action() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'http://example.com/plugins/' . basename(dirname($f)) . '/'; }
function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }
function load_plugin_textdomain() {}
function current_time($t) { return date('Y-m-d H:i:s'); }
function date_i18n($f) { return date($f); }
function wp_mkdir_p($d) { return @mkdir($d, 0777, true) || is_dir($d); }
function esc_html__($t) { return $t; }
function __($t) { return $t; }
function esc_html($t) { return $t; }
function esc_attr($t) { return $t; }
function esc_js($t) { return $t; }
function esc_url($t) { return $t; }
function wp_kses_post($t) { return $t; }
function wp_create_nonce() { return 'test_nonce'; }
function wp_verify_nonce() { return true; }
function wp_send_json_error($d) { echo json_encode(array('success' => false, 'data' => $d)); }
function wp_send_json_success($d) { echo json_encode(array('success' => true, 'data' => $d)); }
function wp_die($m) { throw new Exception((string)$m); }
function wp_safe_redirect() {}
function admin_url() { return 'http://example.com/wp-admin/'; }
function add_query_arg() { return 'http://example.com/wp-admin/admin.php'; }
function add_menu_page() {}
function register_setting() {}
function nocache_headers() {}
function wp_add_inline_style() {}
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_localize_script() {}
function is_admin() { return false; }
function wp_is_mobile() { return false; }
function is_user_logged_in() { return false; }
function get_current_user_id() { return 0; }
function current_user_can() { return true; }
function get_current_screen() { return null; }
function wp_remote_post() { return array('response' => array('code' => 200), 'body' => '{}'); }
function is_wp_error() { return false; }
function wp_remote_retrieve_body() { return '{}'; }
function wp_remote_retrieve_response_code() { return 200; }
function wp_unslash($v) { return $v; }
function wc_get_orders() { return array(); }
function wc_get_order() { return false; }
function wc_get_product() { return false; }
function wc_get_order_status_name($s) { return $s; }
function get_posts() { return array(); }

// ===== 加载插件主文件 (会自动 require 各类并定义常量) =====
$main_file = dirname(__DIR__) . '/wp-ai-customer-service.php';
require_once $main_file;

// ===== 测试辅助 =====
$passCount = 0;
$failCount = 0;
$failureList = array();
$groupResults = array();

function callPrivate($classOrObj, $method, array $args = array()) {
    $ref = new ReflectionClass(is_object($classOrObj) ? get_class($classOrObj) : $classOrObj);
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    $obj = is_object($classOrObj) ? $classOrObj : $ref->newInstanceWithoutConstructor();
    return $m->invokeArgs($obj, $args);
}

function assertEq($actual, $expected, $label) {
    global $passCount, $failCount, $failureList, $curGroup;
    if ($actual === $expected) {
        $passCount++;
        echo "  OK   $label\n";
    } else {
        $failCount++;
        $failureList[] = "[$curGroup] $label";
        echo "  FAIL $label\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
    }
}

function assertTrue($actual, $label) { assertEq($actual === true, true, $label); }
function assertFalse($actual, $label) { assertEq($actual === false, true, $label); }
function assertNull($actual, $label) { assertEq($actual, null, $label); }
function assertNotNull($actual, $label) { assertEq($actual !== null, true, $label); }

function group($name) {
    global $curGroup, $groupResults;
    $curGroup = $name;
    echo "\n[$name]\n";
}

$knownIssues = array();
function info($label, $detail) {
    global $knownIssues;
    $knownIssues[] = "$label\n        $detail";
    echo "  NOTE $label (已知问题)\n        $detail\n";
}

// ===== 开始测试 =====
echo "================================================\n";
echo "WP AI 智能客服插件 - 核心逻辑单元测试\n";
echo "PHP " . PHP_VERSION . "  |  " . date('Y-m-d H:i:s') . "\n";
echo "================================================\n";

// ---------- 1. i18n 文本语言检测 ----------
group('1. i18n detect_from_text (文本语言检测)');
$i18n = new WP_AI_CS_I18n();

assertEq(callPrivate('WP_AI_CS_I18n', 'detect_from_text', array('你好，我想咨询订单')), 'zh', '中文消息 → zh');
assertEq(callPrivate('WP_AI_CS_I18n', 'detect_from_text', array('こんにちは')), 'ja', '日文假名 → ja');
assertEq(callPrivate('WP_AI_CS_I18n', 'detect_from_text', array('안녕하세요')), 'ko', '韩文 → ko');
assertEq(callPrivate('WP_AI_CS_I18n', 'detect_from_text', array('¿Dónde está mi pedido?')), 'es', '西班牙语(¿/ó) → es');
assertEq(callPrivate('WP_AI_CS_I18n', 'detect_from_text', array('Comment ça va?')), 'fr', '法语(ç) → fr');
assertEq(callPrivate('WP_AI_CS_I18n', 'detect_from_text', array('Straße')), 'de', '德语(ß) → de');
// 修复后: ü 已从西语正则移除,德语含 ü 文本不再误判为 es
assertEq(callPrivate('WP_AI_CS_I18n', 'detect_from_text', array('Grüße')), 'de', '德语(ü) → de (已修复)');
assertNull(callPrivate('WP_AI_CS_I18n', 'detect_from_text', array('Hello, I want to track my order')), '纯英文无特殊字符 → null');
assertNull(callPrivate('WP_AI_CS_I18n', 'detect_from_text', array('')), '空字符串 → null');

// ---------- 2. i18n 语言代码规范化 ----------
group('2. i18n normalize_language (语言代码规范化)');
assertEq(callPrivate('WP_AI_CS_I18n', 'normalize_language', array('en-US')), 'en', 'en-US → en');
assertEq(callPrivate('WP_AI_CS_I18n', 'normalize_language', array('zh-CN')), 'zh', 'zh-CN → zh');
assertEq(callPrivate('WP_AI_CS_I18n', 'normalize_language', array('ja-JP')), 'ja', 'ja-JP → ja');
assertEq(callPrivate('WP_AI_CS_I18n', 'normalize_language', array('fr-CA')), 'fr', 'fr-CA → fr');
assertNull(callPrivate('WP_AI_CS_I18n', 'normalize_language', array('xxx')), '未知代码 xxx → null');

// ---------- 3. i18n 综合语言检测 ----------
group('3. i18n detect_language (综合优先级)');
assertEq($i18n->detect_language('你好', ''), 'zh', '中文消息 → zh');
assertEq($i18n->detect_language('Hello', 'zh'), 'en', '前端zh但消息非CJK → en (用户切换语言)');
assertEq($i18n->detect_language('', 'en-US'), 'en', '空消息+前端en → en');
assertEq($i18n->detect_language('こんにちは', 'en'), 'ja', '日文消息覆盖前端 → ja');

// ---------- 4. i18n 系统提示词 ----------
group('4. i18n get_system_prompt (多语言提示词)');
$prompt_en = $i18n->get_system_prompt('en');
assertTrue(strpos($prompt_en, 'respond in English') !== false, '英文提示词含 respond in English');
assertTrue(strpos($prompt_en, 'NEVER mention being an AI') !== false, '英文提示词含反AI披露指令');
$prompt_zh = $i18n->get_system_prompt('zh');
assertTrue(strpos($prompt_zh, '你必须使用中文回答') !== false, '中文提示词含中文回答指令');
$prompt_ja = $i18n->get_system_prompt('ja');
assertTrue(strpos($prompt_ja, '日本語で返答') !== false, '日文提示词含日文回答指令');
// 未知语言回退英文
$fp = $i18n->get_system_prompt('xx');
assertTrue(strpos($fp, 'English') !== false, '未知语言 → 回退英文提示词');
// 附加上下文注入
$pc = $i18n->get_system_prompt('en', "ORDER #1234");
assertTrue(strpos($pc, 'ORDER #1234') !== false, '附加上下文注入到提示词');

// ---------- 5. i18n UI 字符串 ----------
group('5. i18n get_ui_strings / get_human_message');
$s_zh = $i18n->get_ui_strings('zh');
assertEq($s_zh['widget_title'], '在线客服', '中文 widget_title');
assertEq($s_zh['send_button'], '发送', '中文 send_button');
$s_en = $i18n->get_ui_strings('en');
assertEq($s_en['send_button'], 'Send', '英文 send_button');
$s_unknown = $i18n->get_ui_strings('xyz');
assertEq($s_unknown['send_button'], 'Send', '未知语言 UI 回退英文');
$hm = $i18n->get_human_message('zh');
assertTrue(strpos($hm, '已为您转接人工客服') !== false, '中文人工消息');
$qr = $i18n->get_quick_replies('zh');
assertEq(count($qr), 5, '快捷回复数量=5');

// ---------- 6. 订单号提取 ----------
group('6. Woo extract_order_id (订单号提取)');
assertEq(callPrivate('WP_AI_CS_Woo_Integration', 'extract_order_id', array('订单号#1234')), 1234, '中文 订单号#1234');
assertEq(callPrivate('WP_AI_CS_Woo_Integration', 'extract_order_id', array('订单 1234')), 1234, '中文 订单 1234');
assertEq(callPrivate('WP_AI_CS_Woo_Integration', 'extract_order_id', array('order #1234')), 1234, '英文 order #1234');
assertEq(callPrivate('WP_AI_CS_Woo_Integration', 'extract_order_id', array('order 1234')), 1234, '英文 order 1234');
assertEq(callPrivate('WP_AI_CS_Woo_Integration', 'extract_order_id', array('order number 1234')), 1234, '英文 order number 1234');
assertEq(callPrivate('WP_AI_CS_Woo_Integration', 'extract_order_id', array('#1234 tracking')), 1234, '#1234 + tracking 上下文');
assertNull(callPrivate('WP_AI_CS_Woo_Integration', 'extract_order_id', array('#1234')), '裸 #1234 无上下文 → null');
assertNull(callPrivate('WP_AI_CS_Woo_Integration', 'extract_order_id', array('我的订单是1234')), '我的订单是1234 → null');
assertNull(callPrivate('WP_AI_CS_Woo_Integration', 'extract_order_id', array('我想查询订单')), '无数字 → null');

// ---------- 7. "我的订单"查询判断 ----------
group('7. Woo is_my_order_query (无单号订单查询)');
assertTrue(callPrivate('WP_AI_CS_Woo_Integration', 'is_my_order_query', array('我的订单')), '我的订单 → true');
assertTrue(callPrivate('WP_AI_CS_Woo_Integration', 'is_my_order_query', array('查物流')), '查物流 → true');
assertTrue(callPrivate('WP_AI_CS_Woo_Integration', 'is_my_order_query', array('订单状态')), '订单状态 → true');
assertTrue(callPrivate('WP_AI_CS_Woo_Integration', 'is_my_order_query', array('order status')), 'order status → true');
assertTrue(callPrivate('WP_AI_CS_Woo_Integration', 'is_my_order_query', array('track my order')), 'track my order → true');
assertTrue(callPrivate('WP_AI_CS_Woo_Integration', 'is_my_order_query', array('Track Order')), 'Track Order → true');
assertFalse(callPrivate('WP_AI_CS_Woo_Integration', 'is_my_order_query', array('order 1234')), '含订单号 order 1234 → false');
assertFalse(callPrivate('WP_AI_CS_Woo_Integration', 'is_my_order_query', array('订单 1234')), '含订单号 订单 1234 → false');
assertFalse(callPrivate('WP_AI_CS_Woo_Integration', 'is_my_order_query', array('你好')), '你好 → false');

// ---------- 8. 商品关键词提取 ----------
group('8. Woo extract_product_keyword (商品关键词)');
assertEq(callPrivate('WP_AI_CS_Woo_Integration', 'extract_product_keyword', array('有没有耳机')), '耳机', '有没有耳机 → 耳机');
assertEq(callPrivate('WP_AI_CS_Woo_Integration', 'extract_product_keyword', array('推荐机械键盘')), '机械键盘', '推荐机械键盘 → 机械键盘(取最长)');
assertEq(callPrivate('WP_AI_CS_Woo_Integration', 'extract_product_keyword', array('recommend a headphone')), 'headphone', 'recommend a headphone → headphone');
assertEq(callPrivate('WP_AI_CS_Woo_Integration', 'extract_product_keyword', array('looking for a watch')), 'watch', 'looking for a watch → watch');
assertNull(callPrivate('WP_AI_CS_Woo_Integration', 'extract_product_keyword', array('Hello')), 'Hello → null');

// ---------- 9. 本地知识库匹配 ----------
group('9. Local_Knowledge get_answer (知识库匹配)');
$kb = new WP_AI_CS_Local_Knowledge();
assertNotNull($kb->get_answer('物流什么时候到'), '物流 → 命中物流答案');
assertNotNull($kb->get_answer('how to return my order'), 'return → 命中退货答案');
assertNotNull($kb->get_answer('退款多久到账'), '退款 → 命中退款答案');
assertNotNull($kb->get_answer('怎么付款'), '付款 → 命中支付答案');
assertNotNull($kb->get_answer('有优惠券吗'), '券 → 命中优惠答案');
assertNull($kb->get_answer('你好'), '你好 → null');
assertNull($kb->get_answer('order #1234'), 'order #1234(数字被清洗) → null');
// 纯中文保修关键词
$ans = $kb->get_answer('保修期多久');
assertTrue(strpos($ans, '三包') !== false, '保修期 → 命中保修答案(含三包)');
// 修复后: warranty 已从"质量"条目移除,归"保修"条目独占,不再歧义
$ans2 = $kb->get_answer('what is the warranty');
assertTrue(strpos($ans2, '三包') !== false || stripos($ans2, 'warranty') !== false, 'warranty → 命中保修答案(已修复)');
// 质量条目仍可命中
$ans3 = $kb->get_answer('这是正品吗');
assertTrue(strpos($ans3, '质量检测') !== false, '正品 → 命中质量答案');

// ---------- 10. 敏感信息脱敏 ----------
group('10. Logger mask_sensitive_data (敏感信息脱敏)');
assertEq(callPrivate('WP_AI_CS_Logger', 'mask_sensitive_data', array('我的手机号是13812345678')), '我的手机号是138****[REDACTED]', '手机号脱敏');
assertEq(callPrivate('WP_AI_CS_Logger', 'mask_sensitive_data', array('email: test@example.com')), 'email: [email REDACTED]', '邮箱脱敏');
assertEq(callPrivate('WP_AI_CS_Logger', 'mask_sensitive_data', array('api key: sk-abcdefghijklmnopqrstuvwxyz')), 'api key: sk-********[REDACTED]', 'API Key 脱敏');
assertEq(callPrivate('WP_AI_CS_Logger', 'mask_sensitive_data', array('password: secret123')), 'password=******', '密码脱敏');
assertEq(callPrivate('WP_AI_CS_Logger', 'mask_sensitive_data', array('今天天气不错')), '今天天气不错', '普通文本不变化');

// ---------- 11. 人工客服检测 ----------
group('11. detect_human_request (多语言人工客服关键词)');
assertTrue(callPrivate('WP_AI_Customer_Service', 'detect_human_request', array('我要转人工', 'zh')), '中文 转人工 → true');
assertTrue(callPrivate('WP_AI_Customer_Service', 'detect_human_request', array('I want to speak to a human', 'en')), '英文 human → true');
assertTrue(callPrivate('WP_AI_Customer_Service', 'detect_human_request', array('transfer to agent', 'en')), '英文 transfer → true');
assertFalse(callPrivate('WP_AI_Customer_Service', 'detect_human_request', array('你好', 'zh')), '中文 你好 → false');
assertTrue(callPrivate('WP_AI_Customer_Service', 'detect_human_request', array('オペレーターに繋いで', 'ja')), '日文 オペレーター → true');
assertTrue(callPrivate('WP_AI_Customer_Service', 'detect_human_request', array('상담원 연결해주세요', 'ko')), '韩文 상담원 → true');
assertTrue(callPrivate('WP_AI_Customer_Service', 'detect_human_request', array('hablar con un agente', 'es')), '西语 agente → true');

// ---------- 12. 后台 sanitize ----------
group('12. Admin sanitize (设置项校验)');
$admin = new WP_AI_CS_Admin();
assertEq($admin->sanitize_float_0_2('1.5'), '1.5', 'temperature 1.5 → 1.5');
assertEq($admin->sanitize_float_0_2('5'), '2', 'temperature 5 → 2 (上限)');
assertEq($admin->sanitize_float_0_2('-1'), '0', 'temperature -1 → 0 (下限)');
assertEq($admin->sanitize_int_100_8000('50'), '100', 'max_tokens 50 → 100 (下限)');
assertEq($admin->sanitize_int_100_8000('10000'), '8000', 'max_tokens 10000 → 8000 (上限)');
assertEq($admin->sanitize_hex_color('#ff0000'), '#ff0000', '合法颜色 → 保留');
assertEq($admin->sanitize_hex_color('invalid'), '#0066ff', '非法颜色 → 默认');
assertEq($admin->sanitize_chat_position('top-left'), 'top-left', '合法位置 → 保留');
assertEq($admin->sanitize_chat_position('middle'), 'bottom-right', '非法位置 → 默认');
assertEq($admin->sanitize_yes_no('yes'), 'yes', 'yes → yes');
assertEq($admin->sanitize_yes_no('no'), 'no', 'no → no');
assertEq($admin->sanitize_yes_no('other'), 'no', 'other → no');

// ---------- 13. API Handler 未配置 ----------
group('13. API_Handler 边界 (未配置 API Key)');
$handler = new WP_AI_CS_API_Handler(null);
$res = $handler->call_api(array(array('role' => 'user', 'content' => 'hi')));
assertFalse($res['ok'], '无 API Key → ok=false');
assertTrue(strpos($res['error'], 'API Key') !== false, '错误信息含 API Key 提示');

// ===== 汇总 =====
echo "\n================================================\n";
$total = $passCount + $failCount;
echo "测试汇总: 通过 {$passCount} / 失败 {$failCount} (共 {$total})\n";
if (!empty($knownIssues)) {
    echo "已知问题 (非阻断,共 " . count($knownIssues) . "):\n";
    foreach ($knownIssues as $ki) {
        echo "  - $ki\n";
    }
}
if ($failCount > 0) {
    echo "失败用例:\n";
    foreach ($failureList as $f) {
        echo "  - $f\n";
    }
    echo "================================================\n";
    exit(1);
}
echo "全部断言通过 ✓\n";
echo "================================================\n";
exit(0);
