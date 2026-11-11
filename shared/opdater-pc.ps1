# FILE_VERSION: 5.42 - FRAMEWORKETS FIL. Koeres af ejeren i PowerShell, EFTER at master er kopieret ind i projekterne:
#   powershell -ExecutionPolicy Bypass -File C:\ClaudeCoworkers\coworker-development\shared\opdater-pc.ps1
# For hvert projekt i mappen over master (har agents\team_lead.md og _claude\):
#   1) kopierer projektets _claude\ til .claude\ (guard og agenter - Trin 0 tjek 4), men kun naar hver fil matcher
#      shared\claude.lock (5.28). Sletter intet.
#   2) mangler projektet sin egen GitHub-noegle (GH_TOKEN i .claude\settings.local.json): spoerger om den.
#      Enter = spring over. Kun noeglen tilfoejes - resten af filen bliver staaende.
# Til sidst: har alle projekter deres noegle, kan gh's faelles login fjernes (gh auth logout).
param([string]$Root)
$ErrorActionPreference = 'Stop'
$master = Split-Path -Parent $PSScriptRoot
if (-not $Root) { $Root = Split-Path -Parent $master }

function Set-Key([string]$dir, [string]$key) {
    $f = Join-Path $dir '.claude/settings.local.json'
    $j = $null
    if ((Test-Path $f) -and (Get-Item $f).Length -gt 0) { $j = Get-Content $f -Raw | ConvertFrom-Json }
    if (-not $j) { $j = [pscustomobject]@{} }
    if (-not $j.env) { $j | Add-Member env ([pscustomobject]@{}) -Force }
    $j.env | Add-Member GH_TOKEN $key -Force
    $j.env | Add-Member GIT_CONFIG_COUNT '2' -Force
    $j.env | Add-Member GIT_CONFIG_KEY_0 'credential.https://github.com.helper' -Force
    $j.env | Add-Member GIT_CONFIG_VALUE_0 '' -Force
    $j.env | Add-Member GIT_CONFIG_KEY_1 'credential.https://github.com.helper' -Force
    $j.env | Add-Member GIT_CONFIG_VALUE_1 '!gh auth git-credential' -Force
    [IO.File]::WriteAllText($f, ($j | ConvertTo-Json -Depth 20), (New-Object Text.UTF8Encoding $false))
}
function Has-Key([string]$dir) {
    $f = Join-Path $dir '.claude/settings.local.json'
    if (-not (Test-Path $f) -or (Get-Item $f).Length -eq 0) { return $false }
    $j = Get-Content $f -Raw | ConvertFrom-Json
    return [bool]($j.env -and $j.env.GH_TOKEN)
}

# 5.28: _claude\ er git-ignoreret i projektet, saa CI ser den aldrig. Foer den kopieres til .claude\, skal hver fil
# matche shared\claude.lock (som CI laaser via framework.lock). En aendret eller ukendt fil = intet kopieres.
function Test-ClaudeLock([string]$dir) {
    $lock = Join-Path $dir 'shared/claude.lock'
    if (-not (Test-Path $lock)) { return '' }   # framework foer 5.28: ingen laas at tjekke mod
    $src = Join-Path $dir '_claude'
    $want = @{}
    foreach ($line in Get-Content $lock) { if ($line -match '^([0-9a-f]{64})\s+(.+)$') { $want[$Matches[2].Trim()] = $Matches[1] } }
    $fejl = @()
    foreach ($f in $want.Keys) {
        $p = Join-Path $src $f
        if (-not (Test-Path $p)) { $fejl += "mangler $f"; continue }
        if ((Get-FileHash -Algorithm SHA256 -Path $p).Hash.ToLower() -ne $want[$f]) { $fejl += "afviger $f" }
    }
    $base = (Resolve-Path $src).Path
    foreach ($x in Get-ChildItem -Path $src -Recurse -File) {
        $rel = $x.FullName.Substring($base.Length + 1) -replace '\\', '/'
        if ($rel -eq 'settings.local.json' -or $rel -like 'worktrees/*') { continue }
        if (-not $want.ContainsKey($rel)) { $fejl += "ukendt $rel" }
    }
    return ($fejl -join ', ')
}

$missing = @(); $tampered = @()
foreach ($d in Get-ChildItem -Path $Root -Directory) {
    if ($d.FullName -eq $master) { continue }
    if (-not (Test-Path (Join-Path $d.FullName 'agents/team_lead.md'))) { continue }
    $src = Join-Path $d.FullName '_claude'
    if (-not (Test-Path $src)) { Write-Host "$($d.Name): ingen _claude - kopier master ind foerst (springes over)"; $missing += $d.Name; continue }
    $t = Test-ClaudeLock $d.FullName
    if ($t) { Write-Host "$($d.Name): _claude afviger fra shared\claude.lock ($t) - IKKE kopieret. Kopier master ind igen."; $tampered += $d.Name; continue }
    $dst = Join-Path $d.FullName '.claude'
    New-Item -ItemType Directory -Force -Path $dst | Out-Null
    Copy-Item -Recurse -Force -Path (Join-Path $src '*') -Destination $dst
    if (Has-Key $d.FullName) { Write-Host "$($d.Name): .claude opdateret, har sin egen noegle"; continue }
    $s = Read-Host "$($d.Name): .claude opdateret. Indsaet noeglen til DETTE repo (Enter = spring over)" -AsSecureString
    $k = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))
    if ([string]::IsNullOrWhiteSpace($k)) { Write-Host "$($d.Name): sprunget over - har stadig ingen egen noegle"; $missing += $d.Name; continue }
    Set-Key $d.FullName $k.Trim()
    Write-Host "$($d.Name): noeglen er sat"
}
Write-Host ''
if ($tampered.Count -gt 0) { Write-Host "STOP - _claude er aendret i: $($tampered -join ', '). Guarden er ikke opdateret der. Kopier master ind igen og koer scriptet igen." }
if ($missing.Count -eq 0 -and $tampered.Count -eq 0) {
    Write-Host 'ALLE PROJEKTER: .claude opdateret og egen noegle sat. Start nye sessioner.'
    Write-Host "Fjern nu det faelles login, saa intet projekt kan falde tilbage paa det:  gh auth logout"
} elseif ($missing.Count -gt 0) {
    Write-Host "MANGLER EGEN NOEGLE: $($missing -join ', '). Koer scriptet igen, naar du har dem. Behold gh-login indtil da."
}
