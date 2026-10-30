import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import type { Rol } from './api/tipos'
import { useAuth } from './auth/AuthProvider'
import { rutaInicio } from './rutas'
import Layout from './componentes/Layout'
import Login from './paginas/Login'
import Agotados from './paginas/Agotados'
import Venta from './paginas/mostrador/Venta'
import Ingresos from './paginas/logistica/Ingresos'
import IngresoDetalle from './paginas/logistica/IngresoDetalle'
import Etiquetas from './paginas/logistica/Etiquetas'
import Referencias from './paginas/admin/Referencias'
import Universales from './paginas/admin/Universales'
import UniversalDetalle from './paginas/admin/UniversalDetalle'
import Catalogos from './paginas/admin/Catalogos'
import Usuarios from './paginas/admin/Usuarios'
import Auditoria from './paginas/admin/Auditoria'

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
        <Route path="/agotados" element={<Agotados />} />
        <Route element={<ConRol roles={['mostrador']} />}>
          <Route path="/mostrador/venta" element={<Venta />} />
        </Route>
        <Route element={<ConRol roles={['logistica']} />}>
          <Route path="/logistica/ingresos" element={<Ingresos />} />
          <Route path="/logistica/ingresos/:id" element={<IngresoDetalle />} />
          <Route path="/logistica/etiquetas" element={<Etiquetas />} />
        </Route>
        <Route element={<ConRol roles={['admin']} />}>
          <Route path="/admin/referencias" element={<Referencias />} />
          <Route path="/admin/universales" element={<Universales />} />
          <Route path="/admin/universales/:id" element={<UniversalDetalle />} />
          <Route path="/admin/catalogos" element={<Catalogos />} />
          <Route path="/admin/usuarios" element={<Usuarios />} />
          <Route path="/admin/auditoria" element={<Auditoria />} />
        </Route>
        <Route path="*" element={<Navigate to={usuario ? rutaInicio(usuario.rol) : '/login'} replace />} />
      </Route>
    </Routes>
  )
}
