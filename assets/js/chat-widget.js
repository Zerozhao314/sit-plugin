var WP_AICS = {
    chatHistory: [],
    isProcessing: false,
    isOpen: false,
    _idCounter: 0,
    currentLanguage: 'en',
    i18n: {},

    genId: function() {
        return 'wp-ai-cs-msg-' + (++this._idCounter) + '-' + Date.now();
    },

    /**
     * Detect user's language from browser
     */
    detectLanguage: function() {
        var lang = (navigator.languages && navigator.languages.length)
            ? navigator.languages[0]
            : navigator.language || 'en';
        var normalized = (lang || 'en').toLowerCase();
        var base = normalized.split('-')[0];
        var supported = ['en', 'zh', 'ja', 'ko', 'es', 'fr', 'de'];
        if (supported.indexOf(base) !== -1) {
            return base;
        }
        if (normalized.indexOf('zh') === 0) return 'zh';
        return 'en';
    },

    /**
     * Detect message language from text content
     * Uses Unicode range detection for CJK languages
     * For non-CJK messages, uses browser language or defaults to English
     */
    detectMessageLanguage: function(text) {
        if (!text) return this.currentLanguage;
        // Japanese kana detection
        if (/[\u3040-\u309f\u30a0-\u30ff]/.test(text)) return 'ja';
        // Korean detection
        if (/[\uac00-\ud7af\u1100-\u11ff]/.test(text)) return 'ko';
        // Chinese character detection
        if (/[\u4e00-\u9fff\u3400-\u4dbf]/.test(text)) return 'zh';
        // Spanish detection (¿, á, é, í, ó, ú, ñ, ü, ¡)
        if (/[¿¡ñáéíóúü]/.test(text)) return 'es';
        // French detection (ç, à, â, ê, î, ô, û, æ, œ)
        if (/[çàâêîôûæœ]/.test(text)) return 'fr';
        // German detection (ä, ö, ü, ß)
        if (/[äöüß]/.test(text)) return 'de';
        // No special chars detected → use browser language or default English
        var browserLang = this.detectLanguage();
        var cjkLanguages = ['zh', 'ja', 'ko'];
        if (cjkLanguages.indexOf(browserLang) === -1) {
            return browserLang;
        }
        // Browser is CJK but message is non-CJK → user switched to English
        return 'en';
    },

    init: function() {
        this.currentLanguage = 'en';
        this.i18n = wpAICs.i18n || {};
        this.isMobile = this.detectMobile();
        this.applyLocalization();
        this.attachEvents();
        this.initQuickReplyScroll();
    },

    detectMobile: function() {
        // 优先按视口宽度判断（断点与 CSS 的 768px 对齐），
        // 触屏特征仅作辅助：PC 触屏笔记本 (maxTouchPoints>0 但视口很宽) 不应被误判为移动端
        if (typeof window !== 'undefined' && window.innerWidth > 768) {
            return false;
        }
        return (window.innerWidth <= 768) ||
               ('ontouchstart' in window) ||
               (navigator.maxTouchPoints > 0);
    },

    applyLocalization: function() {
        var i18n = this.i18n;
        if (!i18n) return;

        // Update widget title
        var titleEl = document.querySelector('#wp-ai-cs-header .title');
        if (titleEl && i18n.widgetTitle) {
            titleEl.textContent = i18n.widgetTitle;
        }

        // Update status
        var statusEl = document.querySelector('#wp-ai-cs-header .status');
        if (statusEl && i18n.statusOnline) {
            var dotHtml = '<span class="dot"></span> ' + i18n.statusOnline;
            // Preserve the dot span
            var dot = statusEl.querySelector('.dot');
            statusEl.innerHTML = '';
            if (dot) statusEl.appendChild(dot);
            statusEl.appendChild(document.createTextNode(i18n.statusOnline));
        }

        // Update toggle text
        var toggleText = document.querySelector('#wp-ai-cs-toggle .toggle-text');
        if (toggleText && i18n.toggleText) {
            toggleText.textContent = i18n.toggleText;
        }

        // Update input placeholder
        var input = document.getElementById('wp-ai-cs-input');
        if (input && i18n.inputPlaceholder) {
            input.setAttribute('placeholder', i18n.inputPlaceholder);
        }

        // Update send button
        var sendBtn = document.querySelector('#wp-ai-cs-input-area button');
        if (sendBtn && i18n.sendButton) {
            sendBtn.textContent = i18n.sendButton;
        }
    },

    attachEvents: function() {
        var bindInput = function() {
            var input = document.getElementById('wp-ai-cs-input');
            if (input) {
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        WP_AICS.sendMessage();
                    }
                });
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindInput);
        } else {
            bindInput();
        }
    },

    initQuickReplyScroll: function() {
        var container = document.getElementById('wp-ai-cs-quick-replies');
        if (!container) return;

        // Skip hover-scroll on touch devices (use native scroll instead)
        if (this.isMobile) {
            container.style.scrollSnapType = 'x proximity';
            return;
        }

        // Only init if visible
        if (container.offsetWidth === 0) {
            var self = this;
            var observer = new MutationObserver(function(mutations) {
                if (container.offsetWidth > 0) {
                    observer.disconnect();
                    self.initQuickReplyScroll();
                }
            });
            observer.observe(container, { attributes: true, childList: true, subtree: true });
            return;
        }

        if (container._scrollInitialized) return;
        container._scrollInitialized = true;

        var self = this;
        var scrollRaf = null;
        var hoverSide = null;
        var scrollSpeed = 6;

        var updateEdgeIndicators = function() {
            if (!container.isConnected) return;
            var hasOverflow = container.scrollWidth > container.clientWidth;
            if (!hasOverflow) {
                container.classList.remove('has-left-edge', 'has-right-edge');
                return;
            }
            container.classList.toggle('has-left-edge', container.scrollLeft > 2);
            container.classList.toggle('has-right-edge', container.scrollLeft < container.scrollWidth - container.clientWidth - 2);
        };

        var animateScroll = function() {
            if (hoverSide === 'left') {
                container.scrollLeft = Math.max(0, container.scrollLeft - scrollSpeed);
            } else if (hoverSide === 'right') {
                container.scrollLeft = Math.min(container.scrollWidth - container.clientWidth, container.scrollLeft + scrollSpeed);
            }
            updateEdgeIndicators();
            if (hoverSide) {
                scrollRaf = requestAnimationFrame(animateScroll);
            }
        };

        var onMouseMove = function(e) {
            var rect = container.getBoundingClientRect();
            var x = e.clientX - rect.left;
            var width = rect.width;
            var edgeZone = Math.min(40, width * 0.15);

            if (x <= edgeZone && container.scrollLeft > 0) {
                hoverSide = 'left';
            } else if (x >= width - edgeZone && container.scrollLeft < container.scrollWidth - container.clientWidth) {
                hoverSide = 'right';
            } else {
                hoverSide = null;
            }

            if (hoverSide && !scrollRaf) {
                scrollRaf = requestAnimationFrame(animateScroll);
            } else if (!hoverSide && scrollRaf) {
                cancelAnimationFrame(scrollRaf);
                scrollRaf = null;
            }
        };

        var onMouseLeave = function() {
            hoverSide = null;
            if (scrollRaf) {
                cancelAnimationFrame(scrollRaf);
                scrollRaf = null;
            }
        };

        container.addEventListener('mousemove', onMouseMove);
        container.addEventListener('mouseleave', onMouseLeave);
        container.addEventListener('scroll', updateEdgeIndicators);
        window.addEventListener('resize', updateEdgeIndicators);

        // 鼠标滚轮：在 quick-replies 区域内将垂直滚轮转为水平滚动
        var onWheel = function(e) {
            var hasOverflow = container.scrollWidth > container.clientWidth;
            if (!hasOverflow) return;
            e.preventDefault();
            var delta = e.deltaY || e.deltaX;
            container.scrollLeft += delta;
            updateEdgeIndicators();
        };
        container.addEventListener('wheel', onWheel, { passive: false });

        updateEdgeIndicators();
    },

    toggleChat: function() {
        var box = document.getElementById('wp-ai-cs-box');
        var toggle = document.getElementById('wp-ai-cs-toggle');
        var isHidden = box.style.display === 'none' || box.style.display === '';

        if (isHidden) {
            box.style.display = 'flex';
            toggle.style.display = 'none';
            document.getElementById('wp-ai-cs-badge').style.display = 'none';
            // Lock body scroll on mobile to prevent background scrolling
            if (this.isMobile) {
                document.body.style.overflow = 'hidden';
                document.body.style.position = 'fixed';
                document.body.style.width = '100%';
                document.body.style.top = '-' + window.scrollY + 'px';
            }
            var self = this;
            setTimeout(function() {
                var input = document.getElementById('wp-ai-cs-input');
                if (input) input.focus();
                self.initQuickReplyScroll();
            }, 300);
            this.isOpen = true;
        } else {
            box.style.display = 'none';
            toggle.style.display = 'flex';
            // Restore body scroll on mobile
            if (this.isMobile) {
                var scrollY = document.body.style.top;
                document.body.style.overflow = '';
                document.body.style.position = '';
                document.body.style.width = '';
                document.body.style.top = '';
                if (scrollY) {
                    window.scrollTo(0, parseInt(scrollY || '0') * -1);
                }
            }
            this.isOpen = false;
        }
    },

    quickReply: function(text) {
        var input = document.getElementById('wp-ai-cs-input');
        if (input) {
            input.value = text;
            this.sendMessage();
        }
    },

    sendMessage: function() {
        if (this.isProcessing) return;

        var input = document.getElementById('wp-ai-cs-input');
        var text = input.value.trim();
        if (!text) return;

        this.isProcessing = true;

        this.appendMessage(text, 'user');
        this.chatHistory.push({ role: 'user', content: text });
        input.value = '';

        var typingId = this.showTyping();

        // Detect message language and update current language if needed
        var msgLang = this.detectMessageLanguage(text);
        if (msgLang !== this.currentLanguage) {
            this.currentLanguage = msgLang;
            this.applyLocalization();
        }

        var self = this;
        var data = new FormData();
        data.append('action', 'ai_chat_request');
        data.append('nonce', wpAICs.nonce);
        data.append('text', text);
        data.append('history', JSON.stringify(this.chatHistory.slice(-10)));
        data.append('language', this.currentLanguage);
        data.append('browser_language', this.detectLanguage());
        data.append('message_language', msgLang);

        fetch(wpAICs.ajaxUrl, {
            method: 'POST',
            body: data
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            self.removeTyping(typingId);
            if (data.success && data.data && data.data.ok) {
                self.appendMessage(data.data.content, 'bot');
                self.chatHistory.push({ role: 'assistant', content: data.data.content });

                // If server detected a different language, update
                if (data.data.detected_language && data.data.detected_language !== self.currentLanguage) {
                    self.currentLanguage = data.data.detected_language;
                    self.applyLocalization();
                }
            } else {
                var errMsg = (self.i18n && self.i18n.errorService)
                    ? self.i18n.errorService
                    : '😔 Sorry, the service is temporarily unavailable. Please try again later.';
                self.appendMessage(errMsg, 'bot');
                console.error('API Error:', data.data ? data.data.error : 'Unknown');
            }
            self.isProcessing = false;
        })
        .catch(function(error) {
            self.removeTyping(typingId);
            var errMsg = (self.i18n && self.i18n.errorNetwork)
                ? self.i18n.errorNetwork
                : '⚠️ Network error. Please check your connection and try again.';
            self.appendMessage(errMsg, 'bot');
            console.error('Network Error:', error);
            self.isProcessing = false;
        });
    },

    appendMessage: function(text, sender) {
        var messages = document.getElementById('wp-ai-cs-messages');
        var div = document.createElement('div');
        div.className = 'msg ' + sender;
        div.id = this.genId();

        // 检测 bot 消息是否包含 HTML 标签或 markdown 链接
        var hasHtmlTags = /<[a-z][\s\S]*?>/i.test(text);
        var hasMarkdownLinks = /\[[^\]]+\]\(https?:\/\/[^\s)]+\)/.test(text);

        if ((hasHtmlTags || hasMarkdownLinks) && sender === 'bot') {
            // 先将 markdown 链接 [text](url) 转换为 HTML <a> 标签
            var htmlText = this.markdownLinksToHtml(text);
            // 将换行符转换为 <br>
            htmlText = htmlText.replace(/\n/g, '<br>');
            // 安全渲染 HTML：使用白名单过滤，防止 XSS
            div.innerHTML = this.sanitizeHtml(htmlText);
        } else {
            // 纯文本消息：使用 createTextNode 防止 XSS，并支持 **bold** markdown
            var lines = text.split('\n');
            for (var i = 0; i < lines.length; i++) {
                if (i > 0) {
                    div.appendChild(document.createElement('br'));
                }
                var parts = lines[i].split(/(\*\*[^*]+\*\*)/g);
                for (var j = 0; j < parts.length; j++) {
                    var part = parts[j];
                    if (!part) continue;
                    if (part.indexOf('**') === 0 && part.lastIndexOf('**') === part.length - 2) {
                        var strong = document.createElement('strong');
                        strong.textContent = part.slice(2, -2);
                        div.appendChild(strong);
                    } else {
                        div.appendChild(document.createTextNode(part));
                    }
                }
            }
        }

        var time = document.createElement('span');
        time.className = 'time';
        time.textContent = this.getCurrentTime();
        div.appendChild(time);

        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;

        return div.id;
    },

    /**
     * 将 markdown 链接 [text](url) 转换为 HTML <a> 标签
     */
    markdownLinksToHtml: function(text) {
        // 匹配 [text](url) 格式，url 必须以 http 开头
        return text.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, function(match, linkText, url) {
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + linkText + '</a>';
        });
    },

    /**
     * HTML 白名单过滤：只允许安全的标签和属性，防止 XSS
     * 用于渲染后端返回的格式化 HTML（如订单信息列表）
     */
    sanitizeHtml: function(html) {
        var allowedTags = ['br', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'p', 'span', 'a'];
        var allowedAttrs = ['style', 'href', 'target', 'rel'];

        var temp = document.createElement('div');
        temp.innerHTML = html;

        var clean = function(node) {
            var children = node.childNodes;
            for (var i = children.length - 1; i >= 0; i--) {
                var child = children[i];
                if (child.nodeType === 1) {
                    var tag = child.tagName.toLowerCase();
                    if (allowedTags.indexOf(tag) === -1) {
                        // 不在白名单的标签：用纯文本替换（保留内容）
                        var textNode = document.createTextNode(child.textContent);
                        node.replaceChild(textNode, child);
                    } else {
                        // 清理非白名单属性
                        var attrs = child.attributes;
                        for (var j = attrs.length - 1; j >= 0; j--) {
                            if (allowedAttrs.indexOf(attrs[j].name) === -1) {
                                child.removeAttribute(attrs[j].name);
                            }
                        }
                        // 清理 style 中的危险内容
                        if (child.getAttribute('style')) {
                            var style = child.getAttribute('style');
                            if (/javascript:|expression\s*\(|@import|url\s*\(/i.test(style)) {
                                child.removeAttribute('style');
                            }
                        }
                        // 清理 href 中的危险协议（仅允许 http/https）
                        if (child.getAttribute('href')) {
                            var href = child.getAttribute('href');
                            if (!/^https?:\/\//i.test(href)) {
                                child.removeAttribute('href');
                            }
                        }
                        clean(child);
                    }
                }
            }
        };
        clean(temp);
        return temp.innerHTML;
    },

    showTyping: function() {
        var messages = document.getElementById('wp-ai-cs-messages');
        var div = document.createElement('div');
        div.className = 'msg bot';
        div.id = this.genId();

        var typing = document.createElement('div');
        typing.className = 'wp-ai-cs-typing';
        for (var i = 0; i < 3; i++) {
            typing.appendChild(document.createElement('span'));
        }
        div.appendChild(typing);

        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div.id;
    },

    removeTyping: function(id) {
        var el = document.getElementById(id);
        if (el) el.remove();
    },

    getCurrentTime: function() {
        var now = new Date();
        return String(now.getHours()).padStart(2, '0') + ':' +
               String(now.getMinutes()).padStart(2, '0');
    }
};

WP_AICS.init();

function wp_ai_cs_show_unread() {
    var badge = document.getElementById('wp-ai-cs-badge');
    if (badge && !WP_AICS.isOpen) {
        badge.style.display = 'flex';
        var count = parseInt(badge.textContent) || 0;
        badge.textContent = count + 1;
    }
}
