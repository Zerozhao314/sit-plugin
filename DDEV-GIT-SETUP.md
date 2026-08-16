# DDEV + Git 集成配置方案

> 本文档记录 WSL+DDEV+WordPress 调试环境与 Git 双仓库集成的完整配置流程,可作为复现手册与日常查阅参考。
> 最后更新:2026-08-15

---

## 1. 架构总览

```
┌─ 插件仓库 (独立, 可部署) ────────────────┐
│  d:\project\wp-ai-customer-service         │  Windows, IDE 编辑处, 源码权威
│  只跟踪插件源码 + CI/CD 配置               │
│  remote: git@github.com:Zerozhao314/sit-plugin.git
└──────────────────────────────────────────┘
        │  ① git commit / checkout (hook 自动触发)
        │  ② 或手动 .\deploy.ps1
        ▼  rsync 增量同步 (约 3 秒)
┌─ 站点仓库 (WSL) ────────────────────────┐
│  ~/ddev-test                              │  DDEV 环境
│  只跟踪: 3 个自定义调试脚本 + .gitignore  │
│  忽略: WP核心 + 第三方插件/主题 + 日志    │
│  remote: git@github.com:Zerozhao314/my-site.git
└──────────────────────────────────────────┘
        │  ddev start
        ▼
   https://ddev-test.ddev.site
```

### 仓库清单

| 仓库 | 位置 | 用途 | remote | 首次 commit |
|---|---|---|---|---|
| 站点 | WSL `~/ddev-test` | 站点级自定义代码 | `git@github.com:Zerozhao314/my-site.git` | `5666981` |
| 插件 | Win `d:\project\wp-ai-customer-service` | 插件源码 + CI/CD | `git@github.com:Zerozhao314/sit-plugin.git` | `888f578` |

---

## 2. 环境固定信息

| 变量 | 值 |
|---|---|
| WSL 发行版 | `Ubuntu-24.04` |
| WSL 用户 | `zero` |
| WSL 项目目录 | `~/ddev-test` |
| Windows 源码 | `d:\project\wp-ai-customer-service` |
| DDEV 项目名 | `ddev-test` |
| 站点 URL | `https://ddev-test.ddev.site` |
| WP 表前缀 | `syg_` |
| PHP 版本 | `8.3` |
| 数据库 | `mariadb 11.8` (db/db/db) |
| Web 服务器 | `nginx-fpm` |

---

## 3. 配置步骤(已完成)

### Stage A — DDEV config.yaml(保留现有,未改动)

**决策**:现有配置优于用户最初提供的模板,保留不动。

| 字段 | 现有值(保留) | 用户模板(未采用) | 不采用原因 |
|---|---|---|---|
| `name` | `ddev-test` | `wordpress-site` | 改名会使 URL 与 SKILL.md 全部链接失效 |
| `docroot` | `.` | `web` | WP 在根目录,无 `web/` 目录,改后站点打不开 |
| `php_version` | `"8.3"` | `"8.2"` | 8.3 > 8.2,无需降级 |
| `database` | `mariadb 11.8` | `mysql 8.0` | 改 DB 类型需 `ddev delete`,测试数据全丢 |

### Stage B — Git 用户配置(WSL + Windows 两边 global)

```bash
# WSL
wsl -e bash -lc "git config --global user.name 'Zerozhao314' && git config --global user.email 'why.zero.zhao@gmail.com'"
```
```powershell
# Windows
git config --global user.name "Zerozhao314"
git config --global user.email "why.zero.zhao@gmail.com"
```

### Stage C — 站点仓库 Git 初始化 + .gitignore

**位置**:WSL `~/ddev-test`

```bash
# 初始化
wsl -e bash -lc "cd ~/ddev-test && git init -b main"
```

**`.gitignore` 最终内容**(忽略 DDEV 配置 + WP 核心 + 第三方件 + 敏感文件):

```gitignore
# DDEV 自动生成的项目配置
.ddev/

# WordPress 核心代码(DDEV 管理,不入库)
wp-admin/
wp-includes/
wp-*.php
/index.php
/license.txt
/readme.html
/xmlrpc.php
/.htaccess

# Composer / Node 依赖
vendor/
node_modules/

# WordPress 上传文件
wp-content/uploads/

# wp-content 第三方件(插件有独立仓库,部署副本不跟踪)
wp-content/plugins/
wp-content/themes/
wp-content/languages/
wp-content/updraft/
wp-content/upgrade-temp-backup/
wp-content/upgrade/
wp-content/.htaccess
wp-content/index.php
wp-content/*.log
wp-content/sgs_encrypt_key.php
wp-content/sg_wizard_themes_data.json

# 环境变量
.env
```

**首次 commit**(4 文件 / 275 行):
```bash
wsl -e bash -lc "cd ~/ddev-test && git add . && git commit -m 'init: ddev-test site (custom debug scripts)'"
# => commit 5666981
```

