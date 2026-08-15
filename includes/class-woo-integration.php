<?php
if (!defined('ABSPATH')) exit;

/**
 * WooCommerce 集成 - RAG 上下文注入
 * 
 * 从用户消息中提取:
 * - 订单号查询 (中英文)
 * - 商品搜索 (中英文)
 * - 最近订单查询 (无订单号时)
 * 
 * 将提取的信息注入系统提示词，增强 AI 回答的准确性
 */
class WP_AI_CS_Woo_Integration {
    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * 获取上下文 - 从用户消息中提取订单/商品信息
     */
    public function get_context($user_message) {
        if (!class_exists('WooCommerce')) {
            return '';
        }

        $context = '';

        // 1. 订单号查询 (支持中英文)
        $order_id = $this->extract_order_id($user_message);
        if ($order_id) {
            $order_data = $this->get_order_info($order_id);
            if ($order_data) {
                $context .= "\n\n【Order Information / 订单信息】\n" . $order_data;
            }
        }

        // 2. 无订单号但询问 "我的订单" → 查询最近订单
        if (!$order_id && $this->is_my_order_query($user_message)) {
            $status_filter = $this->detect_order_status_filter($user_message);
            $recent = $this->get_recent_orders($status_filter);
            if ($recent) {
                $context .= "\n\n【Recent Orders / 最近订单】\n" . $recent;
            }
        }

        // 3. 商品搜索 (支持中英文)
        $keyword = $this->extract_product_keyword($user_message);
        if ($keyword) {
            $products = $this->search_products($keyword);
            if ($products) {
                $context .= "\n\n【Related Products / 相关商品】\n" . $products;
            } else {
                $context .= "\n\n【Related Products / 相关商品】\nNo products found for '" . $keyword . "'. Please inform the customer directly that this product is not available in our store.\n本店暂无 '" . $keyword . "' 相关商品，请直接告知客户。";
            }
        }

        return apply_filters('wp_ai_cs_woo_context', $context, $user_message);
    }

    /**
     * 从消息中提取订单号 (支持中英文格式)
     */
    private function extract_order_id($message) {
        // 中文: 订单号#16, 订单 16, 订单号 16
        if (preg_match('/订单(?:号|编号)?\s*#?\s*(\d+)/u', $message, $m)) {
            return intval($m[1]);
        }
        // 英文: order #16, order 16, order number 16, order no. 16
        if (preg_match('/\border\s*(?:#|no\.?|number|id)?\s*#?\s*(\d+)/i', $message, $m)) {
            return intval($m[1]);
        }
        // 直接出现 #16 格式 (上下文需包含 order/订单)
        if (preg_match('/[#＃](\d+)/', $message, $m)) {
            // 检查是否在订单相关上下文中
            if (preg_match('/order|订单|tracking|追踪|物流|shipment/i', $message)) {
                return intval($m[1]);
            }
        }
        return null;
    }

    /**
     * 判断是否在询问"我的订单"（无具体订单号）
     */
    private function is_my_order_query($message) {
        // 先去除 emoji 和特殊字符，便于匹配
        $clean = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{FE00}-\x{FE0F}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{200D}\x{20D0}-\x{20FF}]/u', '', $message);
        $clean = trim($clean);
        
        // 中文 - 扩展匹配: 查物流/订单状态/查订单
        //   注意：左侧包含"我"单字以匹配"我最近下的订单..."这类自然语言。
        $zh_patterns = '/(我的|我想|我要|我|查询|查看|看看|查下|查一下|查|查询|帮我查).*(订单|order|购买|物流|发货|状态|快递|配送|下单)/u';
        // 中文 - 反向：先提关键词、再说"帮我查" (例："订单怎么样了，帮我查下")
        $zh_patterns_rev = '/(订单|购买|物流|发货|快递|下单|配送).*(我|帮我|查询|查看|查下|查|看看|查一下|查询)/u';
        // 中文 - 直接匹配 "订单状态" / "查物流" / "订单"
        $zh_direct_patterns = '/^(订单状态|查物流|查订单|我的订单|订单查询|物流查询|查询订单|订单|最近的订单|最近订单|下单情况)$/u';
        
