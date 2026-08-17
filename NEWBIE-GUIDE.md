# 新手快速上手指南

> 本文档是 wp-ai-customer-service 插件开发环境的**唯一入口**。
> 按顺序读完即可上手;遇到问题查第 6 章故障排查。
> 最后更新:2026-08-17

---

## 0. 30 秒认识项目

### 这是什么

- 一个 WordPress 插件 `wp-ai-customer-service`(WooCommerce AI 客服)
- 开发环境:WSL2 + DDEV + WordPress
- 代码在 Windows IDE 写,通过 git hook 自动同步到 WSL DDEV 容器调试

### 双仓库架构

```
┌─ 插件仓库 (源码权威) ─────────────────────┐
│  d:\project\wp-ai-customer-service        │  ← 你在这里写代码
│  GitHub: Zerozhao314/sit-plugin           │
└───────────────────────────────────────────┘
       │ git commit (hook 自动同步,约 3 秒)
       ▼
┌─ 站点仓库 (DDEV 环境) ────────────────────┐
│  WSL: ~/ddev-test                         │  ← DDEV WordPress 运行处
│  GitHub: Zerozhao314/my-site              │
└───────────────────────────────────────────┘
       │ ddev start
       ▼
   https://ddev-test.ddev.site
```

### 你需要记住的 3 个路径

| 用途 | 路径 |
|---|---|
| 写代码 | `d:\project\wp-ai-customer-service\` |
| DDEV 环境 | WSL `~/ddev-test` |
| 浏览器调试 | https://ddev-test.ddev.site |

---

## 1. 首次配置(从零搭建,约 15 分钟)

> **如果你接手的是已配置好的电脑,跳到第 2 章。**

### Step 1.1 — 确认环境就绪

```powershell
# 检查 WSL
wsl -e bash -lc "echo WSL OK && lsb_release -a"
# 期望: Ubuntu 24.04

# 检查 DDEV
wsl -e bash -lc "ddev --version"
# 期望: ddev version v1.23+

# 检查 Git
git --version
# 期望: 2.40+
```

如果以上任何一个失败,先装好再继续。

### Step 1.2 — 配置 Git 身份

```powershell
# Windows (插件仓库用)
git config --global user.name "Zerozhao314"
git config --global user.email "why.zero.zhao@gmail.com"

# WSL (站点仓库用)
wsl -e bash -lc "git config --global user.name 'Zerozhao314' && git config --global user.email 'why.zero.zhao@gmail.com'"
```

**验证**:
```powershell
git config --global user.name
wsl -e bash -lc "git config --global user.name"
# 两边都应输出: Zerozhao314
```

### Step 1.3 — 配置 SSH key(连接 GitHub 必需)

```powershell
# 1. 生成 Windows SSH key (用上一步的邮箱,一路回车不设密码)
ssh-keygen -t ed25519 -C "why.zero.zhao@gmail.com" -f $env:USERPROFILE\.ssh\id_ed25519

# 2. 生成 WSL SSH key
wsl -e bash -lc "ssh-keygen -t ed25519 -C 'why.zero.zhao@gmail.com' -f ~/.ssh/id_ed25519 -N ''"

# 3. 查看并复制 Windows 公钥 (整行复制,含 ssh-ed25519 前缀)
Get-Content $env:USERPROFILE\.ssh\id_ed25519.pub

# 4. 添加到 GitHub: https://github.com/settings/ssh/new
#    Title 填 "Windows",Key 粘贴上一步复制的公钥

# 5. 同样复制 WSL 公钥并添加到 GitHub (Title 填 "WSL")
wsl -e bash -lc "cat ~/.ssh/id_ed25519.pub"
```

**验证**:
```powershell
# 测试 Windows 连接
ssh -T git@github.com
# 期望: Hi Zerozhao314! You've successfully authenticated...

# 测试 WSL 连接
wsl -e bash -lc "ssh -T git@github.com"
# 期望: 同上
```

> 第一次连接会问 `Are you sure you want to continue connecting (yes/no)?`,输入 `yes`。

### Step 1.4 — 克隆两个仓库

```powershell
# 插件仓库 (Windows)
cd d:\project
git clone git@github.com:Zerozhao314/sit-plugin.git wp-ai-customer-service

