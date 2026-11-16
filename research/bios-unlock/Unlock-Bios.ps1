# Unlock-Bios.ps1 — HP EliteBook 830 G5 BIOS admin password removal (offline patch)
#
# V5 recipe (2026-11-16):
#   1. delete the `UserCred` NVRAM variable (state byte 0x3F -> 0xFD),
#   2. delete any staged `AuthNextCredential*` variables (SAT601 revisions),
#   3. set `ClearSecurityOperation` = 1 so the EC drops its own copy.
#      On BIOS >= 01.27 the EC re-injects the password at boot after a
#      UserCred-only patch (proven by afterflash dumps of 5CG8528JYH and
#      5CG8528GQT) - see research/bios-unlock/27-nvram-blank-unlock.md.
# Identity variables (HP_MUD, FactoryConfig, BuildId, ...) stay intact, so
# there is no "BIOS Identity Data" screen after flashing.
#
# The UserCred offset varies per unit, so it is located dynamically by
# walking the variable store.
#
# Usage (PowerShell 5.1 or 7, 64-bit):
#   powershell -ExecutionPolicy Bypass -File .\Unlock-Bios.ps1 -Bin .\W25Q256FV@SOIC8-XXX.BIN
#   (optional) -Out .\output.BIN
#
# Exit codes: 0 ok | 2 bad image | 3 UserCred missing/duplicate | 4 already deleted | 5 write error
#
# Acceptance test (bench PC): the output for the known dumps must match
#   W25Q256FV@SOIC8-5CG8528HKL.BIN  -> MD5 1e75e62788338667b54c3b432f56f7d7
#   W25Q256FV@SOIC8-5CG8403V0S.BIN  -> MD5 27790d3db99671f5ca3d8e60ea88a14b

param(
    [Parameter(Mandatory = $true)][string]$Bin,
    [string]$Out
)

$ErrorActionPreference = 'Stop'

$StoreSig = [byte[]](0x78, 0x2C, 0xF3, 0xAA, 0x7B, 0x94, 0x9A, 0x43, 0xA1, 0x80, 0x2E, 0x14, 0x4E, 0xC3, 0x77, 0x92)

function Fail([string]$msg, [int]$code) {
    Write-Error $msg
    exit $code
}

# Latin1 encoder: Windows PowerShell 5.1 (.NET Framework) has NO
# [System.Text.Encoding]::Latin1 (that is .NET Core only). Use codepage 28591
# (ISO-8859-1, byte<->char identity). PS 7 needs the code-pages provider
# registered first. Fallback to the system default encoding — since the needle
# is built with the SAME encoding as the haystack, matching still works.
$latinEnc = $null
try {
    if ($PSVersionTable.PSEdition -eq 'Core') {
        [System.Text.Encoding]::RegisterProvider([System.Text.CodePagesEncodingProvider]::Instance)
    }
    $latinEnc = [System.Text.Encoding]::GetEncoding(28591)
} catch {
    $latinEnc = [System.Text.Encoding]::Default
}
if ($null -eq $latinEnc) { Fail 'Could not resolve a Latin1 encoding' 2 }
$SigStr = $latinEnc.GetString($StoreSig)

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

# Search for the authenticated variable-store signature (single-byte-encoding
# string IndexOf = fast over 32 MB)
$latin = $latinEnc.GetString($img)
$hits = New-Object System.Collections.ArrayList
$idx = $latin.IndexOf($SigStr)
while ($idx -ge 0) {
    [void]$hits.Add($idx)
    $idx = $latin.IndexOf($SigStr, $idx + 1)
}
Write-Host "Signature hits: $($hits.Count)"