        // 英文 - 订单查询：前缀为"拥有者"视角 (my/our/the) + 订单/购买关键词
        // 注：去掉 where/how/what/please/i 等无实义词作前缀，避免"Do you ship internationally?"这类
        //   物流政策咨询被误判为查订单。真正的"查我的订单"在英文里必然出现 my/our/the + orders。
        $en_patterns = '/\b(my|our|the)\b.*\b(orders?|purchase|purchases?|order)\b/i';

        // 英文 - 无 my 但有 have/has/are there + orders 结构 (Do I have... / I have... / Have I...)
        $en_have_patterns = '/\b(do\s+i\s+have|i\s+have|have\s+i|has\s+she|has\s+he|are\s+there)\b.*\b(orders?|order)\b/i';

        // 英文 - 无 my 但有追踪/查询动作词的强意图（Track Order / Where is my order / Check Order Status）
        //   显式列出动作词，用 \s+ 约束语序，不允许中间插入新的名词主语
        $en_track_patterns = '/\b(track(?:ing)?|check|where\s+is|update)\s+(my|the|an?)\s+order/i';
        $en_status_patterns = '/\b(order\s*status|status\s+of\s+(?:my|the|an?)\s+order)\b/i';

        // 英文 - 直接匹配 "Order Status" / "Track Order" / "My Orders"
        $en_direct_patterns = '/^\s*(order\s*status|track\s*order|my\s*orders?|check\s*order|order\s*tracking)\s*$/i';
        
        // 日文
        $ja_patterns = '/(注文|配送|発送|追跡|状況|私の).*(注文|配送|発送|追跡|状況|荷物)/u';
        // 韩文
        $ko_patterns = '/(주문|배송|추적|상태|내).*(주문|배송|추적|상태|물건)/u';
        
        // 如果消息太短，不触发
        if (strlen($clean) < 2) {
            return false;
        }
        
        // 检查是否已包含订单号 (支持: order 16, 订单 16, 订单号 16, 订单编号 16)
        // 注意: \b 在 Unicode 中文下不生效, 用 (?<!\p{L}) 替代
        if (preg_match('/[#＃]\d+/', $message) || preg_match('/(?:order|订单号|订单编号|订单)\s*\d+/iu', $message)) {
            return false;
        }
        
        // 先检查直接匹配 (快捷按钮等短文本)
        if (preg_match($zh_direct_patterns, $clean)) {
            return true;
        }
        if (preg_match($en_direct_patterns, $clean)) {
            return true;
        }
        
