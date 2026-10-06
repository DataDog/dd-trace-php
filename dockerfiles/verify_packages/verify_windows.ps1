# to run manually: docker run --rm -ti -v $pwd\..\..:c:\app -w c:\app chocolatey/choco:latest-windows powershell.exe .\dockerfiles\verify_packages\verify_windows.ps1

$ErrorActionPreference = 'Stop'

if (-not $env:ChocolateyInstall) { $env:ChocolateyInstall = 'C:\ProgramData\chocolatey' }
Import-Module $env:ChocolateyInstall\helpers\chocolateyProfile.psm1

# Chocolatey may already be installed without its bin directory on PATH.
$env:Path = "$env:ChocolateyInstall\bin;$env:Path"

choco install -y php
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
choco install -y 7zip
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
refreshenv

php build/packages/datadog-setup.php --php-bin=all --file=$(ls build/packages/dd-library-php-*-x86_64-windows.tar.gz)
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

# Check the installed integration, keeping its span until the assertion.
echo "<?php shell_exec('echo 1'); if (dd_trace_serialize_closed_spans()[0]['attributes']['cmd.shell'] !== 'echo 1') { echo 'No ExecIntegration present?'; exit(1); } echo 'SUCCESS';" | php "-ddatadog.trace.cli_enabled=1" "-ddatadog.trace.generate_root_span=0" "-ddatadog.trace.auto_flush_enabled=0"
exit $LASTEXITCODE
