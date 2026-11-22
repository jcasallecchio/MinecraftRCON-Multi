param(
    [Parameter(Mandatory = $true)][string]$EnginePath,
    [Parameter(Mandatory = $true)][string]$ConsoleRoot,
    [int]$PollSeconds = 2
)

$ErrorActionPreference = 'Stop'
$engine = (Resolve-Path -LiteralPath $EnginePath).ProviderPath
$console = (Resolve-Path -LiteralPath $ConsoleRoot).ProviderPath
$source = Join-Path $PSScriptRoot 'seed-tile.c'
$storage = Join-Path $console 'storage'
$queue = Join-Path $storage 'seed-jobs'
if (-not (Test-Path -LiteralPath (Join-Path $engine 'finders.h') -PathType Leaf)) { throw 'EnginePath não aponta para cubiomes-bedrock.' }
if (-not (Test-Path -LiteralPath $storage -PathType Container)) { throw 'ConsoleRoot não contém storage/.' }
if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) { throw 'WSL não está disponível.' }
if ($PollSeconds -lt 1 -or $PollSeconds -gt 30) { throw 'PollSeconds deve ficar entre 1 e 30.' }
New-Item -ItemType Directory -Path $queue -Force | Out-Null

# Requeue work left behind if Windows or the worker stopped during a calculation.
Get-ChildItem -LiteralPath $queue -Filter '*.json.working' -File | ForEach-Object {
    if ($_.LastWriteTime -lt (Get-Date).AddMinutes(-5)) {
        $retry = $_.FullName -replace '\.working$', ''
        if (-not (Test-Path -LiteralPath $retry)) { Move-Item -LiteralPath $_.FullName -Destination $retry }
    }
}

function To-WslPath([string]$Path) {
    $value = & wsl.exe --exec wslpath -a -u $Path
    if ($LASTEXITCODE -ne 0 -or -not $value) { throw "WSL não conseguiu acessar $Path" }
    return ($value | Select-Object -Last 1).Trim()
}

$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
$temp = [IO.Path]::GetFullPath((Join-Path $tempRoot 'minedev-seed-worker'))
if (-not $temp.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'Pasta temporária inválida.' }
New-Item -ItemType Directory -Path $temp -Force | Out-Null
$engineWsl = To-WslPath $engine
$sourceWsl = To-WslPath $source
$tempWsl = To-WslPath $temp
& wsl.exe --exec make -C $engineWsl -j2
if ($LASTEXITCODE -ne 0) { throw 'Falha ao compilar cubiomes-bedrock.' }
$binary = "$tempWsl/seed-tile"
& wsl.exe --exec gcc -O2 -Wall -Wextra -fwrapv "-I$engineWsl" $sourceWsl "$engineWsl/libcubiomes.a" -lm -pthread -o $binary
if ($LASTEXITCODE -ne 0) { throw 'Falha ao compilar o gerador de setores.' }
$outputWsl = "$tempWsl/tile.json"
$lockPath = Join-Path $queue '.worker.lock'
$lock = [IO.File]::Open($lockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
Write-Host "MineDev tile worker ativo. Fila: $queue"
try {
    while ($true) {
        $job = Get-ChildItem -LiteralPath $queue -Filter '*.json' -File | Sort-Object LastWriteTime | Select-Object -First 1
        if (-not $job) { Start-Sleep -Seconds $PollSeconds; continue }
        $claim = $job.FullName + '.working'
        try { Move-Item -LiteralPath $job.FullName -Destination $claim -ErrorAction Stop }
        catch { Start-Sleep -Milliseconds 250; continue }
        try {
            $request = Get-Content -LiteralPath $claim -Raw | ConvertFrom-Json
            if ($request.instance_id -notmatch '^[a-z0-9][a-z0-9_-]{0,31}$' -or
                $request.revision -notmatch '^[a-f0-9]{24}$' -or
                $request.x -lt -16 -or $request.x -gt 15 -or $request.z -lt -16 -or $request.z -gt 15) {
                throw 'Solicitação de setor inválida.'
            }
            $metadataPath = Join-Path $storage "seed-layer-$($request.instance_id).json"
            if (-not (Test-Path -LiteralPath $metadataPath -PathType Leaf)) { throw 'Metadados da seed ausentes.' }
            $metadata = Get-Content -LiteralPath $metadataPath -Raw | ConvertFrom-Json
            if ($metadata.instance_id -ne $request.instance_id -or $metadata.tile_revision -ne $request.revision) {
                throw 'A seed mudou antes de o setor ser calculado.'
            }
            $radius = -[int]$metadata.min_x
            if ($radius -lt 512 -or $radius -gt 32768) { throw 'Raio da seed inválido.' }
            $targetDir = Join-Path $storage "seed-tiles-$($request.instance_id)-$($request.revision)"
            New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
            $target = Join-Path $targetDir "$($request.x)_$($request.z).json"
            if (-not (Test-Path -LiteralPath $target -PathType Leaf)) {
                & wsl.exe --exec $binary $metadata.seed $radius $request.x $request.z $outputWsl
                if ($LASTEXITCODE -ne 0) { throw 'O gerador não concluiu o setor.' }
                $generated = Join-Path $temp 'tile.json'
                $json = [IO.File]::ReadAllText($generated)
                if (-not $json.Trim().StartsWith('[') -or -not $json.Trim().EndsWith(']')) { throw 'JSON do setor não é uma lista.' }
                $null = ConvertFrom-Json -InputObject $json -ErrorAction Stop
                $bytes = [IO.File]::ReadAllBytes($generated)
                $staged = $target + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
                [IO.File]::WriteAllBytes($staged, $bytes)
                Move-Item -LiteralPath $staged -Destination $target -Force
            }
            Remove-Item -LiteralPath $claim -Force
            Write-Host "Setor pronto: $($request.instance_id) $($request.x),$($request.z)"
        } catch {
            $failed = $claim + '.failed'
            Move-Item -LiteralPath $claim -Destination $failed -Force
            Write-Warning "Falha ao calcular $($job.Name): $($_.Exception.Message)"
        }
    }
} finally { $lock.Dispose() }
