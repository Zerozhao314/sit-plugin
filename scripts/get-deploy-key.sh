#!/usr/bin/env bash
# 读取 deploy 私钥 / 公钥, 方便复制到 GitHub
echo '=== deploy 公钥指纹 ==='
ssh-keygen -lf ~/.ssh/id_ed25519_github_deploy.pub

echo
echo '=== deploy 公钥 (authorized_keys 追加用, 已自动追加) ==='
cat ~/.ssh/id_ed25519_github_deploy.pub

echo
echo '=== deploy 私钥 (完整 PEM, 请复制整块到 GitHub Secret PROD_WP_SSH_KEY) ==='
cat ~/.ssh/id_ed25519_github_deploy

echo
echo '=== 私钥结束 ==='
