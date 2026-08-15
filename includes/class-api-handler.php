<?php
if (!defined('ABSPATH')) exit;

class WP_AI_CS_API_Handler {
    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    public function call_api($messages, $temperature = 0.7, $max_tokens = 2000) {
        $api_key = get_option('wp_ai_cs_api_key');
        $api_url = get_option('wp_ai_cs_api_url', 'https://api.deepseek.com/chat/completions');
        $model = get_option('wp_ai_cs_model', 'deepseek-chat');
        
        if (empty($api_key)) {
            return array('ok' => false, 'error' => 'API Key 未配置');
        }

        $data = array(
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $max_tokens,
            'stream' => false
        );

        $args = array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode($data),
            'timeout' => 30
        );

        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            return array('ok' => false, 'error' => '网络请求失败：' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code != 200) {
            $errInfo = json_decode($body, true);
            $errMsg = isset($errInfo['error']['message']) 
                ? $errInfo['error']['message'] 
                : 'API返回HTTP ' . $http_code;
            return array('ok' => false, 'error' => $errMsg);
        }

        $result = json_decode($body, true);
        if (isset($result['choices'][0]['message']['content'])) {
            return array(
                'ok' => true,
                'content' => $result['choices'][0]['message']['content']
            );
        }

        return array('ok' => false, 'error' => 'API返回格式异常');
    }
}