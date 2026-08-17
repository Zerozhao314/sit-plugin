#!/usr/bin/env bash
# =============================================================================
# wp-ai-customer-service — 生产服务器部署初始化脚本 (一次性)
#
# 使用方式:
#   1. SSH 登录到生产服务器:
#      ssh -p 18765 u3031-tndf5e7xw51t@35.208.46.254
#
#   2. 粘贴本脚本全部内容, 或上传后执行:
#      curl -sL <RAW_URL> | bash
#      或者:  cat > /tmp/init-deploy-user.sh <<'SCRIPT_EOF'   (粘贴脚本全文)
#             SCRIPT_EOF
#             bash /tmp/init-deploy-user.sh
#
# 功能:
#   - 生成 GitHub Actions 专用 ed25519 密钥对 (~/.ssh/id_ed25519_deploy)
#   - 把公钥追加到 ~/.ssh/authorized_keys 并修复权限
#   - 自动探测 WordPress 绝对路径 (含 wp-content/plugins/)
#   - 输出 GitHub 需要配置的 Secret / Variable 最终清单 (你逐条复制到 GitHub)
#   - 自动检查 unzip / 磁盘空间 / 目录写权限
# =============================================================================
set -euo pipefail

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; BOLD=$'\033[1m'; RESET=$'\033[0m'
info()  { echo "${GREEN}[INFO]${RESET}  $*"; }
warn()  { echo "${YELLOW}[WARN]${RESET}  $*"; }
err()   { echo "${RED}[FAIL]${RESET}  $*" >&2; }
hr()    { printf -- '-%.0s' $(seq 1 72); echo ""; }

# =============================================================
# 0. 运行环境
# =============================================================
echo ""
hr
echo " ${BOLD}wp-ai-customer-service — 生产部署初始化脚本${RESET}"
hr
echo "  当前用户:  $(whoami)"
echo "  当前 HOME:  ${HOME}"
echo "  服务器 IP:  $(hostname -I 2>/dev/null | awk '{print $1}')"
echo "  Hostname:   $(hostname)"
echo "  日期:       $(date -Iseconds)"
echo ""

# =============================================================
# 1. 生成专用 SSH 密钥对
# =============================================================
KEY_NAME="id_ed25519_github_deploy"
KEY_PATH="${HOME}/.ssh/${KEY_NAME}"
KEY_PUB="${KEY_PATH}.pub"

mkdir -p "${HOME}/.ssh"
chmod 700 "${HOME}/.ssh"

if [ -f "${KEY_PATH}" ]; then
  warn "密钥已存在: ${KEY_PATH}  (不重新生成)"
else
  info "生成 ed25519 密钥对: ${KEY_PATH}"
  ssh-keygen -t ed25519 \
    -C "github-actions-deploy@sit-plugin ($(date +%Y-%m-%d))" \
    -f "${KEY_PATH}" \
    -N "" \
    >/dev/null
  chmod 600 "${KEY_PATH}"
  chmod 644 "${KEY_PUB}"
fi

# 确保 authorized_keys 存在且权限正确
AUTH_KEYS="${HOME}/.ssh/authorized_keys"
touch "${AUTH_KEYS}"
chmod 600 "${AUTH_KEYS}"

# 追加公钥 (去重)
PUB_CONTENT="$(cat "${KEY_PUB}")"
if grep -qxF "${PUB_CONTENT}" "${AUTH_KEYS}" 2>/dev/null; then
  info "authorized_keys 已经包含 deploy 公钥 (跳过)"
else
  echo "${PUB_CONTENT}" >> "${AUTH_KEYS}"
  info "deploy 公钥已追加到 authorized_keys"
fi

# 清理重复条目, 保持文件整洁 (保留顺序)
if command -v awk >/dev/null 2>&1; then
  awk '!seen[$0]++' "${AUTH_KEYS}" > "${AUTH_KEYS}.tmp" && mv "${AUTH_KEYS}.tmp" "${AUTH_KEYS}"
  chmod 600 "${AUTH_KEYS}"
fi

# 私钥内容(稍后要粘贴到 GitHub Secret: PROD_WP_SSH_KEY)
PRV_CONTENT="$(cat "${KEY_PATH}")"
info "密钥对已就绪。公钥指纹:"
ssh-keygen -l -f "${KEY_PATH}"
echo ""

