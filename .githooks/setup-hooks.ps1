# 一键启用 git hooks: 把当前仓库的 core.hooksPath 指向 .githooks/
# 执行一次永久生效(已存在 .git/config 覆盖 key)
Set-Location $PSScriptRoot\..
git config core.hooksPath .githooks
Write-Host "OK: git config core.hooksPath -> .githooks" -ForegroundColor Green
Write-Host "当前:"; git config --get core.hooksPath
Write-Host ""
Write-Host "下次 git commit / git checkout 时,将自动跑 trigger-sync.ps1 同步到 WSL DDEV 站点"
Write-Host "想手动立刻同步一次, 运行: powershell -File .githooks/trigger-sync.ps1"
