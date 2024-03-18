@echo off
if "%1"=="nts" (
    rd "/php"
    mklink /J "/php" "/php-nts"
) else if "%1"=="zts" (
    rd "/php"
    mklink /J "/php" "/php-zts"
) else (
    echo "%1 must be nts or zts"
)