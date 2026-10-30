import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'

export default function Layout() {
  const { usuario, salir, tieneRol } = useAuth()

  return (
    <div className="app">
      <header className="barra">
        <strong className="marca">La 8va · Inventario</strong>
        <nav>
          {tieneRol('mostrador') && <NavLink to="/mostrador/venta">Venta</NavLink>}
          {tieneRol('logistica') && <NavLink to="/logistica/ingresos">Ingresos</NavLink>}
          {tieneRol('logistica') && <NavLink to="/logistica/etiquetas">Etiquetas</NavLink>}
          {tieneRol('admin') && <NavLink to="/admin/referencias">Referencias</NavLink>}
          {tieneRol('admin') && <NavLink to="/admin/universales">Universales</NavLink>}
          {tieneRol('admin') && <NavLink to="/admin/catalogos">Catálogos</NavLink>}
          {tieneRol('admin') && <NavLink to="/admin/usuarios">Usuarios</NavLink>}
          {tieneRol('admin') && <NavLink to="/admin/auditoria">Auditoría</NavLink>}
          <NavLink to="/agotados">Agotados</NavLink>
        </nav>
        <span className="usuario">
          {usuario?.name} ({usuario?.rol}) <button className="enlace" onClick={() => salir()}>Salir</button>
        </span>
      </header>
      <main>
        <Outlet />
      </main>
    </div>
  )
}
