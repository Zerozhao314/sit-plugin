<?php
if (!defined('ABSPATH')) exit;

/**
 * WP AI CS 国际化与语言检测类
 * 
 * 职责:
 * - 自动检测客户语言 (浏览器头 + 对话内容)
 * - 提供多语言 UI 字符串
 * - 提供多语言系统提示词模板
 * - 默认英文,支持中/英/日/韩等
 */
class WP_AI_CS_I18n {

    private static $instance = null;

    /**
     * 支持的语言及其 code
     */
    private $supported_languages = array('en', 'zh', 'ja', 'ko', 'es', 'fr', 'de', 'ru');

    /**
     * 默认语言
     */
    private $default_language = 'en';

    /**
     * 获取单例
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 从多个来源检测语言
     * 优先级:
     * 1. 前端 AJAX 传入的 language 参数 (最准确,基于实际对话)
     * 2. 用户消息内容检测 (字符分析)
     * 3. 浏览器 Accept-Language header
     * 4. 默认英文
     * 
     * @param string $user_message 用户消息
     * @param string $client_language 前端传来的语言
     * @return string 检测到的语言代码
     */
    public function detect_language($user_message = '', $client_language = '') {
        $cjk_languages = array('zh', 'ja', 'ko');
        $text_lang = null;
        
        // 1. 从消息内容检测 (所有语言)
        if (!empty($user_message)) {
            $text_lang = $this->detect_from_text($user_message);
            // 如果检测到明确的非英语语言,直接返回(优先于前端参数)
            // 因为前端参数可能是 UI 语言(如浏览器是中文,但用户用西班牙语提问)
            if ($text_lang && $text_lang !== 'en') {
                return $text_lang;
            }
        }

        // 2. 前端传入的语言参数 (仅在文本检测未识别时使用)
        if (!empty($client_language)) {
            $lang = $this->normalize_language($client_language);
            if ($lang) {
                // 场景: 前端是 CJK (zh/ja/ko),但消息内容是非 CJK (英文/法文等)
                // 说明用户切换了对话语言,此时默认使用英文
                if (in_array($lang, $cjk_languages) && empty($text_lang) && !empty($user_message)) {
                    return 'en';
                }
                return $lang;
            }
        }

        // 3. 消息内容检测到英语
        if ($text_lang) {
            return $text_lang;
        }

        // 4. 从浏览器 Accept-Language header 检测
        $lang = $this->detect_from_browser();
        if ($lang) {
            // 同样: 如果浏览器是 CJK 但消息非 CJK,默认英文
            if (in_array($lang, $cjk_languages) && !empty($user_message)) {
                $msg_check = $this->detect_from_text($user_message);
                if ($msg_check === null) {
                    return 'en';
                }
            }
            return $lang;
        }

        // 5. 默认英文
        return $this->default_language;
    }

    /**
     * 规范化语言代码
     */
    private function normalize_language($lang) {
        $lang = strtolower(trim($lang));
        
        $map = array(
            'en' => 'en', 'en-us' => 'en', 'en-gb' => 'en', 'en-au' => 'en',
            'zh' => 'zh', 'zh-cn' => 'zh', 'zh-tw' => 'zh', 'zh-hk' => 'zh', 'zh-sg' => 'zh',
            'ja' => 'ja', 'ja-jp' => 'ja',
            'ko' => 'ko', 'ko-kr' => 'ko',
            'es' => 'es', 'es-es' => 'es', 'es-mx' => 'es',
            'fr' => 'fr', 'fr-fr' => 'fr', 'fr-ca' => 'fr',
            'de' => 'de', 'de-de' => 'de',
            'ru' => 'ru', 'ru-ru' => 'ru', 'ru-by' => 'ru', 'ru-kz' => 'ru',
        );

        return isset($map[$lang]) ? $map[$lang] : null;
    }

    /**
     * 从文本内容检测语言 (基于 Unicode 范围)
     */
    private function detect_from_text($text) {
        if (empty($text)) {
            return null;
        }

        // 日文假名检测 (优先,因为日文常包含汉字,假名是日文独有的)
        if (preg_match('/[\x{3040}-\x{309f}\x{30a0}-\x{30ff}]/u', $text)) {
            return 'ja';
        }

        // 韩文检测 (韩文是独有的字符)
        if (preg_match('/[\x{ac00}-\x{d7af}\x{1100}-\x{11ff}]/u', $text)) {
            return 'ko';
        }

        // 中文字符检测 (CJK Unified Ideographs + Extension)
        if (preg_match('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{20000}-\x{2a6df}]/u', $text)) {
            return 'zh';
        }

        // 西班牙语检测 (¿, á, é, í, ó, ú, ñ, ¡)
        // 注: ü 是德语特征字符,从西语集中移除以避免德语文本误判为西语
        if (preg_match('/[¿¡ñáéíóú]/u', $text)) {
            return 'es';
        }

        // 法语检测 (ç, à, â, ê, î, ô, û, æ, œ)
        if (preg_match('/[çàâêîôûæœ]/u', $text)) {
            return 'fr';
        }

        // 德语检测 (ä, ö, ü, ß)
        if (preg_match('/[äöüß]/u', $text)) {
            return 'de';
        }

        // 俄语检测 (西里尔字母: Cyrillic 基本区 + 扩展区)
        // 覆盖俄语、乌克兰语、白俄罗斯语、哈萨克语等使用西里尔字母的语言
        if (preg_match('/[\x{0400}-\x{04FF}\x{0500}-\x{052F}\x{2DE0}-\x{2DFF}\x{A640}-\x{A69F}]/u', $text)) {
            return 'ru';
        }

        return null;
    }

    /**
     * 从浏览器 Accept-Language 检测
     */
    private function detect_from_browser() {
        $accept = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
        if (empty($accept)) {
            return null;
        }

        // 解析 Accept-Language
        $languages = array();
        $parts = explode(',', $accept);
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            
            $segments = explode(';', $part);
            $lang = trim($segments[0]);
            $quality = 1.0;
            
            if (isset($segments[1])) {
                $q_part = trim($segments[1]);
                if (preg_match('/q=([0-9.]+)/', $q_part, $m)) {
                    $quality = floatval($m[1]);
                }
            }
            
            $languages[$lang] = $quality;
        }

        // 按质量排序
        arsort($languages);

