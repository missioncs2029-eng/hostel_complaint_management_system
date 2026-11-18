# HavenHostel - XAMPP MySQL repair helper (run through fix-mysql.bat, as Administrator).
# Fixes: 'Port 3306 in use by "Unable to open process"' and 'MySQL shutdown unexpectedly'.
$ErrorActionPreference = 'Continue'
$ProjectDir = Split-Path -Parent $PSScriptRoot
$NewPort    = 3307

function Say($t, $c = 'White') { Write-Host $t -ForegroundColor $c }

function Find-Xampp {
    $saved = Join-Path $ProjectDir 'xampp-path.txt'
    if (Test-Path $saved) {
        $p = (Get-Content $saved -TotalCount 1).Trim()
        if ($p -and (Test-Path (Join-Path $p 'xampp-control.exe'))) { return $p }
    }
    foreach ($d in 'C','D','E','F','G','H') {
        foreach ($n in 'xampp','xamapp','XAMPP','Xampp') {
            $p = "${d}:\$n"
            if (Test-Path (Join-Path $p 'xampp-control.exe')) { return $p }
        }
    }
    $p = (Read-Host 'Type your XAMPP folder (example D:\xampp)').Trim().Trim('"')
    if (Test-Path (Join-Path $p 'xampp-control.exe')) { return $p }
    return $null
}

function Get-PortOwner($port) {
    $out = @()
    $lines = netstat -ano -p TCP | Select-String -Pattern (":$port\s+\S+\s+LISTENING\s+(\d+)")
    foreach ($l in $lines) {
        $m = [regex]::Match($l.Line, 'LISTENING\s+(\d+)')
        if ($m.Success) {
            $procId = [int]$m.Groups[1].Value
            $proc = Get-CimInstance Win32_Process -Filter "ProcessId=$procId" -ErrorAction SilentlyContinue
            $svc  = Get-CimInstance Win32_Service -Filter "ProcessId=$procId" -ErrorAction SilentlyContinue
            $out += [pscustomobject]@{
                Pid = $procId
                Name = $(if ($proc) { $proc.Name } else { 'unknown (system process)' })
                Path = $(if ($proc) { $proc.ExecutablePath } else { '' })
                Services = @($svc | ForEach-Object { $_.Name })
            }
        }
    }
    return $out | Sort-Object Pid -Unique
}

function Stop-XamppMysqld($xampp) {
    Get-Process mysqld -ErrorAction SilentlyContinue | ForEach-Object {
        try { if ($_.Path -and $_.Path.StartsWith($xampp, [StringComparison]::OrdinalIgnoreCase)) { Stop-Process -Id $_.Id -Force; Say "  stopped XAMPP mysqld (PID $($_.Id))" 'Yellow' } } catch {}
    }
}

function Set-MyIniPort($xampp, $port) {
    $ini = Join-Path $xampp 'mysql\bin\my.ini'
    if (-not (Test-Path $ini)) { Say "  my.ini not found at $ini" 'Red'; return $false }
    Copy-Item $ini ($ini + '.bak-' + (Get-Date -Format 'yyyyMMdd-HHmmss')) -Force
    $text = [IO.File]::ReadAllText($ini)
    $new  = [regex]::Replace($text, '(?im)^(\s*port\s*=\s*)\d+', ('${1}' + $port))
    [IO.File]::WriteAllText($ini, $new, (New-Object Text.UTF8Encoding($false)))
    Say "  my.ini now uses port $port (backup saved next to it)" 'Green'
    return $true
}

function Set-PhpMyAdminPort($xampp, $port) {
    $cfg = Join-Path $xampp 'phpMyAdmin\config.inc.php'
    if (-not (Test-Path $cfg)) { return }
    $lines = Get-Content $cfg
    $hasPort = $false
    foreach ($l in $lines) { if ($l -match "\['port'\]") { $hasPort = $true } }
    $portLine = "`$cfg['Servers'][`$i]['port'] = '$port';"
    $out = New-Object System.Collections.Generic.List[string]
    foreach ($l in $lines) {
        if ($l -match "\['port'\]") {
            $out.Add($portLine)
        } else {
            $out.Add($l)
            if ((-not $hasPort) -and ($l -match "\['host'\]\s*=")) {
                $out.Add($portLine)
                $hasPort = $true
            }
        }
    }
    [IO.File]::WriteAllText($cfg, (($out -join "`r`n") + "`r`n"), (New-Object Text.UTF8Encoding($false)))
    Say "  phpMyAdmin now talks to port $port" 'Green'
}

function Write-ProjectConfig($xampp, $port) {
    $targets = @($ProjectDir, (Join-Path $xampp 'htdocs\hostel-management-system')) | Select-Object -Unique
    foreach ($t in $targets) {
        if (Test-Path (Join-Path $t 'index.php')) {
            $body = "<?php`nreturn ['host' => '127.0.0.1', 'port' => $port, 'user' => 'root', 'pass' => '', 'debug' => false];`n"
            [IO.File]::WriteAllText((Join-Path $t 'config.local.php'), $body, (New-Object Text.UTF8Encoding($false)))
            Say "  saved app connection (port $port) in $t\config.local.php" 'Green'
        }
    }
}