# 站点仓库 (WSL)
wsl -e bash -lc "cd ~ && git clone git@github.com:Zerozhao314/my-site.git ddev-test"
```

### Step 1.5 — 启用 git hook(关键)

```powershell
cd d:\project\wp-ai-customer-service
powershell -ExecutionPolicy Bypass -File .githooks\setup-hooks.ps1
```

**验证**:
```powershell
git config --get core.hooksPath
# 应输出: .githooks
```

### Step 1.6 — 启动 DDEV

```powershell
wsl -e bash -lc "cd ~/ddev-test && ddev start"
```

**首次启动**需 3-5 分钟下载 DDEV 镜像 + WordPress 文件;后续启动只需 10-30 秒。

**验证**:
```powershell
# DDEV 是否运行
wsl -e bash -lc "ddev list"
# 期望看到 ddev-test 状态 OK

# 浏览器访问
start https://ddev-test.ddev.site
# 应看到 WordPress 默认首页
```

### Step 1.7 — 部署插件到 DDEV

```powershell
cd d:\project\wp-ai-customer-service

# 手动同步插件源码到 DDEV(无需 commit)
.\deploy.ps1
```

**验证**:
```powershell
# 插件是否在 DDEV 中
wsl -e bash -lc "cd ~/ddev-test && ddev wp plugin list"
# 应看到 wp-ai-customer-service

# 激活插件
wsl -e bash -lc "cd ~/ddev-test && ddev wp plugin activate wp-ai-customer-service"
```

✅ **首次配置完成!** 现在可以开始日常开发(第 2 章)。

---

## 2. 日常开发 5 步走

### Step 2.1 — 修改代码

在 Windows IDE(推荐 VSCode / Trae)中编辑 `d:\project\wp-ai-customer-service\` 下的文件。

**目录速查**:
```
wp-ai-customer-service/
├── assets/         # CSS/JS 前端
├── includes/        # PHP 类文件
├── templates/       # 后台模板
├── tests/           # 测试(不同步 DDEV)
├── wp-ai-customer-service.php  # 插件主入口
└── deploy.ps1       # 手动同步入口
```

### Step 2.2 — 提交代码(自动同步)

```powershell
cd d:\project\wp-ai-customer-service
git add .
git commit -m "fix(woo): 修复订单查询关键词匹配"
```

提交时会看到:
```
[sync] rsync d:\project\wp-ai-customer-service -> WSL ~/ddev-test/wp-content/plugins/...
[sync] OK
```

**约 3 秒后**,DDEV 站点已生效。

### Step 2.3 — 验证改动

```powershell
# 浏览器测试
start https://ddev-test.ddev.site/wp-admin/admin.php?page=wp-ai-cs

# 看实时日志(另开终端)
wsl -e bash -lc "cd ~/ddev-test && ddev exec tail -f /var/www/html/wp-content/debug.log"
```

### Step 2.4 — 推送到 GitHub

```powershell
git push
# 首次或新分支: git push -u origin main
```

### Step 2.5 — 调试结束(可选)

```powershell
# 关闭 Xdebug 提升性能
wsl -e bash -lc "cd ~/ddev-test && ddev xdebug off"

# 停止 DDEV 释放资源
wsl -e bash -lc "cd ~/ddev-test && ddev stop"
```

✅ **日常开发循环完成!**

---

## 3. Git 提交规范(新手必读)

### 提交格式

```
<type>(<scope>): <subject>
```

### Type 类型(必须,小写)

| Type | 何时用 | 示例 |
|---|---|---|
| `feat` | 新增功能 | `feat(api): 新增订单查询接口` |
| `fix` | 修复 Bug | `fix(chat): 修复消息渲染 XSS` |
| `docs` | 文档变更 | `docs: 更新 README` |
| `refactor` | 重构(不改功能) | `refactor(logger): 抽取格式化方法` |
| `test` | 测试相关 | `test: 新增关键词提取测试` |
| `chore` | 构建/工具 | `chore: 升级依赖` |

### Scope 范围(可选)

| Scope | 模块 |
|---|---|
| `api` | API 调用 |
| `chat` | 聊天前端 |
| `admin` | 后台设置 |
| `woo` | WooCommerce 集成 |
| `i18n` | 多语言 |
| `logger` | 日志 |

### 好的提交信息示例

```
fix(woo): 修复订单状态查询的关键词匹配
feat(api): 新增商品搜索的关键词提取
docs: 更新安装步骤
```

### ❌ 禁止的提交

```
update              ← 太模糊
fix bug             ← 没说修了什么
WIP / temp          ← 不要 push 到 main
```

### 触发自动发布的特殊操作

| 操作 | 行为 |
|---|---|
| `git commit -m "..."` | 普通 commit,不触发发布 |
| `git tag v1.2.1 && git push origin v1.2.1` | 自动打包 zip + 创建 GitHub Release |

---

## 4. 常见任务速查

### 4.1 改了代码,想立刻测试(不想 commit)

```powershell
cd d:\project\wp-ai-customer-service
.\deploy.ps1
```

### 4.2 切换分支测试

```powershell
# 切到旧分支
git checkout hotfix/api-timeout
# post-checkout hook 自动同步该分支代码到 DDEV

