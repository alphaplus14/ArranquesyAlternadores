# Inventario — Arranques y Alternadores la 8va

Sistema **solo de inventario**: la facturación, la contabilidad y la cartera se quedan en Contapyme.
Requerimientos: [`docs/requerimientos-v1.1.md`](docs/requerimientos-v1.1.md) · Supuestos abiertos: [`docs/supuestos.md`](docs/supuestos.md) · Reglas para el desarrollo: [`CLAUDE.md`](CLAUDE.md).

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
# 1. Base de datos (MySQL 8 en D:\mysql8, puerto 3307)
D:\mysql8\iniciar-mysql.cmd

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

Usuarios de desarrollo (contraseña `cambiar123`): `admin@la8va.local`, `logistica@la8va.local`, `mostrador@la8va.local`.

## Módulos y roles

| Rol | Pantallas |
|---|---|
| mostrador | Venta (escáner siempre enfocado, búsqueda de respaldo, reversos), agotados |
| logistica | Ingreso por remisión, etiquetas (grande, pequeño, más pequeño), agotados |
| admin | Todo lo anterior + referencias, códigos universales, catálogos, usuarios y auditoría |
