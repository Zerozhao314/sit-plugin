# 本地开发工作流

> wp-ai-customer-service 插件日常开发完整流程,包含 Git 提交规范与 Hook 触发机制。
> 最后更新:2026-08-16

---

## 1. 工作流总览

```
┌─────────────────────────────────────────────────────────────┐
│  Windows IDE (d:\project\wp-ai-customer-service)            │
│  ① 改代码                                                    │
│  ② git add . && git commit                                  │
└─────────────────────────────────────────────────────────────┘
                  │
                  ▼ post-commit hook 自动触发(约 3 秒)
┌─────────────────────────────────────────────────────────────┐
│  trigger-sync.ps1 → WSL rsync 增量同步                      │
│  排除: .git/ tests/ *.md *.zip logs/*.log                   │
└─────────────────────────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│  WSL ~/ddev-test/wp-content/plugins/wp-ai-customer-service  │
│  DDEV 容器 /var/www/html/wp-content/plugins/...             │
│  ③ 刷新 https://ddev-test.ddev.site 即可生效                 │
└─────────────────────────────────────────────────────────────┘
                  │
                  ▼ git push origin main
┌─────────────────────────────────────────────────────────────┐
│  GitHub (Zerozhao314/sit-plugin)                            │
│  ④ 推送远程仓库                                              │
│  ⑤ 打 v*.*.* tag → Actions 自动打包 Release                 │
└─────────────────────────────────────────────────────────────┘
```

### 关键原则

1. **Windows 是源码权威**:所有代码修改在 `d:\project\wp-ai-customer-service` 完成
2. **commit 即同步**:`git commit` 后 hook 自动 rsync 到 DDEV,无需手动
3. **站点仓库不存插件**:插件部署副本通过 rsync 同步,不入库(避免双仓库重复跟踪)
4. **改完必同步**:即使不想 commit,也可用 `.\deploy.ps1` 手动同步

---

## 2. 环境前置条件

### 首次配置(已完成,仅参考)

```powershell
# 1. 启用 git hook(一次性,永久生效)
cd d:\project\wp-ai-customer-service
powershell -ExecutionPolicy Bypass -File .githooks\setup-hooks.ps1

# 2. 验证 hook 已启用
git config --get core.hooksPath
# 应输出: .githooks
```

### 日常启动 DDEV(每次开机后)

```powershell
# 方式 A: 一键脚本(推荐,自动完成 Step 1-4)
& "d:\project\start-debug.ps1"

# 方式 B: 手动启动
wsl -e bash -lc "cd ~/ddev-test && ddev start"
```

### 验证环境就绪

```powershell
# DDEV 是否运行
wsl -e bash -lc "ddev list"

# 插件是否已激活
wsl -e bash -lc "cd ~/ddev-test && ddev wp plugin list | grep wp-ai-customer-service"
```

---

## 3. 日常开发工作流(逐步)

### Step 1 — 修改代码

在 Windows IDE(推荐 VSCode / Trae)中编辑 `d:\project\wp-ai-customer-service\` 下的文件。

**目录结构**:
```
wp-ai-customer-service/
├── assets/           # CSS/JS 前端资源
│   ├── css/chat-widget.css
│   └── js/chat-widget.js
├── includes/          # PHP 类文件
│   ├── class-admin.php
│   ├── class-api-handler.php
│   ├── class-chat-logger.php
│   ├── class-i18n.php
│   ├── class-local-knowledge.php
│   └── class-woo-integration.php
├── templates/         # 后台模板
│   └── admin-settings.php
├── tests/             # 测试代码(不同步到 DDEV)
│   └── test-core.php
├── logs/              # 日志(不同步)
├── wp-ai-customer-service.php  # 插件主入口
├── CHANGELOG.md
└── deploy.ps1         # 手动同步入口
```

### Step 2 — 本地验证(可选)

改 PHP 后可先做语法检查:
```powershell
# 用 DDEV 容器内 PHP 8.3 检查语法
wsl -e bash -lc 'cd ~/ddev-test && ddev exec "cd /var/www/html/wp-content/plugins/wp-ai-customer-service && find . -name \"*.php\" -not -path \"./tests/*\" -print0 | xargs -0 -n1 php -l"'
```

### Step 3 — 提交代码

```powershell
cd d:\project\wp-ai-customer-service
git add .
git commit -m "fix: 修复订单状态查询的关键词匹配"
# ↓ post-commit hook 自动触发 ↓
# [sync] rsync d:\project\wp-ai-customer-service -> WSL ~/ddev-test/...
# [sync] OK
```

**hook 触发后**:约 3 秒,DDEV 站点已生效。刷新浏览器即可看到改动。

### Step 4 — 验证改动

```powershell
# 浏览器测试
start https://ddev-test.ddev.site/wp-admin/admin.php?page=wp-ai-cs

