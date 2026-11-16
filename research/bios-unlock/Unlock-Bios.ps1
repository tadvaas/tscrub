# Unlock-Bios.ps1 — HP EliteBook 830 G5 BIOS admin password removal (offline patch)
#
# Version-aware recipes (see research/bios-unlock/27-nvram-blank-unlock.md):
#   BIOS <= 01.06  -> V2 patch: delete the live `UserCred` variable (state
#                     byte 0x3F -> 0xFD) and any staged `AuthNextCredential*`
#                     variables. One byte per entry; identity intact.
#   BIOS >= 01.24  -> KEEPBB keep-store recipe: the EC re-injects the
#                     credential on these units, so splice the donor BIOS
#                     region (older Q78 code) under the unit's OWN boot block
#                     while transplanting the unit's OWN variable store with
#                     every UserCred copy deleted. Keeps serial/UUID/BuildId,
#                     Windows key (HP_OA3) and settings. Requires
#                     -DonorRegion <16 MiB region file>.
#                     Verified on 01.24, 01.27, 01.28 and 01.31.
#   BIOS 01.07..01.23 -> unverified; refuses unless -ForceV2 (V2 patch) or
#                        -ForceKEEPBB (KEEPBB recipe, needs -DonorRegion).
#                        Tip: a dump scan (decompress + grep for SmcInProgress)
#                        settles the boundary with zero flash cycles.
#
# Usage (PowerShell 5.1 or 7, 64-bit):
#   .\Unlock-Bios.ps1 -Bin .\W25Q256FV@SOIC8-XXX.BIN
#   .\Unlock-Bios.ps1 -Bin .\W25Q256FV@SOIC8-XXX.BIN -DonorRegion .\Region_BIOS_BIOS_region.rgn
#   (optional) -Out .\output.BIN
#
# Exit codes: 0 ok | 2 bad image | 3 UserCred missing/duplicate | 4 already deleted |
#             5 write error | 6 donor region missing / unverified BIOS range
#
# Acceptance test (bench PC): outputs for the known dumps must match
#   W25Q256FV@SOIC8-5CG8528HKL.BIN -> MD5 d292236faef559bbae082a9283e7cd81   (V2)
#   W25Q256FV@SOIC8-5CG8403V0S.BIN -> MD5 873309c68ca93a1b5e574b74ae9ced81   (V2)
#   W25Q256FV_SOIC8-5cg832347d.BIN + Region_BIOS_BIOS_region.rgn
#                                   -> MD5 d4c60489b4064f6645b92171eb82b023   (KEEPBB)
#   W25Q256FV_SOIC8-5CG8403VSN.BIN  + Region_BIOS_BIOS_region.rgn
#                                   -> MD5 90f18197b261b4416bfc2d9570b50275   (KEEPBB)

param(
    [Parameter(Mandatory = $true)][string]$Bin,
    [string]$Out,
    [string]$DonorRegion,
    [switch]$ForceV2,
    [switch]$ForceKEEPBB
)

$ErrorActionPreference = 'Stop'

$StoreSig = [byte[]](0x78, 0x2C, 0xF3, 0xAA, 0x7B, 0x94, 0x9A, 0x43, 0xA1, 0x80, 0x2E, 0x14, 0x4E, 0xC3, 0x77, 0x92)

function Fail([string]$msg, [int]$code) {
    Write-Host "ERROR: $msg" -ForegroundColor Red
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
$biosVerByte = $null
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
        } elseif ($name -like '*LastCheckedBiosVerForBatteryRecall*' -and $null -eq $biosVerByte) {
            $biosVerByte = $img[$p + 0x3C + $ns]
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

# Q78 release dates (from HP release notes / fleet records)
$q78Dates = @{ 1 = 'Jun 2018'; 3 = 'Jul 2018'; 4 = 'Sep 2018'; 6 = 'Jan 2019'; 7 = 'Apr 2019'; 8 = 'Jul 2019'; 20 = 'Mar 2022'; 24 = 'Mar 2023'; 25 = 'Jun 2023'; 26 = 'Oct 2023'; 27 = 'Dec 2023'; 28 = 'Apr 2024'; 29 = 'Sep 2024'; 31 = 'Mar 2025' }
if ($null -ne $biosVerByte) {
    $yr = $q78Dates[[int]$biosVerByte]
    if ($yr) {
        Write-Host "BIOS version: 01.$('{0:D2}' -f [int]$biosVerByte) ($yr)" -ForegroundColor Cyan
    } else {
        Write-Host "BIOS version: 01.$('{0:D2}' -f [int]$biosVerByte) (release date unknown)" -ForegroundColor Cyan
    }
    if ([int]$biosVerByte -ge 24) {
        Write-Host "BIOS 01.24+ - using the KEEPBB keep-store recipe (the EC holds the credential mirror)." -ForegroundColor Yellow
    }
} else {
    Write-Host "BIOS version: unknown (LastCheckedBiosVerForBatteryRecall not found)"
}

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
    Write-Warning "AdminAtPowerOnAuthPolicy is PRESENT (value $powerOnPolicy) - informational only; it does NOT predict the outcome."
}

