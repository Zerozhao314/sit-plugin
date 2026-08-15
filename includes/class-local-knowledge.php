<?php
if (!defined('ABSPATH')) exit;

/**
 * 本地知识库 - 常见问题 RAG 补充
 * 
 * 支持中英文关键词匹配
 * 作为 WooCommerce 上下文的补充，注入系统提示词
 */
class WP_AI_CS_Local_Knowledge {
    private $knowledge_base = array();

    public function __construct() {
        $this->load_default_knowledge();
    }

    /**
     * 加载默认知识库 (支持中英文)
     */
    private function load_default_knowledge() {
        $this->knowledge_base = apply_filters('wp_ai_cs_knowledge_base', array(
            // 物流 / Shipping
            '物流|快递|发货|配送|物流信息|shipping|delivery|tracking|shipment' => 
                '我们一般在下单后24小时内发货，物流时效为2-5天。您可以在订单详情中查看物流信息。 / ' .
                'We ship within 24 hours of ordering. Delivery takes 2-5 days. You can track your order in your order details.',
            
            // 退货 / Returns
            '退货|退换|退货|return|refund|exchange' => 
                '我们支持7天无理由退货，商品需保持完好。请联系客服获取退货地址和流程说明。 / ' .
                'We offer 7-day free returns. Items must be in unused condition. Contact us for return instructions.',
            
            // 退款 / Refund
            '退款|退钱|refund|money.back|payment.return' => 
                '退款将在我们收到退货商品后3-5个工作日内处理，原路返回至您的支付账户。 / ' .
                'Refunds are processed within 3-5 business days after we receive the returned item. Money is returned to your original payment method.',
            
            // 支付 / Payment
            '支付|付款|payment|pay|checkout|credit.card' => 
                '我们支持微信支付、支付宝、银行卡等多种支付方式，所有支付均安全可靠。 / ' .
                'We support multiple payment methods including WeChat Pay, Alipay, and bank cards. All payments are secure.',
            
            // 优惠 / Promotion
            '优惠|折扣|券|促销|活动|coupon|discount|promotion|sale|voucher' => 
                '关注我们的官方公众号，可领取新人优惠券！我们也定期举办促销活动，欢迎关注。 / ' .
                'Follow our official account for new customer coupons! We regularly run promotions and sales.',
            
            // 质量 / Quality (注: warranty/guarantee 归"保修"条目独占,避免歧义)
            '质量|正品|保证|保障|quality|authentic' => 
                '所有商品均经过严格质量检测，如有质量问题，我们承诺无条件退换。 / ' .
                'All products undergo strict quality checks. We offer free returns for any quality issues.',
            
            // 客服 / Support
            '客服|人工|工作时间|customer.service|support|business.hours|working.hours' => 
                '我们的客服工作时间是9:00-21:00，您可以在线咨询或拨打客服电话 400-XXX-XXXX。 / ' .
                'Our customer service hours are 9:00 AM - 9:00 PM. You can chat online or call 400-XXX-XXXX.',
            
            // 保修 / Warranty
            '保修|质保|三包|warranty|guarantee|repair|maintenance' => 
                '商品享受国家三包政策，具体保修期限请查看商品详情页。 / ' .
                'Products are covered by national three-guarantee policy. Please check the product page for specific warranty terms.',
            
            // 账号 / Account
            '账号|登录|注册|账户|account|login|register|sign.in|sign.up' => 
                '您可以在右上角的"我的账户"中登录或注册。新用户注册可享受新人优惠。 / ' .
                'You can login or register in the "My Account" section. New users get a welcome discount.',
            
            // 地址 / Address
            '地址|收货地址|修改地址|address|shipping.address|change.address' => 
                '您可以在"我的账户 - 地址管理"中修改您的收货地址。 / ' .
                'You can update your shipping address in "My Account - Address Management".',
            
            // 发票 / Invoice
            '发票|开票|invoice|receipt|bill|tax' => 
                '我们支持开具电子发票和纸质发票，下单时请在备注中说明发票信息。 / ' .
                'We support both electronic and paper invoices. Please specify invoice details in the order notes.',
            
            // 库存 / Stock
            '库存|缺货|有货|现货|stock|out.of.stock|available|in.stock' => 
                '大部分商品现货供应，库存信息请查看商品页面。热销商品可能会临时缺货。 / ' .
                'Most products are in stock. Check the product page for current stock levels. Popular items may occasionally be out of stock.',
            
            // 跨境 / International
            '跨境|海外|国际运输|international|overseas|worldwide|global' => 
                '目前我们支持国内配送，跨境配送敬请期待。国际订单请联系客服咨询。 / ' .
                'Currently we support domestic delivery only. International shipping is coming soon. Please contact us for international orders.',
            
            // 会员 / Membership
            '会员|VIP|积分|member|vip|points|rewards|loyalty' => 
                '注册即享会员权益，购物可累积积分，积分可抵扣现金使用。 / ' .
                'Register to enjoy member benefits. Earn points on every purchase, redeemable for cash discounts.',
        ));
    }

    /**
     * 获取知识库答案
     * 
     * @param string $question 用户问题
     * @return string|null 匹配的答案或 null
     */
    public function get_answer($question) {
        $question_lower = mb_strtolower($question, 'UTF-8');
        
        // 去除订单号等数字内容，避免干扰关键词匹配
        $cleaned = preg_replace('/\d+/', '', $question_lower);
        $cleaned = preg_replace('/[#＃]\d+/', '', $cleaned);
        
        foreach ($this->knowledge_base as $keywords => $answer) {
            $patterns = explode('|', $keywords);
            foreach ($patterns as $pattern) {
                $pattern = trim($pattern);
                if (empty($pattern)) continue;
                // 使用 mb_strpos 进行子串匹配
                if (mb_strpos($cleaned, $pattern, 0, 'UTF-8') !== false) {
                    return $answer;
                }
            }
        }
        
        return null;
    }

    /**
     * 添加自定义知识条目 (供外部调用扩展)
     */
    public function add_knowledge($keywords, $answer) {
        $this->knowledge_base[$keywords] = $answer;
    }
}
