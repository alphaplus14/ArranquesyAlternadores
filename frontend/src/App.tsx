import { lazy, Suspense } from 'react'
import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import type { Rol } from './api/tipos'
import { useAuth } from './auth/AuthProvider'
import { RUTA_AJUSTES, RUTA_CAJA, RUTA_CAJA_ADMIN, RUTA_CARTERA, RUTA_CORRECCIONES, RUTA_PANEL, RUTA_UNIVERSALES, rutaInicio } from './rutas'
import Layout from './componentes/Layout'
import Login from './paginas/Login'
import Agotados from './paginas/Agotados'
import Venta from './paginas/mostrador/Venta'
import CierreDia from './paginas/mostrador/CierreDia'
import Cartera from './paginas/cartera/Cartera'
import CarteraCliente from './paginas/cartera/CarteraCliente'
import Ingresos from './paginas/logistica/Ingresos'
import IngresoDetalle from './paginas/logistica/IngresoDetalle'
import Etiquetas from './paginas/logistica/Etiquetas'
import Ajustes from './paginas/logistica/Ajustes'
import Productos from './paginas/Productos'
import Universales from './paginas/admin/Universales'
import UniversalDetalle from './paginas/admin/UniversalDetalle'
import Catalogos from './paginas/admin/Catalogos'
import Usuarios from './paginas/admin/Usuarios'
import Clientes from './paginas/admin/Clientes'
import Auditoria from './paginas/admin/Auditoria'
import Configuracion from './paginas/admin/Configuracion'
import Caja from './paginas/admin/Caja'
import CorreccionesIngreso from './paginas/admin/CorreccionesIngreso'

// RF-23: el panel (con sus gráficos) va en su propio paquete; no engorda la carga de los demás roles.
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
        {/* "/" lleva a la pantalla de inicio del rol (rutas.ts). */}
        <Route path="/" element={<Navigate to={usuario ? rutaInicio(usuario.rol) : '/login'} replace />} />
        <Route path="/productos" element={<Productos />} />
        <Route element={<ConRol roles={['mostrador']} />}>
          <Route path="/mostrador/venta" element={<Venta />} />
        </Route>
        {/* RF-15 / RF-18 (S-30): mostrador y admin; logística va a su inicio. */}
        <Route element={<ConRol roles={['mostrador', 'admin']} />}>
          <Route path={RUTA_CARTERA} element={<Cartera />} />
          <Route path={`${RUTA_CARTERA}/:clienteId`} element={<CarteraCliente />} />
          {/* RF-25 / RF-26 (S-54): caja del día; el admin pasa a su Caja (consulta). Logística, a su inicio. */}
          <Route path={RUTA_CAJA} element={<CierreDia />} />
        </Route>
        <Route element={<ConRol roles={['logistica']} />}>
          <Route path="/logistica/ingresos" element={<Ingresos />} />
          <Route path="/logistica/ingresos/:id" element={<IngresoDetalle />} />
          <Route path="/logistica/etiquetas" element={<Etiquetas />} />
        </Route>
        <Route element={<ConRol roles={['logistica', 'admin']} />}>
          <Route path={RUTA_AJUSTES} element={<Ajustes />} />
          {/* RF-08 / RF-22 / S-28: agotados y bajo mínimo, sin mostrador. */}
          <Route path="/agotados" element={<Agotados />} />
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
