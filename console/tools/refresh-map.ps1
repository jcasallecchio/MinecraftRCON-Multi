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

if ($InstanceId -notmatch '^[a-z0-9][a-z0-9_-]{0,31}$') { throw 'InstanceId inválido.' }
if (-not $WorldName -or [System.IO.Path]::GetFileName($WorldName) -ne $WorldName -or $WorldName -in @('.', '..')) { throw 'WorldName precisa ser somente o nome da pasta do mundo.' }
if (-not (Test-Path -LiteralPath $RendererPath -PathType Leaf)) { throw "Não encontrei o executável uNmINeD CLI em $RendererPath" }

$consoleFull = (Resolve-Path -LiteralPath $ConsoleRoot).ProviderPath
$environment = Read-ConsoleEnvironment (Join-Path $consoleFull '.env')
$api = Get-InstanceApi $environment $InstanceId
$instanceRoot = Join-Path (Join-Path $ServerShareRoot 'instances') $InstanceId
$worldPath = Join-Path (Join-Path $instanceRoot 'worlds') $WorldName
$mapsRoot = Join-Path (Join-Path $consoleFull 'storage') 'maps'
$mapPath = Join-Path $mapsRoot $InstanceId
if (-not (Test-Path -LiteralPath $worldPath -PathType Container)) { throw "Pasta do mundo não encontrada: $worldPath" }
$health = Invoke-RestMethod -Uri "$($api.Url)/health" -TimeoutSec 8
if ($health.status -ne 'ready') { throw 'A instância não está pronta. Nenhuma cópia foi iniciada.' }
$beforeStop = Invoke-BedrockApi "$($api.Url)/api/v1/telemetry" $api.Token 'Get'
if ($beforeStop.server.instance_id -ne $InstanceId -or $beforeStop.server.status -ne 'running' -or $null -eq $beforeStop.server.uptime_seconds) {
    throw 'A telemetria não confirmou a instância e a sessão Bedrock em execução.'
}

