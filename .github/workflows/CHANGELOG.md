# GitHub Actions Workflow 变更说明

> 仓库:`Zerozhao314/sit-plugin`(私有仓库)
> 工作流:`release.yml` — 打 tag 自动打包插件 zip + 创建 Release + 可选生产部署
> 变更日期:2026-08-16
> 触发 commit: `5750f3c`

---

## 本次变更总结

发现并修复 **4 个问题**,额外优化 **1 项**(`deploy-production` 重复打包消除):

| # | 问题 | 影响范围 | 修复后状态 |
|---|---|---|---|
| 1 | `workflow_dispatch` 手动触发不创建 Release | build-release job | ✅ 已修复 + 加 draft 模式 |
| 2 | 未配置 `production` 环境时 deploy job 卡住/失败 | deploy-production job | ✅ 已修复 (新增开关 variable) |
| 3 | 私有仓库 SSH 端 `curl` 下载 Release 返回 404 | deploy-production job | ✅ 已修复 (gh CLI + token) |
| 4 | zip 排除模式 `*.git/*` 不精确 | build-release job | ✅ 已修复 (改为 `.git/*`) |
| + | `deploy-production` 重新打包 zip(两次打包不一致风险) | deploy-production job | ✅ 优化 (改为从 Release 下载) |

---

## 1. 触发规则验证(Tag Pattern)

### 当前配置

```yaml
on:
  push:
    tags: [ "v*.*.*" ]     # 语义化版本 tag 触发
  workflow_dispatch:       # Actions 页手动 Run workflow 触发
```

### 各种 tag 匹配验证

| Tag 示例 | 是否触发 | 原因 |
|---|---|---|
| `v1.0.0` | ✅ 触发 | 标准语义化版本 |
| `v1.2.3` | ✅ 触发 | 标准语义化版本 |
| `v1.2.3-beta.1` | ✅ 触发 | 预发布版本,最后 `*` 匹配 `3-beta.1` |
| `v1.2.3-rc.1` | ✅ 触发 | Release Candidate |
| `v1.2.3-hotfix` | ✅ 触发 | 热修复后缀 |
| `v1.0` | ❌ 不触发 | 只有 1 个点,需要 `X.Y.Z` 三段 |
| `v1` | ❌ 不触发 | 只有版本主号 |
| `1.2.3` | ❌ 不触发 | 缺少 `v` 前缀 |
| `release/1.2.3` | ❌ 不触发 | 分支 tag,非 `v*.*.*` 模式 |
| `v1.2.3.4` | ⚠️ 触发(非预期但无害) | 最后 `*` 贪婪匹配 `3.4`;实际不会有这种 tag |

### 结论

触发规则 **正确**。能覆盖语义化版本与预发布版本,是业界主流的 tag 模式。

---

## 2. 问题修复详情

### 问题 1:workflow_dispatch 手动触发不创建 Release

**影响**:在 GitHub Actions 页手动点 "Run workflow" 时,workflow 会跑完打包 zip 这一步,但因为 `startsWith(github.ref, 'refs/tags/')` 为 false(手动触发 ref 是 `refs/heads/main`),Release 创建步骤被跳过。