# 测完切回 main
git checkout main
# hook 再次自动同步
```

### 4.3 创建功能分支开发

```powershell
git checkout main
git pull origin main
git checkout -b feature/new-feature

# 多次 commit 开发
git add .
git commit -m "feat(api): 实现第一步"
git commit -m "feat(api): 实现第二步"

# 推到 GitHub
git push -u origin feature/new-feature

# 在 GitHub 创建 PR 合并到 main
# https://github.com/Zerozhao314/sit-plugin/compare/main...feature/new-feature
```

### 4.4 发布新版本(打 tag)

```powershell
cd d:\project\wp-ai-customer-service
git checkout main
git pull origin main

# 打正式版本
git tag v1.2.1
git push origin v1.2.1

# 或打预发布版本
git tag v1.2.1-beta.1
git push origin v1.2.1-beta.1
```

打 tag 后,GitHub Actions 自动:
1. 打包插件 zip(排除 tests/ 文档/构建产物)
2. 创建 GitHub Release,自动生成 Release Notes
3. 上传 zip 资产

查看进度:https://github.com/Zerozhao314/sit-plugin/actions

### 4.5 备份站点(数据库 + 媒体 + 配置)

```bash
# 在 WSL 内执行
wsl -e bash -lc "cd ~/ddev-test && ./scripts/backup.sh"
```

产出 3 类备份(双副本到 WSL + Windows D 盘):
- `db.sql.gz` — 数据库
- `uploads/` — 用户上传媒体(增量硬链接,省空间)
- `ddev-config.tar.gz` — DDEV 配置

定时备份(每日 02:00):
```bash
wsl -e bash -lc "crontab -e"
# 添加一行:
0 2 * * * /home/zero/ddev-test/scripts/backup.sh >> ~/backups/ddev-test/backup.log 2>&1
```

### 4.6 灾难恢复

完整恢复流程见 [docs/RESTORE-GUIDE.md](file:///D:/project/wp-ai-customer-service/docs/RESTORE-GUIDE.md)(615 行详细手册)。

**核心命令速查**:

```bash
# 数据库恢复
zcat ~/backups/ddev-test/latest/db.sql.gz | ddev import-db

# uploads 恢复
rsync -avH --inplace ~/backups/ddev-test/latest/uploads/ ~/ddev-test/wp-content/uploads/

# 配置恢复
TMP=$(mktemp -d) && tar -xzf ~/backups/ddev-test/latest/ddev-config.tar.gz -C "${TMP}"
cp "${TMP}/.ddev/config.yaml" ~/ddev-test/.ddev/config.yaml
cp "${TMP}/wp-config.php" ~/ddev-test/wp-config.php
rm -rf "${TMP}"
```

### 4.7 看 PHP 错误日志

> **首次使用前必须先开启 WP_DEBUG_LOG**(DDEV 默认关闭,debug.log 不会记录 PHP 错误):

```powershell
# 开启 WP_DEBUG + WP_DEBUG_LOG (一次性, 永久生效)
wsl -e bash -lc "cd ~/ddev-test && ddev config --web-environment-add=WP_DEBUG=true,WP_DEBUG_LOG=true && ddev restart"

# 验证
wsl -e bash -lc "cd ~/ddev-test && ddev wp eval 'var_dump(WP_DEBUG_LOG);'"
# 期望: bool(true)
```

开启后,PHP 错误才会写入 `wp-content/debug.log`:

```powershell
# 实时跟踪
wsl -e bash -lc "cd ~/ddev-test && ddev exec tail -f /var/www/html/wp-content/debug.log"

