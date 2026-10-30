# Backend — API de inventario (Laravel 12)

API REST bajo `/api`, con autenticación Sanctum en modo SPA (sesión por cookies). Ver el [README raíz](../README.md) para el panorama general.

## Requisitos

- PHP 8.3 con pdo_mysql, gd, mbstring, intl y zip
- Composer 2
- **MySQL 8** (InnoDB, utf8mb4): los tests y la búsqueda de respaldo usan ventanas, FULLTEXT y `lockForUpdate`.

## Puesta en marcha

```bash
composer install
cp .env.example .env              # DB_PORT=3307 por defecto
php artisan key:generate
php artisan migrate --seed        # usuarios, categorías, marcas y los ejemplos IM204 / IM210
php artisan serve                 # http://localhost:8000
```

Las bases `inventario` e `inventario_test` deben existir:

```bash
mysql --host=127.0.0.1 --port=3307 --user=root \
  --execute="CREATE DATABASE inventario CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
             CREATE DATABASE inventario_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

## Arquitectura

Las dependencias van en un solo sentido: **HTTP → Servicios → Modelos**.

```
app/
  Http/
    Controllers/Api/   Adaptador HTTP: valida, llama al servicio y responde. Sin reglas de negocio.
    Requests/          Validación de entrada (por ejemplo, la regla de RF-01 en ReferenciaRequest).
    Resources/         Forma del JSON de salida.
    Middleware/        EnsureRol (rol por endpoint) y MedirDuracion (S-04).
  Services/            Casos de uso y transacciones. Toda escritura de inventario pasa por aquí.
    InventarioService   ÚNICO punto que cambia referencias.existencia y el estado de las unidades.
    IngresoService      RF-05/09/10/03 · VentaService RF-06/12 · ReversoService RF-07
    CodigoBarrasService RF-03 · EtiquetaService RF-03 (PDF)
  Models/              Eloquent: relaciones, casts y reglas de integridad del propio registro.
    Concerns/Auditable  RF-11 · Concerns/Inmutable: ventas, reversos, movimientos y auditoría.
  Enums/               Estados y tipos del dominio (Rol, EstadoUnidad, MetodoCaptura…).
  Exceptions/          ReglaInventarioException (→ 4xx) y RegistroInmutableException (→ 409).
  Console/Commands/    inventario:recalcular, inventario:benchmark
```

Reglas:

- Un controlador **no** abre transacciones ni toca `existencia`: delega en un servicio.
- Las lecturas sencillas (listados, reportes) pueden consultar modelos desde el controlador, siempre con `with()` y paginación en el servidor. `preventLazyLoading` está activo fuera de producción.
- Las violaciones de reglas de negocio se lanzan como `ReglaInventarioException` y `bootstrap/app.php` las convierte en JSON.
- La configuración del dominio vive en `config/etiquetas.php` (prefijo del código de barras y tamaños de etiqueta).

## Tests

```bash
php artisan test                              # Feature + Concurrencia, contra inventario_test
./vendor/bin/pest --testsuite=Feature         # un test por criterio de aceptación de cada RF
./vendor/bin/pest --testsuite=Concurrencia    # dos procesos PHP venden la misma unidad: solo uno gana
```

## Mantenimiento y rendimiento

```bash
php artisan inventario:recalcular             # contador vs COUNT(unidades disponibles)
php artisan inventario:recalcular --corregir  # además ajusta el contador y lo deja en la auditoría
php artisan inventario:benchmark --n=300      # p50/p95 de escaneo y búsqueda (todo se revierte)
```

La duración de cada escaneo y búsqueda queda en `storage/logs/rendimiento-AAAA-MM-DD.log`. Si pasa del objetivo, se registra como `warning`. La cabecera `Server-Timing` también la trae.

## Despliegue

`SANCTUM_STATEFUL_DOMAINS` y `SESSION_DOMAIN` deben incluir el dominio desde el que se sirve el frontend. Lo más simple es servir los dos bajo el mismo dominio, con un proxy inverso de `/api` y `/sanctum` hacia este backend.
