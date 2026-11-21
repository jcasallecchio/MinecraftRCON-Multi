[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ServerShareRoot,

    [Parameter(Mandatory = $true)]
    [string]$InstanceId,

    [Parameter(Mandatory = $true)]
    [string]$WorldName,

    [Parameter(Mandatory = $true)]
    [string]$RendererPath,

    [string]$ConsoleRoot = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'
$stopRequested = $false
$snapshotPath = $null
$stagePath = $null
$backupPath = $null

function Assert-ChildPath([string]$Path, [string]$Root) {
    $rootFull = [System.IO.Path]::GetFullPath($Root).TrimEnd([System.IO.Path]::DirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
    $pathFull = [System.IO.Path]::GetFullPath($Path)
    if (-not $pathFull.StartsWith($rootFull, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Caminho fora do diretório permitido: $pathFull"
    }
    return $pathFull
}

function Read-ConsoleEnvironment([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Arquivo .env não encontrado em $Path"
    }
    $values = @{}
    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -match '^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$') {
            $value = $Matches[2].Trim()
            if ($value.Length -ge 2 -and (($value.StartsWith("'") -and $value.EndsWith("'")) -or ($value.StartsWith('"') -and $value.EndsWith('"')))) {
                $value = $value.Substring(1, $value.Length - 2)
            }
            $values[$Matches[1]] = $value
        }
    }
    return $values
}

function Get-InstanceApi([hashtable]$Environment, [string]$Id) {
    $token = [string]$Environment['BEDROCK_API_TOKEN']
    $rawInstances = [string]$Environment['BEDROCK_INSTANCES']
    if ($rawInstances) {
        $instances = ConvertFrom-Json -InputObject $rawInstances
        $instance = @($instances | Where-Object { [string]$_.id -eq $Id } | Select-Object -First 1)
        if ($instance.Count -eq 0) { throw "A instância '$Id' não está definida em BEDROCK_INSTANCES." }
        $url = ([string]$instance[0].url).TrimEnd('/')
        $instanceToken = [string]$instance[0].token
        if ($instanceToken -eq '${BEDROCK_API_TOKEN}') { $instanceToken = $token }
        if ($instanceToken) { $token = $instanceToken }
    } else {
        $url = ([string]$Environment['BEDROCK_API_URL']).TrimEnd('/')
    }
    if (-not $url -or -not $token) { throw 'BEDROCK_API_URL/BEDROCK_INSTANCES ou BEDROCK_API_TOKEN não estão configurados no .env.' }
    $parsed = [System.Uri]$url
    if ($parsed.Scheme -notin @('http', 'https')) { throw 'A API do add-on precisa usar HTTP ou HTTPS.' }
    return @{ Url = $url; Token = $token }
}

function Invoke-BedrockApi([string]$Url, [string]$Token, [string]$Method, $Body = $null) {
    $parameters = @{
        Uri = $Url
        Method = $Method
        Headers = @{ Authorization = "Bearer $Token"; Accept = 'application/json' }
        TimeoutSec = 12
    }
    if ($null -ne $Body) {
        $parameters.ContentType = 'application/json'
        $parameters.Body = ConvertTo-Json -InputObject $Body -Compress
    }
    return Invoke-RestMethod @parameters
}

function Get-StopLineCount([string]$Path) {
    return @(Get-Content -LiteralPath $Path -Tail 2000 | Where-Object { $_ -match 'Server stop requested\.' }).Count
}

if ($InstanceId -notmatch '^[a-z0-9][a-z0-9_-]{0,31}$') { throw 'InstanceId inválido.' }
if (-not $WorldName -or [System.IO.Path]::GetFileName($WorldName) -ne $WorldName -or $WorldName -in @('.', '..')) { throw 'WorldName precisa ser somente o nome da pasta do mundo.' }
if (-not (Test-Path -LiteralPath $RendererPath -PathType Leaf)) { throw "Não encontrei o executável uNmINeD CLI em $RendererPath" }

$consoleFull = (Resolve-Path -LiteralPath $ConsoleRoot).Path
$environment = Read-ConsoleEnvironment (Join-Path $consoleFull '.env')
$api = Get-InstanceApi $environment $InstanceId
$instanceRoot = Join-Path (Join-Path $ServerShareRoot 'instances') $InstanceId
$worldPath = Join-Path (Join-Path $instanceRoot 'worlds') $WorldName
$serverLog = Join-Path $instanceRoot 'server.log'
$mapsRoot = Join-Path (Join-Path $consoleFull 'storage') 'maps'
$mapPath = Join-Path $mapsRoot $InstanceId
if (-not (Test-Path -LiteralPath $worldPath -PathType Container)) { throw "Pasta do mundo não encontrada: $worldPath" }
if (-not (Test-Path -LiteralPath $serverLog -PathType Leaf)) { throw "Log da instância não encontrado: $serverLog" }

$health = Invoke-RestMethod -Uri "$($api.Url)/health" -TimeoutSec 8
if ($health.status -ne 'ready') { throw 'A instância não está pronta. Nenhuma cópia foi iniciada.' }
$stopCountBefore = Get-StopLineCount $serverLog

try {
    $stopRequested = $true
    $null = Invoke-BedrockApi "$($api.Url)/api/v1/control" $api.Token 'Post' @{ action = 'stop' }
    Write-Host "Parada graciosa solicitada para '$InstanceId'. Aguardando o LevelDB fechar..."

    $deadline = [DateTimeOffset]::Now.AddMinutes(2)
    $stopConfirmed = $false
    while ([DateTimeOffset]::Now -lt $deadline) {
        $lines = @(Get-Content -LiteralPath $serverLog -Tail 80)
        $lastStop = -1
        $lastQuit = -1
        for ($index = 0; $index -lt $lines.Count; $index++) {
            if ($lines[$index] -match 'Server stop requested\.') { $lastStop = $index }
            if ($lines[$index] -match '^Quit correctly\s*$') { $lastQuit = $index }
        }
        if ((Get-StopLineCount $serverLog) -gt $stopCountBefore -and $lastStop -ge 0 -and $lastQuit -gt $lastStop) {
            $stableLength = (Get-Item -LiteralPath $serverLog).Length
            Start-Sleep -Seconds 2
            if ((Get-Item -LiteralPath $serverLog).Length -eq $stableLength) {
                $stopConfirmed = $true
                break
            }
        }
        Start-Sleep -Seconds 2
    }
    if (-not $stopConfirmed) { throw 'O log não confirmou o encerramento completo; o mundo não foi copiado.' }

    $snapshotAt = [DateTimeOffset]::Now
    $tempRoot = Assert-ChildPath (Join-Path $env:TEMP "minedev-map-$InstanceId-$([guid]::NewGuid().ToString('N'))") $env:TEMP
    $snapshotPath = Join-Path $tempRoot 'world'
    New-Item -ItemType Directory -Path $snapshotPath -Force | Out-Null
    Get-ChildItem -LiteralPath $worldPath -Force | Copy-Item -Destination $snapshotPath -Recurse -Force
    $sourceStats = Get-ChildItem -LiteralPath $worldPath -Recurse -File -Force | Measure-Object -Property Length -Sum
    $copyStats = Get-ChildItem -LiteralPath $snapshotPath -Recurse -File -Force | Measure-Object -Property Length -Sum
    if ($sourceStats.Count -ne $copyStats.Count -or $sourceStats.Sum -ne $copyStats.Sum) { throw 'A cópia temporária não corresponde ao mundo original.' }

    New-Item -ItemType Directory -Path $mapsRoot -Force | Out-Null
    $stagePath = Assert-ChildPath (Join-Path $mapsRoot ".stage-$InstanceId-$([guid]::NewGuid().ToString('N'))") $mapsRoot
    $null = & $RendererPath web render "--world=$snapshotPath" "--output=$stagePath" --dimension=overworld --imageformat=png --zoomout=5 --zoomin=0 --chunkprocessors=2
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath (Join-Path $stagePath 'index.html') -PathType Leaf)) {
        throw "uNmINeD não conseguiu renderizar o mundo (código $LASTEXITCODE). O mapa anterior será preservado."
    }

    $metadata = [ordered]@{
        world_name = $WorldName
        instance_id = $InstanceId
        snapshot_at = $snapshotAt.ToString('o')
        generated_at = [DateTimeOffset]::Now.ToString('o')
        renderer = 'uNmINeD CLI'
        dimensions = @('overworld')
    }
    $utf8WithoutBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText((Join-Path $stagePath 'map-info.json'), ($metadata | ConvertTo-Json -Depth 4), $utf8WithoutBom)

    $backupPath = Assert-ChildPath (Join-Path $mapsRoot ".backup-$InstanceId-$([guid]::NewGuid().ToString('N'))") $mapsRoot
    $hadPreviousMap = Test-Path -LiteralPath $mapPath -PathType Container
    if ($hadPreviousMap) { Move-Item -LiteralPath $mapPath -Destination $backupPath }
    try {
        Move-Item -LiteralPath $stagePath -Destination $mapPath
        $stagePath = $null
    } catch {
        if ($hadPreviousMap -and (Test-Path -LiteralPath $backupPath -PathType Container) -and -not (Test-Path -LiteralPath $mapPath)) {
            Move-Item -LiteralPath $backupPath -Destination $mapPath
        }
        throw
    }
    if ($hadPreviousMap -and (Test-Path -LiteralPath $backupPath -PathType Container)) {
        $safeBackup = Assert-ChildPath $backupPath $mapsRoot
        Remove-Item -LiteralPath $safeBackup -Recurse -Force
        $backupPath = $null
    }
    Write-Host "Mapa de '$WorldName' publicado em $mapPath."
} finally {
    if ($stopRequested) {
        try {
            $null = Invoke-BedrockApi "$($api.Url)/api/v1/control" $api.Token 'Post' @{ action = 'start' }
            Write-Host "Reinício de '$InstanceId' solicitado."
            $readyDeadline = [DateTimeOffset]::Now.AddMinutes(3)
            $ready = $false
            while ([DateTimeOffset]::Now -lt $readyDeadline) {
                try {
                    $state = Invoke-RestMethod -Uri "$($api.Url)/health" -TimeoutSec 6
                    if ($state.status -eq 'ready') { $ready = $true; break }
                } catch { }
                Start-Sleep -Seconds 3
            }
            if (-not $ready) { Write-Warning "O start foi solicitado, mas /health não confirmou a volta de '$InstanceId' em três minutos. Confira os logs do add-on." }
            else { Write-Host "'$InstanceId' voltou a responder." }
        } catch {
            Write-Warning "Falhou a solicitação de reinício de '$InstanceId'. Confira o estado da instância no MineDev."
        }
    }
    if ($snapshotPath -and (Test-Path -LiteralPath $snapshotPath)) {
        $safeTemp = Assert-ChildPath $snapshotPath $env:TEMP
        Remove-Item -LiteralPath $safeTemp -Recurse -Force
        $parentTemp = Split-Path -Parent $safeTemp
        if ([System.IO.Path]::GetFileName($parentTemp) -like "minedev-map-$InstanceId-*") {
            $safeParent = Assert-ChildPath $parentTemp $env:TEMP
            Remove-Item -LiteralPath $safeParent -Recurse -Force
        }
    }
    if ($stagePath -and (Test-Path -LiteralPath $stagePath -PathType Container)) {
        $safeStage = Assert-ChildPath $stagePath $mapsRoot
        Remove-Item -LiteralPath $safeStage -Recurse -Force
    }
    if ($backupPath -and (Test-Path -LiteralPath $backupPath -PathType Container)) {
        $safeBackup = Assert-ChildPath $backupPath $mapsRoot
        if (-not (Test-Path -LiteralPath $mapPath -PathType Container)) {
            Move-Item -LiteralPath $safeBackup -Destination $mapPath
        } else {
            Remove-Item -LiteralPath $safeBackup -Recurse -Force
        }
    }
}
