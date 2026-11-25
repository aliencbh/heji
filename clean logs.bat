@echo off
:: 切换到当前批处理文件所在的目录
cd /d "%~dp0"

:: 检查是否存在 logs 文件夹
if exist "logs" (
    :: 删除 logs 文件夹下的所有文件（/f 强制删除 /s 包含子目录 /q 静默不提示）
    del /f /s /q "logs\*.*"
)

:: 退出程序
exit
