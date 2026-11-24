import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useVencimientos } from '../hooks/useVencimientos'
import { RUTA_AJUSTES, RUTA_CARTERA, rutaInicio } from '../rutas'
import './cartera.css'
import logo from '../assets/logo-la8va.jpg'
import Icono, { type IconoNombre } from './Icono'
import MiCuenta from './MiCuenta'
import MenuUsuario from './MenuUsuario'

interface ItemMenu {
  to: string
  texto: string
  icono: IconoNombre
  fin?: boolean
  /** RF-22 (adenda v1.3): vencidas y por vencer. */
  contador?: number
}

export default function Layout() {
  const { usuario, salir, tieneRol } = useAuth()
  const ubicacion = useLocation()
  const [abierto, setAbierto] = useState(true)
  const [editandoCuenta, setEditandoCuenta] = useState(false)

  const esContable = tieneRol('contable')
  const vencimientos = useVencimientos({ enabled: esContable })
  const avisos = vencimientos.data?.totales.todas.cantidad

  useEffect(() => {
    if (window.innerWidth < 960) setAbierto(false)
  }, [ubicacion.pathname])

  const grupos: { etiqueta: string; items: ItemMenu[] }[] = [
    tieneRol('mostrador')
      ? {
          etiqueta: 'Mostrador',
          items: [
            { to: '/mostrador/venta', texto: 'Venta', icono: 'venta' },
            { to: RUTA_CARTERA, texto: 'Cartera', icono: 'billetera' },
          ],
        }
      : null,
    tieneRol('admin', 'logistica', 'mostrador')
      ? { etiqueta: 'Productos', items: [{ to: '/productos', texto: 'Listado', icono: 'caja', fin: true }] }
      : null,
    tieneRol('logistica')
      ? {
          etiqueta: 'Logística',
          items: [
            { to: '/logistica/ingresos', texto: 'Ingresos', icono: 'ingreso', fin: true },
            { to: '/logistica/etiquetas', texto: 'Etiquetas', icono: 'etiqueta' },
            { to: RUTA_AJUSTES, texto: 'Ajustes', icono: 'inventario' },
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
            { to: '/admin/configuracion', texto: 'Configuración', icono: 'configuracion' },
          ],
        }
      : null,
    esContable
      ? {
          etiqueta: 'Contable',
          items: [
            { to: '/contable/vencimientos', texto: 'Vencimientos', icono: 'agotados', contador: avisos },
            { to: '/contable/cuentas-por-pagar', texto: 'Cuentas por pagar', icono: 'ingreso' },
            { to: '/contable/cartera', texto: 'Cartera de clientes', icono: 'persona' },
            { to: '/contable/informes', texto: 'Informes', icono: 'auditoria' },
          ],
        }
      : null,
    tieneRol('logistica', 'admin')
      ? { etiqueta: 'Consulta', items: [{ to: '/agotados', texto: 'Agotados y mínimos', icono: 'agotados' }] }
      : null,
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
                    {!!item.contador && (
                      <span className="menu-contador" aria-label={`${item.contador} por atender`}>
                        {item.contador}
                      </span>
                    )}
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