$ErrorActionPreference = 'Stop'

$root = Split-Path $PSScriptRoot -Parent
$dumpFolder = Join-Path $root 'dumps'
New-Item -ItemType Directory -Path $dumpFolder -Force | Out-Null

$wer = 'HKLM:\SOFTWARE\Microsoft\Windows\Windows Error Reporting'
$localDumps = Join-Path $wer 'LocalDumps\php.exe'
New-Item -Path $localDumps -Force | Out-Null
New-ItemProperty -Path $localDumps -Name DumpFolder -Value $dumpFolder -PropertyType ExpandString -Force | Out-Null
New-ItemProperty -Path $localDumps -Name DumpType -Value 2 -PropertyType DWord -Force | Out-Null
New-ItemProperty -Path $localDumps -Name DumpCount -Value 10 -PropertyType DWord -Force | Out-Null
New-ItemProperty -Path $wer -Name DontShowUI -Value 1 -PropertyType DWord -Force | Out-Null

# proc_open's suppress_errors sets SEM_NOGPFAULTERRORBOX in the child, which
# disables WER. Keep WER enabled for every test's FILE process.
$runner = Join-Path $root 'run-tests.php'
$source = [IO.File]::ReadAllText($runner)
$pattern = "'suppress_errors'\s*=>\s*true"
if ([regex]::Matches($source, $pattern).Count -ne 1) {
    throw "Expected one proc_open suppress_errors option in $runner"
}
$replacement = "'suppress_errors' => false"
$source = [regex]::Replace($source, $pattern, [System.Text.RegularExpressions.MatchEvaluator]{ param($match) $replacement })
[IO.File]::WriteAllText($runner, $source, (New-Object System.Text.UTF8Encoding($false)))
