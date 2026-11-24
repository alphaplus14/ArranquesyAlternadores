import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { RUTA_AJUSTES, RUTA_CARTERA, RUTA_PANEL, rutaInicio } from '../rutas'
import logo from '../assets/logo-la8va.jpg'
import Icono, { type IconoNombre } from './Icono'
import MiCuenta from './MiCuenta'
import MenuUsuario from './MenuUsuario'
import { esDeDiaAnterior, useVentasAbiertas } from '../hooks/useVentasAbiertas'

interface ItemMenu {
  to: string
  texto: string
  icono: IconoNombre
  fin?: boolean
  /** S-40: punto de aviso con su texto accesible. */
  alerta?: string
}

export default function Layout() {
  const { usuario, salir, tieneRol } = useAuth()
  const ubicacion = useLocation()
  const [abierto, setAbierto] = useState(true)
  const [editandoCuenta, setEditandoCuenta] = useState(false)

  useEffect(() => {
    if (window.innerWidth < 960) setAbierto(false)
  }, [ubicacion.pathname])

  // S-40: ventas abiertas desde un día anterior, del mostrador. S-43: solo el mostrador vende; el
  // admin no ve "Venta" ni consulta sus ventas abiertas para el punto de alerta.
  const vende = usuario?.rol === 'mostrador'
  const abiertas = useVentasAbiertas(vende)
  // S-46: solo avisan las que tienen ítems vigentes (las vacías se descartan solas, sin avisos).
  const hayVentasViejas = (abiertas.data ?? []).some((v) => v.items_count > 0 && esDeDiaAnterior(v))

  // Orden: Mostrador, Productos, Logística, Administración, Consulta. Qué ve cada rol no cambia.
  const grupos: { etiqueta: string; items: ItemMenu[] }[] = [
    // RF-23: el panel de gestión es lo primero del admin (y su inicio).
    usuario?.rol === 'admin' ? { etiqueta: 'Gestión', items: [{ to: RUTA_PANEL, texto: 'Panel', icono: 'panel' }] } : null,
    tieneRol('mostrador')
      ? {
          etiqueta: 'Mostrador',
          items: [
            ...(vende ? [{ to: '/mostrador/venta', texto: 'Venta', icono: 'venta', alerta: hayVentasViejas ? 'Hay ventas abiertas de días anteriores' : undefined } as ItemMenu] : []),
            { to: RUTA_CARTERA, texto: 'Cartera', icono: 'billetera' }, // RF-15 / S-30
          ],
        }
      : null,
    { etiqueta: 'Productos', items: [{ to: '/productos', texto: 'Listado', icono: 'caja', fin: true }] },
    tieneRol('logistica')
      ? {
          etiqueta: 'Logística',
          items: [
            { to: '/logistica/ingresos', texto: 'Ingresos', icono: 'ingreso', fin: true },
            { to: '/logistica/etiquetas', texto: 'Etiquetas', icono: 'etiqueta' },
            // RF-21. S-44: el admin solo consulta el historial.
            { to: RUTA_AJUSTES, texto: usuario?.rol === 'admin' ? 'Historial de ajustes' : 'Ajustes', icono: 'inventario' },
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
            { to: '/admin/configuracion', texto: 'Configuración', icono: 'configuracion' }, // RF-22
          ],
        }
      : null,
    // S-28: agotados y mínimos son de logística y admin; sin ellos, la sección Consulta no aparece.
    tieneRol('logistica') ? { etiqueta: 'Consulta', items: [{ to: '/agotados', texto: 'Agotados y mínimos', icono: 'agotados' }] } : null,
  ].filter((g): g is { etiqueta: string; items: ItemMenu[] } => g !== null)

  return (
    <div className={abierto ? 'marco menu-abierto' : 'marco'}>
      <header className="barra-superior">
        <button type="button" className="icono-boton" aria-label="Abrir o cerrar el menú" aria-expanded={abierto} onClick={() => setAbierto((v) => !v)}>
          <Icono nombre="menu" />
        </button>
        <Link to={usuario ? rutaInicio(usuario.rol) : '/login'} className="logo-enlace" aria-label="Ir al inicio">
          <img className="logo-empresa" src={logo} alt="Arranques y Alternadores La 8va" />
        </Link>
        <span className="barra-espacio" />
        {usuario && (
          <MenuUsuario
            usuario={usuario}
            onEditarCuenta={usuario.rol === 'admin' ? () => setEditandoCuenta(true) : undefined}
            onSalir={() => salir()}
          />
        )}
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
                    {item.alerta && <span className="punto-alerta" title={item.alerta}><span className="solo-lector">{item.alerta}</span></span>}
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
