import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { RUTA_AJUSTES } from '../rutas'
import logo from '../assets/logo-la8va.jpg'
import Icono, { type IconoNombre } from './Icono'
import MiCuenta from './MiCuenta'

interface ItemMenu {
  to: string
  texto: string
  icono: IconoNombre
  fin?: boolean
}

const rolLegible = { admin: 'Administrador', logistica: 'Logística', mostrador: 'Mostrador' } as const

export default function Layout() {
  const { usuario, salir, tieneRol } = useAuth()
  const ubicacion = useLocation()
  const [abierto, setAbierto] = useState(true)
  const [cuentaAbierta, setCuentaAbierta] = useState(false)
  const [editandoCuenta, setEditandoCuenta] = useState(false)
  const cuentaRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (window.innerWidth < 960) setAbierto(false)
    setCuentaAbierta(false)
  }, [ubicacion.pathname])

  useEffect(() => {
    if (!cuentaAbierta) return
    const cerrar = (e: MouseEvent | KeyboardEvent) => {
      if (e instanceof KeyboardEvent ? e.key === 'Escape' : !cuentaRef.current?.contains(e.target as Node)) setCuentaAbierta(false)
    }
    document.addEventListener('mousedown', cerrar)
    document.addEventListener('keydown', cerrar)
    return () => {
      document.removeEventListener('mousedown', cerrar)
      document.removeEventListener('keydown', cerrar)
    }
  }, [cuentaAbierta])

  const grupos: { etiqueta: string; items: ItemMenu[] }[] = [
    { etiqueta: 'Productos', items: [{ to: '/productos', texto: 'Listado', icono: 'caja', fin: true }] },
    tieneRol('mostrador') ? { etiqueta: 'Mostrador', items: [{ to: '/mostrador/venta', texto: 'Venta', icono: 'venta' }] } : null,
    tieneRol('logistica')
      ? {
          etiqueta: 'Logística',
          items: [
            { to: '/logistica/ingresos', texto: 'Ingresos', icono: 'ingreso', fin: true },
            { to: '/logistica/etiquetas', texto: 'Etiquetas', icono: 'etiqueta' },
            { to: RUTA_AJUSTES, texto: 'Ajustes', icono: 'inventario' }, // RF-21
          ],
        }
      : null,
    tieneRol('admin')
      ? {
          etiqueta: 'Administración',
          items: [
            { to: '/admin/universales', texto: 'Universales', icono: 'universal' },
            { to: '/admin/catalogos', texto: 'Catálogos', icono: 'catalogo' },
            { to: '/admin/clientes', texto: 'Clientes', icono: 'persona' },
            { to: '/admin/usuarios', texto: 'Usuarios', icono: 'usuarios' },
            { to: '/admin/auditoria', texto: 'Auditoría', icono: 'auditoria' },
          ],
        }
      : null,
    { etiqueta: 'Consulta', items: [{ to: '/agotados', texto: 'Agotados', icono: 'agotados' }] },
  ].filter((g): g is { etiqueta: string; items: ItemMenu[] } => g !== null)

  return (
    <div className={abierto ? 'marco menu-abierto' : 'marco'}>
      <header className="barra-superior">
        <button type="button" className="icono-boton" aria-label="Abrir o cerrar el menú" aria-expanded={abierto} onClick={() => setAbierto((v) => !v)}>
          <Icono nombre="menu" />
        </button>
        <img className="logo-empresa" src={logo} alt="Arranques y Alternadores La 8va" />
        <span className="barra-espacio" />
        <div className="cuenta" ref={cuentaRef}>
          <button
            type="button"
            className="icono-boton"
            aria-label="Cuenta"
            aria-haspopup="menu"
            aria-expanded={cuentaAbierta}
            onClick={() => setCuentaAbierta((v) => !v)}
          >
            <Icono nombre="persona" />
          </button>
          {cuentaAbierta && (
            <div className="cuenta-panel" role="menu">
              <div className="cuenta-sesion">
                <strong>{usuario?.name}</strong>
                <span>{usuario?.usuario}</span>
                <span className="chip">{usuario ? rolLegible[usuario.rol] : ''}</span>
              </div>
              {usuario?.rol === 'admin' && (
                <button type="button" role="menuitem" className="cuenta-opcion" onClick={() => { setCuentaAbierta(false); setEditandoCuenta(true) }}>
                  <Icono nombre="persona" />
                  <span>Editar mi cuenta</span>
                </button>
              )}
              <button type="button" role="menuitem" className="cuenta-opcion cuenta-salir" onClick={() => salir()}>
                <Icono nombre="salir" />
                <span>Salir</span>
              </button>
            </div>
          )}
        </div>
      </header>

      <div className="cuerpo">
        <nav className="menu" aria-label="Módulos">
          <div className="menu-interior">
            {grupos.map((grupo) => (
              <div key={grupo.etiqueta}>
                <div className="menu-grupo">{grupo.etiqueta}</div>
                {grupo.items.map((item) => (
                  <NavLink key={item.to} to={item.to} end={item.fin}>
                    <Icono nombre={item.icono} />
                    <span>{item.texto}</span>
                  </NavLink>
                ))}
              </div>
            ))}
          </div>
        </nav>
        <button type="button" className="velo" aria-label="Cerrar el menú" tabIndex={-1} onClick={() => setAbierto(false)} />
        <main className="contenido">
          <Outlet />
        </main>
      </div>
      {editandoCuenta && <MiCuenta onCerrar={() => setEditandoCuenta(false)} />}
    </div>
  )
}
