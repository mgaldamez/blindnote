@echo off
title BlindNote - servidor local
cd /d "%~dp0"

echo ============================================================
echo   BlindNote  -  servidor de desarrollo
echo ============================================================
echo.
echo   URL:  http://localhost:8099/
echo   (Cierra esta ventana para detener el servidor)
echo.

REM Abre el navegador 2 segundos despues de arrancar
start "" cmd /c "timeout /t 2 >nul & start http://localhost:8099/"

REM Inicia el servidor embebido de PHP usando la carpeta actual
php -S localhost:8099 -t "%~dp0"

pause
