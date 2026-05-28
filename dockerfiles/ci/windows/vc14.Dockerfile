FROM mcr.microsoft.com/windows/servercore:1809

# https://aka.ms/vs/14/release/vs_buildtools.exe has been removed
ADD vs14_buildtools.exe /tmp/vs_buildtools.exe
RUN powershell "$ErrorActionPreference = 'Stop'; $p = Start-Process C:\tmp\vs_buildtools.exe -ArgumentList @('/S', '/InstallSelectableItems', 'VisualCppBuildTools_NETFX_SDK') -Wait -PassThru; if (@(0, 3010) -notcontains $p.ExitCode) { exit $p.ExitCode }"
RUN powershell "$ErrorActionPreference = 'Stop'; $vc = (Get-ItemProperty 'HKLM:\SOFTWARE\Wow6432Node\Microsoft\VisualStudio\14.0\Setup\VC' -Name ProductDir).ProductDir; $vcvars = Join-Path $vc 'vcvarsall.bat'; if (-not (Test-Path $vcvars)) { Write-Error ('VC14 vcvarsall.bat not found at ' + $vcvars); exit 3 }; $q = [char]34; $cmd = 'call ' + $q + $vcvars + $q + ' amd64 >NUL && where cl.exe && where link.exe'; cmd /S /C $cmd; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }"