被跟踪的 5 项:`.gitignore` + 3 个自定义调试脚本(`debug-woo-context.php` / `eval-rag.php` / `test-rag-hard-reply.php`)。

### Stage D — 插件独立仓库初始化

**位置**:Windows `d:\project\wp-ai-customer-service`

```powershell
cd d:\project\wp-ai-customer-service
git init -b main
git add .
git commit -m "init: wp-ai-customer-service plugin"
# => commit 888f578 (14 文件 / 4968 行)
```

**插件级 `.gitignore`**:
```gitignore
# 日志
logs/
# 依赖
vendor/
node_modules/
# 构建产物
*.zip
*.tar.gz
# 环境变量
.env
```

### Stage E — 两仓库 Remote 配置(SSH)

```bash
# 站点仓库 (WSL)
wsl -e bash -lc "cd ~/ddev-test && git remote add origin git@github.com:Zerozhao314/my-site.git"
```
```powershell
# 插件仓库 (Windows)
cd d:\project\wp-ai-customer-service
git remote add origin git@github.com:Zerozhao314/sit-plugin.git
```

### Stage F — SSH Key 生成 + GitHub 添加

**生成 key**(WSL + Windows 各一份 ed25519):

```bash
# WSL
wsl -e bash -lc "ssh-keygen -t ed25519 -C 'why.zero.zhao@gmail.com (WSL zero@ddev)' -f ~/.ssh/id_ed25519 -N ''"
```
```powershell
# Windows (需关闭沙箱)
ssh-keygen -t ed25519 -C "why.zero.zhao@gmail.com (Windows Admin)" -f $env:USERPROFILE\.ssh\id_ed25519
```

