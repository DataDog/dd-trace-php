# to run manually: docker run --rm -ti -v $pwd\..\..:c:\app -w c:\app chocolatey/choco:latest-windows powershell.exe .\dockerfiles\verify_packages\verify_windows.ps1

if (-not $env:ChocolateyInstall) { $env:ChocolateyInstall = 'C:\ProgramData\chocolatey' }
Import-Module $env:ChocolateyInstall\helpers\chocolateyProfile.psm1

# install.ps1 only adds choco to the session PATH on a FRESH install. On runners where
# Chocolatey is already present it short-circuits, leaving `choco` unresolvable in this
# session. Prepend the known bin dir so the command resolves regardless of runner state.
$env:Path = "$env:ChocolateyInstall\bin;$env:Path"

choco install -y php
choco install -y 7zip
refreshenv

php build/packages/datadog-setup.php --php-bin=all --file=$(ls build/packages/dd-library-php-*-x86_64-windows.tar.gz)

# Check source directories present by triggering an integration
echo "<?php shell_exec('echo 1'); if (dd_trace_serialize_closed_spans()[0]['meta']['cmd.shell'] !== 'echo 1') { echo 'No ExecIntegration present?'; exit(1); } echo 'SUCCESS';" | php "-ddatadog.trace.cli_enabled=1" "-ddatadog.trace.generate_root_span=0"