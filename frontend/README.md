# Frontend — SPA de inventario (React + Vite + TypeScript)

Consume la API del [backend](../backend/README.md). Ver el [README raíz](../README.md) para el panorama general.

## Puesta en marcha

```bash
npm install
cp .env.example .env     # opcional: BACKEND_URL si el backend no está en http://localhost:8000
npm run dev              # http://localhost:5173
npm run build            # tsc + build de producción en dist/
npm run lint
```

En desarrollo, Vite hace proxy de `/api` y `/sanctum` hacia el backend. Así, frontend y API comparten origen y las cookies de sesión de Sanctum funcionan sin CORS.

## Arquitectura

```
src/
  api/          cliente.ts (axios + CSRF de Sanctum, mensajeError) y tipos.ts (contratos de la API)
  auth/         AuthProvider: sesión actual y tieneRol()
  hooks/        useScanner (RF-12: ráfaga < 30 ms + Enter = escaneado), catálogos en caché, debounce
  componentes/  piezas compartidas: Layout (menú por rol) y Paginacion
  paginas/      una carpeta por módulo, igual que los roles del backend
    mostrador/  Venta (RF-06, RF-07, RF-12)
    logistica/  Ingresos, IngresoDetalle (RF-05, RF-09, RF-10), Etiquetas (RF-03)
    admin/      Referencias (RF-01), Universales y su desglose (RF-02), Catálogos, Usuarios, Auditoría (RF-11)
    Agotados    común a todos los roles (RF-08)
  App.tsx       rutas protegidas por rol (el backend vuelve a validar cada endpoint)
```

Reglas:

- **Estado del servidor solo con TanStack Query**: nada de copiar respuestas de la API a estados globales.
- **Toda llamada HTTP pasa por `api/cliente.ts`**, y los tipos de respuesta viven en `api/tipos.ts`.
- **El frontend no decide reglas de negocio** (existencias, permisos, reversos): las muestra. Quien manda es el backend.
- **La configuración del dominio no se duplica**: se pide a la API (por ejemplo, `GET /api/etiquetas/tamanos`).