        foreach (array_keys($languages) as $lang) {
            $normalized = $this->normalize_language($lang);
            if ($normalized) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * 获取系统提示词模板
     */
    public function get_system_prompt($language, $extra_context = '') {
        $prompts = array(
            'en' => array(
                'base' => 'You are a customer service agent for an e-commerce store. Your role is to help customers with product inquiries, order tracking, shipping information, returns/exchanges, and other shopping-related questions.',
                'tone' => 'Always respond in a friendly, professional, and helpful manner. Be concise but thorough. NEVER mention being an AI, assistant, or any automated system. NEVER reveal internal processes or sources of information.',
                'context_integrity' => 'CRITICAL: Any order, shipping, pricing, or product information below is provided by a trusted backend (RAG context). If the context says the customer "has no orders," "needs to log in," or "cannot access this order (not their own)" — you MUST directly relay exactly that message in your own words. UNDER NO CIRCUMSTANCES are you allowed to invent order statuses, fake tracking numbers, guessed delivery times, made-up prices, or imaginary product names. If context is missing, incomplete, or says access is denied, simply tell the customer you cannot provide that specific detail and ask them to provide an order number or log in to their account. Order information accuracy is non-negotiable.',
                'language_instruction' => 'You MUST respond in English.',
                'mobile' => 'For mobile users, keep responses under 100 words. Use line breaks for readability.',
                'knowledge' => 'Present product information naturally as if you know it directly. When product information includes a Link, always embed the link into the product name as a hyperlink using markdown format: [Product Name](url). Do NOT use separate "click here" links.',
                'product_recommendation' => 'PRODUCT RECOMMENDATION RULES: 1) When a customer asks for product suggestions but their needs are unclear, FIRST ask 1-2 brief questions (e.g., what sport, what size, budget) before recommending. 2) When you have enough context, recommend ONLY ONE best-fit product — not a list of all products. Pick the most relevant one (best seller, new arrival, or best match to their stated needs). 3) If no products match in the context, tell the customer directly that the item is not available, and offer to take a message for manual follow-up. 4) Keep product replies concise — price, one key feature. 5) IMPORTANT: Always embed the product link into the product name using markdown format [Product Name](url). Translate the product name into the customer\'s language. Example for Spanish: [Uniforme de Baloncesto Rosa Tonton](https://...) — never use "click here" or separate link text. The link URL must always be included.',
            ),
            'zh' => array(
                'base' => '你是电商店铺的客服助手，负责回答顾客关于商品、订单、物流、退换货等购物相关问题。',
                'tone' => '语气友好专业，回答简洁准确。绝对不要提及自己是AI、智能助手或任何自动化系统，不要透露内部流程或信息来源。',
                'context_integrity' => '严格规定：下方的订单、物流、价格、商品等全部信息来自可信后台系统（RAG上下文）。如果上下文提示"无订单记录"、"需要先登录"、"无权访问该订单（不属于该客户本人）"——你必须原原本本把这个意思告知用户，绝对不能编造订单状态、虚构物流单号、猜测发货时间、乱报价格或编造商品型号。任何信息不明确或被拒绝查询时，请直接告诉用户无法提供该详情，并请用户提供正确订单号或登录账号查看。订单信息的准确性高于一切，绝不允许猜测。',
                'language_instruction' => '你必须使用中文回答。',
                'mobile' => '移动端用户回复尽量简洁，每条不超过100字，注意分段。',
                'knowledge' => '介绍商品信息时，请以店铺客服的口吻自然呈现。当商品信息中包含链接（Link）时，请将链接嵌入商品名称中，使用 markdown 格式：[商品名称](url)。不要使用"点击这里"等单独的链接文字。',
                'product_recommendation' => '商品推荐规则：1）客户询问商品推荐但需求不明确时，先简短问1-2个问题（如运动项目、尺码、预算），了解需求后再推荐。2）了解需求后，只推荐1个最合适的商品——不要列出所有商品。选择最匹配的（热销款、新款或最符合客户需求的）。3）如果上下文中没有匹配的商品，直接告诉客户该商品暂无，并可以表示帮客户留言转人工处理。4）商品回复要简洁——价格、一个亮点即可。5）重要：务必将商品链接嵌入商品名称中，使用 markdown 格式 [商品名称](url)。商品名称需翻译成客户使用的语言。中文示例：[Tonton 粉色篮球服](https://...) ——绝对不要用"点击这里"或单独的链接文字。链接 URL 必须始终包含。',
            ),
            'ja' => array(
                'base' => 'あなたはECショップのカスタマーサービスアシスタントです。商品の問い合わせ、注文追跡、配送情報、返品・交換などのショッピング関連の質問にお客様を支援します。',
                'tone' => '常にフレンドリーでプロフェッショナル、そして親切な態度で対応してください。AIや自動システムであることを絶対に言及しないでください。',
                'context_integrity' => '厳格ルール：以下に記載される注文・配送・価格・商品情報はすべて信頼できるバックエンド（RAGコンテキスト）から提供されています。もしコンテキストに「注文履歴なし」「ログインが必要」「他の顧客の注文のため閲覧不可」と記載されている場合は、その内容をそのまま丁寧にお客様に伝えてください。絶対に注文状況を想像したり、架空の追跡番号・発送日時・価格を作り上げたりしてはなりません。情報が明確でない、またはアクセスが拒否された場合は、その詳細を提供できない旨を正直に伝え、正しい注文番号の確認やログインをお願いしてください。注文情報の正確性は何よりも優先されます。',
                'language_instruction' => '日本語で返答してください。',
                'mobile' => 'モバイルユーザー向けに、返答は100語以下に抑え、改行で読みやすくしてください。',
                'knowledge' => '商品知識に言及する場合は、店舗スタッフとして自然に提示してください。商品情報にリンクが含まれている場合は、必ず商品名にリンクを埋め込んでください。markdown形式：[商品名](url)。「ここをクリック」などの別リンクは使用しないでください。',
            ),
            'ko' => array(
                'base' => '당신은 전자상거래 쇼핑몰의 고객 서비스 어시스턴트입니다. 상품 문의, 주문 추적, 배송 정보, 반품/교환 등 쇼핑 관련 질문으로 고객을 지원합니다.',
                'tone' => '항상 친절하고 전문적이며 도움이 되는 방식으로 답변하세요. AI나 자동 시스템임을 절대 언급하지 마세요.',
                'context_integrity' => '필수 규칙: 아래에 나오는 주문, 배송, 가격, 상품 정보는 모두 신뢰할 수 있는 백엔드(RAG 컨텍스트)에서 제공됩니다. 컨텍스트에 "주문 기록 없음", "로그인 필요", "본인 주문이 아니므로 접근 불가"라고 명시되어 있다면, 그 내용을 그대로 고객에게 정중하게 전달해야 합니다. 절대로 주문 상태를 추측하거나 가짜 운송장 번호, 배송 일자, 가격, 상품명을 지어내서는 안 됩니다. 정보가 불분명하거나 접근이 거부된 경우, 해당 내용을 제공할 수 없다고 솔직히 말하고 올바른 주문번호 확인 또는 로그인을 요청하세요. 주문 정보의 정확성은 최우선입니다.',
                'language_instruction' => '반드시 한국어로 답변하세요.',
                'mobile' => '모바일 사용자의 경우 답변을 100단어 이내로 유지하고 가독성을 위해 줄 바꿈을 사용하세요.',
                'knowledge' => '상품 지식을 언급할 때 점원처럼 자연스럽게 제시하세요. 상품 정보에 링크가 포함된 경우, 항상 상품명에 링크를 삽입하세요. markdown 형식: [상품명](url). "여기를 클릭"과 같은 별도 링크는 사용하지 마세요.',
            ),
            'es' => array(
                'base' => 'Eres un asistente de servicio al cliente para una tienda de comercio electrónico. Tu función es ayudar a los clientes con consultas sobre productos, seguimiento de pedidos, información de envío, devoluciones/cambios y otras preguntas relacionadas con compras.',
                'tone' => 'Responde siempre de manera amigable, profesional y útil. Sé conciso pero completo. NUNCA menciones que eres una IA o sistema automatizado.',
                'context_integrity' => 'REGLA OBLIGATORIA: Toda la información sobre pedidos, envíos, precios y productos mostrada a continuación proviene de un backend fiable (contexto RAG). Si el contexto indica "sin pedidos", "necesita iniciar sesión" o "no puede ver este pedido (no pertenece al cliente)", DEBES transmitir exactamente ese mensaje al cliente con tus propias palabras. BAJO NINGÚN CONCEPTO inventes estados de pedido, números de seguimiento falsos, fechas de envío adivinadas, precios inventados o nombres de productos inexistentes. Si falta información o el acceso es denegado, informa sinceramente al cliente que no puedes proporcionar ese detalle y pide el número de pedido correcto o el inicio de sesión. La exactitud de la información de pedidos es innegociable.',
                'language_instruction' => 'DEBES responder en español.',
                'mobile' => 'Para usuarios móviles, mantén las respuestas por debajo de 100 palabras. Usa saltos de línea para mayor legibilidad.',
                'knowledge' => 'Presenta la información del producto de forma natural como si la conocieras directamente. Cuando la información del producto incluya un enlace, siempre incrusta el enlace en el nombre del producto usando formato markdown: [Nombre del Producto](url). NO uses enlaces separados como "haz clic aquí".',
            ),
            'fr' => array(
                'base' => 'Vous êtes un assistant de service client pour une boutique en ligne. Votre rôle est d\'aider les clients pour les demandes de produits, le suivi des commandes, les informations d\'expédition, les retours/échanges et autres questions liées aux achats.',
                'tone' => 'Répondez toujours de manière amicale, professionnelle et utile. Soyez concis mais complet. Ne mentionnez JAMAIS que vous êtes une IA ou un système automatisé.',
                'context_integrity' => 'RÈGLE OBLIGATOIRE : Toutes les informations sur les commandes, livraisons, prix et produits ci-dessous proviennent d\'un backend fiable (contexte RAG). Si le contexte mentionne "aucune commande", "connexion requise" ou "accès refusé à cette commande (pas celle du client)", VOUS DEVEZ relayer fidèlement ce message au client. EN AUCUN CAS vous ne devez inventer d\'états de commande, faux numéros de suivi, dates d\'expédition estimées, prix fictifs ou noms de produits imaginaires. Si l\'information manque ou l\'accès est refusé, dites honnêtement au client que vous ne pouvez pas fournir ce détail et demandez le bon numéro de commande ou la connexion au compte. L\'exactitude des informations de commande est impérative.',
                'language_instruction' => 'Vous DEVEZ répondre en français.',
                'mobile' => 'Pour les utilisateurs mobiles, gardez les réponses sous 100 mots. Utilisez des sauts de ligne pour plus de lisibilité.',
                'knowledge' => 'Présentez les informations produit naturellement comme si vous les connaissiez directement. Lorsque les informations produit incluent un lien, intégrez toujours le lien dans le nom du produit au format markdown : [Nom du Produit](url). N\'utilisez PAS de liens séparés comme "cliquez ici".',
            ),
            'de' => array(
                'base' => 'Sie sind ein Kundendienstassistent für einen E-Commerce-Shop. Ihre Aufgabe ist es, Kunden bei Produktanfragen, Bestellverfolgung, Versandinformationen, Rücksendungen/Umtauschen und anderen Einkaufsbezogenen Fragen zu unterstützen.',
                'tone' => 'Antworten Sie stets freundlich, professionell und hilfsbereit. Seien Sie präzise aber vollständig. Erwähnen Sie NIEMALS, dass Sie eine KI oder ein automatisiertes System sind.',
                'context_integrity' => 'ZWINGENDE REGEL: Alle unten genannten Bestell-, Versand-, Preis- und Produktinformationen stammen aus einem vertrauenswürdigen Backend (RAG-Kontext). Falls der Kontext "keine Bestellungen", "Login erforderlich" oder "Zugriff auf diese Bestellung verweigert (nicht eigene)" lautet, MÜSSEN Sie diese Nachricht exakt im eigenen Wortlaut an den Kunden weitergeben. Unter KEINEN Umständen dürfen Sie Bestellstatus, erfundene Sendungsnummern, geratene Lieferzeiten, falsche Preise oder Fantasieprodukte erfinden. Fehlt die Information oder ist der Zugriff abgelehnt, sagen Sie dem Kunden offen, dass Sie genau diese Angabe nicht machen können, und bitten Sie um die korrekte Bestellnummer oder einen Login. Die Genauigkeit von Bestelldaten ist nicht verhandelbar.',
                'language_instruction' => 'Sie MÜSSEN auf Deutsch antworten.',
                'mobile' => 'Halten Sie Antworten für mobile Nutzer unter 100 Wörtern. Verwenden Sie Zeilenumbrüche für die Lesbarkeit.',
                'knowledge' => 'Präsentieren Sie Produktwissen natürlich, als ob Sie es direkt kennen würden. Wenn Produktinformationen einen Link enthalten, betten Sie den Link immer in den Produktnamen im Markdown-Format ein: [Produktname](url). Verwenden Sie KEINE separaten Links wie "hier klicken".',
            ),
            'ru' => array(
                'base' => 'Вы — ассистент службы поддержки клиентов интернет-магазина. Ваша задача — помогать клиентам с вопросами о товарах, отслеживанием заказов, информацией о доставке, возвратом/обменом и другими вопросами, связанными с покупками.',
                'tone' => 'Всегда отвечайте дружелюбно, профессионально и по делу. Будьте кратки, но полны. НИКОГДА не упоминайте, что вы ИИ или автоматизированная система. Не раскрывайте внутренние процессы или источники информации.',
                'context_integrity' => 'ОБЯЗАТЕЛЬНОЕ ПРАВИЛО: Вся информация о заказах, доставке, ценах и товарах ниже получена из надёжного бэкенда (RAG-контекст). Если в контексте указано «нет заказов», «требуется вход в систему» или «доступ к заказу запрещён (не ваш заказ)», вы ДОЛЖНЫ точно передать клиенту именно это сообщение своими словами. НИ ПРИ КАКИХ ОБСТОЯТЕЛЬСТВАХ не выдумывайте статусы заказов, фальшивые номера отслеживания, предполагаемые даты отправки, вымышленные цены или названия товаров. При отсутствии информации или запрете доступа честно сообщите клиенту, что не можете предоставить такие сведения, и попросите верный номер заказа либо выполнить вход в аккаунт. Точность данных о заказах — абсолютный приоритет.',
                'language_instruction' => 'Вы ДОЛЖНЫ отвечать на русском языке.',
                'mobile' => 'Для мобильных пользователей ответы должны быть до 100 слов. Используйте разрывы строк для удобства чтения.',
                'knowledge' => 'Представляйте информацию о товарах естественно, как будто знаете её напрямую. Когда информация о товаре содержит ссылку, всегда встраивайте ссылку в название товара в формате markdown: [Название товара](url). НЕ используйте отдельные ссылки вроде "нажмите здесь".',
            ),
        );

        if (!isset($prompts[$language])) {
            $language = 'en';
        }

        $p = $prompts[$language];
        $system_prompt = $p['base'] . "\n\n" . $p['tone'] . "\n\n" . $p['context_integrity'] . "\n\n" . $p['language_instruction'];
        if (!empty($p['knowledge'])) {
            $system_prompt .= "\n\n" . $p['knowledge'];
        }
        // 商品推荐规则：未设置时回退到英文版本，确保所有语言都有链接渲染规则
        $product_rules = !empty($p['product_recommendation']) ? $p['product_recommendation'] : $prompts['en']['product_recommendation'];
        $system_prompt .= "\n\n" . $product_rules;

        if (!empty($extra_context)) {
            $system_prompt .= "\n\n" . $extra_context;
        }

        return $system_prompt;
    }

    /**
     * 获取移动端附加提示
     */
    public function get_mobile_instruction($language) {
        $mobile_instructions = array(
            'en' => "\n\n[Mobile User] Please keep responses concise (under 100 words). Use line breaks for readability.",
            'zh' => "\n\n[移动端用户] 回复尽量简洁（不超过100字），注意分段以保证可读性。",
            'ja' => "\n\n[モバイルユーザー] 返答は簡潔に（100語以下）、改行で読みやすくしてください。",
            'ko' => "\n\n[모바일 사용자] 답변을 간결하게 유지하고(100단어 이내), 가독성을 위해 줄 바꿈을 사용하세요.",
            'es' => "\n\n[Usuario Móvil] Mantén las respuestas concisas (menos de 100 palabras). Usa saltos de línea para legibilidad.",
            'fr' => "\n\n[Utilisateur Mobile] Gardez les réponses concises (moins de 100 mots). Utilisez des sauts de ligne pour la lisibilité.",
            'de' => "\n\n[Mobilnutzer] Halten Sie Antworten präzise (unter 100 Wörtern). Verwenden Sie Zeilenumbrüche für die Lesbarkeit.",
            'ru' => "\n\n[Мобильный пользователь] Ответы должны быть краткими (до 100 слов). Используйте разрывы строк для удобства чтения.",
        );

        return isset($mobile_instructions[$language]) ? $mobile_instructions[$language] : $mobile_instructions['en'];
    }

    /**
     * 获取 UI 翻译字符串
     */
    public function get_ui_strings($language) {
        $strings = array(
            'en' => array(
                'widget_title' => 'Customer Service',
                'status_online' => 'Online',
                'toggle_text' => 'Support',
                'input_placeholder' => 'Type your question...',
                'send_button' => 'Send',
                'typing_text' => 'Typing...',
                'error_network' => 'Network error. Please check your connection and try again.',
                'error_service' => 'Sorry, the service is temporarily unavailable. Please try again later.',
                'error_empty' => 'Please enter your question.',
                'error_too_long' => 'Text too long (max 4000 characters).',
                'human_title' => 'Human Customer Service',
                'human_phone' => 'Phone: 400-XXX-XXXX',
                'human_online' => 'Online chat: Click [Contact Support] below',
                'human_hours' => 'Hours: 9:00 AM - 9:00 PM',
                'human_followup' => 'Feel free to ask about products anytime!',
                'initial_message' => 'Hello! 👋<br>I can help you with product inquiries, order status, shipping info, and more. How can I help you today?',
                'quick_check_shipping' => '🚚 Track Order',
                'quick_order_status' => '📦 Order Status',
                'quick_return' => '🔄 Returns & Exchanges',
                'quick_recommend' => '🎁 Recommended Products',
                'quick_human' => '📞 Contact Human',
            ),
            'zh' => array(
                'widget_title' => '在线客服',
                'status_online' => '在线',
                'toggle_text' => '在线客服',
                'input_placeholder' => '输入您的问题...',
                'send_button' => '发送',
                'typing_text' => '正在输入...',
                'error_network' => '网络连接异常，请检查网络后重试。',
                'error_service' => '抱歉，服务暂时不可用，请稍后重试。',
                'error_empty' => '请输入您的问题。',
                'error_too_long' => '文本过长（最多4000字）。',
                'human_title' => '已为您转接人工客服',
                'human_phone' => '客服电话：400-XXX-XXXX',
                'human_online' => '在线客服：点击下方「联系客服」按钮',
                'human_hours' => '工作时间：9:00 - 21:00',
                'human_followup' => '您也可以继续咨询商品信息，我随时为您服务！',
                'initial_message' => '您好！👋<br>可以帮您查询商品、订单状态、物流信息等，请问有什么可以帮您？',
                'quick_check_shipping' => '🚚 查物流',
                'quick_order_status' => '📦 订单状态',
                'quick_return' => '🔄 退换货',
                'quick_recommend' => '🎁 推荐商品',
                'quick_human' => '📞 联系人工',
            ),
            'ja' => array(
                'widget_title' => 'カスタマーサービス',
                'status_online' => 'オンライン',
                'toggle_text' => 'サポート',
                'input_placeholder' => 'ご質問を入力...',
                'send_button' => '送信',
                'typing_text' => '入力中...',
                'error_network' => 'ネットワークエラー。接続を確認して再度お試しください。',
                'error_service' => '申し訳ありません、サービスが一時的に利用できません。後ほど再試行してください。',
                'error_empty' => 'ご質問を入力してください。',
                'error_too_long' => 'テキストが長すぎます（最大4000文字）。',
                'human_title' => '有人カスタマーサービスにお繋ぎしました',
                'human_phone' => '電話：400-XXX-XXXX',
                'human_online' => 'オンラインチャット：下の「サポート連絡」ボタンをクリック',
                'human_hours' => '営業時間：9:00 - 21:00',
                'human_followup' => '商品について引き続きご質問いただけます！',
                'initial_message' => 'こんにちは！👋<br>商品のお問い合わせ、注文状況、配送情報などをお手伝いできます。何かお手伝いできることはありますか？',
                'quick_check_shipping' => '🚚 配送追跡',
                'quick_order_status' => '📦 注文状況',
                'quick_return' => '🔄 返品・交換',
                'quick_recommend' => '🎁 おすすめ商品',
                'quick_human' => '📞 有人サポート',
            ),
            'ko' => array(
                'widget_title' => '고객 서비스',
                'status_online' => '온라인',
                'toggle_text' => '고객센터',
                'input_placeholder' => '질문을 입력하세요...',
                'send_button' => '보내기',
                'typing_text' => '입력 중...',
                'error_network' => '네트워크 오류입니다. 연결을 확인한 후 다시 시도해 주세요.',
                'error_service' => '죄송합니다. 서비스가 일시적으로 사용할 수 없습니다. 나중에 다시 시도해 주세요.',
                'error_empty' => '질문을 입력해 주세요.',
                'error_too_long' => '텍스트가 너무 깁니다 (최대 4000자).',
                'human_title' => '인간 고객 서비스로 연결되었습니다',
                'human_phone' => '전화: 400-XXX-XXXX',
                'human_online' => '온라인 채팅: 아래 [고객센터] 버튼 클릭',
                'human_hours' => '운영시간: 9:00 - 21:00',
                'human_followup' => '상품에 대해 계속 질문하실 수 있습니다!',
                'initial_message' => '안녕하세요!👋<br>상품 문의, 주문 상태, 배송 정보 등을 도와드릴 수 있습니다. 무엇을 도와드릴까요?',
                'quick_check_shipping' => '🚚 배송 추적',
                'quick_order_status' => '📦 주문 상태',
                'quick_return' => '🔄 반품/교환',
                'quick_recommend' => '🎁 추천 상품',
                'quick_human' => '📞 상담원 연결',
            ),
            'es' => array(
                'widget_title' => 'Atención al Cliente',
                'status_online' => 'En línea',
                'toggle_text' => 'Soporte',
                'input_placeholder' => 'Escribe tu pregunta...',
                'send_button' => 'Enviar',
                'typing_text' => 'Escribiendo...',
                'error_network' => 'Error de red. Revisa tu conexión e inténtalo de nuevo.',
                'error_service' => 'Lo sentimos, el servicio está temporalmente no disponible. Inténtalo de nuevo más tarde.',
                'error_empty' => 'Por favor, introduce tu pregunta.',
                'error_too_long' => 'Texto demasiado largo (máximo 4000 caracteres).',
                'human_title' => 'Transferido a atención humana',
                'human_phone' => 'Teléfono: 400-XXX-XXXX',
                'human_online' => 'Chat en línea: Haz clic en el botón [Contactar] abajo',
                'human_hours' => 'Horario: 9:00 - 21:00',
                'human_followup' => '¡Puedes seguir consultando sobre productos en cualquier momento!',
                'initial_message' => '¡Hola! 👋<br>Puedo ayudarte con consultas de productos, estado de pedidos, información de envío y más. ¿En qué puedo ayudarte?',
                'quick_check_shipping' => '🚚 Envío',
                'quick_order_status' => '📦 Estado del pedido',
                'quick_return' => '🔄 Devoluciones',
                'quick_recommend' => '🎁 Productos recomendados',
                'quick_human' => '📞 Contactar humano',
            ),
            'fr' => array(
                'widget_title' => 'Service Client',
                'status_online' => 'En ligne',
                'toggle_text' => 'Support',
                'input_placeholder' => 'Tapez votre question...',
                'send_button' => 'Envoyer',
                'typing_text' => 'En train d\'écrire...',
                'error_network' => 'Erreur réseau. Veuillez vérifier votre connexion et réessayer.',
                'error_service' => 'Désolé, le service est temporairement indisponible. Veuillez réessayer plus tard.',
                'error_empty' => 'Veuillez entrer votre question.',
                'error_too_long' => 'Texte trop long (4000 caractères max).',
                'human_title' => 'Transféré au service client humain',
                'human_phone' => 'Téléphone : 400-XXX-XXXX',
                'human_online' => 'Chat en ligne : Cliquez sur le bouton [Contacter] ci-dessous',
                'human_hours' => 'Horaires : 9h00 - 21h00',
                'human_followup' => 'Vous pouvez continuer à poser des questions sur les produits à tout moment !',
                'initial_message' => 'Bonjour ! 👋<br>Je peux vous aider pour les demandes de produits, le suivi de commande, les informations d\'expédition et plus encore. Comment puis-je vous aider ?',
                'quick_check_shipping' => '🚚 Suivi expédition',
                'quick_order_status' => '📦 Statut commande',
                'quick_return' => '🔄 Retours',
                'quick_recommend' => '🎁 Produits recommandés',
                'quick_human' => '📞 Contacter humain',
            ),
            'de' => array(
                'widget_title' => 'Kundenservice',
                'status_online' => 'Online',
                'toggle_text' => 'Support',
                'input_placeholder' => 'Geben Sie Ihre Frage ein...',
                'send_button' => 'Senden',
                'typing_text' => 'Schreibt...',
                'error_network' => 'Netzwerkfehler. Bitte überprüfen Sie Ihre Verbindung und versuchen Sie es erneut.',
                'error_service' => 'Entschuldigung, der Dienst ist vorübergehend nicht verfügbar. Bitte versuchen Sie es später erneut.',
                'error_empty' => 'Bitte geben Sie Ihre Frage ein.',
                'error_too_long' => 'Text zu lang (maximal 4000 Zeichen).',
                'human_title' => 'An menschlichen Kundenservice weitergeleitet',
                'human_phone' => 'Telefon: 400-XXX-XXXX',
                'human_online' => 'Online-Chat: Klicken Sie auf die Schaltfläche [Kontakt] unten',
                'human_hours' => 'Öffnungszeiten: 9:00 - 21:00',
                'human_followup' => 'Sie können jederzeit nach Produkten fragen!',
                'initial_message' => 'Hallo! 👋<br>Ich kann Ihnen bei Produktanfragen, Bestellstatus, Versandinformationen und mehr helfen. Wie kann ich Ihnen helfen?',
                'quick_check_shipping' => '🚚 Versand',
                'quick_order_status' => '📦 Bestellstatus',
                'quick_return' => '🔄 Rücksendungen',
                'quick_recommend' => '🎁 Empfohlene Produkte',
                'quick_human' => '📞 Menschlicher Kontakt',
            ),
            'ru' => array(
                'widget_title' => 'Служба поддержки',
                'status_online' => 'Онлайн',
                'toggle_text' => 'Поддержка',
                'input_placeholder' => 'Введите ваш вопрос...',
                'send_button' => 'Отправить',
                'typing_text' => 'Печатает...',
                'error_network' => 'Ошибка сети. Проверьте подключение и повторите попытку.',
                'error_service' => 'Извините, сервис временно недоступен. Повторите попытку позже.',
                'error_empty' => 'Пожалуйста, введите ваш вопрос.',
                'error_too_long' => 'Слишком длинный текст (макс. 4000 символов).',
                'human_title' => 'Перевод на оператора',
                'human_phone' => 'Телефон: 400-XXX-XXXX',
                'human_online' => 'Онлайн-чат: нажмите кнопку [Связаться] ниже',
                'human_hours' => 'Часы работы: 9:00 - 21:00',
                'human_followup' => 'Вы можете продолжить задавать вопросы о товарах в любое время!',
                'initial_message' => 'Здравствуйте! 👋<br>Я могу помочь с вопросами о товарах, статусе заказа, информацией о доставке и многом другом. Чем я могу помочь?',
                'quick_check_shipping' => '🚚 Отследить заказ',
                'quick_order_status' => '📦 Статус заказа',
                'quick_return' => '🔄 Возврат и обмен',
                'quick_recommend' => '🎁 Рекомендуемые товары',
                'quick_human' => '📞 Связаться с оператором',
            ),
        );

        if (!isset($strings[$language])) {
            $language = 'en';
        }

        return $strings[$language];
    }

    /**
     * 获取初始欢迎消息 (HTML)
     */
    public function get_initial_message($language) {
        $strings = $this->get_ui_strings($language);
        return $strings['initial_message'];
    }

    /**
     * 获取快捷回复按钮
     */
    public function get_quick_replies($language) {
        $strings = $this->get_ui_strings($language);
        return array(
            $strings['quick_check_shipping'],
            $strings['quick_order_status'],
            $strings['quick_return'],
            $strings['quick_recommend'],
            $strings['quick_human'],
        );
    }

    /**
     * 获取人工客服消息
     */
    public function get_human_message($language) {
        $strings = $this->get_ui_strings($language);
        return "🎉 " . $strings['human_title'] . "\n\n"
             . $strings['human_phone'] . "\n"
             . $strings['human_online'] . "\n"
             . $strings['human_hours'] . "\n\n"
             . "💡 " . $strings['human_followup'];
    }

    /**
     * 获取语言的本地名称
     */
    public function get_language_name($language) {
        $names = array(
            'en' => 'English',
            'zh' => '中文',
            'ja' => '日本語',
            'ko' => '한국어',
            'es' => 'Español',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'ru' => 'Русский',
        );
        return isset($names[$language]) ? $names[$language] : 'English';
    }

    /**
     * 获取 Woo 上下文的标准回复（硬拦截，不经过 AI，避免幻觉）
     * 当 RAG 返回明确的权限拒绝、空订单等确定状态时，直接返回标准话术
     *
     * @param string $context Woo 上下文原文（含 【Order Information/订单信息】标记）
     * @param string $language 检测到的语言
     * @return string|null 返回标准消息则直接回复给用户，返回 null 则继续走 AI API
     */
    public function get_woo_context_standard_reply($context, $language) {
        if (empty($context)) {
            return null;
        }

        // 多语言拒绝/空消息匹配
        $reject_patterns = array(
            // 越权访问（查询他人订单）
            'only_view_own' => array(
                'en' => 'You can only view your own orders',
                'zh' => '您只能查询自己的订单',
                'ja' => 'ご自身の注文のみ閲覧いただけます',
                'ko' => '자신의 주문만 조회할 수 있습니다',
                'es' => 'Solo puede ver sus propios pedidos',
                'fr' => 'Vous ne pouvez consulter que vos propres commandes',
                'de' => 'Sie können nur Ihre eigenen Bestellungen einsehen',
                'ru' => 'Вы можете просматривать только свои собственные заказы',
            ),
            // 未登录
            'need_login_order' => array(
                'en' => 'need to log in to view your orders',
                'zh' => '先登录才能查看订单',
                'ja' => '注文を表示するにはログインが必要です',
                'ko' => '주문을 보려면 로그인이 필요합니다',
                'es' => 'debe iniciar sesión para ver sus pedidos',
                'fr' => 'devez vous connecter pour voir vos commandes',
                'de' => 'müssen sich anmelden, um Ihre Bestellungen anzuzeigen',
                'ru' => 'Чтобы просматривать заказы, нужно войти в систему',
            ),
            'need_login_detail' => array(
                'en' => 'To view order details, please log in first',
                'zh' => '查看订单需要先登录',
            ),
            // 无订单记录
            'no_orders' => array(
                // ====== English (primary) ======
                'en1' => 'You have no orders yet',
                'en2' => 'no orders found',
                'en3' => "don't have any orders yet",
                'en4' => 'has no orders',
                'en5' => 'no orders in the database',
                // ====== Chinese ======
                'zh1' => '您还没有订单记录',
                'zh2' => '没有订单',
                'zh3' => '未找到订单',
                'zh4' => '无订单记录',
                'zh5' => '没订单',
                // ====== Japanese ======
                'ja1' => 'まだ注文記録がありません',
                'ja2' => '注文が見つかりません',
                'ja3' => '注文はありません',
                'ja4' => '見つかりませんでした',
                // ====== Korean ======
                'ko1' => '아직 주문 기록이 없습니다',
                'ko2' => '주문이 없습니다',
                'ko3' => '주문을 찾을 수 없습니다',
                // ====== Spanish ======
                'es1' => 'Aún no tiene pedidos',
                'es2' => 'no se encontraron pedidos',
                'es3' => 'No tienes pedidos',
                // ====== French ======
                'fr1' => 'Vous n\'avez pas encore de commandes',
                'fr2' => 'aucune commande trouvée',
                'fr3' => 'pas de commandes',
                // ====== German ======
                'de1' => 'Sie haben noch keine Bestellungen',
                'de2' => 'keine Bestellungen gefunden',
                'de3' => 'keine Bestellungen',
                // ====== Russian ======
                'ru1' => 'У вас пока нет заказов',
                'ru2' => 'заказов не найдено',
                'ru3' => 'нет заказов',
            ),
        );

        // 标准回复模板（8 种语言）
        $reply_templates = array(
            'zh' => array(
                'only_view_own' => '抱歉，您只能查询自己名下的订单，无法访问其他客户的订单信息。请确认订单号是否正确，或登录后查询"我的订单"列表查看您的所有订单。如果您是管理员，请使用后台账号登录。',
                'need_login'    => '查看订单信息需要先登录账号。请登录后再尝试查询订单、物流和购买记录。如果您还没有账号，可以先注册后再下单。',
                'no_orders'     => '您目前还没有订单记录。可以在商城挑选喜欢的商品下单，下单后随时在这里查询订单状态和物流信息哦～',
            ),
            'en' => array(
                'only_view_own' => 'Sorry, you can only view orders placed under your own account. Please double-check the order number or visit "My Orders" after logging in to see all of your purchases. If you are an administrator, please log in with your admin credentials.',
                'need_login'    => 'You need to log in to view order details, purchase history, and tracking information. Please sign in first, or register an account if you haven\'t already done so.',
                'no_orders'     => 'You don\'t have any orders yet. Feel free to browse our catalog and place an order — once you do, you can check the order status and shipping updates right here at any time.',
            ),
            'ja' => array(
                'only_view_own' => '申し訳ございませんが、ご自身のアカウントでご注文いただいた内容のみ閲覧いただけます。注文番号をご確認のうえ、ログイン後「マイオーダー」からご自身の注文一覧をご覧ください。管理者の方は管理者アカウントでログインしてください。',
                'need_login'    => '注文内容や配送情報をご確認いただくにはログインが必要です。まずはログインをお願いいたします。アカウントをお持ちでない場合は新規会員登録後にご注文ください。',
                'no_orders'     => '現在、注文履歴はまだございません。商品をお選びのうえご注文いただくと、こちらで注文状況や配送情報を随時ご確認いただけます。',
            ),
            'ko' => array(
                'only_view_own' => '죄송하지만 본인 계정으로 주문하신 내역만 확인하실 수 있습니다. 주문번호를 다시 확인하시거나 로그인 후 "내 주문" 메뉴에서 전체 주문 내역을 조회해 주세요. 관리자이신 경우 관리자 계정으로 로그인하시기 바랍니다.',
                'need_login'    => '주문 내역과 배송 정보를 확인하시려면 로그인이 필요합니다. 먼저 로그인해 주세요. 아직 계정이 없으시면 회원가입 후 주문하실 수 있습니다.',
                'no_orders'     => '아직 주문 내역이 없습니다. 상품을 둘러보시고 주문하시면, 여기에서 언제든지 주문 상태와 배송 정보를 확인하실 수 있어요.',
            ),
            'es' => array(
                'only_view_own' => 'Lo sentimos, solo puedes consultar los pedidos realizados con tu propia cuenta. Por favor, verifica el número de pedido o accede a "Mis Pedidos" después de iniciar sesión para ver todas tus compras. Si eres administrador, inicia sesión con tus credenciales de administrador.',
                'need_login'    => 'Para ver el detalle de los pedidos, el historial de compras y la información de envío necesitas iniciar sesión primero. Por favor, accede a tu cuenta o regístrate si aún no lo has hecho.',
                'no_orders'     => 'Todavía no tienes pedidos. Puedes explorar nuestro catálogo y realizar un pedido cuando quieras; después, podrás consultar el estado del pedido y la información de envío aquí mismo en cualquier momento.',
            ),
            'fr' => array(
                'only_view_own' => 'Désolé, vous ne pouvez consulter que les commandes passées avec votre propre compte. Veuillez vérifier le numéro de commande ou accéder à "Mes Commandes" après connexion pour voir tous vos achats. Si vous êtes administrateur, connectez-vous avec vos identifiants d\'administrateur.',
                'need_login'    => 'Vous devez être connecté pour consulter le détail des commandes, l\'historique des achats et les informations d\'expédition. Veuillez vous connecter d\'abord, ou créer un compte si vous n\'en avez pas encore.',
                'no_orders'     => 'Vous n\'avez pas encore de commandes. N\'hésitez pas à parcourir notre catalogue et à passer commande : vous pourrez ensuite suivre l\'état de votre commande et les informations d\'expédition directement ici, à tout moment.',
            ),
            'de' => array(
                'only_view_own' => 'Entschuldigung, Sie können nur Bestellungen einsehen, die mit Ihrem eigenen Konto getätigt wurden. Bitte prüfen Sie die Bestellnummer oder besuchen Sie nach dem Login "Meine Bestellungen", um alle Ihre Einkäufe einzusehen. Wenn Sie Administrator sind, melden Sie sich bitte mit Ihren Admin-Zugangsdaten an.',
                'need_login'    => 'Um Bestelldetails, Einkaufshistorie und Versandinformationen anzuzeigen, müssen Sie sich zuerst anmelden. Bitte melden Sie sich an oder registrieren Sie sich, falls Sie noch kein Konto haben.',
                'no_orders'     => 'Sie haben noch keine Bestellungen. Schauen Sie sich gerne in unserem Katalog um und tätigen Sie eine Bestellung — danach können Sie hier jederzeit den Bestellstatus und Versandupdates einsehen.',
            ),
            'ru' => array(
                'only_view_own' => 'Извините, вы можете просматривать только заказы, оформленные в вашей учётной записи. Пожалуйста, проверьте номер заказа или после входа перейдите в раздел «Мои заказы», чтобы увидеть все свои покупки. Если вы администратор, войдите с учётными данными администратора.',
                'need_login'    => 'Чтобы просматривать детали заказов, историю покупок и информацию о доставке, сначала нужно войти в аккаунт. Пожалуйста, авторизуйтесь или зарегистрируйтесь, если у вас ещё нет учётной записи.',
                'no_orders'     => 'У вас пока нет заказов. Ознакомьтесь с нашим каталогом и оформите заказ — после этого вы сможете в любое время проверять статус заказа и информацию о доставке прямо здесь.',
            ),
        );

        $lang = isset($reply_templates[$language]) ? $language : 'en';

        // 越权访问判断（优先级最高）
        foreach (array('only_view_own') as $key) {
            foreach ($reject_patterns[$key] as $plang => $needle) {
                if (stripos($context, $needle) !== false) {
                    return $reply_templates[$lang]['only_view_own'];
                }
            }
        }
        // 未登录判断
        foreach (array('need_login_order', 'need_login_detail') as $key) {
            if (isset($reject_patterns[$key])) {
                foreach ($reject_patterns[$key] as $plang => $needle) {
                    if (stripos($context, $needle) !== false) {
                        return $reply_templates[$lang]['need_login'];
                    }
                }
            }
        }
        // 无订单记录
        foreach ($reject_patterns['no_orders'] as $plang => $needle) {
            if (stripos($context, $needle) !== false) {
                return $reply_templates[$lang]['no_orders'];
            }
        }

        return null;
    }

    /**
     * 第1.5层防御：把 RAG 中解析出的 "- Order #NNN | Status | Total | Items"
     * 真实数据，按用户语言格式化成人类客服风格的 HTML 回复（不调用 AI）。
     *
     * 设计动机：
     *   - 即使 RAG 注入了真实订单，LLM 仍有"把有订单说成没订单"的反向幻觉。
     *   - 对订单这类高敏感信息使用"确定性格式化输出"，保真度 = 100%。
     *
     * @param array   $matches   PREG_SET_ORDER 结果：[ [0, id, status, total, items], ... ]
     * @param string  $language  用户语言
     * @return string|null       格式化后的 HTML 回复；输入不合法返回 null
     */
    public function format_orders_as_reply($matches, $language) {
        if (!is_array($matches) || empty($matches)) {
            return null;
        }

        // 8 种语言模板
        $headers = array(
            'zh' => array(
                'single' => '您好！已经为您查询到最近的订单信息：',
                'multi'  => '您好！已经为您查询到最近 %d 条订单信息：',
                'id'     => '订单号',
                'status' => '状态',
                'total'  => '金额',
                'items'  => '商品明细',
                'ask'    => '如需进一步了解付款方式、发货安排或退换货政策，随时告诉我哦～',
            ),
            'en' => array(
                'single' => 'Hi, I\'ve located the most recent order for you:',
                'multi'  => 'Hi, I\'ve located your %d most recent orders:',
                'id'     => 'Order',
                'status' => 'Status',
                'total'  => 'Total',
                'items'  => 'Items',
                'ask'    => 'Feel free to ask if you need details on payment, shipping schedule, or returns & exchanges.',
            ),
            'ja' => array(
                'single' => 'お客様、直近のご注文を確認いたしました：',
                'multi'  => 'お客様、直近 %d 件のご注文を確認いたしました：',
                'id'     => '注文No.',
                'status' => 'ステータス',
                'total'  => '合計',
                'items'  => '商品',
                'ask'    => 'お支払い方法、発送予定、返品・交換ポリシーなど、詳しく知りたい場合はいつでもお問い合わせください。',
            ),
            'ko' => array(
                'single' => '고객님, 가장 최근 주문 내역을 확인했습니다：',
                'multi'  => '고객님, 최근 %d건의 주문 내역을 확인했습니다：',
                'id'     => '주문번호',
                'status' => '상태',
                'total'  => '총액',
                'items'  => '상품',
                'ask'    => '결제방법, 발송일정, 반품/교환 정책 등 더 궁금한 점이 있으시면 언제든지 문의해 주세요.',
            ),
            'es' => array(
                'single' => 'Hola, ya localicé tu pedido más reciente:',
                'multi'  => 'Hola, ya localicé tus %d pedidos más recientes:',
                'id'     => 'Pedido',
                'status' => 'Estado',
                'total'  => 'Total',
                'items'  => 'Artículos',
                'ask'    => 'Si necesitas información sobre pago, envío o devoluciones, pregúntame en cualquier momento.',
            ),
            'fr' => array(
                'single' => 'Bonjour, j\'ai retrouvé votre commande la plus récente :',
                'multi'  => 'Bonjour, j\'ai retrouvé vos %d commandes les plus récentes :',
                'id'     => 'Commande',
                'status' => 'Statut',
                'total'  => 'Total',
                'items'  => 'Articles',
                'ask'    => 'N\'hésitez pas si vous avez besoin de détails sur le paiement, la livraison ou nos retours & échanges.',
            ),
            'de' => array(
                'single' => 'Hallo, ich habe Ihre aktuellste Bestellung gefunden:',
                'multi'  => 'Hallo, ich habe Ihre %d aktuellsten Bestellungen gefunden:',
                'id'     => 'Bestellung',
                'status' => 'Status',
                'total'  => 'Gesamt',
                'items'  => 'Artikel',
                'ask'    => 'Wenn Sie Details zu Zahlung, Versand oder Rücksendung / Umtausch benötigen, stehe ich gerne zur Verfügung.',
            ),
            'ru' => array(
                'single' => 'Здравствуйте! Я нашёл ваш последний заказ：',
                'multi'  => 'Здравствуйте! Я нашёл ваши последние %d заказов：',
                'id'     => 'Заказ',
                'status' => 'Статус',
                'total'  => 'Сумма',
                'items'  => 'Товары',
                'ask'    => 'Если нужны подробности об оплате, сроках отправки, возврате или обмене — обращайтесь в любое время.',
            ),
        );

        $lang = isset($headers[$language]) ? $language : 'en';
        $h = $headers[$lang];

        // 自实现安全 HTML 转义：不依赖 WordPress 函数（避免 CLI / eval 场景 fatally die）
        $e = function($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $count = count($matches);
        $out  = '';
        $out .= $count === 1 ? $h['single'] : sprintf($h['multi'], $count);
        $out .= '<br>';

        foreach ($matches as $m) {
            // $m = [ full, id, status, total, items ]
            $id    = isset($m[1]) ? trim($m[1]) : '';
            $stat  = isset($m[2]) ? trim($m[2]) : '';
            $tot   = isset($m[3]) ? trim($m[3]) : '';
            $items = isset($m[4]) ? trim($m[4]) : '';
            if ($id === '') continue;

            $out .= '<ul style="margin:6px 0 12px 18px; padding:0; line-height:1.7;">';
            $out .= sprintf('<li><strong>%s #%s</strong></li>', $e($h['id']), $e($id));
            $out .= sprintf('<li>%s：%s</li>',    $e($h['status']), $e($stat));
            $out .= sprintf('<li>%s：%s</li>',    $e($h['total']),  $e($tot));
            $out .= sprintf('<li>%s：%s</li>',    $e($h['items']),  $e($items));
            $out .= '</ul>';
        }

        $out .= '<br>' . $h['ask'];
        return $out;
    }

    /**
     * 获取前端 JS 所需的全部 i18n 数据
     */
    public function get_frontend_data($language) {
        $strings = $this->get_ui_strings($language);
        return array(
            'language' => $language,
            'languageName' => $this->get_language_name($language),
            'widgetTitle' => $strings['widget_title'],
            'statusOnline' => $strings['status_online'],
            'toggleText' => $strings['toggle_text'],
            'inputPlaceholder' => $strings['input_placeholder'],
            'sendButton' => $strings['send_button'],
            'errorNetwork' => $strings['error_network'],
            'errorService' => $strings['error_service'],
        );
    }
}
