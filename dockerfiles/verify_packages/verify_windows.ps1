# to run manually: docker run --rm -ti -v $pwd\..\..:c:\app -w c:\app chocolatey/choco:latest-windows powershell.exe .\dockerfiles\verify_packages\verify_windows.ps1

$ErrorActionPreference = 'Stop'

if (-not $env:ChocolateyInstall) { $env:ChocolateyInstall = 'C:\ProgramData\chocolatey' }
Import-Module $env:ChocolateyInstall\helpers\chocolateyProfile.psm1

# Chocolatey may already be installed without its bin directory on PATH.
$env:Path = "$env:ChocolateyInstall\bin;$env:Path"

choco install -y php
$phpInstallCode = $LASTEXITCODE
choco install -y 7zip
$sevenZipInstallCode = $LASTEXITCODE
refreshenv
# A feed error makes choco either exit 0 with "installed 0/0 packages" or exit 1 with "installed 0/1 packages"; treat a missing php or 7zip as infra (75 is retried) before propagating choco's exit codes.
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { Write-Host "php is not installed after choco install (feed failure?); exiting 75 so GitLab auto-retries"; exit 75 }
if (-not (Get-Command 7z -ErrorAction SilentlyContinue)) { Write-Host "7zip is not installed after choco install (feed failure?); exiting 75 so GitLab auto-retries"; exit 75 }
if ($phpInstallCode -ne 0) { exit $phpInstallCode }
if ($sevenZipInstallCode -ne 0) { exit $sevenZipInstallCode }

php build/packages/datadog-setup.php --php-bin=all --file=$(ls build/packages/dd-library-php-*-x86_64-windows.tar.gz)
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

# Check the installed integration, keeping its span until the assertion.
echo "<?php shell_exec('echo 1'); if (dd_trace_serialize_closed_spans()[0]['meta']['cmd.shell'] !== 'echo 1') { echo 'No ExecIntegration present?'; exit(1); } echo 'SUCCESS';" | php "-ddatadog.trace.cli_enabled=1" "-ddatadog.trace.generate_root_span=0" "-ddatadog.trace.auto_flush_enabled=0"
exit $LASTEXITCODE
