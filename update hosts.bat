@echo off
chcp 65001 >nul

:: 1. 自动检测并请求管理员权限 (UAC)
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo 正在请求管理员权限以修改 hosts 文件...
    powershell -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

set "HOSTS_PATH=%SystemRoot%\System32\drivers\etc\hosts"
set "BACKUP_PATH=%HOSTS_PATH%.bak"
set "NEW_ENTRY=127.0.0.1 shop.laravel.demo stock.laravel.demo recipe.laravel.demo"

:: 2. 备份原 hosts 文件
copy /y "%HOSTS_PATH%" "%BACKUP_PATH%" >nul

:: 3. 移除已存在的旧域名记录并写入新记录
powershell -NoProfile -Command ^
    "$path = '%HOSTS_PATH%';" ^
    "$content = Get-Content -Path $path -ErrorAction SilentlyContinue;" ^
    "$filtered = $content | Where-Object { $_ -notmatch '\b(shop|stock|recipe)\.laravel\.demo\b' };" ^
    "$filtered += '%NEW_ENTRY%';" ^
    "Set-Content -Path $path -Value $filtered -Encoding ASCII"

:: 4. 刷新 DNS 缓存使修改立即生效
ipconfig /flushdns >nul

echo.
echo ✅ 已成功更新 hosts 文件并刷新 DNS 缓存：
echo    %NEW_ENTRY%
echo.
pause
