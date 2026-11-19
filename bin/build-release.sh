#!/usr/bin/env bash
# Genera el paquete de producción para subir a IONOS por SFTP.
#   bin/build-release.sh 1.0.0
# Requiere git, PHP 8.2+, Composer y zip. Parte del commit actual (HEAD): los
# cambios sin confirmar NO entran en el paquete.
set -euo pipefail

version="${1:-}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Uso: $0 X.Y.Z" >&2
    exit 2
fi

repo="$(cd "$(dirname "$0")/.." && pwd)"
out="$repo/build"
name="univers365-incidencias-$version"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

if [[ -n "$(git -C "$repo" status --porcelain)" ]]; then
    echo "Aviso: hay cambios sin confirmar; el paquete se genera desde HEAD ($(git -C "$repo" rev-parse --short HEAD))." >&2
fi

# Solo lo versionado: imposible que se cuele config/config.php, app.key o storage/
git -C "$repo" archive --format=tar --prefix="$name/" HEAD | tar -x -C "$work"
cd "$work/$name"

composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative
# Si Composer no pudo descargar el paquete "dist" y clonó el repositorio, quita su historial
find vendor -name .git -prune -exec rm -rf {} +

# Fuera del paquete: pruebas, CI, herramientas de desarrollo y el diagnóstico
# (este se sube a mano solo cuando se necesita y se borra después)
rm -rf tests .github phpunit.xml .phpunit.cache tools/diagnostico.php build
rmdir tools 2>/dev/null || true
printf '%s\n' "$version" > VERSION
git -C "$repo" rev-parse HEAD > REVISION

# Comprobaciones de seguridad del contenido
if find . -path ./vendor -prune -o \( -name 'config.php' -path '*/config/*' -o -name '*.key' -o -name 'install.token' -o -name '.env*' \) -print | grep -q .; then
    echo "ERROR: el paquete contiene configuración o claves." >&2
    exit 1
fi
if [[ -n "$(find . -name .git -print -quit)" ]]; then
    echo "ERROR: el paquete contiene carpetas .git." >&2
    exit 1
fi
if [[ -n "$(find storage -mindepth 1 ! -name .gitkeep ! -name .htaccess -print -quit)" ]]; then
    echo "ERROR: storage/ no está vacío." >&2
    exit 1
fi
find app bin public -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

mkdir -p "$out"
rm -f "$out/$name.zip"
(cd "$work" && zip -qr -X "$out/$name.zip" "$name")
(cd "$out" && sha256sum "$name.zip" | tee "$name.zip.sha256")
echo "Paquete: $out/$name.zip"
