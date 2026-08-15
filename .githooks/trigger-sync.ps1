# 自动同步 Windows 插件源码到 WSL DDEV 站点 wp-content/plugins/wp-ai-customer-service
# 与 wp-ddev-quickstart SKILL.md "方式 A: rsync 增量同步" 参数完全一致
$ErrorActionPreference = "Continue"
$WIN_SRC = "d:\project\wp-ai-customer-service"
$WSL_DST = "~/ddev-test/wp-content/plugins/wp-ai-customer-service"

Write-Host "[sync] rsync $WIN_SRC -> WSL $WSL_DST" -ForegroundColor Cyan

# WSL 可访问 Windows 盘 /mnt/d/ ;用单行 rsync 避免换行解析歧义
$rsyncCmd = "rsync -av --delete --exclude '.git' --exclude 'tests/' --exclude '*.md' --exclude '*.zip' --exclude '*.tar.gz' --exclude '*.bak' --exclude 'logs/*.log' --exclude 'logs/*.archived' /mnt/d/project/wp-ai-customer-service/ ~/ddev-test/wp-content/plugins/wp-ai-customer-service/"

$output = & wsl -e bash -lc $rsyncCmd 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "[sync] OK" -ForegroundColor Green
    $output | Select-Object -Last 5 | ForEach-Object { Write-Host "  $_" }
} else {
    Write-Host "[sync] FAIL (exit=$LASTEXITCODE). 手动执行: wsl -e bash -lc `"$rsyncCmd`"" -ForegroundColor Red
    Write-Host ($output -join "`n")
    exit 1
}