# =============================================================
# 2. 自动探测 WordPress wp-content/plugins 绝对路径
# =============================================================
CANDIDATES=()
# 2.1 从当前用户 HOME 开始找 (共享主机常见 layout)
#    注意: 不用 bash 进程替换 <(...) (某些共享主机 shell 不支持 /dev/fd)
FIND_CANDIDATES_TMP="$(mktemp)"
FIND_WP_TMP="$(mktemp)"
trap 'rm -f "${FIND_CANDIDATES_TMP}" "${FIND_WP_TMP}"' EXIT

for base in "${HOME}" "/var/www/vhosts" "/home/vhosts" "/var/www"; do
  if [ -d "${base}" ]; then
    # 快速定位: 同时存在 wp-content/plugins + wp-settings.php = WP root
    find "${base}" -maxdepth 7 -type d -name plugins -path "*/wp-content/*" 2>/dev/null \
      | head -20 >> "${FIND_CANDIDATES_TMP}" || true
    # wp-config.php 探测 (后面单独用)
    find "${base}" -maxdepth 7 -type f -name "wp-config.php" 2>/dev/null \
      | head -20 >> "${FIND_WP_TMP}" || true
  fi
done

# 用 while-read 处理 (兼容 sh/bash/dash)
while IFS= read -r plugin_dir; do
  [ -z "${plugin_dir:-}" ] && continue
  wp_root="$(dirname "$(dirname "${plugin_dir}")")"
  if [ -f "${wp_root}/wp-settings.php" ]; then
    CANDIDATES+=("${plugin_dir}")
  fi
done < "${FIND_CANDIDATES_TMP}"

# 2.2 另外: 找 wp-config.php 再推导 (某些主题 WP root 不等于 plugins 上级)
while IFS= read -r wp_config; do
  [ -z "${wp_config:-}" ] && continue
  wp_root="$(dirname "${wp_config}")"
  if [ -d "${wp_root}/wp-content/plugins" ]; then
    CANDIDATES+=("${wp_root}/wp-content/plugins")
  fi
done < "${FIND_WP_TMP}"