# 看最近 50 行
wsl -e bash -lc "cd ~/ddev-test && ddev exec tail -50 /var/www/html/wp-content/debug.log"
```

### 4.8 开启 Xdebug 断点调试

```powershell
wsl -e bash -lc "cd ~/ddev-test && ddev xdebug on"
# VSCode 配置 launch.json (端口 9003)
# 调试完关闭
wsl -e bash -lc "cd ~/ddev-test && ddev xdebug off"
```

### 4.9 重启 DDEV(配置改动后)

```powershell
wsl -e bash -lc "cd ~/ddev-test && ddev restart"
```

### 4.10 清空 WordPress 缓存

```powershell
wsl -e bash -lc "cd ~/ddev-test && ddev wp cache flush"
```

---

## 5. Hook 触发机制(了解即可)

### 哪些操作会自动同步到 DDEV

| 操作 | 是否触发同步 | 说明 |
|---|---|---|
| `git commit` | ✅ 触发 | 最常用 |
| `git commit --amend` | ✅ 触发 | 修改最后一次 commit |
| `git checkout <branch>` | ✅ 触发 | 切分支后同步 |
| `git switch <branch>` | ✅ 触发 | 同上 |
| `git pull` | ❌ 不触发 | 需手动 `.\deploy.ps1` |
| `git merge` | ❌ 不触发 | 需手动 `.\deploy.ps1` |
| `git rebase` | ❌ 不触发 | 需手动 `.\deploy.ps1` |
| `git reset --hard` | ❌ 不触发 | 需手动 `.\deploy.ps1` |
| `git stash pop` | ❌ 不触发 | 需手动 `.\deploy.ps1` |

**记住**:**只要不是 commit/checkout 触发的代码变化,都要手动跑 `.\deploy.ps1`**。

### Hook 失败时

hook 失败**不影响 commit**(commit 已写入 git 历史),只是同步没成功:

```powershell
# 重新跑同步
.\deploy.ps1

