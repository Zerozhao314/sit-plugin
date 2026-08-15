<?php
if (!defined('ABSPATH')) exit;

class WP_AI_CS_Logger {

    /**
     * 日志最大文件大小 (50MB)
     */
    const MAX_LOG_FILE_SIZE = 52428800;

    /**
     * 日志保留天数 (30天)
     */
    const LOG_RETENTION_DAYS = 30;

    /**
     * 调试日志最大上下文长度 (防止日志膨胀)
     */
    const MAX_DEBUG_CONTEXT_LENGTH = 2000;

    /**
     * 调试日志最大单条长度
     */
    const MAX_DEBUG_LINE_LENGTH = 8000;

    /**
     * 写入聊天日志
     *
     * @param string $question  用户问题
     * @param bool   $success   调用是否成功
     * @param bool   $is_mobile 是否移动端
     * @param string $source    来源 (api/error/local)
     * @param string $error     错误信息
     * @return bool 是否写入成功
     */
    public function log($question, $success, $is_mobile, $source, $error = '') {
        // 生产环境: 敏感信息脱敏后记录入口
        $safe_preview = $this->mask_sensitive_data(
            is_string($question) ? mb_substr($question, 0, 80) : '(non-string)'
        );
        $this->debug('=== log() 入口 ===', array(
            'question_len' => is_string($question) ? strlen($question) : gettype($question),
            'question_preview' => $safe_preview,
            'success' => $success,
            'is_mobile' => $is_mobile,
            'source' => $source,
            'error_preview' => $this->mask_sensitive_data(substr((string)$error, 0, 100)),
        ));

        // 节点 1: 启用检查
        $enable_log = get_option('wp_ai_cs_enable_log', 'yes');
        if ($enable_log !== 'yes') {
            $this->debug('节点1[启用检查] 日志功能已禁用,跳过写入', array(
                'option_value' => $enable_log,
            ));
            return false;
        }
        $this->debug('节点1[启用检查] 日志功能已启用', array('option_value' => $enable_log));

        // 节点 2: 目录检查
        $log_dir = WP_AI_CS_PATH . 'logs';
        $this->debug('节点2[目录检查] 开始', array('log_dir' => $log_dir));

        if (!file_exists($log_dir)) {
            $this->debug('节点2[目录检查] 目录不存在,尝试创建', array('log_dir' => $log_dir));
            $created = wp_mkdir_p($log_dir);
            if (!$created) {
                $this->debug('节点2[目录检查] ❌ wp_mkdir_p 失败', array(
                    'log_dir' => $log_dir,
                    'parent_exists' => file_exists(dirname($log_dir)),
                    'parent_writable' => is_writable(dirname($log_dir)),
                ));
                return false;
            }
            $this->debug('节点2[目录检查] ✅ 目录已创建', array('log_dir' => $log_dir));
        }

        // 再次验证目录可写
        if (!is_writable($log_dir)) {
            $this->debug('节点2[目录检查] ❌ 目录不可写', array(
                'log_dir' => $log_dir,
                'perms' => substr(decoct(fileperms($log_dir)), -4),
            ));
            return false;
        }

        $this->debug('节点2[目录检查] 目录状态正常', array(
            'is_dir' => is_dir($log_dir),
            'is_writable' => is_writable($log_dir),
            'perms' => substr(decoct(fileperms($log_dir)), -4),
        ));

        // 节点 3: 文件路径与既有状态 + 大小限制检查
        $log_file = $log_dir . '/chat_' . date('Y-m-d') . '.log';
        $file_exists_before = file_exists($log_file);
        $file_size_before = $file_exists_before ? filesize($log_file) : 0;

        // 日志大小限制检查
        if ($file_size_before >= self::MAX_LOG_FILE_SIZE) {
            $this->debug('节点3[大小限制] ❌ 日志文件已达上限', array(
                'log_file' => $log_file,
                'current_size_mb' => round($file_size_before / 1048576, 2),
                'max_size_mb' => round(self::MAX_LOG_FILE_SIZE / 1048576, 2),
                'action' => '拒绝写入,防止磁盘溢出',
            ));
            return false;
        }

        $this->debug('节点3[文件路径] 确定', array(
            'log_file' => $log_file,
            'file_exists_before' => $file_exists_before,
            'file_size_before_kb' => round($file_size_before / 1024, 2),
            'remaining_kb' => round((self::MAX_LOG_FILE_SIZE - $file_size_before) / 1024, 2),
        ));

        // 节点 4: 数据清洗与脱敏
        $ip = isset($_SERVER['REMOTE_ADDR']) ? filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) : '';

        $entry = array(
            'time' => current_time('mysql'),
            'ip' => $ip ?: '',
            'device' => $is_mobile ? 'mobile' : 'desktop',
            'source' => sanitize_text_field($source),
            'question' => sanitize_text_field($question),
            'success' => $success ? 1 : 0,
            'error' => sanitize_text_field($error),
        );