# ---- Choose the recipe by BIOS version ----
$ver = -1
if ($null -ne $biosVerByte) { $ver = [int]$biosVerByte }
if ($ver -le 6) {
    Write-Host "BIOS <= 01.06: applying the V2 patch (verified recipe)."
} elseif ($ver -ge 24) {
    Write-Host "BIOS 01.24+: applying the KEEPBB keep-store recipe (verified)."
} else {
    if (-not $ForceV2 -and -not $ForceKEEPBB) {
        Fail "BIOS version is in the UNVERIFIED range (01.07-01.23) - no recipe is proven there. Re-run with -ForceV2 (V2 patch) or -ForceKEEPBB (KEEPBB keep-store, needs -DonorRegion)." 6
    }
    if ($ForceV2) { Write-Warning "Forcing the V2 patch on an unverified BIOS version." }
    if ($ForceKEEPBB) { Write-Warning "Forcing the KEEPBB recipe on an unverified BIOS version." }
}

$authLive = @($authCredOffsets | Where-Object { $_.State -eq 0x3F })
if ($authLive.Count -gt 0) {
    Write-Host "AuthNextCredential* live entries to delete: $($authLive.Count)"
}

if ($ver -ge 24 -or $ForceKEEPBB) {
    # ============ KEEPBB keep-store recipe ============
    if ([string]::IsNullOrEmpty($DonorRegion)) {
        Fail "BIOS 01.27+ needs the donor region file. Run with -DonorRegion <path to Region_BIOS_BIOS_region.rgn>" 6
    }
    if (-not (Test-Path -LiteralPath $DonorRegion)) { Fail "Donor region not found: $DonorRegion" 6 }
    $donor = [System.IO.File]::ReadAllBytes($DonorRegion)
    if ($donor.Length -ne 16777216) { Fail "Donor region must be exactly 16 MiB (got $($donor.Length))" 6 }

    # Locate the primary store (the one containing the live UserCred)
    $storeOff = -1; $storeSize = -1
    foreach ($off in $hits) {
        $sz = [BitConverter]::ToUInt32($img, $off + 0x10)
        $fmt = $img[$off + 0x14]
        if ($fmt -ne 0x5A) { continue }
        if ($sz -lt 0x40 -or $sz -gt 0x200000) { continue }
        if ($uc.Offset -ge $off -and $uc.Offset -lt ($off + $sz)) {
            $storeOff = $off; $storeSize = $sz; break
        }
    }
    if ($storeOff -lt 0) { Fail 'Could not locate the store containing UserCred' 5 }

    # Splice: unit bottom 16 MiB + unit top 128 KiB (boot block), donor region between
    $spliced = [byte[]]$img.Clone()
    [Array]::Copy($donor, 0, $spliced, 0x1000000, 0xFFE000)

    # Transplant the unit's own store with every UserCred copy -> 0xFD
    $store = New-Object byte[] $storeSize
    [Array]::Copy($img, $storeOff, $store, 0, $storeSize)
    $flips = 0
    foreach ($e in $userCredOffsets) {
        if ($e.State -ne 0xFD) {
            $store[$e.Offset - $storeOff + 2] = 0xFD; $flips++
        }
    }
    foreach ($a in $authLive) {
        $store[$a.Offset - $storeOff + 2] = 0xFD; $flips++
    }
    [Array]::Copy($store, 0, $spliced, $storeOff, $storeSize)
    Write-Host "KEEPBB: store @0x$('{0:X}' -f $storeOff) size=0x$('{0:X}' -f $storeSize), $flips entry state byte(s) flipped to 0xFD"

    # Structural verification: bottom untouched, boot block untouched,
    # mid == donor outside the store, store == patched unit store
    $bad = 0
    for ($i = 0; $i -lt 0x1000000; $i += 4096) {
        $n = [Math]::Min(4096, 0x1000000 - $i)
        $a = New-Object byte[] $n; $b = New-Object byte[] $n
        [Array]::Copy($img, $i, $a, 0, $n); [Array]::Copy($spliced, $i, $b, 0, $n)
        for ($j = 0; $j -lt $n; $j++) { if ($a[$j] -ne $b[$j]) { $bad++ } }
    }
    if ($bad -ne 0) { Fail "Bottom 16 MiB changed ($bad bytes) - aborting" 5 }
    $bad = 0
    for ($i = 0x1FFE000; $i -lt 0x2000000; $i += 4096) {
        $n = [Math]::Min(4096, 0x2000000 - $i)
        $a = New-Object byte[] $n; $b = New-Object byte[] $n
        [Array]::Copy($img, $i, $a, 0, $n); [Array]::Copy($spliced, $i, $b, 0, $n)
        for ($j = 0; $j -lt $n; $j++) { if ($a[$j] -ne $b[$j]) { $bad++ } }
    }
    if ($bad -ne 0) { Fail "Boot block changed ($bad bytes) - aborting" 5 }
    $bad = 0
    for ($i = 0x1000000; $i -lt 0x1FFE000; $i += 4096) {
        $n = [Math]::Min(4096, 0x1FFE000 - $i)
        $a = New-Object byte[] $n; $b = New-Object byte[] $n
        [Array]::Copy($donor, $i - 0x1000000, $a, 0, $n); [Array]::Copy($spliced, $i, $b, 0, $n)
        for ($j = 0; $j -lt $n; $j++) {
            $abs = $i + $j
            if ($abs -ge $storeOff -and $abs -lt ($storeOff + $storeSize)) { continue }
            if ($a[$j] -ne $b[$j]) { $bad++ }
        }
    }
    if ($bad -ne 0) { Fail "Mid region differs from donor outside the store ($bad bytes) - aborting" 5 }

    # Re-walk the output store: no live UserCred may remain
    $p = $storeOff + 0x1C
    $end = $storeOff + $storeSize
    $liveCred = 0; $oa3Found = $false
    while (($p + 0x3C) -le $end) {
        if ($spliced[$p] -ne 0xAA -or $spliced[$p + 1] -ne 0x55) { break }
        $st = $spliced[$p + 2]
        $ns = [BitConverter]::ToUInt32($spliced, $p + 0x24)
        $ds = [BitConverter]::ToUInt32($spliced, $p + 0x28)
        if (($p + 0x3C + $ns + $ds) -gt $end) { break }
        $name = [System.Text.Encoding]::Unicode.GetString($spliced, $p + 0x3C, $ns)
        if ($name -like '*UserCred*' -and $st -ne 0xFD) { $liveCred++ }
        if ($name -like '*HP_OA3*' -and $st -eq 0x3F) { $oa3Found = $true }
        $p += 0x3C + $ns + $ds
        $p = ($p + 3) -band -4
    }
    if ($liveCred -ne 0) { Fail "$liveCred UserCred entry(ies) still live in the output - aborting" 5 }
    Write-Host "Output store verified: no live UserCred; HP_OA3 preserved: $oa3Found"

    if (-not $Out) {
        $Out = [regex]::Replace($Bin, '(?i)\.bin$', '-KEEPBB-KEEPSTORE.BIN')
    }
    [System.IO.File]::WriteAllBytes($Out, $spliced)
    Write-Host "Wrote $Out"
    Write-Host "Patch OK (KEEPBB keep-store): old code under your boot block, your store with UserCred deleted."
    Write-Host "Flash it with Xgpro: Erase -> Blank Check -> Program -> Verify."
    Write-Host "Expected: no password; serial, UUID, Windows key (HP_OA3) and settings preserved."
    Write-Host "A later official BIOS update to latest is safe - the password does not return."
    exit 0
}

# ============ V2 patch ============
$patched = [byte[]]$img.Clone()
$patched[$uc.Offset + 2] = 0xFD
foreach ($a in $authLive) { $patched[$a.Offset + 2] = 0xFD }
$expectedChanges = 1 + $authLive.Count

# Verify exactly N bytes differ (chunked compare, fast)
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
    $Out = [regex]::Replace($Bin, '(?i)\.bin$', '-UNLOCKED-V2.BIN')
}
[System.IO.File]::WriteAllBytes($Out, $patched)

Write-Host "Wrote $Out"
Write-Host "Patch OK (V2): UserCred deleted, AuthNextCredential* deleted, identity intact, $($patched.Length) bytes"
Write-Host "Flash it with Xgpro: Erase -> Blank Check -> Program -> Verify"
Write-Host "Note: first boot may show a 'finishing BIOS update' progress bar - that is normal."
if ($ver -le 6) {
    Write-Host "Verified recipe for this BIOS version - no EC re-injection expected."
} else {
    Write-Warning "Forced patch on an unverified BIOS version - if the password returns, re-read the chip and send the dump."
}
exit 0