**修复前**([release.yml:36, 修复前版本](file:///D:/project/wp-ai-customer-service/.github/workflows/release.yml#L36)):

```yaml
- name: 创建 GitHub Release 并上传 zip
  uses: softprops/action-gh-release@v2
  if: startsWith(github.ref, 'refs/tags/')
```

**修复后**(当前版本 L36-L42):

```yaml
- name: 创建 GitHub Release 并上传 zip
  uses: softprops/action-gh-release@v2
  # 修复问题 1: tag 触发 或 手动触发(workflow_dispatch)都创建 Release
  if: startsWith(github.ref, 'refs/tags/') || github.event_name == 'workflow_dispatch'
  with:
    files: dist/wp-ai-customer-service.zip
    generate_release_notes: true
    # 手动触发时若没有对应 tag, 生成一个 draft release 供人工发布
    draft: ${{ github.event_name == 'workflow_dispatch' }}
```

**设计说明**:
- 手动触发时自动使用 `draft: true`,生成 **Draft Release**,不会被误发布
- tag 触发时 `draft` 自动为 false,立即发布正式 Release

**触发矩阵**:

| 触发方式 | 是否创建 Release | 是否 Draft |
|---|---|---|
| `git tag v1.2.1 && git push` | ✅ 创建 | ❌ 正式发布 |
| Actions 页点 "Run workflow" | ✅ 创建 | ✅ Draft(人工确认后发布) |

---

### 问题 2:未配置 production 环境时 deploy-production job 卡住

**影响**:即使不打算使用生产部署,只要打了 tag,`deploy-production` job 就会进入 `Waiting for environment` 状态;如果仓库没创建 `production` 环境,job 直接报 `Environment production was not found` 失败,让 Release 流程看起来有红色 X。

**修复前**(原 L51-L53):

```yaml
deploy-production:
  needs: build-release
  if: startsWith(github.ref, 'refs/tags/')    # ← 只要打 tag 就触发,不关心是否配置了环境
  runs-on: ubuntu-latest
  environment: production
```

**修复后**(当前 [release.yml:L58-L63](file:///D:/project/wp-ai-customer-service/.github/workflows/release.yml#L58-L63)):

```yaml
deploy-production:
  needs: build-release
  # 修复问题 2: 用 repository variable 控制开关, 默认不运行(不卡在 environment)
  if: startsWith(github.ref, 'refs/tags/') && vars.ENABLE_PROD_DEPLOY == 'true'
  runs-on: ubuntu-latest
  environment: production
```

**启用方式**:
在 GitHub 仓库 `Settings → Secrets and variables → Actions → Variables`(是 Variables,不是 Secrets)新增:

| Key | 值 |
|---|---|
| `ENABLE_PROD_DEPLOY` | `true` |

同时还需要配置 Secrets:

| Key | 说明 | 示例 |
|---|---|---|
| `PROD_WP_SSH_HOST` | 生产服务器地址 | `prod.example.com` |
| `PROD_WP_SSH_USER` | SSH 用户名 | `wpdeploy` |
| `PROD_WP_SSH_KEY` | SSH 私钥(PEM 内容) | 对应服务器 `~/.ssh/authorized_keys` 中的公钥 |
| `PROD_WP_PLUGINS_DIR` | WordPress 插件目录绝对路径 | `/var/www/html/wp-content/plugins` |

还需要在 `Settings → Environments` 创建名为 `production` 的环境(可配置 Required reviewers 审批,避免误部署):

| 触发条件 | job 状态 |
|---|---|
| 未设置 `ENABLE_PROD_DEPLOY`(默认) | 🟩 自动 Skipped(不出现红叉) |
| 设置 `ENABLE_PROD_DEPLOY=true` + 配置齐全 Secrets + Environment | 🟩 正常运行 |
| 设置了开关但缺 Secrets | 🟥 部署时失败(在 `scp` / `ssh` step 失败) |

---

### 问题 3:私有仓库 SSH 端 curl 下载 Release 返回 404(🔴 高危)

这是本次修复最关键的问题。**仓库私有**时,Release 资产的直接 URL 未经认证访问会返回 404,原流程 `curl` 会失败,生产部署直接被堵住。

**修复前**:原脚本在生产服务器 SSH 端执行 `curl` 下载 Release zip:

```bash
# ← 原方案:SSH 到生产服务器,在生产服务器外网 curl GitHub Release 资产
# ← 仓库私有:这个 URL 返回 404,没有任何认证头
TAG="${GITHUB_REF_NAME}"
RELEASE_URL="https://github.com/${GITHUB_REPOSITORY}/releases/download/${TAG}/wp-ai-customer-service.zip"
curl -fsSL -o "${TMP_ZIP}" "${RELEASE_URL}"
# ↑ 私有仓库 → HTTP 404 → curl: (22) The requested URL returned error: 404
```

**修复后**(当前 [release.yml:L65-L105](file:///D:/project/wp-ai-customer-service/.github/workflows/release.yml#L65-L105)):

采用 **三段式** 流程(最安全):

```
① GitHub Actions Runner 用 gh CLI + GITHUB_TOKEN(自动注入) 从 Release 下载 zip
   👉 token 只在 GitHub 自有 Runner 上,不会泄露
      ↓
② SCP 从 Runner → 生产服务器传 zip
   👉 生产服务器只需能从 Runner 接收 SSH/SCP,不需公网访问 GitHub
      ↓
③ SSH 到生产服务器 unzip 部署
   👉 生产服务器执行纯本地操作,无外网依赖
```

**三个 step 代码**:

Step ① — Runner 端 gh CLI 下载:
```yaml
- name: 从 GitHub Release 下载 zip (带认证, 支持私有仓库)
  env:
    GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}   # Actions 自动生成,无需手配
  run: |
    TAG="${GITHUB_REF_NAME}"
    gh release download "${TAG}" \
      --repo "${GITHUB_REPOSITORY}" \
      --pattern "wp-ai-customer-service.zip" \
      --dir /tmp
    ls -lh /tmp/wp-ai-customer-service.zip
```

Step ② — SCP 传到生产服务器 `/tmp`:
```yaml
- name: 通过 SCP 上传 zip 到生产服务器
  uses: appleboy/scp-action@v0.1.7
  with:
    host:     ${{ secrets.PROD_WP_SSH_HOST }}
    username: ${{ secrets.PROD_WP_SSH_USER }}
    key:      ${{ secrets.PROD_WP_SSH_KEY }}
    port:     22
    source: /tmp/wp-ai-customer-service.zip
    target: /tmp
```

Step ③ — 生产服务器 unzip(纯本地,不依赖外网):
```yaml
- name: SSH 解压并部署到 WordPress 插件目录
  uses: appleboy/ssh-action@v1.2.0
  with:
    host / username / key / port ...
    script_stop: true
    script: |
      set -euo pipefail
      PLUGINS_DIR="${{ secrets.PROD_WP_PLUGINS_DIR }}"
      TMP_ZIP="/tmp/wp-ai-customer-service.zip"
      rm -rf "${PLUGINS_DIR}/wp-ai-customer-service"
      unzip -q -o "${TMP_ZIP}" -d "${PLUGINS_DIR}/wp-ai-customer-service"
      rm -f "${TMP_ZIP}"
```

**额外好处**:
- **安全**:生产服务器不再需要 GitHub 公网访问 / 不再持有 token
- **一致性**:部署的 zip = Release 页面用户下载的 zip,100% 一致
- **可追溯**:每次部署都是明确的 Release 版本

---

### 问题 4:zip 排除模式 `*.git/*` 不精确

**影响**:`*` 放在前面会匹配任意路径段,例如一个叫 `myrepo.git/` 的目录也会被排除(虽然项目里实际不会存在这种名字,但 glob 模式语义应精确)。

**修复前**:
```bash
zip -r dist/wp-ai-customer-service.zip . \
  -x "*.git/*" \   # ← 前导 * 过于宽泛
  ...
```

**修复后**(当前 [release.yml:L21-L30](file:///D:/project/wp-ai-customer-service/.github/workflows/release.yml#L21-L30)):
```bash
zip -r dist/wp-ai-customer-service.zip . \
  -x ".git/*" \    # ← 精确匹配项目根的 .git 目录
  ...
```

---

## 3. 额外优化:消除 deploy-production 重复打包

| 项目 | 修复前 | 修复后 |
|---|---|---|
| 打包次数 | build-release 打一次 + deploy-production 再打一次 = 2 次 | build-release 打一次 + deploy-production 从 Release 下载 = 0 次重复 |
| 一致性风险 | 两次打包期间如有 checkout 差异,zip 内容可能不一致 | 部署的 zip 与 Release 资产 100% 相同 |
| 运行耗时 | 多花 10-30 秒 | 省掉一次 10-30 秒打包 |

---

## 4. 完整修复后执行矩阵

| 场景 | build-release | deploy-production |
|---|---|---|
| 打 tag `v1.2.1`,未配置生产部署 | ✅ 创建正式 Release + 上传 zip | 🟩 Skipped |
| 打 tag `v1.2.1`,配置了 `ENABLE_PROD_DEPLOY=true` + 所有 Secrets | ✅ 创建正式 Release + 上传 zip | ✅ 执行三段式部署(Runner 下载 → SCP → SSH unzip) |
| Actions 页手动 Run workflow | ✅ 创建 **Draft** Release + 上传 zip(无 tag 时 draft 更安全) | 🟩 Skipped(仅 tag 触发部署) |
| 普通 push 到 main(非 tag) | 🟩 Skipped | 🟩 Skipped |
| PR 合并 | 🟩 Skipped | 🟩 Skipped |

---

## 5. 文件清单

| 文件 | 路径 | 说明 |
|---|---|---|
| 工作流(当前) | [release.yml](file:///D:/project/wp-ai-customer-service/.github/workflows/release.yml) | 修复后的 106 行版本 |
| 工作流备份 | [release.yml.bak](file:///D:/project/wp-ai-customer-service/.github/workflows/release.yml.bak) | 与当前 release.yml 相同的快照,便于回滚对比 |
| 变更说明 | [CHANGELOG.md](file:///D:/project/wp-ai-customer-service/.github/workflows/CHANGELOG.md) | 本文档 |

**回滚方法**(如需回到修复前状态):

```bash
git revert 5750f3c
git push
# 或直接用备份覆盖
cp .github/workflows/release.yml.bak .github/workflows/release.yml
git add .github/workflows/release.yml
git commit -m "revert: 回滚 workflow 修复"
```

---

## 6. 验证流程(打 tag 实测建议)

上线前可用预发布 tag 跑一次完整流程:

```powershell
cd d:\project\wp-ai-customer-service

# 1. 先手动触发一次验证打包 + draft Release
#    浏览器: https://github.com/Zerozhao314/sit-plugin/actions → release workflow → Run workflow

# 2. 打预发布 tag(不会影响生产)
git tag v1.2.1-beta.1
git push origin v1.2.1-beta.1

# 3. 查看构建结果
#    https://github.com/Zerozhao314/sit-plugin/actions
#    build-release 应成功创建 Release
#    deploy-production 应 Skipped(未配置 ENABLE_PROD_DEPLOY)
```

---

## 7. 当前工作流版本信息

- **触发 commit**:`5750f3c` (`fix(ci): 修复 workflow 4 个问题`)
- **工作流名称**:`Release wp-ai-customer-service Plugin`
- **jobs**:
  1. `build-release` — checkout + zip + softprops Release(15~30s)
  2. `deploy-production`(可选,默认 Skipped)— gh 下载 → SCP 上传 → SSH unzip(15~60s)
- **permissions**:`contents: write`(Release 创建需要)
- **runs-on**:`ubuntu-latest`
- **主要外部 Actions**:
  - `actions/checkout@v4`
  - `softprops/action-gh-release@v2`
  - `appleboy/scp-action@v0.1.7`
  - `appleboy/ssh-action@v1.2.0`
