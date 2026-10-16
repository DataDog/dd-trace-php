# Runs the shm_gen_cache tests inside a php-*_windows CI container (CI job
# "shm_gen_cache tests: windows" in .gitlab/generate-shared.php). Only the
# native tests: the GenMC suite (shm_gen_cache_verification) runs on Linux.
#
# The image's machine environment carries the VC build environment
# (dockerfiles/ci/windows/store-env.bat), so cargo finds link.exe.
#
# Exits 75 (GitLab's default retry, generate-common.php) when downloading the
# toolchain or cargo-nextest fails; otherwise with the exit code of nextest.

$ErrorActionPreference = 'Stop'
# Windows PowerShell's progress rendering slows Invoke-WebRequest a lot.
$ProgressPreference = 'SilentlyContinue'

Set-Location (Split-Path $PSScriptRoot -Parent)

# Long paths: resolving the workspace checks out cargo's git dependencies
# (e.g. rust-tuf's deeply nested interop-tests fixtures).
Set-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\FileSystem' -Name LongPathsEnabled -Value 1 -Type DWord

# The pinned toolchain; a no-op when the image already has it. Not left to
# rustup's auto-install, which some rustup versions lack.
$toolchain = (Select-String -Path rust-toolchain.toml -Pattern '^channel\s*=\s*"([^"]+)"').Matches[0].Groups[1].Value
rustup toolchain install $toolchain --profile minimal
if ($LASTEXITCODE -ne 0) { exit 75 }

# cargo-nextest, next to cargo. GITHUB_RELEASES_MIRROR has GitHub's layout;
# GitHub itself is the fallback (the mirror is otherwise only used from the
# runner host, not from inside containers).
$nextest = "cargo-nextest-$env:NEXTEST_VERSION"
$zip = Join-Path $env:TEMP "$nextest.zip"
$path = "nextest-rs/nextest/releases/download/$nextest/$nextest-x86_64-pc-windows-msvc.zip"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$downloaded = $false
foreach ($base in @($env:GITHUB_RELEASES_MIRROR, 'https://github.com') | Where-Object { $_ }) {
    try {
        Invoke-WebRequest -UseBasicParsing -OutFile $zip "$base/$path"
        $downloaded = $true
        break
    } catch {
        Write-Host "Downloading cargo-nextest from $base failed: $_"
    }
}
if (-not $downloaded) { exit 75 }
Expand-Archive $zip -DestinationPath (Split-Path (Get-Command cargo).Source) -Force

cargo nextest run -p shm_gen_cache --profile ci
exit $LASTEXITCODE
