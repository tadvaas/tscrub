# Unlock-Bios.ps1 — HP EliteBook 830 G5 BIOS admin password removal (offline patch)
#
# Deletes the `UserCred` UEFI NVRAM variable by flipping its state byte
# 0x3F (added) -> 0xFD (deleted) inside the W25Q256 (32 MiB) main-BIOS dump.
# One byte changes. Identity variables (HP_MUD, FactoryConfig, BuildId, ...)
# stay intact, so there is no "BIOS Identity Data" screen after flashing.
#
# Confirmed on units 5CG8528HKL and 5CG8403V0S (see
# research/bios-unlock/27-nvram-blank-unlock.md). The UserCred offset varies
# per unit, so it is located dynamically by walking the variable store.
#
# Usage (PowerShell 5.1 or 7, 64-bit):
#   powershell -ExecutionPolicy Bypass -File .\Unlock-Bios.ps1 -Bin .\W25Q256FV@SOIC8-XXX.BIN
#   (optional) -Out .\output.BIN
#
# Exit codes: 0 ok | 2 bad image | 3 UserCred missing/duplicate | 4 already deleted | 5 write error
#
# Acceptance test (bench PC): the output for the known dumps must match
#   W25Q256FV@SOIC8-5CG8528HKL.BIN  -> MD5 d292236faef559bbae082a9283e7cd81
#   W25Q256FV@SOIC8-5CG8403V0S.BIN  -> MD5 873309c68ca93a1b5e574b74ae9ced81

param(
    [Parameter(Mandatory = $true)][string]$Bin,
    [string]$Out
)

$ErrorActionPreference = 'Stop'

$StoreSig = [byte[]](0x78, 0x2C, 0xF3, 0xAA, 0x7B, 0x94, 0x9A, 0x43, 0xA1, 0x80, 0x2E, 0x14, 0x4E, 0xC3, 0x77, 0x92)
$SigStr = -join ($StoreSig | ForEach-Object { [char]$_ })

function Fail([string]$msg, [int]$code) {
    Write-Error $msg
    exit $code
}

if (-not (Test-Path -LiteralPath $Bin)) { Fail "Input not found: $Bin" 2 }

$raw = [System.IO.File]::ReadAllBytes($Bin)
$img = $null
if ($raw.Length -eq 33554704) {
    # Xgpro appends a 272-byte "-Configuration-" trailer; strip it.
    Write-Host "Stripped 272-byte Xgpro trailer"
    $img = New-Object byte[] 33554432
    [Array]::Copy($raw, $img, 33554432)
} elseif ($raw.Length -eq 33554432) {
    $img = $raw
} else {
    Fail "Unexpected file size $($raw.Length) (expected 33554432 or 33554704)" 2
}

# Intel SPI header check
if ($img[0x10] -ne 0x5A -or $img[0x11] -ne 0xA5 -or $img[0x12] -ne 0xF0 -or $img[0x13] -ne 0x0F) {
    Fail "Not an Intel SPI image (header missing 5A A5 F0 0F)" 2
}

# Search for the authenticated variable-store signature (Latin1 string IndexOf = fast)
$latin = [System.Text.Encoding]::Latin1.GetString($img)
$hits = New-Object System.Collections.ArrayList
$idx = $latin.IndexOf($SigStr)
while ($idx -ge 0) {
    [void]$hits.Add($idx)
    $idx = $latin.IndexOf($SigStr, $idx + 1)
}
Write-Host "Signature hits: $($hits.Count)"

# Walk every valid store; collect UserCred and HP_MUD
$userCredOffsets = New-Object System.Collections.ArrayList
$mudSerial = $null
$totalEntries = 0

foreach ($off in $hits) {
    $sz = [BitConverter]::ToUInt32($img, $off + 0x10)
    $fmt = $img[$off + 0x14]
    $state = $img[$off + 0x15]
    if ($fmt -ne 0x5A) { continue }
    if ($sz -lt 0x40 -or $sz -gt 0x200000) { continue }

    $p = $off + 0x1C
    $end = $off + $sz
    while (($p + 0x3C) -le $end) {
        if ($img[$p] -ne 0xAA -or $img[$p + 1] -ne 0x55) { break }
        $st = $img[$p + 2]
        $ns = [BitConverter]::ToUInt32($img, $p + 0x24)
        $ds = [BitConverter]::ToUInt32($img, $p + 0x28)
        if (($p + 0x3C + $ns + $ds) -gt $end) { break }
        $name = [System.Text.Encoding]::Unicode.GetString($img, $p + 0x3C, $ns)
        $totalEntries++
        if ($name -like '*UserCred*') {
            [void]$userCredOffsets.Add([pscustomobject]@{ Offset = $p; State = $st; DataSize = $ds })
        } elseif ($name -like '*HP_MUD*' -and $null -eq $mudSerial) {
            $sn = [System.Text.Encoding]::Unicode.GetString($img, $p + 0x3C + $ns, 40).TrimEnd([char]0)
            $mudSerial = $sn
        }
        $p += 0x3C + $ns + $ds
        $p = ($p + 3) -band -4   # 4-byte align
    }
}

Write-Host "Store entries walked: $totalEntries"
if ($mudSerial) { Write-Host "Unit serial (from HP_MUD): $mudSerial" }

if ($userCredOffsets.Count -eq 0) {
    Fail "UserCred not found - already unlocked or not an 830 G5 dump" 3
}
if ($userCredOffsets.Count -gt 1) {
    Fail "Found $($userCredOffsets.Count) UserCred entries - refusing to patch" 3
}
$uc = $userCredOffsets[0]
if ($uc.State -ne 0x3F) {
    Fail "UserCred state is 0x$('{0:X2}' -f $uc.State), expected 0x3F (already deleted?)" 4
}

Write-Host "UserCred @0x$('{0:X}' -f $uc.Offset) data=$($uc.DataSize) B state=0x3F"

# Patch: one byte
$patched = [byte[]]$img.Clone()
$patched[$uc.Offset + 2] = 0xFD

# Verify exactly one byte differs (chunked compare, fast)
$diffCount = 0
$block = 4096
for ($i = 0; $i -lt $img.Length; $i += $block) {
    $n = [Math]::Min($block, $img.Length - $i)
    $a = New-Object byte[] $n
    $b = New-Object byte[] $n
    [Array]::Copy($img, $i, $a, 0, $n)
    [Array]::Copy($patched, $i, $b, 0, $n)
    $diff = 0
    for ($j = 0; $j -lt $n; $j++) { if ($a[$j] -ne $b[$j]) { $diff++ } }
    $diffCount += $diff
}
if ($diffCount -ne 1) { Fail "Expected exactly 1 changed byte, got $diffCount" 5 }

if (-not $Out) {
    $Out = [regex]::Replace($Bin, '(?i)\.bin$', '-UNLOCKED-V2.BIN')
}
[System.IO.File]::WriteAllBytes($Out, $patched)

Write-Host "Wrote $Out"
Write-Host "Patch OK: UserCred deleted (0xFD), identity intact, $($patched.Length) bytes"
Write-Host "Flash it with Xgpro: Erase -> Blank Check -> Program -> Verify"
exit 0
