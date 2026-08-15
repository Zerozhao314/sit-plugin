# 一键手动同步: 等价于 SKILL.md "方式 A: rsync 增量同步"
# 用法: 打开 PowerShell, cd d:\project\wp-ai-customer-service, 执行 .\deploy.ps1
$ErrorActionPreference = "Stop"
& "$PSScriptRoot\.githooks\trigger-sync.ps1"
