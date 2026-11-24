import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import type { Rol } from './api/tipos'
import { useAuth } from './auth/AuthProvider'
import { RUTA_AJUSTES, RUTA_CARTERA, rutaInicio } from './rutas'
import Layout from './componentes/Layout'
import Login from './paginas/Login'
import Agotados from './paginas/Agotados'
import Venta from './paginas/mostrador/Venta'
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
import Vencimientos from './paginas/contable/Vencimientos'
import Saldos from './paginas/contable/Saldos'
import ProveedorCuenta from './paginas/contable/ProveedorCuenta'
import ClienteCuenta from './paginas/contable/ClienteCuenta'
import Informes from './paginas/contable/Informes'

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
        </Route>
        <Route element={<ConRol roles={['mostrador']} />}>
          <Route path="/mostrador/venta" element={<Venta />} />
        </Route>
        <Route element={<ConRol roles={['mostrador', 'admin']} />}>
          <Route path={RUTA_CARTERA} element={<Cartera />} />
          <Route path={`${RUTA_CARTERA}/:clienteId`} element={<CarteraCliente />} />
        </Route>
        <Route element={<ConRol roles={['logistica']} />}>
          <Route path="/logistica/ingresos" element={<Ingresos />} />
          <Route path="/logistica/ingresos/:id" element={<IngresoDetalle />} />
          <Route path="/logistica/etiquetas" element={<Etiquetas />} />
        </Route>
        <Route element={<ConRol roles={['logistica', 'admin']} />}>
          <Route path={RUTA_AJUSTES} element={<Ajustes />} />
          <Route path="/agotados" element={<Agotados />} />
        </Route>
        <Route element={<ConRol roles={['admin']} />}>
          <Route path="/admin/referencias" element={<Navigate to="/productos" replace />} />
          <Route path="/admin/universales" element={<Universales />} />
          <Route path="/admin/universales/:id" element={<UniversalDetalle />} />
          <Route path="/admin/catalogos" element={<Catalogos />} />
          <Route path="/admin/usuarios" element={<Usuarios />} />
          <Route path="/admin/clientes" element={<Clientes />} />
          <Route path="/admin/auditoria" element={<Auditoria />} />
          <Route path="/admin/configuracion" element={<Configuracion />} />
        </Route>
        <Route path="*" element={<Navigate to={usuario ? rutaInicio(usuario.rol) : '/login'} replace />} />
      </Route>
    </Routes>
  )
}