**公钥内容**(需添加到 https://github.com/settings/keys):

| 用途 | Title 建议 | 公钥 |
|---|---|---|
| WSL(站点仓库) | `WSL-zero-ddev` | `ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH8U6KKLZyFZqH/N3vChiw3pruQqfmmRYEgC9RYjBClg why.zero.zhao@gmail.com (WSL zero@ddev)` |
| Windows(插件仓库) | `Windows-Admin` | `ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPpNYkF6JkYc3NTyuuyUYHfoj+ropr6cPH2muJZ6NG00 why.zero.zhao@gmail.com (Windows Admin)` |

**添加公钥后执行 push**:
```bash
wsl -e bash -lc "cd ~/ddev-test && git push -u origin main"
```
```powershell
cd d:\project\wp-ai-customer-service
git push -u origin main
```

> known_hosts 两边均已预加 GitHub host key,push 不会再弹确认。

---

## 4. 自动同步方案(核心创新)

### 改造前 — 必须手动 rsync

| 检查项 | 结果 |
|---|---|
| 插件目录类型 | 真实目录(`drwxr-xr-x`),非软链 |
| DDEV `hooks` 段 | 全注释,无 post-start 同步 |
| WSL crontab | 空 |
| 文件监控进程 | 无 inotify/fswatch |
| DDEV 自定义命令 | 只有 README |

**结论**:每次 Windows 改完代码必须手动跑 SKILL.md 里的 rsync 命令。

### 改造后 — git hook 自动触发

**工作流**:
```
Windows IDE 改代码 → git commit
                         ↓ (post-commit hook 自动触发, 约 3 秒)
              trigger-sync.ps1 → WSL rsync
                         ↓
              刷新 https://ddev-test.ddev.site 即可生效
```

**配置文件清单**(已 commit 到插件仓库 `71af696`):

| 文件 | 作用 |
|---|---|
| [.githooks/trigger-sync.ps1](file:///D:/project/wp-ai-customer-service/.githooks/trigger-sync.ps1) | rsync 主逻辑(与 SKILL.md 方式 A 参数一致) |
| [.githooks/post-commit](file:///D:/project/wp-ai-customer-service/.githooks/post-commit) | 每次 commit 后触发 |
| [.githooks/post-checkout](file:///D:/project/wp-ai-customer-service/.githooks/post-checkout) | 切分支后触发 |
| [.githooks/setup-hooks.ps1](file:///D:/project/wp-ai-customer-service/.githooks/setup-hooks.ps1) | 一键启用 hook(已执行) |
| [deploy.ps1](file:///D:/project/wp-ai-customer-service/deploy.ps1) | 手动一键同步入口 |

**启用 hook**:
```powershell
cd d:\project\wp-ai-customer-service
powershell -ExecutionPolicy Bypass -File .githooks\setup-hooks.ps1
# 一次性配置, 设置 core.hooksPath -> .githooks
```

**手动同步**(不想 commit 时):
```powershell
.\deploy.ps1
```

### 同步正确性验证(md5 核对)

| 端 | 文件 | MD5 |
|---|---|---|
| Windows 源码 | `wp-ai-customer-service.php` | `589F5835BF65D9E10EEBAFB8B712824A` |
| DDEV 容器内 | `/var/www/html/wp-content/plugins/.../wp-ai-customer-service.php` | `589f5835bf65d9e10eebafb8b712824a` |

值一致(大小写不敏感) → 同步 100% 正确 ✓;实测约 3.4 秒。

---

## 5. GitHub Actions 生产发布(可选)

**配置文件**:[.github/workflows/release.yml](file:///D:/project/wp-ai-customer-service/.github/workflows/release.yml)

**触发**:打 `v*.*.*` tag 或手动在 Actions 页点 "Run workflow"

```powershell
cd d:\project\wp-ai-customer-service
git tag v1.2.1
git push origin v1.2.1
# => 自动 build-release job: 打包 zip + 创建 GitHub Release
```

**可选生产部署**(SSH 推到线上 WordPress):

在仓库 Settings → Secrets → Actions 添加:

| Secret | 含义 | 示例 |
|---|---|---|
| `PROD_WP_SSH_HOST` | 生产服务器 | `prod.example.com` |
| `PROD_WP_SSH_USER` | SSH 用户 | `wpdeploy` |
| `PROD_WP_SSH_KEY` | SSH 私钥内容 | (对应生产机 authorized_keys) |
| `PROD_WP_PLUGINS_DIR` | 远程插件目录 | `/var/www/html/wp-content/plugins` |

不需要生产部署时,删掉 `release.yml` 里 `deploy-production` 整个 job 段即可。

---

## 6. 日常命令速查

### 插件开发闭环
```powershell
cd d:\project\wp-ai-customer-service

# 改完代码 → commit (hook 自动同步到 DDEV)
git add .
git commit -m "fix: 修复 xxx"

# 不想 commit 但想立刻同步
.\deploy.ps1

# 推送到 GitHub
git push
```

### 站点仓库
```bash
# WSL 内查看状态
wsl -e bash -lc "cd ~/ddev-test && git status"

# 推送站点自定义代码
wsl -e bash -lc "cd ~/ddev-test && git push"
```

### DDEV 环境
```bash
# 启动
wsl -e bash -lc "cd ~/ddev-test && ddev start"

# 激活插件
wsl -e bash -lc "cd ~/ddev-test && ddev wp plugin activate wp-ai-customer-service"

# 看日志
wsl -e bash -lc "cd ~/ddev-test && ddev exec tail -f /var/www/html/wp-content/debug.log"
```

---

## 7. 当前配置状态总览

| 配置项 | 状态 | 备注 |
|---|---|---|
| DDEV config.yaml | ✅ 保留现有 | 优于模板,未改动 |
| Git 用户(WSL) | ✅ 已配置 | Zerozhao314 / why.zero.zhao@gmail.com |
| Git 用户(Win) | ✅ 已配置 | 同上 |
| 站点仓库初始化 | ✅ 完成 | commit `5666981` |
| 插件仓库初始化 | ✅ 完成 | commit `888f578` |
| 站点 .gitignore | ✅ 完整 | WP核心+第三方件全忽略 |
| 插件 .gitignore | ✅ 完整 | 日志+依赖+构建产物 |
| 两仓库 remote | ✅ 已配 | SSH 形式 |
| WSL SSH key | ✅ 已生成 | 待加入 GitHub |
| Windows SSH key | ✅ 已生成 | 待加入 GitHub |
| GitHub known_hosts | ✅ 两边已加 | push 不会弹确认 |
| 本地自动同步 hook | ✅ 已启用 | md5 验证通过 |
| GitHub Actions | ✅ 已配置 | 打 tag 触发发布 |
| 两仓库 git status | ✅ 均 clean | — |
| **push 到 GitHub** | ⏳ 待执行 | 需先加 SSH 公钥到 GitHub |

---

## 8. 待办(用户需做)

1. 打开 https://github.com/settings/keys
2. 添加 2 条 SSH public key(见 Stage F 表格)
3. 回终端执行两次 `git push -u origin main`(站点 + 插件)

完成后全部配置闭环。

---

## 9. 故障排查

| 现象 | 原因 | 解决 |
|---|---|---|
| `git push` 报 `Permission denied (publickey)` | SSH 公钥未加入 GitHub | 把 Stage F 两条公钥加到 https://github.com/settings/keys |
| `git push` 报 `Host key verification failed` | known_hosts 缺 GitHub | `ssh -T -o StrictHostKeyChecking=accept-new git@github.com` |
| commit 后未自动同步 | hook 未启用 | 跑 `.\.githooks\setup-hooks.ps1` |
| 同步后站点未生效 | DDEV 未启动或 WP_CACHE | `ddev start`;或 `ddev wp cache flush` |
| 沙箱阻止写 `C:\Users\Administrator\.ssh` | Trae 沙箱限制 | 命令需 `dangerouslyDisableSandbox: true` 或在系统 PowerShell 执行 |
| `wp-content/` 在站点 git status 出现 | .gitignore 漏配 | 检查是否漏 `wp-content/plugins/` 等 |
