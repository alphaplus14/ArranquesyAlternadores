import { Suspense, useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider'
import { useVencimientos } from '../hooks/useVencimientos'
import { RUTA_AJUSTES, RUTA_CAJA, RUTA_CAJA_ADMIN, RUTA_CARTERA, RUTA_CORRECCIONES, RUTA_COSTOS, RUTA_PANEL, RUTA_UNIVERSALES, rutaInicio } from '../rutas'
import { useVerCostos } from '../hooks/useVerCostos'
import logo from '../assets/logo-la8va.jpg'
import Icono, { type IconoNombre } from './Icono'
import MiCuenta from './MiCuenta'
import DialogoPreferencias from '../preferencias/DialogoPreferencias'
import { admitePreferencias, useMenuPlegado } from '../preferencias/preferencias'
import MenuUsuario from './MenuUsuario'
import ErrorPantalla from './ErrorPantalla'
import { esDeDiaAnterior, useVentasAbiertas } from '../hooks/useVentasAbiertas'

interface ItemMenu {
  to: string
  texto: string
  icono: IconoNombre
  fin?: boolean
  /** S-40: punto de aviso con su texto accesible. */
  alerta?: string
  /** RF-30: vencidas y por vencer (contable). */
  contador?: number
}

export default function Layout() {
  const { usuario, salir, tieneRol } = useAuth()
  const ubicacion = useLocation()
  const [abierto, setAbierto] = useState(true)
  const [editandoCuenta, setEditandoCuenta] = useState(false)
  // Admin: grupos del menú desplegados a mano. Solo en memoria del componente (no se guarda en el navegador).
  const [gruposAbiertos, setGruposAbiertos] = useState<Record<string, boolean>>({})
  const [editandoPreferencias, setEditandoPreferencias] = useState(false)
  const menuPlegado = useMenuPlegado()

  const esContable = tieneRol('contable')
  const vencimientos = useVencimientos({ enabled: esContable })
  const verCostos = useVerCostos()
  const avisos = vencimientos.data?.totales.todas.cantidad

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
    // RF-25 a RF-27: Caja del admin (día en consulta, mes, cuentas y categorías, empleados).
    usuario?.rol === 'admin'
      ? { etiqueta: 'Gestión', items: [{ to: RUTA_PANEL, texto: 'Panel', icono: 'panel' }, { to: RUTA_CAJA_ADMIN, texto: 'Caja', icono: 'caja-diaria' }] }
      : null,
    tieneRol('mostrador')
      ? {
          etiqueta: 'Mostrador',
          items: [
            ...(vende ? [{ to: '/mostrador/venta', texto: 'Venta', icono: 'venta', alerta: hayVentasViejas ? 'Hay ventas abiertas de días anteriores' : undefined } as ItemMenu] : []),
            { to: RUTA_CARTERA, texto: 'Cartera', icono: 'billetera' }, // RF-15 / S-30
            // RF-25 / RF-26: el mostrador cierra su caja; el admin la consulta desde Gestión › Caja.
            ...(vende ? [{ to: RUTA_CAJA, texto: 'Caja', icono: 'caja-diaria' } as ItemMenu] : []),
          ],
        }
      : null,
    { etiqueta: 'Productos', items: [{ to: '/productos', texto: 'Inventario', icono: 'caja', fin: true }] },
    tieneRol('logistica')
      ? {
          etiqueta: 'Logística',
          items: [
            { to: '/logistica/ingresos', texto: 'Compras', icono: 'ingreso', fin: true },
            // El admin no la tiene en el menú (no la usa en el día a día); la ruta sigue abierta por URL.
            ...(usuario?.rol === 'admin' ? [] : [{ to: '/logistica/etiquetas', texto: 'Etiquetas', icono: 'etiqueta' } as ItemMenu]),
            // RF-21. S-44: el admin solo consulta el historial. S-63: "Ajustes" se llama "Movimientos".
            { to: RUTA_AJUSTES, texto: usuario?.rol === 'admin' ? 'Historial de movimientos' : 'Movimientos', icono: 'inventario' },
            // S-59: logística crea y edita universales. El admin entra a /universales por URL o enlaces
            // internos, sin ítem de menú.
            ...(usuario?.rol === 'logistica' ? [{ to: RUTA_UNIVERSALES, texto: 'Códigos universales', icono: 'universal' } as ItemMenu] : []),
          ],
        }
      : null,
    tieneRol('admin')
      ? {
          etiqueta: 'Administración',
          items: [
            { to: '/admin/catalogos', texto: 'Catálogos', icono: 'catalogo' },
            { to: '/admin/clientes', texto: 'Clientes', icono: 'persona' },
            { to: '/admin/usuarios', texto: 'Usuarios', icono: 'usuarios' },
            { to: '/admin/auditoria', texto: 'Auditoría', icono: 'auditoria' },
            { to: RUTA_CORRECCIONES, texto: 'Correcciones de ingresos', icono: 'ingreso' }, // RF-28
            { to: '/admin/configuracion', texto: 'Configuración', icono: 'configuracion' }, // RF-22
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
            { to: '/contable/recaudos', texto: 'Recaudos de cartera', icono: 'billetera' }, // S-76
          ],
        }
      : null,
    // S-28: agotados y mínimos son de logística y admin; sin ellos, la sección Consulta no aparece.
    // S-71: "Costos por producto" solo con ver-historial-costos (admin y logística); el mostrador no tiene Consulta.
    tieneRol('logistica')
      ? {
          etiqueta: 'Consulta',
          items: [
            { to: '/agotados', texto: 'Agotados y mínimos', icono: 'agotados' },
            ...(verCostos.historial ? [{ to: RUTA_COSTOS, texto: 'Costos por producto', icono: 'ingreso' } as ItemMenu] : []),
          ],
        }
      : null,
  ].filter((g): g is { etiqueta: string; items: ItemMenu[] } => g !== null)

  // S-70: grupos plegables según la preferencia del usuario (admin: sí por defecto, como antes;
  // logística y contable: no). El mostrador no tiene preferencias.
  const plegable = menuPlegado
  const esRutaActiva = (item: ItemMenu) => (item.fin
    ? ubicacion.pathname === item.to
    : ubicacion.pathname === item.to || ubicacion.pathname.startsWith(`${item.to}/`))

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
            onPreferencias={admitePreferencias(usuario.rol) ? () => setEditandoPreferencias(true) : undefined}
            onSalir={() => salir()}
          />
        )}
      </header>

      <div className="cuerpo">
        <nav className="menu" aria-label="Módulos">
          <div className="menu-interior">
            {grupos.map((grupo) => {
              // Admin: cada grupo se pliega con un clic en su título; el de la ruta activa siempre queda
              // abierto. Así su menú (el más largo) no necesita scroll. Los demás roles no cambian.
              const activo = grupo.items.some(esRutaActiva)
              const desplegado = !plegable || activo || !!gruposAbiertos[grupo.etiqueta]
              const idLista = `menu-grupo-${grupo.etiqueta.toLowerCase().normalize('NFD').replace(/[^a-z]/g, '')}`
              return (
              <div key={grupo.etiqueta}>
                {plegable ? (
                  <button
                    type="button"
                    className="menu-grupo menu-grupo-boton"
                    aria-expanded={desplegado}
                    aria-controls={idLista}
                    title={activo ? 'Contiene la pantalla actual' : undefined}
                    onClick={() => { if (!activo) setGruposAbiertos((g) => ({ ...g, [grupo.etiqueta]: !g[grupo.etiqueta] })) }}
                  >
                    <span>{grupo.etiqueta}</span>
                    <Icono nombre="chevron" />
                  </button>
                ) : (
                  <div className="menu-grupo">{grupo.etiqueta}</div>
                )}
                <div id={idLista} hidden={!desplegado}>
                {grupo.items.map((item) => (
                  <NavLink key={item.to} to={item.to} end={item.fin}>
                    <Icono nombre={item.icono} />
                    <span>{item.texto}</span>
                    {item.alerta && <span className="punto-alerta" title={item.alerta}><span className="solo-lector">{item.alerta}</span></span>}
                    {item.contador != null && item.contador > 0 && (
                      <span className="menu-contador" title={`${item.contador} avisos`}>{item.contador}</span>
                    )}
                  </NavLink>
                ))}
                </div>
              </div>
              )
            })}
          </div>
        </nav>
        <button type="button" className="velo" aria-label="Cerrar el menú" tabIndex={-1} onClick={() => setAbierto(false)} />
        <main className="contenido">
          {/* Cada pantalla llega en su propio paquete (App.tsx): el menú se queda mientras carga. */}
          {/* Si una pantalla falla, aviso con "Recargar" en vez de dejar todo en blanco. */}
          <ErrorPantalla clave={ubicacion.pathname}>
            <Suspense fallback={<div className="cargando">Cargando…</div>}>
              <Outlet />
            </Suspense>
          </ErrorPantalla>
        </main>
      </div>
      {editandoCuenta && <MiCuenta onCerrar={() => setEditandoCuenta(false)} />}
      {editandoPreferencias && <DialogoPreferencias onCerrar={() => setEditandoPreferencias(false)} />}
    </div>
  )
}