        // 再检查模糊匹配（中文支持双向：主语先 → 订单关键词先）
        return (preg_match($zh_patterns, $clean)
            || preg_match($zh_patterns_rev, $clean)
            || preg_match($en_patterns, $clean)
            || preg_match($en_have_patterns, $clean)
            || preg_match($en_track_patterns, $clean)
            || preg_match($en_status_patterns, $clean)
            || preg_match($ja_patterns, $clean)
            || preg_match($ko_patterns, $clean));
    }

    /**
     * 从用户消息中检测订单状态过滤关键词
     * 返回 WooCommerce 状态 (completed/processing/pending/cancelled) 或 null
     */
    private function detect_order_status_filter($message) {
        // 中文关键词
        $zh_pending = '/(?:未付款|待付款|待支付|未支付|没付款|还没付款|未付款的)/u';
        $zh_processing = '/(?:发货(?:了|了吗|吗|了没)?|运输中|处理中|物流|快递|配送中|寄出|已发货)/u';
        $zh_completed = '/(?:已?完成|已?收到|收到了|完成的|收到的|已送达|已签收)/u';
        $zh_cancelled = '/(?:取消|退款|退货|撤销|退单)/u';

        // 英文关键词
        $en_pending = '/\b(?:pending|unpaid|not\s*paid|awaiting\s*payment|payment\s*pending)\b/i';
        $en_processing = '/\b(?:processing|shipped?|shipping|in\s*transit|dispatched|on\s*its\s*way|out\s*for\s*delivery)\b/i';
        $en_completed = '/\b(?:completed|received|finished|delivered|arrived)\b/i';
        $en_cancelled = '/\b(?:cancel(?:led)?|refund(?:ed)?|returned?)\b/i';

        // 检测优先级：更具体的匹配优先
        if (preg_match($zh_pending, $message) || preg_match($en_pending, $message)) {
            return 'pending';
        }
        if (preg_match($zh_completed, $message) || preg_match($en_completed, $message)) {
            return 'completed';
        }
        if (preg_match($zh_processing, $message) || preg_match($en_processing, $message)) {
            return 'processing';
        }
        if (preg_match($zh_cancelled, $message) || preg_match($en_cancelled, $message)) {
            return 'cancelled';
        }

        return null;
    }

    /**
     * 获取最近订单列表（当前用户）
     */
    private function get_recent_orders($status_filter = null) {
        $current_user_id = get_current_user_id();
        if ($current_user_id === 0) {
            return 'You need to log in to view your orders / 您需要先登录才能查看订单。';
        }

        if ($status_filter) {
            $args = array(
                'customer_id' => $current_user_id,
                'limit' => 10,
                'orderby' => 'date',
                'order' => 'DESC',
                'status' => $status_filter,
            );
        } else {
            $args = array(
                'customer_id' => $current_user_id,
                'limit' => 3,
                'orderby' => 'date',
                'order' => 'DESC',
                'status' => array('pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'),
            );
        }

        $orders = wc_get_orders($args);
        if (empty($orders)) {
            if ($status_filter) {
                return sprintf(
                    'You have no %s orders. / 您没有%s状态的订单。',
                    wc_get_order_status_name($status_filter),
                    wc_get_order_status_name($status_filter)
                );
            }
            return 'You have no orders yet / 您还没有订单记录。';
        }

        $result = '';
        foreach ($orders as $order) {
            $item_names = array();
            foreach ($order->get_items() as $item) {
                $item_names[] = $item->get_name() . ' × ' . $item->get_quantity();
            }
            $result .= sprintf(
                "- Order #%d | Status: %s | Total: %s %s | Items: %s\n",
                $order->get_id(),
                wc_get_order_status_name($order->get_status()),
                $order->get_total(),
                $order->get_currency(),
                implode(', ', $item_names)
            );
        }

        return $result;
    }

    /**
     * 获取订单详情
     */
    private function get_order_info($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        // 权限校验
        $current_user_id = get_current_user_id();
        if ($current_user_id === 0) {
            return 'To view order details, please log in first. / 查看订单需要先登录。';
        }
        if (!current_user_can('manage_woocommerce') && (int) $order->get_customer_id() !== $current_user_id) {
            return 'You can only view your own orders. / 您只能查询自己的订单。';
        }

        // 构建订单详情
        $items_detail = '';
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $price = $product ? $product->get_price() : $item->get_subtotal() / $item->get_quantity();
            $items_detail .= sprintf(
                "  - %s | Qty: %d | Unit Price: %s %s\n",
                $item->get_name(),
                $item->get_quantity(),
                $price,
                $order->get_currency()
            );
        }

        // 物流信息（从订单备注获取）
        $note = $order->get_customer_note();
        $shipping_info = '';
        if (!empty($note)) {
            if (preg_match('/物流单号[：:]\s*(\S+)/u', $note, $m)) {
                $shipping_info = "\nTracking Number: {$m[1]}";
            } elseif (preg_match('/预计(\d+-\d+-\d+)/u', $note, $m)) {
                $shipping_info = "\nEstimated Delivery: {$m[1]}";
            }
        }

        return sprintf(
            "Order #%d\nStatus: %s\nTotal: %s %s\nCustomer: %s %s\nEmail: %s\n" .
            "Shipping Address: %s, %s, %s %s\n" .
            "Items (%d items):\n%s" .
            "Payment Method: %s%s",
            $order->get_id(),
            wc_get_order_status_name($order->get_status()),
            $order->get_total(),
            $order->get_currency(),
            $order->get_billing_first_name(),
            $order->get_billing_last_name(),
            $order->get_billing_email(),
            $order->get_shipping_address_1(),
            $order->get_shipping_city(),
            $order->get_shipping_postcode(),
            $order->get_shipping_country(),
            count($order->get_items()),
            $items_detail,
            $order->get_payment_method_title(),
            $shipping_info
        );
    }

    /**
     * 提取商品搜索关键词
     */
    private function extract_product_keyword($message) {
        // 中文: 先尝试直接商品类型匹配 (更精确)
        if (preg_match_all('/(耳机|手表|键盘|鼠标|手机|电脑|充电宝?|蓝牙|机械键盘|智能手表|运动手表)/u', $message, $m)) {
            // 取最长匹配
            $best = '';
            foreach ($m[0] as $match) {
                if (mb_strlen($match, 'UTF-8') > mb_strlen($best, 'UTF-8')) {
                    $best = $match;
                }
            }
            if ($best) return $best;
        }
        // 中文: 动作词 + 关键词
        if (preg_match('/(?:有没有|推荐|查找|搜索|什么|哪个|怎么选|想买|要买|推荐下|介绍)\s*(.{2,20})/u', $message, $m)) {
            $keyword = trim($m[1]);
            // 去除常见后缀词
            $keyword = preg_replace('/[?？!！。.，,]+$/', '', $keyword);
            $keyword = preg_replace('/(推荐|怎么样|多少钱|贵不贵|便宜|好吗|行吗|可以吗)$/u', '', $keyword);
            $keyword = trim($keyword);
            if (mb_strlen($keyword, 'UTF-8') >= 2) {
                return $keyword;
            }
        }
        // 英文: 动作词 + 关键词（跳过 any/some/a/an 等量词，捕获真正的商品词）
        if (preg_match('/(?:recommend|suggest|find|search|looking\s+for|want|need|buy|are\s+there|do\s+you\s+have)\s+(?:any\s+|some\s+|a\s+|an\s+|the\s+)?([a-zA-Z]{2,30})/i', $message, $m)) {
            $keyword = trim($m[1]);
            $stop_words = array('product', 'products', 'item', 'items', 'something', 'anything', 'good', 'nice', 'best', 'new', 'cheap', 'expensive', 'have', 'there', 'do', 'you', 'any', 'some', 'what', 'how', 'more', 'details', 'info', 'please');
            if (!in_array(strtolower($keyword), $stop_words) && strlen($keyword) >= 2) {
                return $keyword;
            }
        }
        // 英文: 直接商品类型 (支持复数形式)
        if (preg_match('/(headphone|earphone|watch|keyboard|mouse|phone|laptop|charger|speaker|cable|adapter|power.?bank|mechanical|wireless|bluetooth|smart|sport|electronic|device|gadget|basketball|uniform|jersey|kit|soccer|football|shoe|shirt|short|sock|glove|helmet|pad|jacket|hoodie|cap|hat|bag|backpack|bottle|whistle|cone|training|wear|fanwear|apparel|clothing|gear|equipment)[s]?/i', $message, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * 搜索商品
     */
    private function search_products($keyword) {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 5,
            's' => $keyword,
            'post_status' => 'publish'
        );

        $products = get_posts($args);
        if (empty($products)) {
            return null;
        }

        $currency = get_woocommerce_currency();
        $result = '';
        foreach ($products as $post) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $price = $product->get_price();
                $regular_price = $product->get_regular_price();
                $stock = $product->get_stock_quantity();
                $stock_text = $stock !== null ? $stock . ' in stock' : 'In stock';
                
                // 价格显示（使用 WooCommerce 货币设置）
                $price_text = $price . ' ' . $currency;
                if ($regular_price && $regular_price > $price) {
                    $price_text .= ' (was ' . $regular_price . ' ' . $currency . ')';
                }
                
                $result .= sprintf(
                    "- %s | Price: %s | Stock: %s | Link: %s\n",
                    $product->get_name(),
                    $price_text,
                    $stock_text,
                    $product->get_permalink()
                );
            }
        }
        return $result;
    }
}