try {
    $stopRequested = $true
    $null = Invoke-BedrockApi "$($api.Url)/api/v1/control" $api.Token 'Post' @{ action = 'stop' }
    Write-Host "Parada graciosa solicitada para '$InstanceId'. Aguardando a sessão Bedrock encerrar..."

    $deadline = [DateTimeOffset]::Now.AddMinutes(2)
    $stopConfirmed = $false
    while ([DateTimeOffset]::Now -lt $deadline) {
        $telemetry = Invoke-BedrockApi "$($api.Url)/api/v1/telemetry" $api.Token 'Get'
        if ($telemetry.server.instance_id -eq $InstanceId -and $telemetry.server.status -eq 'maintenance' -and $null -eq $telemetry.server.uptime_seconds) {
            Start-Sleep -Seconds 2
            $confirmed = Invoke-BedrockApi "$($api.Url)/api/v1/telemetry" $api.Token 'Get'
            if ($confirmed.server.status -eq 'maintenance' -and $null -eq $confirmed.server.uptime_seconds) {
                $stopConfirmed = $true
                break
            }
        }
        Start-Sleep -Seconds 2
    }
    if (-not $stopConfirmed) { throw 'A telemetria não confirmou o encerramento completo; o mundo não foi copiado.' }

    $snapshotAt = [DateTimeOffset]::Now
    $tempRoot = Assert-ChildPath (Join-Path $env:TEMP "minedev-map-$InstanceId-$([guid]::NewGuid().ToString('N'))") $env:TEMP
    $snapshotPath = Join-Path $tempRoot 'world'
    New-Item -ItemType Directory -Path $snapshotPath -Force | Out-Null
    Start-Sleep -Seconds 3
    $null = & robocopy.exe $worldPath $snapshotPath /E /R:2 /W:2 /NFL /NDL /NJH /NJS /NP
    if ($LASTEXITCODE -ge 8) { throw "A cópia do mundo falhou (robocopy: $LASTEXITCODE)." }
    $sourceFiles = @(Get-ChildItem -LiteralPath $worldPath -Recurse -File -Force | ForEach-Object { "$(($_.FullName.Substring($worldPath.Length)).ToLowerInvariant())|$($_.Length)" } | Sort-Object)
    $copyFiles = @(Get-ChildItem -LiteralPath $snapshotPath -Recurse -File -Force | ForEach-Object { "$(($_.FullName.Substring($snapshotPath.Length)).ToLowerInvariant())|$($_.Length)" } | Sort-Object)
    if (@(Compare-Object -ReferenceObject $sourceFiles -DifferenceObject $copyFiles).Count -gt 0) { throw 'A cópia temporária não corresponde ao mundo original.' }

    New-Item -ItemType Directory -Path $mapsRoot -Force | Out-Null
    $stagePath = Assert-ChildPath (Join-Path $mapsRoot ".stage-$InstanceId-$([guid]::NewGuid().ToString('N'))") $mapsRoot
    $null = & $RendererPath web render "--world=$snapshotPath" "--output=$stagePath" --dimension=overworld --imageformat=png --zoomout=5 --zoomin=3 --chunkprocessors=2
    if ($LASTEXITCODE -ne 0) {
        throw "uNmINeD não conseguiu renderizar o mundo (código $LASTEXITCODE). O mapa anterior será preservado."
    }
    $indexPath = Join-Path $stagePath 'index.html'
    $alternateIndex = Join-Path $stagePath 'unmined.index.html'
    if (-not (Test-Path -LiteralPath $indexPath -PathType Leaf) -and (Test-Path -LiteralPath $alternateIndex -PathType Leaf)) {
        Move-Item -LiteralPath $alternateIndex -Destination $indexPath
    }
    if (-not (Test-Path -LiteralPath $indexPath -PathType Leaf)) {
        throw 'uNmINeD não produziu index.html nem unmined.index.html. O mapa anterior será preservado.'
    }

    # Keep MineDev integrations and per-instance markers outside the generated export.
    $bridgeSource = Join-Path (Join-Path $consoleFull 'assets') 'minedev-map-bridge.js'
    $bridgeStyle = Join-Path (Join-Path $consoleFull 'assets') 'minedev-map-bridge.css'
    if (-not (Test-Path -LiteralPath $bridgeSource -PathType Leaf)) { throw "Integração do mapa não encontrada: $bridgeSource" }
    if (-not (Test-Path -LiteralPath $bridgeStyle -PathType Leaf)) { throw "Estilo do mapa não encontrado: $bridgeStyle" }
    Copy-Item -LiteralPath $bridgeSource -Destination (Join-Path $stagePath 'minedev-map-bridge.js') -Force
    Copy-Item -LiteralPath $bridgeStyle -Destination (Join-Path $stagePath 'minedev-map-bridge.css') -Force

    $overlayRoot = Join-Path (Join-Path (Join-Path $consoleFull 'storage') 'map-overlays') $InstanceId
    $overlayMarkers = Join-Path $overlayRoot 'custom.markers.js'
    $publishedMarkers = Join-Path $mapPath 'custom.markers.js'
    if (-not (Test-Path -LiteralPath $overlayMarkers -PathType Leaf) -and (Test-Path -LiteralPath $publishedMarkers -PathType Leaf)) {
        New-Item -ItemType Directory -Path $overlayRoot -Force | Out-Null
        Copy-Item -LiteralPath $publishedMarkers -Destination $overlayMarkers -Force
    }
    if (Test-Path -LiteralPath $overlayMarkers -PathType Leaf) {
        Copy-Item -LiteralPath $overlayMarkers -Destination (Join-Path $stagePath 'custom.markers.js') -Force
    }

    $seedMetadata = Join-Path (Join-Path $consoleFull 'storage') "seed-layer-$InstanceId.json"
    if (Test-Path -LiteralPath $seedMetadata -PathType Leaf) {
        $prediction = Get-Content -LiteralPath $seedMetadata -Raw | ConvertFrom-Json
        if ($prediction.instance_id -ne $InstanceId -or $prediction.min_x -ne $prediction.min_z -or
            $prediction.width -ne $prediction.height -or $prediction.step -ne 16 -or
            -[int]$prediction.min_x -ne ([int]$prediction.width * 8)) {
            throw 'Metadados da previsão pela seed inválidos; o mapa anterior será preservado.'
        }
        $radius = -[int]$prediction.min_x
        $propertiesPath = Join-Path $stagePath 'unmined.map.properties.js'
        $properties = [System.IO.File]::ReadAllText($propertiesPath)
        $limits = @{ minRegionX = -[int]($radius / 512); minRegionZ = -[int]($radius / 512);
                     maxRegionX = [int]([math]::Ceiling($radius / 512) - 1); maxRegionZ = [int]([math]::Ceiling($radius / 512) - 1) }
        foreach ($field in $limits.Keys) {
            $pattern = '(?m)(\b' + $field + ':\s*)(-?\d+)'
            $matches = [regex]::Matches($properties, $pattern)
            if ($matches.Count -ne 1) { throw "Campo $field inesperado no mapa exportado." }
            $current = [int]$matches[0].Groups[2].Value
            $limit = [int]$limits[$field]
            $value = if ($field.StartsWith('min')) { [math]::Min($current, $limit) } else { [math]::Max($current, $limit) }
            $replacement = [System.Text.RegularExpressions.MatchEvaluator]{ param($match) $match.Groups[1].Value + $value }
            $properties = [regex]::Replace($properties, $pattern, $replacement)
        }
        [System.IO.File]::WriteAllText($propertiesPath, $properties, [System.Text.UTF8Encoding]::new($false))
    }

    $mapHtml = [System.IO.File]::ReadAllText($indexPath)
    if (-not $mapHtml.Contains('</body>')) { throw 'O exportador mudou o HTML do mapa; a integração não foi instalada.' }
    $mapHtml = $mapHtml.Replace('</body>', "    <script src=`"minedev-map-bridge.js?v=29`"></script>`r`n</body>")
    $utf8WithoutBom = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($indexPath, $mapHtml, $utf8WithoutBom)

    $metadata = [ordered]@{
        world_name = $WorldName
        instance_id = $InstanceId
        snapshot_at = $snapshotAt.ToString('o')
        generated_at = [DateTimeOffset]::Now.ToString('o')
        renderer = 'uNmINeD CLI'
        dimensions = @('overworld')
    }
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