        $this->debug('节点4[数据组装] 完成', array(
            'question_len' => strlen($entry['question']),
            'ip_valid' => $ip !== false,
            'source' => $entry['source'],
            'time' => $entry['time'],
        ));

        // 节点 5: JSON 编码
        $json = json_encode($entry, JSON_UNESCAPED_UNICODE);
        $json_error = json_last_error();
        if ($json === false || $json_error !== JSON_ERROR_NONE) {
            $this->debug('节点5[JSON编码] ❌ 失败', array(
                'json_error' => json_last_error_msg(),
                'json_error_code' => $json_error,
            ));
            return false;
        }
        $this->debug('节点5[JSON编码] ✅ 成功', array(
            'json_length' => strlen($json),
        ));

        // 节点 6: 写入文件
        $line = $json . "\n";

        // 再次检查写入后文件是否超限
        $estimated_after = $file_size_before + strlen($line);
        if ($estimated_after > self::MAX_LOG_FILE_SIZE) {
            $this->debug('节点6[大小限制] ❌ 预计写入后超限,拒绝写入', array(
                'estimated_after_kb' => round($estimated_after / 1024, 2),
                'max_kb' => round(self::MAX_LOG_FILE_SIZE / 1024, 2),
            ));
            return false;
        }

        $this->debug('节点6[文件写入] 开始', array(
            'log_file' => $log_file,
            'bytes_to_write' => strlen($line),
            'flags' => 'FILE_APPEND | LOCK_EX',
        ));