# 看详细 rsync 输出(排查问题)
wsl -e bash -lc 'rsync -av --delete --exclude ".git" --exclude "tests/" --exclude "*.md" --exclude "*.zip" --exclude "*.tar.gz" --exclude "*.bak" --exclude "logs/*.log" --exclude "logs/*.archived" /mnt/d/project/wp-ai-customer-service/ ~/ddev-test/wp-content/plugins/wp-ai-customer-service/'
```

### 同步了哪些文件,排除了哪些

**同步**:`assets/` `includes/` `templates/` `wp-ai-customer-service.php` 等运行所需文件

**排除**(不同步):
| 排除项 | 原因 |
|---|---|
| `.git` | 仓库元数据 |
| `tests/` | 测试代码不进生产 |
| `*.md` | 文档不影响运行 |
| `*.zip` `*.tar.gz` | 构建产物 |
| `logs/*.log` | 本地日志不污染 DDEV |

---

## 6. 故障排查

### 6.1 `git push` 报 `Permission denied (publickey)`

**原因**:SSH 公钥未添加到 GitHub。

**解决**:
```powershell
# 1. 查看 Windows 公钥
Get-Content $env:USERPROFILE\.ssh\id_ed25519.pub

# 2. 添加到 https://github.com/settings/keys (Title: Windows-Admin)

# 3. 测试连接
ssh -T git@github.com
# 期望: Hi Zerozhao314! You've successfully authenticated...

# WSL 同样:
wsl -e bash -lc "cat ~/.ssh/id_ed25519.pub"
# 添加到 GitHub (Title: WSL-zero-ddev)
wsl -e bash -lc "ssh -T git@github.com"
```

### 6.2 `git push` 报 `Host key verification failed`

**原因**:known_hosts 缺 GitHub。

**解决**:
```powershell
ssh -T -o StrictHostKeyChecking=accept-new git@github.com
# 或 WSL:
wsl -e bash -lc "ssh -T -o StrictHostKeyChecking=accept-new git@github.com"
```

### 6.3 commit 后未自动同步

**原因**:hook 未启用。

**解决**:
```powershell
cd d:\project\wp-ai-customer-service
powershell -ExecutionPolicy Bypass -File .githooks\setup-hooks.ps1

# 验证
git config --get core.hooksPath
# 应输出: .githooks
```

### 6.4 同步后站点未生效

**原因**:DDEV 未启动 / WP 缓存 / 同步未成功。

**解决**(按顺序排查):
```powershell
# 1. md5 核对(Windows vs DDEV)
Get-FileHash "wp-ai-customer-service.php" -Algorithm MD5
wsl -e bash -lc "cd ~/ddev-test && ddev exec md5sum /var/www/html/wp-content/plugins/wp-ai-customer-service/wp-ai-customer-service.php"

# 2. 如不一致,重新同步
.\deploy.ps1

# 3. 清 WP 缓存
wsl -e bash -lc "cd ~/ddev-test && ddev wp cache flush"

# 4. 确认 DDEV 运行中
wsl -e bash -lc "ddev list"
```

### 6.5 DDEV 站点打不开(502 / 拒绝连接)

**解决**:
```powershell
# 1. 检查 DDEV 状态
wsl -e bash -lc "ddev list"

# 2. 重启 DDEV
wsl -e bash -lc "cd ~/ddev-test && ddev restart"

# 3. 仍不行,重建容器
wsl -e bash -lc "cd ~/ddev-test && ddev stop && ddev start"
```

### 6.6 commit 信息中文乱码

**原因**:PowerShell 编码问题。

**解决**:
```powershell
chcp 65001
# 切到 UTF-8 后再 commit
```

### 6.7 hook 报 `[sync] FAIL`

**原因**:DDEV 未启动 / WSL 异常。

**解决**:
```powershell
wsl -e bash -lc "ddev start"
.\deploy.ps1
```

### 6.8 rsync 报 `permission denied`

**原因**:WSL 内文件权限不对。

**解决**:
```powershell
wsl -e bash -lc "chmod -R 755 ~/ddev-test/wp-content/plugins/wp-ai-customer-service"
```

### 6.9 插件在 WP 后台不显示或报 fatal

**解决**:
```powershell
# 1. 重新同步代码
.\deploy.ps1

# 2. 检查插件主入口文件存在
wsl -e bash -lc "ls ~/ddev-test/wp-content/plugins/wp-ai-customer-service/wp-ai-customer-service.php"

# 3. PHP 语法检查
wsl -e bash -lc "cd ~/ddev-test && ddev exec 'php -l /var/www/html/wp-content/plugins/wp-ai-customer-service/wp-ai-customer-service.php'"
# 期望: No syntax errors detected

# 4. 看错误日志
wsl -e bash -lc "cd ~/ddev-test && ddev exec tail -50 /var/www/html/wp-content/debug.log"
```

---

## 7. 命令速查卡

### 日常开发(高频)

```powershell
# 提交并自动同步
git add . && git commit -m "fix(xxx): 描述"

# 手动同步(不想 commit 时)
.\deploy.ps1

# 推送远程
git push

# 看实时日志
wsl -e bash -lc "cd ~/ddev-test && ddev exec tail -f /var/www/html/wp-content/debug.log"
```

### DDEV 操作

```powershell
# 启动 / 停止 / 重启
wsl -e bash -lc "cd ~/ddev-test && ddev start"
wsl -e bash -lc "cd ~/ddev-test && ddev stop"
wsl -e bash -lc "cd ~/ddev-test && ddev restart"

# 激活 / 停用插件
wsl -e bash -lc "cd ~/ddev-test && ddev wp plugin activate wp-ai-customer-service"
wsl -e bash -lc "cd ~/ddev-test && ddev wp plugin deactivate wp-ai-customer-service"

# 开 / 关 Xdebug
wsl -e bash -lc "cd ~/ddev-test && ddev xdebug on"
wsl -e bash -lc "cd ~/ddev-test && ddev xdebug off"

# 清 WP 缓存
wsl -e bash -lc "cd ~/ddev-test && ddev wp cache flush"
```

### Git 操作

```powershell
# 查看状态
git status

# 查看提交历史
git log --oneline -10

# 切分支
git checkout main
git checkout -b feature/new-feature

# 推送
git push
git push -u origin main     # 首次

# 打发布 tag
git tag v1.2.1
git push origin v1.2.1
```

### 备份与恢复

```bash
# 备份(WSL 内)
cd ~/ddev-test && ./scripts/backup.sh

# 数据库恢复
zcat ~/backups/ddev-test/latest/db.sql.gz | ddev import-db

# uploads 恢复
rsync -avH --inplace ~/backups/ddev-test/latest/uploads/ ~/ddev-test/wp-content/uploads/

# 配置恢复
TMP=$(mktemp -d) && tar -xzf ~/backups/ddev-test/latest/ddev-config.tar.gz -C "${TMP}"
cp "${TMP}/.ddev/config.yaml" ~/ddev-test/.ddev/config.yaml
cp "${TMP}/wp-config.php" ~/ddev-test/wp-config.php
rm -rf "${TMP}"
```

---

## 8. 进阶文档索引

新手阶段熟悉后,如需深入了解,查阅以下文档:

| 文档 | 内容 | 适合场景 |
|---|---|---|
| [DDEV-GIT-SETUP.md](file:///D:/project/wp-ai-customer-service/DDEV-GIT-SETUP.md) | DDEV + Git 集成完整配置记录(含决策细节) | 想了解为什么这样配 |
| [DEVELOPMENT-WORKFLOW.md](file:///D:/project/wp-ai-customer-service/DEVELOPMENT-WORKFLOW.md) | 完整开发工作流(含 Hook 触发原理) | 想深入了解 hook 机制 |
| [.github/workflows/CHANGELOG.md](file:///D:/project/wp-ai-customer-service/.github/workflows/CHANGELOG.md) | GitHub Actions workflow 变更说明 | 想了解 CI 历史与修复细节 |
| [docs/RESTORE-GUIDE.md](file:///D:/project/wp-ai-customer-service/docs/RESTORE-GUIDE.md) | 灾难恢复完整手册(615 行) | 出大事了要恢复 |
| [.github/workflows/release.yml](file:///D:/project/wp-ai-customer-service/.github/workflows/release.yml) | GitHub Actions 完整配置 | 想改 CI 配置 |
| [.trae/skills/wp-ddev-quickstart/SKILL.md](file:///D:/project/.trae/skills/wp-ddev-quickstart/SKILL.md) | DDEV 启动 SKILL | 想用 IDE 快捷启动 |

---

## 9. 文件清单

### 你会接触到的文件

| 文件 | 位置 | 作用 |
|---|---|---|
| `wp-ai-customer-service.php` | 插件根 | 插件主入口 |
| `includes/class-*.php` | includes/ | PHP 类文件 |
| `assets/js/chat-widget.js` | assets/js/ | 聊天前端 |
| `assets/css/chat-widget.css` | assets/css/ | 聊天样式 |
| `templates/admin-settings.php` | templates/ | 后台设置页 |
| `deploy.ps1` | 插件根 | 手动同步入口 |
| `.githooks/` | 插件根 | git hook 配置 |
| `.gitignore` | 插件根 | 忽略规则 |

### 不需要你管的环境文件

| 文件 | 位置 | 由谁维护 |
|---|---|---|
| `.ddev/config.yaml` | WSL `~/ddev-test/.ddev/` | DDEV 自动生成 + 备份脚本快照 |
| `wp-config.php` | WSL `~/ddev-test/` | DDEV 自动生成 |
| `wp-admin/` `wp-includes/` | WSL `~/ddev-test/` | DDEV 自动下载 |
| `wp-content/uploads/` | WSL `~/ddev-test/` | 备份脚本维护 |

---

## 10. 常见陷阱(新手必看)

### 陷阱 1:在 WSL 内改代码

❌ 不要在 WSL `~/ddev-test/wp-content/plugins/wp-ai-customer-service/` 内改代码

✅ 永远在 Windows `d:\project\wp-ai-customer-service\` 改代码

**原因**:WSL 内的插件目录只是 rsync 同步的副本,改了会被下次同步覆盖。

### 陷阱 2:pull / merge 后忘同步

```powershell
git pull origin main
# ❌ 此时 DDEV 还是旧代码!
# ✅ 解决:
.\deploy.ps1
```

### 陷阱 3:改了 .gitignore 但不生效

```powershell
# .gitignore 只对未跟踪文件生效。已跟踪文件改 ignore 不会停止跟踪
git rm --cached <文件路径>
git commit -m "chore: 停止跟踪 xxx"
```

### 陷阱 4:WIP commit 推到 main

❌ 不要把 `WIP` / `temp` / `测试` 提交推到 main

✅ 用功能分支:`git checkout -b feature/wip-xxx`,在分支上随便 commit

### 陷阱 5:站点打不开就慌

按顺序排查:
1. `wsl -e bash -lc "ddev list"` → 看 DDEV 是否运行
2. `wsl -e bash -lc "cd ~/ddev-test && ddev restart"` → 重启
3. 看错误日志 → 第 4.7 节
4. 还不行 → 第 6.5 节

### 陷阱 6:push 前没看 git status

```powershell
# 养成习惯
git status
git diff --cached
# 确认无误再:
git commit -m "..."
git push
```

---

✅ **看完这份文档,你应该能独立完成日常开发了。**

遇到问题先查第 6 章,再查进阶文档(第 8 章)。祝开发顺利!
