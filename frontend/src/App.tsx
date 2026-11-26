import { lazy, Suspense } from 'react'
import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import type { Rol } from './api/tipos'
import { useAuth } from './auth/AuthProvider'
import { RUTA_AJUSTES, RUTA_CAJA, RUTA_CAJA_ADMIN, RUTA_CARTERA, RUTA_CORRECCIONES, RUTA_COSTOS, RUTA_PANEL, RUTA_PRECIOS_COMPRA, RUTA_UNIVERSALES, rutaInicio } from './rutas'
import Layout from './componentes/Layout'
import Login from './paginas/Login'

// Cada pantalla va en su propio paquete: cada rol descarga solo lo que abre (el mostrador no baja
// logística, administración ni contabilidad). Layout las espera con un Suspense; main.tsx recarga
// si, tras un despliegue, el paquete de una pantalla ya no existe.
const Agotados = lazy(() => import('./paginas/Agotados'))
const Costos = lazy(() => import('./paginas/consulta/Costos'))
const Venta = lazy(() => import('./paginas/mostrador/Venta'))
const CierreDia = lazy(() => import('./paginas/mostrador/CierreDia'))
const Cartera = lazy(() => import('./paginas/cartera/Cartera'))
const CarteraCliente = lazy(() => import('./paginas/cartera/CarteraCliente'))
const Ingresos = lazy(() => import('./paginas/logistica/Ingresos'))
const IngresoDetalle = lazy(() => import('./paginas/logistica/IngresoDetalle'))
const Etiquetas = lazy(() => import('./paginas/logistica/Etiquetas'))
const Ajustes = lazy(() => import('./paginas/logistica/Ajustes'))
const Productos = lazy(() => import('./paginas/Productos'))
const Universales = lazy(() => import('./paginas/admin/Universales'))
const UniversalDetalle = lazy(() => import('./paginas/admin/UniversalDetalle'))
const Catalogos = lazy(() => import('./paginas/admin/Catalogos'))
const Usuarios = lazy(() => import('./paginas/admin/Usuarios'))
const Clientes = lazy(() => import('./paginas/admin/Clientes'))
const Auditoria = lazy(() => import('./paginas/admin/Auditoria'))
const Configuracion = lazy(() => import('./paginas/admin/Configuracion'))
const Caja = lazy(() => import('./paginas/admin/Caja'))
const CorreccionesIngreso = lazy(() => import('./paginas/admin/CorreccionesIngreso'))
const Vencimientos = lazy(() => import('./paginas/contable/Vencimientos'))
const Saldos = lazy(() => import('./paginas/contable/Saldos'))
const ProveedorCuenta = lazy(() => import('./paginas/contable/ProveedorCuenta'))
const ClienteCuenta = lazy(() => import('./paginas/contable/ClienteCuenta'))
const Informes = lazy(() => import('./paginas/contable/Informes'))
const RecaudosContable = lazy(() => import('./paginas/contable/Recaudos')) // S-76
// RF-23: el panel (con sus gráficos) también; tiene su propio aviso de carga.
const Panel = lazy(() => import('./paginas/admin/Panel'))

/** Protege un grupo de rutas por rol (el backend vuelve a validar cada endpoint). */
function ConRol({ roles }: { roles: Rol[] }) {
  const { tieneRol, usuario } = useAuth()
  if (!usuario) return <Navigate to="/login" replace />
  return tieneRol(...roles) ? <Outlet /> : <Navigate to={rutaInicio(usuario.rol)} replace />
}

export default function App() {
  const { usuario, cargando } = useAuth()
  if (cargando) return <div className="cargando">Cargando…</div>

  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={usuario ? <Layout /> : <Navigate to="/login" replace />}>
        <Route path="/" element={<Navigate to={usuario ? rutaInicio(usuario.rol) : '/login'} replace />} />
        <Route path="/productos" element={<Productos />} />
        <Route element={<ConRol roles={['contable']} />}>
          <Route path="/contable/vencimientos" element={<Vencimientos />} />
          <Route path="/contable/cuentas-por-pagar" element={<Saldos de="proveedores" />} />
          <Route path="/contable/proveedores/:id" element={<ProveedorCuenta />} />
          <Route path="/contable/cartera" element={<Saldos de="clientes" />} />
          <Route path="/contable/clientes/:id" element={<ClienteCuenta />} />
          <Route path="/contable/informes" element={<Informes />} />
          <Route path="/contable/recaudos" element={<RecaudosContable />} />
        </Route>
        <Route element={<ConRol roles={['mostrador']} />}>
          <Route path="/mostrador/venta" element={<Venta />} />
        </Route>
        <Route element={<ConRol roles={['mostrador', 'admin']} />}>
          <Route path={RUTA_CARTERA} element={<Cartera />} />
          <Route path={`${RUTA_CARTERA}/:clienteId`} element={<CarteraCliente />} />
          {/* RF-25 / RF-26 (S-54): caja del día; el admin pasa a su Caja (consulta). Logística, a su inicio. */}
          <Route path={RUTA_CAJA} element={<CierreDia />} />
        </Route>
        <Route element={<ConRol roles={['logistica']} />}>
          <Route path="/logistica/ingresos" element={<Ingresos />} />
          <Route path="/logistica/ingresos/:id" element={<IngresoDetalle />} />
          <Route path={RUTA_PRECIOS_COMPRA} element={<Navigate to={RUTA_COSTOS} replace />} />
          <Route path="/logistica/etiquetas" element={<Etiquetas />} />
        </Route>
        <Route element={<ConRol roles={['logistica', 'admin']} />}>
          <Route path={RUTA_AJUSTES} element={<Ajustes />} />
          <Route path="/agotados" element={<Agotados />} />
          {/* S-71: historial de costos (ver-historial-costos: admin y logística; el mostrador vuelve a su inicio). */}
          <Route path={RUTA_COSTOS} element={<Costos />} />
          {/* S-59: logística crea y edita códigos universales (mismas pantallas del admin, sin eliminar). */}
          <Route path={RUTA_UNIVERSALES} element={<Universales />} />
          <Route path={`${RUTA_UNIVERSALES}/:id`} element={<UniversalDetalle />} />
        </Route>
        <Route element={<ConRol roles={['admin']} />}>
          <Route path={RUTA_PANEL} element={<Suspense fallback={<div className="cargando">Cargando el panel…</div>}><Panel /></Suspense>} />
          <Route path="/admin/referencias" element={<Navigate to="/productos" replace />} />
          {/* Enlaces viejos del admin: S-59 movió los universales a RUTA_UNIVERSALES. */}
          <Route path="/admin/universales" element={<Navigate to={RUTA_UNIVERSALES} replace />} />
          <Route path="/admin/universales/:id" element={<UniversalDetalle />} />
          <Route path="/admin/catalogos" element={<Catalogos />} />
          <Route path="/admin/usuarios" element={<Usuarios />} />
          <Route path="/admin/clientes" element={<Clientes />} />
          <Route path="/admin/auditoria" element={<Auditoria />} />
          {/* RF-28: correcciones de ingresos confirmados (solo consulta). */}
          <Route path={RUTA_CORRECCIONES} element={<CorreccionesIngreso />} />
          <Route path="/admin/configuracion" element={<Configuracion />} />
          {/* RF-25 a RF-27: Caja del admin (día en consulta, mes, cuentas y categorías, empleados). */}
          <Route path={RUTA_CAJA_ADMIN} element={<Caja />} />
        </Route>
        <Route path="*" element={<Navigate to={usuario ? rutaInicio(usuario.rol) : '/login'} replace />} />
      </Route>
    </Routes>
  )
}