# Walk every valid store; collect UserCred, AuthNextCredential* and HP_MUD
$userCredOffsets = New-Object System.Collections.ArrayList
$authCredOffsets = New-Object System.Collections.ArrayList
$mudSerial = $null
$powerOnPolicy = $null
$buildId = $null
$clearSecurity = $null
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
        } elseif ($name -like 'AuthNextCredential*') {
            [void]$authCredOffsets.Add([pscustomobject]@{ Offset = $p; State = $st })
        } elseif ($name -like '*AdminAtPowerOnAuthPolicy*') {
            $powerOnPolicy = $img[$p + 0x3C + $ns]
        } elseif ($name -like '*ClearSecurityOperation*') {
            $clearSecurity = [pscustomobject]@{ Offset = $p; NameSize = $ns }
        } elseif ($name -like '*BuildId*') {
            $buildId = [System.Text.Encoding]::Unicode.GetString($img, $p + 0x3C + $ns, $ds).TrimEnd([char]0)
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
if ($buildId) { Write-Host "BIOS BuildId: $buildId" }

# A password change leaves a stale DELETED (0xFD) copy behind; only the LIVE
# (0x3F) entry carries the current password.
$ucLive = @($userCredOffsets | Where-Object { $_.State -eq 0x3F })
if ($userCredOffsets.Count -gt $ucLive.Count) {
    Write-Host "UserCred entries: $($userCredOffsets.Count) total, $($ucLive.Count) live (stale deleted copies ignored)"
}
if ($ucLive.Count -eq 0) {
    Fail "UserCred has no live (0x3F) entry - already unlocked?" 4
}
if ($ucLive.Count -gt 1) {
    Fail "Found $($ucLive.Count) live UserCred entries - refusing to patch" 3
}
$uc = $ucLive[0]
if ($uc.State -ne 0x3F) {
    Fail "UserCred state is 0x$('{0:X2}' -f $uc.State), expected 0x3F (already deleted?)" 4
}

Write-Host "UserCred @0x$('{0:X}' -f $uc.Offset) data=$($uc.DataSize) B state=0x3F"

if ($null -ne $powerOnPolicy) {
    Write-Warning "AdminAtPowerOnAuthPolicy is PRESENT (value $powerOnPolicy)."
    Write-Warning "Info: this flag alone does NOT predict the outcome - on SOME units"
    Write-Warning "the EC also holds the admin credential and the prompt returns after flash."
    Write-Warning "Flash and verify; if the password returns, re-read the chip and send the dump."
}

# Staged credential: some BIOS revisions (e.g. 17WWCSAT601) re-apply the
# staged AuthNextCredential* variable on boot and bring the password back.
$authLive = @($authCredOffsets | Where-Object { $_.State -eq 0x3F })
if ($authLive.Count -gt 0) {
    Write-Host "AuthNextCredential* live entries to delete: $($authLive.Count)"
}

if ($null -ne $clearSecurity) {
    $csDataOff = $clearSecurity.Offset + 0x3C + $clearSecurity.NameSize
    Write-Host "ClearSecurityOperation @0x$('{0:X}' -f $clearSecurity.Offset) data=0x$('{0:X2}' -f $img[$csDataOff]) -> will set 0x01"
} else {
    Write-Host "ClearSecurityOperation not found - EC clear request skipped"
}

# Patch: one byte per entry + ClearSecurityOperation = 1
$patched = [byte[]]$img.Clone()
$patched[$uc.Offset + 2] = 0xFD
foreach ($a in $authLive) { $patched[$a.Offset + 2] = 0xFD }
if ($null -ne $clearSecurity) { $patched[$csDataOff] = 0x01 }
$expectedChanges = 1 + $authLive.Count
if ($null -ne $clearSecurity) { $expectedChanges++ }

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
if ($diffCount -ne $expectedChanges) { Fail "Expected exactly $expectedChanges changed byte(s), got $diffCount" 5 }

if (-not $Out) {
    $Out = [regex]::Replace($Bin, '(?i)\.bin$', '-UNLOCKED-V5.BIN')
}
[System.IO.File]::WriteAllBytes($Out, $patched)

Write-Host "Wrote $Out"
Write-Host "Patch OK (V5): UserCred deleted, AuthNextCredential* deleted, ClearSecurityOperation=1, identity intact, $($patched.Length) bytes"
Write-Host "Flash it with Xgpro: Erase -> Blank Check -> Program -> Verify"
Write-Host "Note: first boot may show a 'finishing BIOS update' progress bar - that is normal."
Write-Host "If the password still returns, the EC holds the credential - dump QN800 (16 MB) and send it."
exit 0
