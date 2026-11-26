#!/usr/bin/env bash
# Worktrees aislados para los agentes del orquestador (ver .claude/ORQUESTACION.md).
#
#   bash .claude/scripts/agentes.sh crear <nombre> [--sin-front] [--sin-back]
#   bash .claude/scripts/agentes.sh tests [argumentos de php artisan test]
#   bash .claude/scripts/agentes.sh listar
#   bash .claude/scripts/agentes.sh borrar <nombre>
#
# Cada agente trabaja en <ARRANQUES_WT>/<nombre> (por defecto C:\dev\arranques-wt, fuera de OneDrive),
# en la rama agente/<nombre> creada desde el HEAD de la carpeta principal, con su propia base MySQL
# inventario_test_<nombre>. Así dos agentes pueden correr Pest a la vez sin pisarse.
# Variables opcionales: ARRANQUES_WT (carpeta de los worktrees), MYSQL_BIN (carpeta bin de MySQL 8).
set -euo pipefail

RAIZ_WT="${ARRANQUES_WT:-/c/dev/arranques-wt}"
MYSQL_ARGS=(--host=127.0.0.1 --port=3307 --user=root)

fallar() { echo "agentes.sh: $*" >&2; exit 1; }

principal() {
    local comun
    comun="$(git rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" || fallar "no estoy dentro del repositorio."
    dirname "$comun"
}

mysql_cli() {
    if [ -n "${MYSQL_BIN:-}" ]; then echo "$MYSQL_BIN/mysql"; return; fi
    local d
    for d in /c/mysql8/bin /d/mysql8/bin; do
        [ -x "$d/mysql.exe" ] && { echo "$d/mysql.exe"; return; }
    done
    command -v mysql || fallar "no encuentro el cliente mysql; defina MYSQL_BIN."
}

sql() { "$(mysql_cli)" "${MYSQL_ARGS[@]}" -N -e "$1"; }

exigir_mysql() {
    sql "SELECT 1" >/dev/null 2>&1 || fallar "MySQL no responde en 127.0.0.1:3307. Arránquelo con <carpeta-mysql>\\iniciar-mysql.cmd."
}

validar_nombre() {
    [[ "$1" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || fallar "nombre inválido '$1': minúsculas, números y guion (máx. 31)."
}

base_de() { echo "inventario_test_${1//-/_}"; }

crear() {
    local nombre="${1:-}"; shift || true
    validar_nombre "$nombre"
    local con_front=1 con_back=1 op
    for op in "$@"; do
        case "$op" in
            --sin-front) con_front=0 ;;
            --sin-back) con_back=0 ;;
            *) fallar "opción desconocida: $op" ;;
        esac
    done

    local raiz dir rama base
    raiz="$(principal)"; dir="$RAIZ_WT/$nombre"; rama="agente/$nombre"; base="$(base_de "$nombre")"
    [ -e "$dir" ] && fallar "ya existe $dir."
    git -C "$raiz" show-ref --verify --quiet "refs/heads/$rama" && fallar "ya existe la rama $rama."
    exigir_mysql

    mkdir -p "$RAIZ_WT"
    git -C "$raiz" worktree add -q -b "$rama" "$dir" HEAD

    # .env propio: cualquier artisan dentro del worktree usa solo la base del agente.
    sed -E "s/^DB_DATABASE=.*/DB_DATABASE=$base/" "$raiz/backend/.env" > "$dir/backend/.env"
    [ -f "$raiz/frontend/.env" ] && cp "$raiz/frontend/.env" "$dir/frontend/.env"
    sql "CREATE DATABASE IF NOT EXISTS \`$base\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

    if [ "$con_back" = 1 ]; then
        (cd "$dir/backend" && composer install --no-interaction --no-progress -q)
    fi
    if [ "$con_front" = 1 ]; then
        (cd "$dir/frontend" && npm ci --no-audit --no-fund --loglevel=error >/dev/null)
    fi

    echo "Worktree:  $dir"
    echo "Rama:      $rama (desde $(git -C "$raiz" rev-parse --short HEAD) de $(git -C "$raiz" branch --show-current))"
    echo "Base:      $base"
    echo "Tests:     cd \"$dir\" && bash .claude/scripts/agentes.sh tests [--filter=...]"
}

tests() {
    local raiz actual base
    raiz="$(principal)"
    actual="$(git rev-parse --show-toplevel)"
    if [ "$actual" -ef "$raiz" ]; then
        base="inventario_test"      # carpeta principal: la base de siempre (phpunit.xml)
    else
        base="$(base_de "$(basename "$actual")")"
    fi
    [[ "$base" == inventario_test* ]] || fallar "base inesperada '$base'."
    exigir_mysql
    sql "CREATE DATABASE IF NOT EXISTS \`$base\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    echo "Pest contra $base"
    cd "$actual/backend" && DB_DATABASE="$base" php artisan test "$@"
}

listar() {
    git -C "$(principal)" worktree list
    echo "--- bases de los agentes"
    sql "SHOW DATABASES LIKE 'inventario\\_test\\_%';" 2>/dev/null || echo "(MySQL apagado)"
}

borrar() {
    local nombre="${1:-}"
    validar_nombre "$nombre"
    local raiz dir rama base
    raiz="$(principal)"; dir="$RAIZ_WT/$nombre"; rama="agente/$nombre"; base="$(base_de "$nombre")"

    if [ -d "$dir" ]; then
        git -C "$raiz" worktree remove "$dir" \
            || fallar "el worktree tiene cambios sin commitear; revíselos o descártelos antes de borrarlo."
    fi
    git -C "$raiz" worktree prune
    sql "DROP DATABASE IF EXISTS \`$base\`;" 2>/dev/null || echo "Aviso: no pude borrar $base (¿MySQL apagado?)."

    if git -C "$raiz" show-ref --verify --quiet "refs/heads/$rama"; then
        if git -C "$raiz" branch -d "$rama" >/dev/null 2>&1; then
            echo "Rama $rama borrada (ya estaba integrada)."
        else
            echo "La rama $rama tiene commits sin integrar: se conserva."
        fi
    fi
    echo "Listo: $nombre."
}

case "${1:-}" in
    crear) shift; crear "$@" ;;
    tests) shift; tests "$@" ;;
    listar) listar ;;
    borrar) shift; borrar "$@" ;;
    *) sed -n '2,8p' "$0"; exit 1 ;;
esac
