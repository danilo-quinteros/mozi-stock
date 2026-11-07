# Empaqueta MoziStock para un hosting compartido (sin Node ni Composer en el servidor).
# Uso, desde la carpeta app:  powershell -ExecutionPolicy Bypass -File scripts\empaquetar-produccion.ps1
# Resultado: ..\dist\mozistock-AAAAMMDD-HHMM.zip, listo para subir y descomprimir en el servidor.
#
# El paquete NO lleva .env, datos (storage/app), registros, pruebas, node_modules ni dependencias
# de desarrollo. Se arma en una carpeta temporal: tu carpeta de trabajo no se modifica (salvo
# public/build, que se recompila).

param([string]$Salida = (Join-Path $PSScriptRoot '..\..\dist'))

$ErrorActionPreference = 'Stop'
$app = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$fecha = Get-Date -Format 'yyyyMMdd-HHmm'
$tmp = Join-Path ([IO.Path]::GetTempPath()) "mozistock-build-$fecha"
New-Item -ItemType Directory -Force $Salida | Out-Null
$zip = Join-Path (Resolve-Path $Salida).Path "mozistock-$fecha.zip"

Write-Host '1/5 Compilando estilos y scripts (public/build)...'
Push-Location $app
try {
    npm run build
    if ($LASTEXITCODE -ne 0) { throw 'npm run build fallo' }
} finally { Pop-Location }

Write-Host '2/5 Copiando el codigo a una carpeta limpia...'
$excluirCarpetas = @('node_modules', 'vendor', 'tests', '.git', '.github', '.idea', '.vscode', 'nbproject',
    (Join-Path $app 'storage'), (Join-Path $app 'bootstrap\cache'))
$excluirArchivos = @('.env', '.env.backup', '.env.testing', '.env.local', 'hot', '*.log', '*.zip', '*.sqlite',
    'ejecutar_*.php', 'phpunit.xml', '.phpunit.result.cache')
robocopy $app $tmp /E /NFL /NDL /NJH /NJS /NP /XD $excluirCarpetas /XF $excluirArchivos | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy fallo (codigo $LASTEXITCODE)" }

# Estructura vacia que Laravel necesita escribir en el servidor.
foreach ($carpeta in @('storage\app\private', 'storage\app\public', 'storage\framework\cache\data',
        'storage\framework\sessions', 'storage\framework\views', 'storage\logs', 'bootstrap\cache')) {
    New-Item -ItemType Directory -Force (Join-Path $tmp $carpeta) | Out-Null
}

Write-Host '3/5 Instalando dependencias de produccion (composer --no-dev)...'
Push-Location $tmp
try {
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
    if ($LASTEXITCODE -ne 0) { throw 'composer install fallo' }
} finally { Pop-Location }

# El paquete SIAT local se instala como enlace (junction en Windows): se reemplaza por una copia
# real para que funcione en Linux. rmdir quita solo el enlace, no su contenido.
$siat = Join-Path $tmp 'vendor\mozistudios\siat'
if ((Get-Item $siat).Attributes -band [IO.FileAttributes]::ReparsePoint) {
    cmd /c rmdir "$siat"
    robocopy (Join-Path $tmp 'packages\siat') $siat /E /NFL /NDL /NJH /NJS /NP /XD vendor tests | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "copia del paquete SIAT fallo (codigo $LASTEXITCODE)" }
}

# Caches generadas en Windows (rutas C:\...): se regeneran en el servidor.
Get-ChildItem (Join-Path $tmp 'bootstrap\cache') -Filter '*.php' | Remove-Item -Force

Write-Host '4/5 Comprimiendo...'
# tar.exe (incluido en Windows 10/11) escribe rutas con "/", que Linux descomprime bien.
tar.exe -a -c -f $zip -C $tmp .
if ($LASTEXITCODE -ne 0) { throw 'tar fallo al crear el zip' }

Write-Host '5/5 Limpiando la carpeta temporal...'
Remove-Item -Recurse -Force $tmp

$mb = [math]::Round((Get-Item $zip).Length / 1MB, 1)
Write-Host "Listo: $zip ($mb MB)"
