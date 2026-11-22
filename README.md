# Inventario — Arranques y Alternadores la 8va

Inventario, compras, cuentas por pagar, ventas a crédito, abonos y saldos. Contapyme se queda con la factura electrónica DIAN y la contabilidad general: no se integra y este sistema no factura.
Requerimientos: [`docs/requerimientos-v1.1.md`](docs/requerimientos-v1.1.md) · Adenda (manda si contradice al v1.1): [`docs/adenda-v1.2-cartera-y-cuentas-por-pagar.md`](docs/adenda-v1.2-cartera-y-cuentas-por-pagar.md) · Supuestos: [`docs/supuestos.md`](docs/supuestos.md) · Reglas para el desarrollo: [`CLAUDE.md`](CLAUDE.md).

## Estructura

```
backend/    API REST · Laravel 12 (PHP 8.3) + MySQL 8 · Sanctum SPA     → backend/README.md
frontend/   SPA · React + Vite + TypeScript + TanStack Query            → frontend/README.md
docs/       requerimientos y supuestos (compartidos por las dos apps)
```

Son dos aplicaciones independientes: cada una con sus dependencias, su configuración (`.env`), sus tests y su forma de desplegarse. Solo se comunican por HTTP, a través de la API JSON bajo `/api`. El frontend no conoce la base de datos y el backend no sirve pantallas (la única vista que renderiza es el PDF de etiquetas).

```
 navegador ──► frontend (Vite :5173) ──proxy /api, /sanctum──► backend (Laravel :8000) ──► MySQL 8 (:3307)
```

## Arranque rápido (desarrollo)

```bash
# 1. Base de datos: MySQL 8 en el puerto 3307. La carpeta depende del equipo
#    (D:\mysql8 en el de César, C:\mysql8 en el de Franklin).
<carpeta-mysql>\iniciar-mysql.cmd

# 2. Backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve                 # http://localhost:8000

# 3. Frontend (otra terminal)
cd frontend
npm install
npm run dev                       # http://localhost:5173
```

Usuarios de desarrollo (contraseña `cambiar123`): `admin`, `logistica`, `mostrador`. Se inicia sesión con el nombre de usuario, no con correo (3 a 30 caracteres: minúsculas, números, punto, guion y guion bajo).

## Módulos y roles

| Rol | Pantallas |
|---|---|
| mostrador | Venta (escáner siempre enfocado, búsqueda de respaldo, reversos), agotados |
| logistica | Ingreso de remisiones y facturas (RF-05, RF-04), etiquetas (grande, pequeño, más pequeño), agotados |
| admin | Todo lo anterior + referencias, códigos universales, catálogos, usuarios y auditoría |

## Ingreso de remisiones y facturas

Logística crea el ingreso a mano: tipo (factura o remisión), proveedor, número y fecha. Luego escanea los productos: cada lectura busca el código entre las referencias de ese proveedor y suma 1 a su línea. Si el código no existe, la pantalla ofrece crear la referencia y la agrega sola. Cantidad, costo, descuento e IVA se corrigen en la tabla. Al confirmar se crean las unidades y se imprimen las etiquetas. Un mismo documento no entra dos veces. El QR y el XML de la factura ya no se leen (ver S-06, S-19 y S-20 en `docs/supuestos.md`).
