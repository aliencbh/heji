@echo off
chcp 65001 > nul
title Docker Desktop 一键清空工具

echo ==================================================
echo      正在连接并检查 Docker Desktop 状态...
echo ==================================================

:: 强制切换到 Windows Docker Desktop 上下文
docker context use desktop-linux > nul 2>&1
if errorlevel 1 (
    docker context use default > nul 2>&1
)

:: 检查 Docker 是否正在运行
docker info > nul 2>&1
if errorlevel 1 (
    echo [错误] 无法连接到 Docker Desktop！
    echo 请先启动 Docker Desktop，等待左下角引擎变为绿色后再运行此脚本。
    pause
    exit /b 1
)

for /f "tokens=*" %%i in ('docker context show') do set CURRENT_CTX=%%i
echo 当前连接的上下文: %CURRENT_CTX%
echo.

:: 1. 停止所有正在运行的容器
echo [1/4] 正在停止所有运行中的容器...
for /f "tokens=*" %%i in ('docker ps -q') do (
    docker stop %%i
)

:: 2. 强制删除所有容器
echo [2/4] 正在删除所有容器...
for /f "tokens=*" %%i in ('docker ps -aq') do (
    docker rm -f %%i
)

:: 3. 深度清理所有镜像、悬空网络和构建缓存
echo [3/4] 正在清理所有未使用的镜像和缓存...
docker system prune -a -f

:: 4. 兜底清理顽固或带有多个标签的残留镜像
echo [4/4] 正在检查并移除残留镜像...
for /f "tokens=*" %%i in ('docker images -aq') do (
    docker rmi -f %%i > nul 2>&1
)

echo.
echo ==================================================
echo      清理完成！当前剩余状态如下（均应为空）：
echo ==================================================
echo --- 剩余容器 ---
docker ps -a
echo.
echo --- 剩余镜像 ---
docker images
echo.
