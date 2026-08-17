#!/usr/bin/env bash
set -u
P='/home/u3031-tndf5e7xw51t/www/tontonkits.com/public_html/wp-content/plugins'

echo '--- 目录权限 ---'
ls -ld "$P"

echo
echo '--- 所有插件 ---'
ls -1 "$P" | head -30

echo
echo '--- 是否已有 wp-ai-customer-service ---'
if [ -d "$P/wp-ai-customer-service" ]; then
    echo '✅ 已存在'
    ls -la "$P/wp-ai-customer-service" | head -10
else
    echo '❌ 不存在 (首次部署)'
fi

echo
echo '--- 写权限测试 ---'
if touch "$P/.deploy-test-write-$$" 2>/dev/null; then
    echo '✅ 可写'
    rm -f "$P/.deploy-test-write-$$"
else
    echo '❌ 不可写'
fi

echo
echo '--- deploy key 指纹 ---'
ls -la ~/.ssh/id_ed25519_github_deploy ~/.ssh/id_ed25519_github_deploy.pub 2>/dev/null || echo 'deploy key 文件不存在'
ssh-keygen -lf ~/.ssh/id_ed25519_github_deploy.pub 2>/dev/null

echo
echo '--- /tmp 空间 ---'
df -h /tmp
