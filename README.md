# Inventario — Arranques y Alternadores la 8va

Sistema **solo de inventario**: la facturación, la contabilidad y la cartera se quedan en Contapyme.
Requerimientos: [`docs/requerimientos-v1.1.md`](docs/requerimientos-v1.1.md) · Supuestos abiertos: [`docs/supuestos.md`](docs/supuestos.md) · Reglas para el desarrollo: [`CLAUDE.md`](CLAUDE.md).

## Requisitos

- PHP 8.3 con pdo_mysql, gd, mbstring, intl y zip
- Composer 2
- Node 20 o superior
- **MySQL 8** (InnoDB, utf8mb4). Los tests y la búsqueda de respaldo usan funciones de MySQL 8 (ventanas, FULLTEXT, `lockForUpdate`).

## Puesta en marcha

```bash
composer install
cp .env.example .env          # ajuste DB_PORT/DB_USERNAME/DB_PASSWORD (por defecto, MySQL 8 en el puerto 3307)
php artisan key:generate

# crear las bases (una vez)
mysql -h127.0.0.1 -P3307 -uroot -e "CREATE DATABASE inventario CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                                     CREATE DATABASE inventario_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate --seed    # crea usuarios, categorías, marcas y los ejemplos IM204 / IM210
php artisan serve             # API en http://localhost:8000

cd frontend
npm install
npm run dev                   # http://localhost:5173 (con proxy de /api y /sanctum a :8000)
```

Usuarios de desarrollo (contraseña `cambiar123`, cámbiela antes de usar el sistema en el local):
`admin@la8va.local`, `logistica@la8va.local`, `mostrador@la8va.local`.

## Tests

```bash
php artisan test                                   # todo (Feature + Concurrencia) contra inventario_test
./vendor/bin/pest --testsuite=Feature              # un test por criterio de aceptación de cada RF
./vendor/bin/pest --testsuite=Concurrencia         # dos procesos PHP venden la misma unidad: solo uno gana
```

## Mantenimiento y rendimiento

```bash
php artisan inventario:recalcular             # compara referencias.existencia con COUNT(unidades disponibles)
php artisan inventario:recalcular --corregir  # además ajusta el contador y lo deja en la auditoría
php artisan inventario:benchmark --n=300      # p50/p95 de escaneo y búsqueda (no deja datos: todo se revierte)
```

La duración de cada escaneo y búsqueda queda en `storage/logs/rendimiento-AAAA-MM-DD.log`. Si una respuesta supera el objetivo, se registra como `warning`. La cabecera `Server-Timing` también trae la duración.

## Módulos y roles

| Rol | Pantallas |
|---|---|
| mostrador | Venta (escáner siempre enfocado, búsqueda de respaldo, reversos), agotados |
| logistica | Ingreso por remisión, etiquetas (grande, pequeño, más pequeño), agotados |
| admin | Todo lo anterior + referencias, códigos universales, catálogos, usuarios y auditoría |
