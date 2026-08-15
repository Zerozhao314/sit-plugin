# 更新日志

## v1.2.0 (2026-08-14)

### 新功能
- 订单状态语义过滤：根据用户问句中的状态关键词（发货/未付款/收到等）自动过滤对应状态订单
- 商品链接自动嵌入：AI 推荐商品时自动附带可点击的商品链接
- HTML 安全渲染：bot 消息中的 HTML 标签（列表、加粗等）正确渲染，XSS 白名单过滤
- Markdown 链接渲染：`[text](url)` 自动转换为可点击的 `<a>` 标签

### 优化
- 商品推荐策略：先询问需求，只推荐 1 个最合适商品（热销款/新款/匹配需求）
- 搜索不到商品时直接告知客户无货，并提供留言人工处理选项
- 货币符号动态获取（`get_woocommerce_currency()`），不再硬编码 ¥
- 商品名称多语言翻译：根据用户语言自动翻译商品标题
- 链接嵌入商品名称，不再使用"点击这里"
- 英文商品关键词提取改进：跳过量词，扩展运动商品词库
- 英文订单查询句式扩展：支持 "Do I have..." 等句式

### 修复
- HTML 标签源码显示问题（`<br>`, `<ul>`, `<li>`, `<strong>` 不再以源码形式显示）
- 货币符号错误（¥ → USD，跟随 WooCommerce 设置）
- 商品推荐中货币符号硬编码问题
- 英文订单查询 "Do I have unpaid orders?" 未识别为查单
- 西班牙语等非英文语言商品推荐无链接问题
- 系统提示词拼接逻辑：`knowledge` 和 `product_recommendation` 字段未包含

### 文件变更
- `wp-ai-customer-service.php`：版本号 1.1.0 → 1.2.0
- `includes/class-woo-integration.php`：状态过滤、商品链接、关键词提取、货币符号、无货提示
- `includes/class-i18n.php`：商品推荐规则、链接格式、多语言翻译、提示词拼接
- `assets/js/chat-widget.js`：HTML 渲染、markdown 链接转换、XSS 白名单
- `assets/css/chat-widget.css`：链接样式
