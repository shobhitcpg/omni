param(
    [Parameter(Mandatory = $true)]
    [string]$Destination
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$output = [System.IO.Path]::GetFullPath($Destination)
if (Test-Path -LiteralPath $output) {
    throw 'Destination already exists. Use a new release filename.'
}
if ($output.StartsWith($root + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'The release archive must be outside the plugin source directory.'
}

$stage = Join-Path ([System.IO.Path]::GetTempPath()) ('otai-package-' + [guid]::NewGuid().ToString('N'))
$plugin = Join-Path $stage 'omnithread-ai'
New-Item -Path $plugin -ItemType Directory | Out-Null
try {
    foreach ($name in @('omnithread-ai.php', 'uninstall.php', 'readme.txt', 'includes', 'assets', 'languages', 'docs')) {
        Copy-Item -LiteralPath (Join-Path $root $name) -Destination $plugin -Recurse
    }
    Compress-Archive -LiteralPath $plugin -DestinationPath $output
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($output)
    try {
        if (-not ($archive.Entries | Where-Object { $_.FullName.Replace('\', '/') -eq 'omnithread-ai/omnithread-ai.php' })) {
            throw 'The archive is missing the standard WordPress plugin entry point.'
        }
        if ($archive.Entries | Where-Object { $_.FullName -match '(^|[/\\])(tests|tools|\.git)([/\\]|$)' }) {
            throw 'Development-only files leaked into the release archive.'
        }
        Write-Output ("PASS clean WordPress archive: {0} entries" -f $archive.Entries.Count)
    } finally {
        $archive.Dispose()
    }
    Get-FileHash -LiteralPath $output -Algorithm SHA256
} finally {
    Remove-Item -LiteralPath $plugin -Recurse -Force
    Remove-Item -LiteralPath $stage
}