# 看实时日志(另开终端)
wsl -e bash -lc "cd ~/ddev-test && ddev exec tail -f /var/www/html/wp-content/debug.log"
```

### Step 5 — 推送到 GitHub

```powershell
git push
# 首次或新分支:git push -u origin main
```

### Step 6 — 调试结束清理(可选)

```powershell
# 关闭 Xdebug 提升性能
wsl -e bash -lc "cd ~/ddev-test && ddev xdebug off"

# 清空 debug.log
wsl -e bash -lc "cd ~/ddev-test && ddev exec truncate -s 0 /var/www/html/wp-content/debug.log"

# 停止 DDEV(释放资源)
wsl -e bash -lc "cd ~/ddev-test && ddev stop"
```

---

## 4. Git 提交规范

采用 **Conventional Commits** 规范,便于自动生成 CHANGELOG 与触发 Actions。

### 提交格式

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Type 类型(必须,小写)

| Type | 含义 | 示例 |
|---|---|---|
| `feat` | 新功能 | `feat(api): 新增订单状态查询接口` |
| `fix` | Bug 修复 | `fix(chat): 修复消息渲染 XSS 漏洞` |
| `docs` | 文档变更 | `docs: 更新 README 安装步骤` |
| `style` | 代码格式(不影响功能) | `style: 统一缩进为 4 空格` |
| `refactor` | 重构(既非 feat 也非 fix) | `refactor(logger): 抽取日志格式化方法` |
| `perf` | 性能优化 | `perf(api): 缓存商品搜索结果` |
| `test` | 测试相关 | `test: 新增订单查询单元测试` |
| `chore` | 构建/工具变更 | `chore: 升级 composer 依赖` |
| `ci` | CI 配置变更 | `ci: 添加生产部署 workflow` |

### Scope 范围(可选)

按模块划分,本项目常见 scope:

| Scope | 模块 |
|---|---|
| `api` | API 调用(`includes/class-api-handler.php`) |
| `chat` | 聊天前端(`assets/js/chat-widget.js`) |
| `admin` | 后台设置(`templates/admin-settings.php`) |
| `woo` | WooCommerce 集成(`includes/class-woo-integration.php`) |
| `i18n` | 多语言(`includes/class-i18n.php`) |
| `logger` | 日志(`includes/class-chat-logger.php`) |
| `ui` | 前端样式(`assets/css/chat-widget.css`) |

### Subject 规则

- 使用祈使句(中文用动词开头):`修复` / `新增` / `优化`
- 不超过 50 字
- 结尾不加句号

### 完整示例

**简单提交**:
```
fix(woo): 修复订单状态查询的关键词匹配
```

**完整提交(带 body 和 footer)**:
```
feat(api): 新增商品搜索的关键词提取与白名单过滤

- 扩展产品类型白名单: basketball, uniform, jersey, kit, soccer, football, training, wear, fanwear, apparel
- 跳过停用词: any/some/a/an/the
- 兼容中英文混合查询