# 去重
PLUGIN_DIRS=()
if [ ${#CANDIDATES[@]} -gt 0 ]; then
  readarray -t PLUGIN_DIRS < <(printf "%s\n" "${CANDIDATES[@]}" | sort -u)
fi

hr
info "WordPress 插件目录探测结果:"
if [ ${#PLUGIN_DIRS[@]} -eq 0 ]; then
  warn "自动探测未找到。请手动填写 PROD_WP_PLUGINS_DIR。"
  PROD_WP_PLUGINS_DIR="(手动填写, 格式: /home/.../wp-content/plugins)"
else
  # 取第一个候选 (若有多个, 下面会列出供你确认)
  PROD_WP_PLUGINS_DIR="${PLUGIN_DIRS[0]}"
  for i in "${!PLUGIN_DIRS[@]}"; do
    d="${PLUGIN_DIRS[$i]}"
    # 写权限检查
    if [ -w "${d}" ]; then w_tag="${GREEN}WRITABLE${RESET}"; else w_tag="${RED}NOT WRITABLE${RESET}"; fi
    echo "   [$((i+1))] ${d}   [${w_tag}]"
  done
  if [ ${#PLUGIN_DIRS[@]} -gt 1 ]; then
    warn "探测到多个插件目录。默认选 [1], 请核对后决定是否替换。"
  fi
fi

# =============================================================
# 3. 部署前置检查
# =============================================================
hr
info "部署前置检查:"

checks_passed=0
checks_total=5

# 3.1 unzip
if command -v unzip >/dev/null 2>&1; then
  echo "   [OK ] unzip: $(unzip -v 2>&1 | head -1 | awk '{print $2, $3}')"
  ((checks_passed+=1))
else
  err "unzip 命令不存在 — GitHub Actions deploy 会失败"
  echo "       Ubuntu/Debian: sudo apt-get install -y unzip"
  echo "       CentOS/RHEL:   sudo yum install -y unzip"
  echo "       共享主机面板:   找 'SSH 工具/扩展' 或联系主机商安装"
fi

# 3.2 磁盘空间 /tmp
avail_kb="$(df -Pk /tmp 2>/dev/null | awk 'NR==2 {print $4}')"
if [ -n "${avail_kb}" ] && [ "${avail_kb}" -gt 102400 ]; then
  echo "   [OK ] /tmp 可用空间: $((avail_kb / 1024)) MB"
  ((checks_passed+=1))
else
  warn "/tmp 可用 < 100MB, 部署时 zip 可能写不下 (建议清理)"
fi

# 3.3 PROD_WP_PLUGINS_DIR 存在 + 写
if [ -n "${PROD_WP_PLUGINS_DIR}" ] && [ "${PROD_WP_PLUGINS_DIR:0:1}" = "/" ] && [ -d "${PROD_WP_PLUGINS_DIR}" ]; then
  if [ -w "${PROD_WP_PLUGINS_DIR}" ]; then
    echo "   [OK ] PROD_WP_PLUGINS_DIR 存在 + 可写"
    ((checks_passed+=1))
  else
    err "PROD_WP_PLUGINS_DIR 存在但无写权限。请修复目录权限:"
    echo "       chmod 775 '${PROD_WP_PLUGINS_DIR}'"
    echo "       当前 owner=$(stat -c '%U:%G' "${PROD_WP_PLUGINS_DIR}" 2>/dev/null) 权限=$(stat -c '%a' "${PROD_WP_PLUGINS_DIR}" 2>/dev/null)"
  fi
else
  warn "PROD_WP_PLUGINS_DIR 无效或未找到, 部署前必须修正"
fi

# 3.4 磁盘总空间
WP_AVAIL_KB="$(df -Pk "${PROD_WP_PLUGINS_DIR:-/}" 2>/dev/null | awk 'NR==2 {print $4}')"
if [ -n "${WP_AVAIL_KB}" ] && [ "${WP_AVAIL_KB}" -gt 204800 ]; then
  echo "   [OK ] 插件所在盘可用: $((WP_AVAIL_KB / 1024)) MB (> 200MB)"
  ((checks_passed+=1))
else
  warn "插件盘可用 < 200MB, 部署可能失败"
fi

# 3.5 PHP / WP CLI 可选 (方便后续 wp plugin activate)
WP_CLI_AVAILABLE=false
if command -v wp >/dev/null 2>&1; then
  WP_CLI_AVAILABLE=true
  echo "   [OK ] wp-cli 可用 (部署后可自动激活插件)"
  ((checks_passed+=1))
else
  warn "wp-cli 未安装 (非必需, 只影响自动激活。插件文件到位后手动在 WP 后台激活即可)"
fi

echo ""
echo "   通过: ${checks_passed} / ${checks_total}"
echo ""

# =============================================================
# 4. 输出 GitHub Secrets/Vars 清单 (最终)
# =============================================================
hr
echo ""
echo " ${BOLD}=== 下一步: 把以下内容复制到 GitHub ===${RESET}"
echo ""
echo " GitHub:  https://github.com/Zerozhao314/sit-plugin/settings"
echo ""
echo " ${BOLD}Variables${RESET} (Settings → Secrets and variables → Actions → Variables):"
echo ""
echo "   ┌─────────────────────┬──────────────────────────────────────────────────┐"
echo "   │ Name                │ Value                                            │"
echo "   ├─────────────────────┼──────────────────────────────────────────────────┤"
echo "   │ ENABLE_PROD_DEPLOY  │ true                                             │"
echo "   └─────────────────────┴──────────────────────────────────────────────────┘"
echo ""
echo " ${BOLD}Secrets${RESET} (Settings → Secrets and variables → Actions → Secrets):"
echo ""
echo "   ┌─────────────────────┬──────────────────────────────────────────────────┐"
echo "   │ Name                │ Value                                            │"
echo "   ├─────────────────────┼──────────────────────────────────────────────────┤"
printf "   │ PROD_WP_SSH_HOST    │ %-48s │\n" "35.208.46.254"
printf "   │ PROD_WP_SSH_PORT    │ %-48s │\n" "18765"
printf "   │ PROD_WP_SSH_USER    │ %-48s │\n" "$(whoami)"
echo "   │ PROD_WP_SSH_KEY     │ 粘贴下方 PRIVATE KEY 整块内容(含上下两行吗)    │"
printf "   │ PROD_WP_PLUGINS_DIR │ %-48s │\n" "${PROD_WP_PLUGINS_DIR}"
echo "   └─────────────────────┴──────────────────────────────────────────────────┘"
echo ""

echo " ${BOLD}=== 1) PROD_WP_SSH_KEY (私钥, 直接复制下面整块, 别少 BEGIN/END 行) ===${RESET}"
echo "-----BEGIN OPENSSH PRIVATE KEY----- 起, -----END... 止, 全部复制到 GitHub Secret-----"
echo "${PRV_CONTENT}"
echo "-----END 结束-----------------------------------------------------------------"
echo ""

echo " ${BOLD}=== 2) authorized_keys 已追加的公钥(仅核对用, 不用再操作) ===${RESET}"
echo "${PUB_CONTENT}"
echo ""

# =============================================================
# 5. 可选 production 环境
# =============================================================
echo " ${BOLD}=== 3) (强烈推荐) 创建 production 环境 + 审批人 ===${RESET}"
echo "   GitHub → Settings → Environments → New environment"
echo "   环境名: production"
echo "   勾选: Required reviewers → 添加你自己 Zerozhao314"
echo "   → Save"
echo ""

# =============================================================
# 6. 一键本地联调命令
# =============================================================
echo " ${BOLD}=== 4) 验证: 从你的 Windows 测一遍 SSH 能否正常登录(无密码) ===${RESET}"
echo "   (用服务器新生成的私钥文件 ${KEY_PATH} 的内容复制到本地一个文件后执行)"
echo ""
echo "   ssh -i C:\\Users\\Administrator\\.ssh\\id_ed25519_github_deploy -p 18765 -o IdentitiesOnly=yes \\"
echo "       $(whoami)@35.208.46.254 'echo SSH_OK' "
echo ""
echo "   若成功: 部署测试 tag v1.0.0-rc.1"
echo "      cd d:\\project\\wp-ai-customer-service"
echo "      git tag v1.0.0-rc.1"
echo "      git push origin v1.0.0-rc.1"
echo "      # 打开 https://github.com/Zerozhao314/sit-plugin/actions 观察进度"
echo ""

# 保存产物到本地文件
OUT_DIR="${HOME}/deploy-config-$(date +%Y%m%d-%H%M%S)"
mkdir -p "${OUT_DIR}"
echo "${PRV_CONTENT}"  > "${OUT_DIR}/PROD_WP_SSH_KEY.txt"
echo "${PUB_CONTENT}"  > "${OUT_DIR}/authorized_keys_line.txt"
cat > "${OUT_DIR}/github-config.txt" <<EOF
# GitHub Secrets/Vars — $(date -Iseconds)
# 仓库: Zerozhao314/sit-plugin

[Repository Variables]
ENABLE_PROD_DEPLOY=true

[Repository Secrets]
PROD_WP_SSH_HOST=35.208.46.254
PROD_WP_SSH_PORT=18765
PROD_WP_SSH_USER=$(whoami)
PROD_WP_SSH_KEY=见 PROD_WP_SSH_KEY.txt (私钥整块内容)
PROD_WP_PLUGINS_DIR=${PROD_WP_PLUGINS_DIR}
EOF
chmod 600 "${OUT_DIR}/PROD_WP_SSH_KEY.txt"

hr
info "配置产物已保存到: ${OUT_DIR}/"
ls -la "${OUT_DIR}/"
hr
warn "安全提醒: 上面的私钥泄露=任何人能登录你的服务器 SSH。"
warn "   粘贴到 GitHub Secret 后, 请 rm -rf ${OUT_DIR} 并清空剪贴板。"
warn "   密钥不要 commit 到任何 git 仓库。"
hr

if [ "${checks_passed}" -ge 3 ]; then
  echo "${GREEN}${BOLD}初始化完成, 可以去 GitHub 粘贴 Secrets/Vars 了。${RESET}"
else
  echo "${YELLOW}${BOLD}请先修复上面的 FAIL 项, 否则首次部署会报错。${RESET}"
fi
echo ""