Clear-Host
Say '=== HavenHostel : MySQL repair helper ===' 'Cyan'
$xampp = Find-Xampp
if (-not $xampp) { Say 'XAMPP not found. Aborting.' 'Red'; exit 1 }
Say "XAMPP folder: $xampp" 'Green'

# ---- Diagnose ----
Say "`nWho is using port 3306?" 'Cyan'
$owners = @(Get-PortOwner 3306)
$blockedByOther = $false
if ($owners.Count -eq 0) {
    Say '  Nobody - port 3306 is free.' 'Green'
} else {
    foreach ($o in $owners) {
        Say ("  PID {0}  {1}  {2}  {3}" -f $o.Pid, $o.Name, $o.Path, ($(if ($o.Services.Count) { 'service: ' + ($o.Services -join ',') } else { '' }))) 'Yellow'
        if (-not ($o.Path -and $o.Path.StartsWith($xampp, [StringComparison]::OrdinalIgnoreCase))) { $blockedByOther = $true }
    }
}
$mysqlSvcs = @(Get-CimInstance Win32_Service | Where-Object { $_.Name -match 'mysql|maria' -and $_.State -eq 'Running' })
foreach ($s in $mysqlSvcs) { Say "  Windows service running: $($s.Name)" 'Yellow' }

$errLog = Get-ChildItem (Join-Path $xampp 'mysql\data') -Filter '*.err' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $errLog) { $errLog = Get-ChildItem (Join-Path $xampp 'mysql\data') -Filter 'mysql_error.log' -ErrorAction SilentlyContinue | Select-Object -First 1 }
if ($errLog) {
    Say "`nLast lines of MySQL's own error log ($($errLog.Name)):" 'Cyan'
    Get-Content $errLog.FullName -Tail 12 | ForEach-Object { Say "  $_" 'DarkGray' }
}

# ---- Menu ----
Say "`nChoose a fix:" 'Cyan'
Say '  1) RECOMMENDED - Move XAMPP MySQL to port 3307 (nothing else on your PC is touched)'
Say '  2) Stop the Windows MySQL/MariaDB service that is using 3306 (set to Manual start)'
Say '  3) MySQL still crashes after 1 or 2 - rebuild XAMPP MySQL data folder (keeps a full backup)'
Say '  4) Exit'
$choice = (Read-Host 'Type 1, 2, 3 or 4').Trim()

switch ($choice) {
    '1' {
        Stop-XamppMysqld $xampp
        if (Get-PortOwner $NewPort) { Say "Port $NewPort is also busy; trying 3308." 'Yellow'; $NewPort = 3308 }
        if (Set-MyIniPort $xampp $NewPort) {
            Set-PhpMyAdminPort $xampp $NewPort
            Write-ProjectConfig $xampp $NewPort
            Say "`nDONE. Now:" 'Cyan'
            Say "  1. Open XAMPP Control Panel -> click Start next to MySQL (it should go green)." 
            Say "  2. Optional (hides the red warning): XAMPP -> Config -> Service and Port Settings -> MySQL main port = $NewPort -> Save."
            Say '  3. Open http://localhost/hostel-management-system/setup.php once, then use the site.'
        }
    }
    '2' {
        if ($mysqlSvcs.Count -eq 0) { Say 'No MySQL/MariaDB Windows service is running. Use option 1 instead.' 'Yellow'; break }
        foreach ($s in $mysqlSvcs) {
            Say "Stopping service $($s.Name) ..." 'Yellow'
            Stop-Service -Name $s.Name -Force -ErrorAction SilentlyContinue
            Set-Service -Name $s.Name -StartupType Manual -ErrorAction SilentlyContinue
        }
        Start-Sleep 2
        if (Get-PortOwner 3306) { Say 'Port 3306 is STILL busy. Run this tool again and pick option 1.' 'Red' }
        else { Say 'Port 3306 is free now. Start MySQL in XAMPP.' 'Green' }
    }
    '3' {
        $my = Join-Path $xampp 'mysql'
        $data = Join-Path $my 'data'; $bak = Join-Path $my 'backup'
        if (-not (Test-Path $bak)) { Say "No $bak folder found - cannot rebuild." 'Red'; break }
        $go = (Read-Host 'This renames mysql\data to a backup folder and rebuilds it. Type YES to continue').Trim()
        if ($go -ne 'YES') { Say 'Cancelled.'; break }
        Stop-XamppMysqld $xampp
        $old = Join-Path $my ('data_old_' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
        Rename-Item $data $old
        Copy-Item $bak $data -Recurse
        Get-ChildItem $old -Directory | Where-Object { $_.Name -notin 'mysql','performance_schema','phpmyadmin','sys','test' } | ForEach-Object { Copy-Item $_.FullName (Join-Path $data $_.Name) -Recurse -Force }
        if (Test-Path (Join-Path $old 'ibdata1')) { Copy-Item (Join-Path $old 'ibdata1') (Join-Path $data 'ibdata1') -Force }
        Say "Rebuilt. Your old data is safe in: $old" 'Green'
        Say 'Now start MySQL in XAMPP. If it still stops, run this tool again and pick option 1.'
    }
    default { Say 'Nothing changed.' }
}
