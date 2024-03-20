# to run manually: docker run --rm -ti -v $pwd\..\..:c:\app -w c:\app chocolatey/choco:latest-windows powershell.exe .\dockerfiles\verify_packages\verify_windows.ps1

Import-Module $env:ChocolateyInstall\helpers\chocolateyProfile.psm1

choco install -y php
choco install -y 7zip
refreshenv

php build/packages/datadog-setup.php --php-bin=all --file=$(ls build/packages/dd-library-php-*-x86_64-windows.tar.gz)

# Check source directories present by triggering an integration
echo "<?php shell_exec('echo 1'); if (dd_trace_serialize_closed_spans()[0]['meta']['cmd.shell'] !== 'echo 1') { echo 'No ExecIntegration present?'; exit(1); } echo 'SUCCESS';" | php "-ddatadog.trace.cli_enabled=1" "-ddatadog.trace.generate_root_span=0"