Closes #42
```

### 触发 Actions 的特殊提交

| 提交 | Actions 行为 |
|---|---|
| 普通 commit | 不触发(只 push 到 main) |
| `git tag v1.2.1 && git push origin v1.2.1` | 触发 `release.yml`:打包 zip + 创建 Release |
| `git tag v1.2.1-beta.1 && git push origin v1.2.1-beta.1` | 同上(预发布) |

### 禁止的提交

❌ `update` / `fix bug` / `修改` — 太模糊,无法追溯
❌ `WIP` / `temp` / `测试` — 不要 push 到 main
❌ 一次提交多个不相关改动 — 拆成多个 commit

---

## 5. Hook 触发机制详解

### Hook 类型与触发时机

| Hook | 触发时机 | 文件 | 用途 |
|---|---|---|---|
| `post-commit` | `git commit` 成功后 | [.githooks/post-commit](file:///D:/project/wp-ai-customer-service/.githooks/post-commit) | 自动 rsync 同步到 DDEV |
| `post-checkout` | `git checkout` / `git switch` 后 | [.githooks/post-checkout](file:///D:/project/wp-ai-customer-service/.githooks/post-checkout) | 切分支后同步(避免老代码残留) |

### Hook 工作原理

**post-commit 流程**:
```
git commit -m "xxx"
    │
    ├─ 1. Git 创建 commit 对象(写入 .git/objects)
    │
    ├─ 2. Git 更新 HEAD 与分支引用
    │
    └─ 3. 【触发 post-commit hook】
            │
            └─ 执行 .githooks/post-commit (sh 脚本)
                    │
                    └─ powershell.exe -File .githooks/trigger-sync.ps1
                            │
                            └─ wsl -e bash -lc "rsync ..."
                                    │
                                    └─ 增量同步到 ~/ddev-test/wp-content/plugins/...
```

### trigger-sync.ps1 详解

**核心 rsync 命令**(与 SKILL.md 方式 A 完全一致):

```bash
rsync -av --delete \
  --exclude ".git" \
  --exclude "tests/" \
  --exclude "*.md" \
  --exclude "*.zip" \
  --exclude "*.tar.gz" \
  --exclude "*.bak" \
  --exclude "logs/*.log" \
  --exclude "logs/*.archived" \
  /mnt/d/project/wp-ai-customer-service/ \
  ~/ddev-test/wp-content/plugins/wp-ai-customer-service/
