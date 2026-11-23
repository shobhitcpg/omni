param(
    [Parameter(Mandatory = $true)]
    [string]$WordPressCore,
    [Parameter(Mandatory = $true)]
    [string]$PHPBinary,
    [Parameter(Mandatory = $true)]
    [string]$MySQLBin,
    [string]$NodeBinary = 'node',
    [switch]$IncludeNetwork
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = (Resolve-Path -LiteralPath $PHPBinary).Path
$mysql = Join-Path $MySQLBin 'mysql.exe'
$mysqldump = Join-Path $MySQLBin 'mysqldump.exe'
foreach ($file in @($mysql, $mysqldump, (Join-Path $WordPressCore 'wp-settings.php'))) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Required validation file is missing: $file"
    }
}
$phpArgs = @('-n', '-d', ('extension_dir=' + (Join-Path (Split-Path -Parent $php) 'ext')),
    '-d', 'extension=mysqli', '-d', 'extension=mbstring', '-d', 'extension=openssl', '-d', 'extension=curl')

function Invoke-CheckedPHP([string]$Suite, [string[]]$Arguments = @()) {
    $script = Join-Path $root ('tests\' + $Suite + '.php')
    $output = & $php @phpArgs $script @Arguments 2>&1
    $code = $LASTEXITCODE
    $text = @($output | ForEach-Object { "$_" })
    $text | Write-Output
    if ($code -ne 0 -or -not ($text -match '^RESULT ') -or ($text -match '^(FAIL |PHP Warning|PHP Fatal|Warning:|Fatal error:)')) {
        throw "Validation failed: $Suite (exit $code). A successful RESULT marker is required."
    }
}

$savedCore = $env:OTAI_TEST_WP_CORE
$savedHost = $env:OTAI_TEST_DB_HOST
$savedNetwork = $env:OTAI_TEST_NETWORK
$dump = Join-Path ([System.IO.Path]::GetTempPath()) ('otai-restore-' + [guid]::NewGuid().ToString('N') + '.sql')
try {
    $env:OTAI_TEST_WP_CORE = (Resolve-Path -LiteralPath $WordPressCore).Path
    $env:OTAI_TEST_DB_HOST = '127.0.0.1:19306'
    Remove-Item Env:\OTAI_TEST_NETWORK -ErrorAction SilentlyContinue
    $files = Get-ChildItem -LiteralPath $root -Recurse -Filter '*.php' -File
    foreach ($file in $files) {
        $result = & $php -n -l $file.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            $result | Write-Output
            throw "PHP syntax failure: $($file.Name)"
        }
    }
    Write-Output "PASS PHP syntax: $($files.Count) files"
    foreach ($file in (Get-ChildItem -LiteralPath (Join-Path $root 'assets') -Recurse -Filter '*.js' -File)) {
        & $NodeBinary --check $file.FullName
        if ($LASTEXITCODE -ne 0) {
            throw "JavaScript syntax failure: $($file.Name)"
        }
        Write-Output "PASS JavaScript syntax: $($file.Name)"
    }
    foreach ($suite in @('budget-integration', 'setup-integration', 'rag-integration', 'rag-lifecycle',
        'release-integration', 'privacy-integration', 'grants-integration')) {
        Invoke-CheckedPHP $suite
    }
    Invoke-CheckedPHP 'restore-fixture' @('seed')
    & $mysqldump --no-defaults --host=127.0.0.1 --port=19306 --user=root --default-character-set=utf8mb4 --single-transaction --skip-lock-tables --set-gtid-purged=OFF "--result-file=$dump" otai_rag_test
    if ($LASTEXITCODE -ne 0) {
        throw 'Isolated SQL backup failed.'
    }
    Invoke-CheckedPHP 'restore-fixture' @('mutate')
    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = $mysql
    $info.Arguments = '--no-defaults --host=127.0.0.1 --port=19306 --user=root --default-character-set=utf8mb4 otai_rag_test'
    $info.UseShellExecute = $false
    $info.RedirectStandardInput = $true
    $info.RedirectStandardError = $true
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $info
    try {
        if (-not $process.Start()) {
            throw 'Cannot start the isolated SQL restore.'
        }
        $stderr = $process.StandardError.ReadToEndAsync()
        $input = [System.IO.File]::OpenRead($dump)
        try {
            $input.CopyTo($process.StandardInput.BaseStream)
        } finally {
            $input.Dispose()
            $process.StandardInput.Close()
        }
        $process.WaitForExit()
        $errorText = $stderr.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) {
            throw "Isolated SQL restore failed: $errorText"
        }
    } finally {
        $process.Dispose()
    }
    Invoke-CheckedPHP 'restore-fixture' @('verify')
    Invoke-CheckedPHP 'restore-fixture' @('clean')
    Remove-Item -LiteralPath $dump
    if ($IncludeNetwork) {
        $env:OTAI_TEST_NETWORK = 'run'
        Invoke-CheckedPHP 'network-integration'
    }
    Write-Output 'RESULT offline validation completed. Live providers and browser smoke checks are separate.'
} finally {
    $env:OTAI_TEST_WP_CORE = $savedCore
    $env:OTAI_TEST_DB_HOST = $savedHost
    $env:OTAI_TEST_NETWORK = $savedNetwork
    if (Test-Path -LiteralPath $dump) {
        Write-Warning "An interrupted synthetic restore backup was retained for recovery: $dump"
    }
}
