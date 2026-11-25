$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$slug = 'calico-jetengine-mpc-cpt-bridge'
$mainFile = Join-Path $repoRoot "$slug.php"
$versionMatch = [regex]::Match((Get-Content -LiteralPath $mainFile -Raw), 'Version:\s*([0-9]+\.[0-9]+\.[0-9]+)')
if (!$versionMatch.Success) { throw 'Missing plugin version.' }
$version = $versionMatch.Groups[1].Value
$stageRoot = Join-Path $env:TEMP ("calico-bridge-package-" + [guid]::NewGuid().ToString('N'))
$pluginRoot = Join-Path $stageRoot $slug
New-Item -ItemType Directory -Path $pluginRoot -Force | Out-Null
foreach ($entryName in @("$slug.php", 'includes', 'assets', 'README.md', 'readme.txt', 'LICENSE', 'uninstall.php')) {
    Copy-Item -LiteralPath (Join-Path $repoRoot $entryName) -Destination $pluginRoot -Recurse -Force
}
$distRoot = Join-Path $repoRoot 'dist'
New-Item -ItemType Directory -Path $distRoot -Force | Out-Null
$archive = Join-Path $distRoot "$slug.zip"
Compress-Archive -LiteralPath $pluginRoot -DestinationPath $archive -Force
Write-Output "Built version $version at $archive"
Get-FileHash -LiteralPath $archive -Algorithm SHA256
