@echo off
setlocal EnableExtensions DisableDelayedExpansion
if /i not "%~1"=="maximized" (
    start "" /max "%~f0" maximized
    exit /b
)

chcp 936 >nul
pushd "%~dp0" || exit /b 1

if not defined PGADMIN_DEFAULT_EMAIL set "PGADMIN_DEFAULT_EMAIL=pgadmin@laravel.demo"
if not defined PGADMIN_DEFAULT_PASSWORD set "PGADMIN_DEFAULT_PASSWORD=password"

:input_env
set "mode=dev"
set /p "mode=请输入运行环境 (prod/dev，直接回车使用 dev): "
setlocal EnableDelayedExpansion
if /i "!mode!"=="prod" goto selected_prod
if /i "!mode!"=="dev" goto selected_dev
endlocal
echo 请输入 prod 或 dev。
goto input_env

:selected_prod
endlocal
set "mode=prod"
goto start_flow

:selected_dev
endlocal
set "mode=dev"

:start_flow
echo 正在准备 Docker 运维容器...
docker compose --env-file ".env.example" -f "compose.setup.yaml" run --rm -T --build ops init
if errorlevel 1 goto failed

echo 正在修复日志目录权限...
docker compose --env-file ".env.example" -f "compose.setup.yaml" run --rm -T --entrypoint sh --no-deps ops -c "mkdir -p /srv/heji/var/logs/php /srv/heji/var/logs/nginx /srv/heji/var/logs/postgres /srv/heji/var/logs/redis /srv/heji/var/logs/runtime /srv/heji/var/logs/pgadmin /srv/heji/var/logs/npm /srv/heji/var/logs/tests; chown -R 999:999 /srv/heji/var/logs/redis /srv/heji/var/logs/postgres; chmod -R 777 /srv/heji/var/logs"
if errorlevel 1 goto failed

if /i "%mode%"=="prod" goto release
rem 实际部署环境文件由 ops 读取，默认为 /srv/heji/.env。
rem 验证时使用发布配置和开发叠加文件，避免在切换版本后才发现配置错误。
call :dev_compose config --quiet
if errorlevel 1 goto failed

:release
echo 正在按项目发布流程构建、测试并启动三个网站...
docker compose --env-file ".env.example" -f "compose.setup.yaml" run --rm -T --no-deps ops release
if errorlevel 1 goto failed

if /i "%mode%"=="prod" goto success
echo 正在启动开发工具...
call :dev_compose up -d --wait --no-deps nginx-proxy-manager pgadmin
if errorlevel 1 goto failed

:success
start "" "https://shop.laravel.demo/"
popd
pause
exit /b 0

:failed
set "rc=%errorlevel%"
echo 操作失败，请查看上方错误信息。首次部署请按 README 初始化环境并配置证书。
popd
pause
exit /b %rc%

:dev_compose
rem /project 是 ops 容器中的源码根目录。PGADMIN 变量可来自 Windows 环境或部署环境文件。
docker compose --env-file ".env.example" -f "compose.setup.yaml" run --rm -T --no-deps -e PGADMIN_DEFAULT_EMAIL -e PGADMIN_DEFAULT_PASSWORD ops compose -f /project/compose-dev.yml %*
exit /b %errorlevel%