        $bytes_written = @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);

        if ($bytes_written === false) {
            $this->debug('节点6[文件写入] ❌ file_put_contents 返回 false', array(
                'log_file' => $log_file,
                'dir_writable' => is_writable($log_dir),
            ));
            return false;
        }

        $this->debug('节点6[文件写入] ✅ 完成', array(
            'bytes_written' => $bytes_written,
            'bytes_expected' => strlen($line),
            'match' => $bytes_written === strlen($line),
        ));

        // 节点 7: 写入后验证
        $file_exists_after = file_exists($log_file);
        $file_size_after = $file_exists_after ? filesize($log_file) : 0;
        $size_diff = $file_size_after - $file_size_before;

        $this->debug('节点7[写入后验证] 完成', array(
            'file_size_after_kb' => round($file_size_after / 1024, 2),
            'size_diff' => $size_diff,
            'size_match' => $size_diff === strlen($line),
        ));

        // 回读最后一行验证
        if ($file_exists_after && $file_size_after > 0) {
            $fp = @fopen($log_file, 'r');
            if ($fp) {
                fseek($fp, 0, SEEK_END);
                $size = ftell($fp);
                $seek = max(0, $size - 8192);
                fseek($fp, $seek);
                $tail = fread($fp, $size - $seek);
                fclose($fp);
                $lines = explode("\n", trim($tail));
                $last_line = end($lines);
                $verify = json_decode($last_line, true);
                $this->debug('节点7[写入后验证] 回读校验', array(
                    'last_line_length' => strlen($last_line),
                    'json_decode_ok' => $verify !== null,
                    'question_match' => isset($verify['question']) && $verify['question'] === $entry['question'],
                    'time_match' => isset($verify['time']) && $verify['time'] === $entry['time'],
                ));
            }
        }

        // 节点 8: 日志轮转与过期清理
        $this->maybe_cleanup_old_logs($log_dir);
        $this->debug('节点8[轮转检查]', array(
            'file_size_kb' => round($file_size_after / 1024, 2),
            'needs_cleanup' => $file_size_after > self::MAX_LOG_FILE_SIZE,
            'retention_days' => self::LOG_RETENTION_DAYS,
        ));

        $this->debug('=== log() 完成 ===' . "\n");
        return true;
    }

    /**
     * 清理过期日志和超限日志
     *
     * @param string $log_dir 日志目录
     */
    private function maybe_cleanup_old_logs($log_dir) {
        // 清理过期日志 (超过保留天数)
        $threshold = time() - (self::LOG_RETENTION_DAYS * DAY_IN_SECONDS);
        $patterns = ['chat_*.log', 'debug_*.log'];

        foreach ($patterns as $pattern) {
            $files = glob($log_dir . '/' . $pattern);
            if (!$files) continue;

            foreach ($files as $file) {
                $mtime = filemtime($file);
                if ($mtime && $mtime < $threshold) {
                    // 过期日志: 重命名为 .bak 后删除 (确保不丢失数据可恢复)
                    @unlink($file);
                    $this->debug('清理过期日志', array(
                        'file' => basename($file),
                        'age_days' => floor((time() - $mtime) / DAY_IN_SECONDS),
                    ));
                }
            }
        }

        // 如果当前日志文件仍超限,重命名归档
        $today_file = $log_dir . '/chat_' . date('Y-m-d') . '.log';
        if (file_exists($today_file) && filesize($today_file) > self::MAX_LOG_FILE_SIZE) {
            $archive_name = $log_dir . '/chat_' . date('Y-m-d-His') . '_archived.log';
            @rename($today_file, $archive_name);
            $this->debug('归档超限日志', array(
                'source' => basename($today_file),
                'archive' => basename($archive_name),
                'size_mb' => round(filesize($archive_name) / 1048576, 2),
            ));
        }
    }

    /**
     * 敏感信息脱敏
     * 检测并脱敏: 密码、银行卡号、手机号、邮箱、API Key、Token 等
     *
     * @param string $input 原始文本
     * @return string 脱敏后文本
     */
    private function mask_sensitive_data($input) {
        if (!is_string($input) || empty($input)) {
            return $input;
        }

        $patterns = array(
            // 密码: 英文 password/passwd/pwd 后跟的值 (引号包裹)
            '/(password|passwd|pwd)\s*[:=]\s*["\']([^"\']+)["\']/i' => '$1=["******"]',
            // 密码: 英文 password/passwd/pwd 后跟的值 (无引号)
            '/(password|passwd|pwd)\s*[:=]\s*(\S+)/i' => '$1=******',
            // 密码: 中文"密码是"后跟的值
            '/密码(?:是|为|[:：])\s*([^\s,，。.！!？?]+)/u' => '密码******',
            // 密码: 中文"密码"后跟引号包裹的值
            '/密码\s*["\x{201C}]([^\s,，。.！!？?]+?)["\x{201D}]/u' => '密码["******"]',
            // API Key: sk- 开头 (DeepSeek 等)
            '/sk-[a-zA-Z0-9]{20,}/' => 'sk-********[REDACTED]',
            // Bearer Token
            '/bearer\s+[a-zA-Z0-9\-\._~\+\/]+=*/i' => 'bearer [REDACTED]',
            // JWT Token
            '/eyJ[A-Za-z0-9_\-]+\.eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/' => '[JWT REDACTED]',
            // 银行卡号 (13-19位连续数字)
            '/\b(\d{4})-?(\d{4})-?(\d{4})-?(\d{4,7})\b/' => '$1****$3****',
            // 手机号 (中国大陆)
            '/\b1[3-9]\d{9}\b/' => '138****[REDACTED]',
            // 邮箱地址
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '[email REDACTED]',
            // 身份证号 (18位)
            '/\b\d{17}[\dXx]\b/' => '[ID REDACTED]',
        );

        foreach ($patterns as $pattern => $replacement) {
            $input = preg_replace($pattern, $replacement, $input);
        }

        // 限制最大长度
        if (strlen($input) > self::MAX_DEBUG_CONTEXT_LENGTH) {
            $input = substr($input, 0, self::MAX_DEBUG_CONTEXT_LENGTH) . '...[TRUNCATED]';
        }

        return $input;
    }

    /**
     * 写入调试日志 (独立于主聊天日志)
     * 通过 wp_ai_cs_enable_debug_log 选项或 WP_AI_CS_DEBUG 常量控制
     *
     * @param string $message 调试消息
     * @param array  $context 上下文数据
     */
    private function debug($message, $context = array()) {
        // 检查是否启用调试日志 (双重控制: 常量优先,防止线上忘记关闭)
        $constant_enabled = defined('WP_AI_CS_DEBUG') && WP_AI_CS_DEBUG;
        if (!$constant_enabled) {
            // 非常量模式下,实时读取选项以确保状态同步
            $option_value = get_option('wp_ai_cs_enable_debug_log', 'no');
            if ($option_value !== 'yes') {
                return;
            }
        }

        $debug_dir = WP_AI_CS_PATH . 'logs';
        if (!file_exists($debug_dir) && !wp_mkdir_p($debug_dir)) {
            // 目录创建失败时静默失败 (不抛异常,不中断主流程)
            return;
        }

        $debug_file = $debug_dir . '/debug_' . date('Y-m-d') . '.log';

        // 调试日志大小检查
        if (file_exists($debug_file) && filesize($debug_file) >= self::MAX_LOG_FILE_SIZE) {
            // 归档超限的调试日志
            $archive_name = $debug_dir . '/debug_' . date('Y-m-d-His') . '_archived.log';
            @rename($debug_file, $archive_name);
        }

        $line = '[' . current_time('mysql') . '] [' . round(microtime(true), 4) . '] ' . $message;

        if (!empty($context)) {
            // 脱敏 + 截断上下文数据
            $safe_context = $this->sanitize_debug_context($context);
            $json = json_encode($safe_context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }

        $line .= "\n";

        // 限制单条长度
        if (strlen($line) > self::MAX_DEBUG_LINE_LENGTH) {
            $line = substr($line, 0, self::MAX_DEBUG_LINE_LENGTH) . "\n";
        }

        @file_put_contents($debug_file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 调试日志上下文数据脱敏
     *
     * @param array $context 原始上下文
     * @return array 脱敏后上下文
     */
    private function sanitize_debug_context($context) {
        $safe = array();
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $safe[$key] = $this->mask_sensitive_data($value);
            } elseif (is_array($value)) {
                $safe[$key] = $this->sanitize_debug_context($value);
            } elseif (is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;
            } else {
                $safe[$key] = gettype($value);
            }
        }
        return $safe;
    }
}