```

**参数解释**:

| 参数 | 含义 |
|---|---|
| `-a` | 归档模式(保留权限/时间/符号链接) |
| `-v` | 显示同步详情 |
| `--delete` | 删除目标中源已不存在的文件(保持镜像一致) |
| `--exclude` | 排除模式(不同步) |

**为何排除这些文件**:

| 排除项 | 原因 |
|---|---|
| `.git` | git 仓库元数据,DDEV 不需要 |
| `tests/` | 测试代码不进生产环境 |
| `*.md` | 文档不影响运行 |
| `*.zip` `*.tar.gz` | 构建产物 |
| `*.bak` | 备份文件 |
| `logs/*.log` `logs/*.archived` | 本地日志不污染 DDEV |

### Hook 触发的几种场景

| 场景 | 是否触发同步 | 说明 |
|---|---|---|
| `git commit -m "xxx"` | ✅ 触发 | 最常见,改完即同步 |
| `git commit --amend` | ✅ 触发 | 修改最后一次 commit |
| `git checkout feature-x` | ✅ 触发 | 切到 feature 分支 |
| `git switch main` | ✅ 触发 | 切回 main |
| `git pull` | ❌ 不触发 | pull 是 fetch+merge,会触发 post-merge(未配置) |
| `git merge feature-x` | ❌ 不触发 | 需手动 `.\deploy.ps1` |
| `git rebase` | ❌ 不触发 | rebase 后需手动同步 |
| `git reset --hard` | ❌ 不触发 | 需手动同步 |
| `git stash pop` | ❌ 不触发 | 需手动同步 |

### 手动同步(无需 commit)

```powershell
cd d:\project\wp-ai-customer-service
.\deploy.ps1
# 等价于 .\.githooks\trigger-sync.ps1
```

### Hook 失败时的处理

hook 失败**不会**影响 commit(commit 已写入 git 历史),只是同步没成功:

```powershell
# 重新跑同步
.\deploy.ps1

# 看 rsync 详细输出(排查问题)
wsl -e bash -lc 'rsync -av --delete --exclude ".git" --exclude "tests/" --exclude "*.md" --exclude "*.zip" --exclude "*.tar.gz" --exclude "*.bak" --exclude "logs/*.log" --exclude "logs/*.archived" /mnt/d/project/wp-ai-customer-service/ ~/ddev-test/wp-content/plugins/wp-ai-customer-service/'
```

### 临时禁用 Hook

```powershell
# 单次 commit 跳过 hook
git commit --no-verify -m "xxx"
# 注意:--no-verify 主要跳过 pre-commit/pre-push,post-commit 仍会触发

# 永久禁用(不推荐)
git config core.hooksPath /dev/null

# 恢复
powershell -File .githooks\setup-hooks.ps1
```

---

## 6. 多分支开发流程

### 分支策略

```
main            ────●────●────●────●────●──────── 稳定,可发布
                       \         /
feature/xxx             ●──●──●──●  功能开发
                                       \
hotfix/yyy                             ●──●  紧急修复
```

### 分支命名规范

| 前缀 | 用途 | 示例 |
|---|---|---|
| `feature/` | 新功能 | `feature/order-status-filter` |
| `fix/` | Bug 修复 | `fix/chat-xss` |
| `hotfix/` | 紧急修复(基于 main) | `hotfix/api-timeout` |
| `refactor/` | 重构 | `refactor/logger` |
| `docs/` | 文档 | `docs/api-reference` |

### 典型功能开发流程

```powershell
# 1. 基于 main 创建功能分支
git checkout main
git pull origin main
git checkout -b feature/product-search-optimize

# 2. 开发 + 多次 commit(hook 每次自动同步)
git add .
git commit -m "feat(api): 扩展产品类型白名单"
git commit -m "feat(api): 跳过停用词过滤"
git commit -m "test: 新增关键词提取测试"

# 3. 推送到 GitHub
git push -u origin feature/product-search-optimize

# 4. 在 GitHub 创建 PR,合并到 main
# https://github.com/Zerozhao314/sit-plugin/compare/main...feature/product-search-optimize

# 5. 合并后切回 main
git checkout main
git pull origin main
# post-checkout hook 自动同步最新代码到 DDEV
```

---

## 7. 发布流程(GitHub Actions)

### 打 Release

```powershell
cd d:\project\wp-ai-customer-service

# 1. 确认 main 是最新且 clean
git checkout main
git pull origin main
git status  # 应为 clean

# 2. 打 tag(语义化版本)
git tag v1.2.1
git push origin v1.2.1

# 3. 在 GitHub Actions 页面查看构建进度
# https://github.com/Zerozhao314/sit-plugin/actions
```

### 语义化版本规范

```
v<MAJOR>.<MINOR>.<PATCH>

v1.0.0   ── 初始稳定版
v1.0.1   ── Bug 修复(PATCH +1)
v1.1.0   ── 向下兼容的新功能(MINOR +1)
v2.0.0   ── 不兼容的破坏性变更(MAJOR +1)
v1.2.1-beta.1  ── 预发布版本
```

### Actions 自动完成

打 tag 后,`.github/workflows/release.yml` 自动:

1. **build-release job**:打包插件 zip(排除 tests/ *.md .githooks/ .github/ 等非运行时文件)
2. **创建 GitHub Release**:自动生成 Release Notes
3. **上传 zip 资产**:用户可在 Release 页下载

### 可选:生产部署

如已配置 Secrets(`PROD_WP_SSH_HOST` 等),还会:

4. **deploy-production job**:SSH 到生产服务器,curl 下载 zip,unzip 到 `wp-content/plugins/wp-ai-customer-service/`

---

## 8. 常见场景速查

### 场景 A:改了 PHP 代码,想立刻测试

```powershell
git add .
git commit -m "fix(woo): 修复订单查询"
# hook 自动同步,3 秒后刷新浏览器
```

### 场景 B:改了代码但不想 commit,想先测试

```powershell
.\deploy.ps1
# 立刻同步到 DDEV
```

### 场景 C:切到旧分支看问题

```powershell
git checkout hotfix/api-timeout
# post-checkout 自动同步该分支代码到 DDEV
# 测试完毕后切回
git checkout main
# post-checkout 再次自动同步 main 代码
```

### 场景 D:DDEV 站点打不开

```powershell
# 1. 检查 DDEV 是否运行
wsl -e bash -lc "ddev list"

# 2. 重启 DDEV
wsl -e bash -lc "cd ~/ddev-test && ddev restart"

# 3. 检查插件是否激活
wsl -e bash -lc "cd ~/ddev-test && ddev wp plugin list"

# 4. 重新激活
wsl -e bash -lc "cd ~/ddev-test && ddev wp plugin activate wp-ai-customer-service"
```

### 场景 E:同步后代码未生效

```powershell
# 1. md5 核对(Windows vs DDEV 容器)
Get-FileHash "wp-ai-customer-service.php" -Algorithm MD5
wsl -e bash -lc "cd ~/ddev-test && ddev exec md5sum /var/www/html/wp-content/plugins/wp-ai-customer-service/wp-ai-customer-service.php"

# 2. 如 md5 不一致,重新同步
.\deploy.ps1

# 3. 清 WordPress 缓存
wsl -e bash -lc "cd ~/ddev-test && ddev wp cache flush"
```

### 场景 F:看实时 PHP 错误日志

```powershell
# 另开终端,实时跟踪
wsl -e bash -lc "cd ~/ddev-test && ddev exec tail -f /var/www/html/wp-content/debug.log"
```

### 场景 G:开启 Xdebug 断点调试

```powershell
# 开启 Xdebug
wsl -e bash -lc "cd ~/ddev-test && ddev xdebug on"

# VSCode 配置 launch.json(端口 9003)
# 调试完关闭
wsl -e bash -lc "cd ~/ddev-test && ddev xdebug off"
```

---

## 9. 故障排查

| 现象 | 原因 | 解决 |
|---|---|---|
| commit 后未自动同步 | hook 未启用 | `powershell -File .githooks\setup-hooks.ps1` |
| hook 报 `[sync] FAIL` | DDEV 未启动 / WSL 异常 | `wsl -e bash -lc "ddev start"` 后重试 |
| rsync 报 `permission denied` | WSL 文件权限 | `wsl -e bash -lc "chmod -R 755 ~/ddev-test/wp-content/plugins/wp-ai-customer-service"` |
| `git push` 报 `Permission denied (publickey)` | SSH key 未加 GitHub | 加公钥到 https://github.com/settings/keys |
| `git push` 报 `Host key verification` | known_hosts 缺 GitHub | `ssh -T -o StrictHostKeyChecking=accept-new git@github.com` |
| 浏览器访问站点 502 | DDEV 容器异常 | `ddev restart` |
| commit 信息中文乱码 | PowerShell 编码 | `chcp 65001` 切 UTF-8 |
| hook 执行慢(>10秒) | WSL 跨盘 IO 慢 | 改用软链方式(见 SKILL.md 方式 B) |

---

## 10. 文件清单

### 配置文件(已入库)

| 文件 | 作用 |
|---|---|
| [.gitignore](file:///D:/project/wp-ai-customer-service/.gitignore) | 插件级忽略规则 |
| [.githooks/post-commit](file:///D:/project/wp-ai-customer-service/.githooks/post-commit) | commit 后自动同步 hook |
| [.githooks/post-checkout](file:///D:/project/wp-ai-customer-service/.githooks/post-checkout) | 切分支后自动同步 hook |
| [.githooks/trigger-sync.ps1](file:///D:/project/wp-ai-customer-service/.githooks/trigger-sync.ps1) | rsync 同步主逻辑 |
| [.githooks/setup-hooks.ps1](file:///D:/project/wp-ai-customer-service/.githooks/setup-hooks.ps1) | 一键启用 hook |
| [deploy.ps1](file:///D:/project/wp-ai-customer-service/deploy.ps1) | 手动同步入口 |
| [.github/workflows/release.yml](file:///D:/project/wp-ai-customer-service/.github/workflows/release.yml) | GitHub Actions 发布 |
| [DDEV-GIT-SETUP.md](file:///D:/project/wp-ai-customer-service/DDEV-GIT-SETUP.md) | DDEV+Git 集成配置文档 |
| [DEVELOPMENT-WORKFLOW.md](file:///D:/project/wp-ai-customer-service/DEVELOPMENT-WORKFLOW.md) | 本文档 |

### 关联资源(外部)

| 资源 | 位置 |
|---|---|
| DDEV 环境配置 | WSL `~/ddev-test/.ddev/config.yaml` |
| 站点仓库 .gitignore | WSL `~/ddev-test/.gitignore` |
| WP DDEV Quickstart SKILL | `d:\project\.trae\skills\wp-ddev-quickstart\SKILL.md` |
| 一键启动脚本 | `d:\project\start-debug.ps1` |
| GitHub 仓库 | https://github.com/Zerozhao314/sit-plugin |
