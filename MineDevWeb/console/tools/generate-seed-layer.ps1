param(
    [Parameter(Mandatory = $true)][string]$InstanceId,
    [Parameter(Mandatory = $true)][string]$Seed,
    [Parameter(Mandatory = $true)][string]$EnginePath,
    [Parameter(Mandatory = $true)][string]$ConsoleRoot,
    [ValidateRange(512, 32768)][int]$Radius = 32768,
    [ValidateRange(16, 32768)][int]$DungeonRadius = 512,
    [string]$ServerVersion = ''
)

$ErrorActionPreference = 'Stop'
if ($InstanceId -cnotmatch '^[a-z0-9][a-z0-9_-]{0,31}$') { throw 'InstanceId inválido.' }
if ($Seed -notmatch '^-?\d{1,19}$') { throw 'Seed precisa ser um inteiro de 64 bits.' }
try { $null = [long]::Parse($Seed, [Globalization.CultureInfo]::InvariantCulture) }
catch { throw 'Seed fora do intervalo de 64 bits.' }
if ($Radius % 16 -ne 0) { throw 'Radius precisa ser múltiplo de 16.' }
if ($DungeonRadius % 16 -ne 0 -or $DungeonRadius -gt $Radius) { throw 'DungeonRadius precisa ser múltiplo de 16 e não pode superar Radius.' }
$engine = (Resolve-Path -LiteralPath $EnginePath).ProviderPath
$console = (Resolve-Path -LiteralPath $ConsoleRoot).ProviderPath
$source = Join-Path $PSScriptRoot 'seed-layer.c'
$dungeonSource = Join-Path $PSScriptRoot 'scan-dungeons.c'
$publisher = Join-Path $PSScriptRoot 'publish-seed-layer.py'
if (-not (Test-Path -LiteralPath (Join-Path $engine 'finders.h') -PathType Leaf)) { throw 'EnginePath não aponta para cubiomes-bedrock.' }
if (-not (Test-Path -LiteralPath $source -PathType Leaf) -or -not (Test-Path -LiteralPath $dungeonSource -PathType Leaf) -or -not (Test-Path -LiteralPath $publisher -PathType Leaf)) { throw 'Ferramentas da previsão ausentes.' }
if (-not (Test-Path -LiteralPath (Join-Path $console 'storage') -PathType Container)) { throw 'ConsoleRoot precisa conter storage/.' }
if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) { throw 'WSL não está disponível neste Windows.' }
if (-not (Get-Command python.exe -ErrorAction SilentlyContinue)) { throw 'Python 3 não está disponível neste Windows.' }

function To-WslPath([string]$WindowsPath) {
    $value = & wsl.exe --exec wslpath -a -u $WindowsPath
    if ($LASTEXITCODE -ne 0 -or -not $value) { throw "WSL não conseguiu acessar $WindowsPath" }
    return ($value | Select-Object -Last 1).Trim()
}

$temporaryRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
$temporary = [IO.Path]::GetFullPath((Join-Path $temporaryRoot ("minedev-seed-" + [guid]::NewGuid().ToString('N'))))
if (-not $temporary.StartsWith($temporaryRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'Caminho temporário fora de TEMP.' }
New-Item -ItemType Directory -Path $temporary | Out-Null
try {
    $engineWsl = To-WslPath $engine
    $sourceWsl = To-WslPath $source
    $dungeonSourceWsl = To-WslPath $dungeonSource
    $temporaryWsl = To-WslPath $temporary
    & wsl.exe --exec make -C $engineWsl -j2
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao compilar cubiomes-bedrock.' }
    $binary = "$temporaryWsl/seed-layer"
    & wsl.exe --exec gcc -O2 -Wall -Wextra -fwrapv "-I$engineWsl" $sourceWsl "$engineWsl/libcubiomes.a" -lm -pthread -o $binary
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao compilar a ferramenta MineDev.' }
    & wsl.exe --exec $binary $Seed $Radius "$temporaryWsl/biomes.ppm" "$temporaryWsl/layer.json" "$temporaryWsl/slime.pgm"
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao calcular a previsão.' }
    $dungeonBinary = "$temporaryWsl/scan-dungeons"
    & wsl.exe --exec gcc -O2 -Wall -Wextra -fwrapv "-I$engineWsl" $dungeonSourceWsl "$engineWsl/libcubiomes.a" -lm -pthread -o $dungeonBinary
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao compilar a busca de masmorras.' }
    & wsl.exe --exec $dungeonBinary $Seed 0 0 $DungeonRadius "$temporaryWsl/dungeons.json"
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao calcular as masmorras.' }
    & python.exe $publisher --instance-id $InstanceId --seed $Seed --ppm (Join-Path $temporary 'biomes.ppm') --slime (Join-Path $temporary 'slime.pgm') --json (Join-Path $temporary 'layer.json') --dungeons (Join-Path $temporary 'dungeons.json') --console-root $console --server-version $ServerVersion
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao publicar a previsão.' }
} finally {
    if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Recurse -Force }
}
