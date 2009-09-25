@echo.
@echo off

SET app=%0
SET lib=%~dp0

php -q "%lib%snap.php" %*

%lib%..\tmp\backup.bat

echo.
