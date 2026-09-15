@echo off
REM ============================================================================
REM FNLLA PROJECT LAUNCHER
REM File: fnlla.cmd
REM Purpose: Provides a Windows launcher for downstream-safe FNLLA project commands.
REM ============================================================================
setlocal DisableDelayedExpansion
php "%~dp0fnlla" %*
exit /b %errorlevel%
