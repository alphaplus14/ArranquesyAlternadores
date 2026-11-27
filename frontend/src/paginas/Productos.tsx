import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../api/cliente'
import { NOMBRE_CALIDAD, type Calidad, type ItemInventario, type MetodoCaptura, type Pagina, type Referencia, type UniversalInventario } from '../api/tipos'
import ChipCalidad from '../componentes/ChipCalidad'
import { errorStockMinimo } from '../utilidades/stockMinimo'
import { useAuth } from '../auth/AuthProvider'
import Icono from '../componentes/Icono'
import Paginacion from '../componentes/Paginacion'
import SelectorProveedor from '../componentes/SelectorProveedor'
import SelectorCategoria from '../componentes/SelectorCategoria'
import SelectorUniversal from '../componentes/SelectorUniversal'
import Dialogo, { DialogoSeccion } from '../componentes/Dialogo'
import DialogoUniversal from '../componentes/DialogoUniversal'
import CampoMoneda from '../componentes/CampoMoneda'
import { formatoNumero, leerNumero } from '../componentes/numeros'
import AvisoCoincidencias from '../componentes/AvisoCoincidencias'
import Resumen from '../componentes/Resumen'
import { useCategorias } from '../hooks/useCatalogos'
import { useDebounce } from '../hooks/useDebounce'
import { useListaPaginada } from '../hooks/useListaPaginada'
import { useScanner } from '../hooks/useScanner'
import { useVentana } from '../hooks/useVentana'
import { useAncho } from '../hooks/useAncho'
import BotonCancelar from '../componentes/BotonCancelar'
import { useVerCostos } from '../hooks/useVerCostos'
import { RUTA_COSTOS } from '../rutas'
import './consulta/costos.css'

/** Filas por petición. El tope del API es 100; el DOM solo pinta las visibles. */
const POR_PAGINA = 100
const ALTO_FILA = 56

/**
 * Modo del listado según el ancho de la tarjeta (no de la ventana):
 * - completa (≥ 1100 px): todas las columnas;
 * - media (760–1099 px): sin Categoría ni IVA (se ven al editar);
 * S-93: el mostrador nunca ve la columna IVA (el precio ya lo incluye); admin y logística sí.
 * - tarjetas (< 760 px): cada producto es una tarjeta de alto fijo (la lista sigue virtualizada).
 */
type Modo = 'completa' | 'media' | 'tarjetas'
const modoDe = (ancho: number): Modo => (ancho === 0 || ancho >= 1100 ? 'completa' : ancho >= 760 ? 'media' : 'tarjetas')
/** Alto de cada tarjeta, con su separación: cabecera + 5 pares (6 con el costo, S-71) + acciones (admin). */
const ALTO_TARJETA = { conAcciones: 252, otro: 192 }
/** Alto de un par de una línea ("Costo", S-71, o "IVA", que el mostrador no ve, S-93). */
const ALTO_PAR = 28

/** Columnas por modo. La Descripción es la única flexible; el resto, ancho fijo. */
/** Ancho de la columna Acciones según cuántos botones de 36 px muestra (0 = sin columna). */
function anchoAcciones(botones: number) {
  return botones === 0 ? '' : ` ${botones * 36 + (botones - 1) * 6 + 4}px`
}

function columnasDe(modo: Modo, botones: number, conCosto: boolean, conIva: boolean) {
  const acciones = anchoAcciones(botones)
  // S-71: "Costo" (último costo de compra, sin IVA) entre Precio e IVA; la descripción absorbe el ancho.
  const costo = conCosto ? ' 112px' : ''
  // S-93: sin la columna IVA (mostrador) se quita su ancho; la descripción absorbe la diferencia.
  const iva = conIva ? ' 72px' : ''
  // RF-22: Existencia a 124 px para el chip "Bajo mínimo"; la descripción absorbe la diferencia.
  if (modo === 'completa') return `164px minmax(0, 1fr) 140px 112px 104px${costo}${iva} 124px${acciones}`
  return `140px minmax(0, 1fr) 124px 100px${costo} 124px${acciones}`
}

type Formulario = {
  codigo_referencia: string
  /** S-25: código de barras o QR del producto; '' = no trae. */
  codigo_fabricante: string
  /** S-23: '' = sin calidad. */
  calidad: '' | Calidad
  proveedor_id: string
  categoria_id: string
  codigo_universal_id: string
  descripcion: string
  precio_venta: string
  /** RF-22: '' = usa el general; '0' = no maneja stock mínimo. */
  stock_minimo: string
}

const vacio: Formulario = {
  codigo_referencia: '', codigo_fabricante: '', calidad: '', proveedor_id: '', categoria_id: '',
  codigo_universal_id: '', descripcion: '', precio_venta: '', stock_minimo: '',
}

/** S-18: ningún precio puede ser 0. Las referencias antiguas en 0 no se pueden vender hasta asignarles uno. */
function sinPrecio(r: Referencia) {
  return Number(r.precio_venta) === 0
}

interface Aviso { tipo: 'ok' | 'error' | 'atencion'; texto: string }
interface Lectura { codigo: string; metodo: MetodoCaptura; titulo: string; detalle: string }
interface Busqueda {
  unidad: { codigo_barras: string; estado: string; referencia?: Referencia } | null
  referencias: Referencia[] | { data: Referencia[] }
}

function comoLista(valor: Busqueda['referencias'] | undefined): Referencia[] {
  if (!valor) return []
  return Array.isArray(valor) ? valor : valor.data ?? []
}

/** S-82: fila pintada en el listado: un universal, una referencia sin universal o una hija desplegada. */
type FilaVista =
  | { clase: 'universal'; u: UniversalInventario }
  | { clase: 'referencia'; r: Referencia }
  | { clase: 'hija'; r: Referencia; u: UniversalInventario; ultima: boolean }

/**
 * S-82 (ajuste): orden de las hijas dentro del grupo, solo presentación. Primero las activas con
 * existencia (de mayor a menor), luego las activas agotadas y al final las inactivas; empate por código.
 */
function ordenHijas(a: Referencia, b: Referencia) {
  const grupo = (r: Referencia) => (r.activo === false ? 2 : r.existencia > 0 ? 0 : 1)
  return grupo(a) - grupo(b) || b.existencia - a.existencia || a.codigo_referencia.localeCompare(b.codigo_referencia)
}

/** Chevron de la flecha del universal: apunta a la derecha y gira 90° al desplegar (CSS). */
function Chevron() {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden focusable="false">
      <path d="M9 6l6 6-6 6" />
    </svg>
  )
}

const reducirMovimiento = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

const claveFila = (f: FilaVista) => (f.clase === 'universal' ? `u${f.u.id}` : f.clase === 'hija' ? `h${f.u.id}-${f.r.id}` : `r${f.r.id}`)
const idHija = (u: number, r: number) => `grupo-${u}-ref-${r}`

function textoProveedores(u: UniversalInventario) {
  if (u.proveedores.length === 0) return '—'
  if (u.proveedores.length === 1) return u.proveedores[0].nombre
  return `${u.proveedores.length} proveedores`
}

async function traerInventario(params: Record<string, string | number | undefined>) {
  const { data } = await api.get<Pagina<ItemInventario>>('/inventario', { params })
  return data
}

/**
 * RF-01: catálogo, paginado en el servidor. S-82: los códigos universales viven aquí: cada fila es un
 * universal (con su existencia = suma de sus referencias, RF-02, y una flecha que despliega sus
 * referencias en la misma tabla) o una referencia sin universal; una referencia con universal solo
 * aparece dentro de su grupo. Las filas desplegadas tienen el mismo alto, así la lista sigue
 * virtualizada. RF-12: el escáner localiza la unidad al momento (escaneado o digitado): si es de un
 * universal, el grupo se despliega y la referencia se resalta, sin sacar el foco del campo.
 */
export default function Productos() {
  const qc = useQueryClient()
  const { tieneRol, usuario } = useAuth()
  const esAdmin = tieneRol('admin')
  /** S-28: rol exacto (tieneRol también es verdadero para el admin). */
  const esMostrador = usuario?.rol === 'mostrador'
  /** S-93: el precio ya incluye IVA y el mostrador no lo calcula; admin y logística lo usan al editar. */
  const verIva = !esMostrador
  const verCostos = useVerCostos()
  // S-26: admin y logística crean, editan y desactivan referencias; mostrador solo consulta.
  const puedeGestionar = tieneRol('logistica') // el admin pasa siempre
  const [params, setParams] = useSearchParams()
  /** S-82: /productos?universal=ID (enlaces viejos a /universales/:id) abre solo ese grupo, desplegado. */
  const universalParam = params.get('universal')
  const [verInactivas, setVerInactivas] = useState(false)
  /** Fila con la confirmación "¿Desactivar?" abierta, y el error del backend en una fila. */
  const [confirmando, setConfirmando] = useState<number | null>(null)
  const [errorFila, setErrorFila] = useState<{ id: number; texto: string } | null>(null)
  const [q, setQ] = useState('')
  const [proveedorId, setProveedorId] = useState('')
  /** Referencias que encontró el último escaneo (S-25: un código de barras puede estar en varios proveedores). */
  const [resaltadas, setResaltadas] = useState<number[]>([])
  /** S-82: universales resaltados (p. ej. al llegar por /universales/:id). */
  const [universalResaltado, setUniversalResaltado] = useState<number | null>(universalParam ? Number(universalParam) : null)
  /** S-82: universales desplegados (en el cliente; se limpia al cambiar de página o de filtros). */
  const [desplegados, setDesplegados] = useState<Set<number>>(() => new Set(universalParam ? [Number(universalParam)] : []))
  const [aviso, setAviso] = useState<Aviso | null>(null)
  const [lectura, setLectura] = useState<Lectura | null>(null)
  const [editando, setEditando] = useState<{ id: number | null; datos: Formulario } | null>(null)
  /** S-82: modal del universal (null = cerrada; 'nuevo' o el universal a editar). */
  const [universalEditando, setUniversalEditando] = useState<UniversalInventario | 'nuevo' | null>(null)
  const lectorRef = useRef<HTMLInputElement>(null)
  const termino = useDebounce(q.trim())

  const { filas: items, resultado, info, pagina, irA, cargando } = useListaPaginada<ItemInventario>({
    clave: ['inventario', termino, proveedorId, verInactivas, universalParam],
    filtros: [q, proveedorId, verInactivas, universalParam],
    prefetch: true,
    porPagina: { defecto: POR_PAGINA }, // S-70
    traer: (n, porPagina) => traerInventario({
      q: termino || undefined,
      proveedor_id: proveedorId || undefined,
      // S-26: inactivas=1 las INCLUYE junto con las activas (solo admin y logística).
      inactivas: verInactivas ? 1 : undefined,
      universal_id: universalParam ?? undefined,
      page: n,
      por_pagina: porPagina,
    }),
  })

  // S-82: al llegar otra página o filtro se pliega todo y se despliegan solos los grupos con
  // coincidencias de la búsqueda, el del enlace o el que contiene una referencia resaltada.
  const claveLista = JSON.stringify([termino, proveedorId, verInactivas, universalParam, pagina])
  const claveAnterior = useRef(claveLista)
  useEffect(() => {
    const cambio = claveAnterior.current !== claveLista
    claveAnterior.current = claveLista
    const automaticos = items.filter((i): i is UniversalInventario => i.tipo === 'universal' && (
      i.coincidencias.length > 0
      || String(i.id) === universalParam
      || i.referencias.some((r) => resaltadas.includes(r.id))
    )).map((u) => u.id)
    setDesplegados((antes) => {
      const base = cambio ? new Set<number>() : new Set(antes)
      automaticos.forEach((id) => base.add(id))
      return base.size === antes.size && [...base].every((id) => antes.has(id)) ? antes : base
    })
  }, [items, claveLista, universalParam, resaltadas])

  /** Lista plana de lo visible: cada universal desplegado inserta sus referencias debajo. */
  const filas = useMemo<FilaVista[]>(() => items.flatMap((i): FilaVista[] => {
    if (i.tipo === 'referencia') return [{ clase: 'referencia', r: i }]
    const grupo: FilaVista[] = [{ clase: 'universal', u: i }]
    if (desplegados.has(i.id)) {
      const hijas = [...i.referencias].sort(ordenHijas)
      hijas.forEach((r, k) => grupo.push({ clase: 'hija', r, u: i, ultima: k === hijas.length - 1 }))
    }
    return grupo
  }), [items, desplegados])

  const agotadosPagina = items.filter((i) => (i.tipo === 'universal' ? i.existencia_total : i.existencia) === 0).length
  const universalesPagina = items.filter((i): i is UniversalInventario => i.tipo === 'universal')
  const todosDesplegados = universalesPagina.length > 0 && universalesPagina.every((u) => desplegados.has(u.id))
  const desplegarTodos = () => setDesplegados(todosDesplegados ? new Set() : new Set(universalesPagina.map((u) => u.id)))
  /** S-82 (ajuste): universal recién desplegado a mano; se lleva a la vista con sus hijas. */
  const verAlDesplegar = useRef<number | null>(null)
  const tarjeta = useAncho<HTMLElement>()
  const modo = modoDe(tarjeta.ancho)
  // Lista virtualizada de alto fijo: cada par que se agrega o se quita cambia el alto de la tarjeta.
  const alto = modo === 'tarjetas'
    ? (puedeGestionar ? ALTO_TARJETA.conAcciones : ALTO_TARJETA.otro) + (verCostos.ultimo ? ALTO_PAR : 0) - (verIva ? 0 : ALTO_PAR)
    : ALTO_FILA
  const ventana = useVentana(filas, alto)

  const scroller = ventana.ref
  const sincronizar = ventana.sincronizar
  useEffect(() => {
    const el = scroller.current
    if (!el) return
    el.scrollTop = 0
    sincronizar()
  }, [pagina, termino, scroller, sincronizar])
  useEffect(() => {
    const el = scroller.current
    if (!el || (resaltadas.length === 0 && universalResaltado === null)) return
    const indice = filas.findIndex((f) => (f.clase === 'universal' ? f.u.id === universalResaltado : resaltadas.includes(f.r.id)))
    if (indice < 0) return
    el.scrollTop = indice * alto
    sincronizar()
  }, [resaltadas, universalResaltado, filas, alto, scroller, sincronizar])

  const alternar = (u: UniversalInventario) => {
    if (!desplegados.has(u.id)) verAlDesplegar.current = u.id
    setDesplegados((antes) => {
      const nuevo = new Set(antes)
      if (nuevo.has(u.id)) nuevo.delete(u.id)
      else nuevo.add(u.id)
      return nuevo
    })
  }
  // S-82 (ajuste): si las hijas del grupo recién desplegado quedan fuera de la tabla, se desplaza lo
  // mínimo para verlas (o deja el universal arriba si no caben). Se calcula con la ventana virtual
  // (índice × alto), no con scrollIntoView: las filas pueden no estar montadas.
  useEffect(() => {
    const uid = verAlDesplegar.current
    const el = scroller.current
    if (uid === null || !el) return
    verAlDesplegar.current = null
    const indice = filas.findIndex((f) => f.clase === 'universal' && f.u.id === uid)
    if (indice < 0) return
    const hijas = filas.filter((f) => f.clase === 'hija' && f.u.id === uid).length
    const cabeza = (el.querySelector('.tabla-cabeza') as HTMLElement | null)?.offsetHeight ?? 0
    const visible = el.clientHeight - cabeza
    const arriba = indice * alto
    const abajo = (indice + 1 + hijas) * alto
    if (arriba >= el.scrollTop && abajo <= el.scrollTop + visible) return
    const destino = abajo - arriba > visible ? arriba : Math.min(arriba, Math.max(el.scrollTop, abajo - visible))
    el.scrollTo({ top: destino, behavior: reducirMovimiento() ? 'auto' : 'smooth' })
  }, [filas, alto, scroller])
  /** Esc con el foco en un grupo desplegado lo pliega y deja el foco en su flecha. */
  const plegarConEsc = (e: KeyboardEvent<HTMLDivElement>, u: UniversalInventario) => {
    if (e.key !== 'Escape' || !desplegados.has(u.id) || confirmando !== null) return
    e.preventDefault()
    e.stopPropagation()
    alternar(u)
    setTimeout(() => document.getElementById(`flecha-${u.id}`)?.focus(), 0)
  }

  const localizar = useMutation({
    mutationFn: async ({ codigo, metodo }: { codigo: string; metodo: MetodoCaptura }) => {
      const { data } = await api.get<Busqueda>('/buscar', { params: { q: codigo } })
      return { data, codigo, metodo }
    },
    onSuccess: ({ data, codigo, metodo }) => {
      const lista = comoLista(data.referencias)
      // Primero la que coincide exacto (referencia o código de barras, S-25); si no, la primera.
      const referencia = data.unidad?.referencia ?? lista.find((r) => r.codigo_fabricante === codigo || r.codigo_referencia.toUpperCase() === codigo.toUpperCase()) ?? lista[0]
      if (!referencia) {
        setResaltadas([])
        lectorRef.current?.select()
        setLectura({ codigo, metodo, titulo: 'Sin coincidencia', detalle: 'No está en el catálogo' })
        setAviso({ tipo: 'error', texto: `Sin resultados para ${codigo}.` })
        return
      }
      // S-25: si el código es un código de barras, puede estar en referencias de varios proveedores:
      // se filtra por el código leído (la búsqueda lo encuentra en todos) y se resaltan todas.
      // S-82: si la referencia es de un universal, el listado trae su grupo y lo despliega solo.
      const porBarras = data.unidad ? [] : lista.filter((r) => r.codigo_fabricante === codigo || r.codigo_referencia.toUpperCase() === codigo.toUpperCase())
      const ids = porBarras.length > 1 ? porBarras.map((r) => r.id) : [referencia.id]
      const filtro = porBarras.length > 1 || referencia.codigo_fabricante === codigo ? codigo : referencia.codigo_referencia
      setResaltadas(ids)
      setUniversalResaltado(null)
      setQ(filtro)
      irA(1)
      // Queda en el campo el código encontrado, seleccionado: el próximo escaneo lo reemplaza.
      if (lectorRef.current) {
        lectorRef.current.value = filtro
        lectorRef.current.select()
      }
      // RF-21: una unidad vendida o retirada no está en inventario; se avisa con su estado.
      const fuera = data.unidad?.estado === 'vendida' ? 'Unidad vendida' : data.unidad?.estado === 'retirada' ? 'Unidad retirada del inventario' : null
      const leido = data.unidad?.codigo_barras ?? codigo
      // S-23: sin marca; el proveedor y la calidad distinguen la referencia.
      const calidad = referencia.calidad ? NOMBRE_CALIDAD[referencia.calidad] : null
      const origen = [referencia.proveedor?.nombre, calidad].filter(Boolean).join(' · ')
      setLectura({
        codigo: leido,
        metodo,
        titulo: referencia.codigo_referencia,
        detalle: fuera ?? `${origen ? `${origen} · ` : ''}existencia ${referencia.existencia}`,
      })
      // El código leído no se repite si es el mismo de la referencia (p. ej. el de la caja).
      const que = leido.toUpperCase() === referencia.codigo_referencia.toUpperCase() ? leido : `${leido} → ${referencia.codigo_referencia}`
      const universal = referencia.codigo_universal ? `universal ${referencia.codigo_universal.codigo}` : null
      const partes = [que, referencia.descripcion, referencia.proveedor?.nombre, calidad, universal, `existencia ${referencia.existencia}`]
      setAviso({
        tipo: fuera ? 'atencion' : 'ok',
        texto: `${fuera ? `${fuera} · ` : ''}${metodo === 'escaneado' ? 'Escaneado' : 'Digitado'} · ${partes.filter(Boolean).join(' · ')}${ids.length > 1 ? ` · y ${ids.length - 1} más con otro proveedor` : ''}`,
      })
    },
    onError: (e) => {
      setAviso({ tipo: 'error', texto: mensajeError(e) })
    },
  })

  const onCodigo = useCallback((codigo: string, metodo: MetodoCaptura) => {
    localizar.mutate({ codigo, metodo })
  }, [localizar])
  const scanner = useScanner(onCodigo)

  /** Limpia el campo único: sin filtro ni resaltado, listado completo y foco en el campo. */
  const quitarFiltro = () => {
    if (lectorRef.current) lectorRef.current.value = ''
    scanner.reiniciar()
    setQ('')
    setResaltadas([])
    setUniversalResaltado(null)
    irA(1)
    lectorRef.current?.focus()
  }
  const quitarUniversalParam = () => {
    setParams((p) => { const n = new URLSearchParams(p); n.delete('universal'); return n })
    setUniversalResaltado(null)
  }

  const editar = (r: Referencia) => setEditando({
    id: r.id,
    datos: {
      codigo_referencia: r.codigo_referencia,
      codigo_fabricante: r.codigo_fabricante ?? '',
      calidad: r.calidad ?? '',
      proveedor_id: String(r.proveedor_id),
      categoria_id: String(r.categoria_id),
      codigo_universal_id: r.codigo_universal_id ? String(r.codigo_universal_id) : '',
      descripcion: r.descripcion,
      precio_venta: formatoNumero(r.precio_venta), // S-67: se edita con separador de miles
      stock_minimo: r.stock_minimo == null ? '' : String(r.stock_minimo),
    },
  })

  // Acciones visibles: Editar + Desactivar/Reactivar (gestión) y Historial (solo admin: Auditoría es del admin).
  const botonesAcciones = puedeGestionar ? (esAdmin ? 3 : 2) : 0
  const columnas = columnasDe(modo, botonesAcciones, verCostos.ultimo, verIva)

  const enfocarCampo = () => setTimeout(() => lectorRef.current?.focus(), 0)
  const refrescarLista = () => {
    qc.invalidateQueries({ queryKey: ['inventario'] })
    qc.invalidateQueries({ queryKey: ['referencias'] })
  }
  const cambiarEstado = useMutation({
    mutationFn: async ({ r, accion }: { r: Referencia; accion: 'desactivar' | 'reactivar' }) =>
      (await api.post<Referencia>(`/referencias/${r.id}/${accion}`)).data,
    onSuccess: (_, { r, accion }) => {
      setConfirmando(null)
      setErrorFila(null)
      setAviso({ tipo: 'ok', texto: `${r.codigo_referencia} ${accion === 'desactivar' ? 'quedó inactiva' : 'quedó activa de nuevo'}.` })
      refrescarLista()
      enfocarCampo()
    },
    onError: (e, { r }) => { setConfirmando(null); setErrorFila({ id: r.id, texto: mensajeError(e) }) },
  })
  const completa = modo === 'completa'

  /** Acciones de una referencia (suelta o hija): las de siempre, según el rol. */
  const accionesReferencia = (r: Referencia) => (
    <div role="cell" className="acciones-fila">
      {r.activo !== false && (
        <button type="button" className="icono-accion" aria-label={`Editar ${r.codigo_referencia}`} title="Editar" onClick={() => editar(r)}>
          <Icono nombre="editar" />
        </button>
      )}
      {r.activo === false ? (
        <button type="button" className="icono-accion ok" aria-label={`Reactivar ${r.codigo_referencia}`} title="Reactivar" disabled={cambiarEstado.isPending} onClick={() => cambiarEstado.mutate({ r, accion: 'reactivar' })}>
          <Icono nombre="reactivar" />
        </button>
      ) : (
        <button type="button" className="icono-accion peligro" aria-label={`Desactivar ${r.codigo_referencia}`} title="Desactivar" onClick={() => { setErrorFila(null); setConfirmando(r.id) }}>
          <Icono nombre="prohibido" />
        </button>
      )}
      {esAdmin && (
        <Link className="icono-accion" to={`/admin/auditoria?tabla=referencias&registro_id=${r.id}`} aria-label={`Historial de ${r.codigo_referencia}`} title="Historial">
          <Icono nombre="auditoria" />
        </Link>
      )}
    </div>
  )

  /** Celdas de una referencia (suelta o hija). */
  const celdasReferencia = (r: Referencia) => (
    <>
      <div role="cell" className="corta celda-referencia" title={r.codigo_fabricante ? `${r.codigo_referencia} · código de barras ${r.codigo_fabricante}` : r.codigo_referencia}>
        <strong>{r.codigo_referencia}</strong>
        {r.activo === false && <span className="chip inactiva">Inactiva</span>}
        {r.calidad && <> <ChipCalidad calidad={r.calidad} /></>}
        {r.codigo_fabricante && r.codigo_fabricante.toUpperCase() !== r.codigo_referencia.toUpperCase() && (
          <small className="codigo-barras" aria-label={`Código de barras ${r.codigo_fabricante}`}>▮ {r.codigo_fabricante}</small>
        )}
      </div>
      <div role="cell" data-etiqueta="Descripción" className="corta" title={r.descripcion}>{r.descripcion}</div>
      <div role="cell" data-etiqueta="Proveedor" className="corta" title={r.proveedor?.nombre}>{r.proveedor?.nombre}</div>
      {completa && <div role="cell" className="corta" title={r.categoria?.nombre}>{r.categoria?.nombre}</div>}
      <div role="cell" data-etiqueta="Precio" className="col-numero">{sinPrecio(r) ? <span className="chip sin-precio" title="No se puede vender hasta que el administrador le asigne un precio">Sin precio</span> : Number(r.precio_venta).toLocaleString('es-CO')}</div>
      {verCostos.ultimo && (
        <div role="cell" data-etiqueta="Costo" className="col-numero costos-celda">
          <CeldaUltimoCosto r={r} conEnlace={verCostos.historial} />
        </div>
      )}
      {verIva && (completa || modo === 'tarjetas') && <div role="cell" data-etiqueta="IVA" className="col-numero">{Number(r.iva_venta_porcentaje) === 0 ? <span className="chip">Sin IVA</span> : `${Number(r.iva_venta_porcentaje)} %`}</div>}
      <div role="cell" data-etiqueta="Existencia" className={r.existencia === 0 ? 'agotado col-numero celda-existencia' : 'col-numero celda-existencia'}>
        {/* S-28: el backend no lo envía a mostrador; tampoco se pinta. */}
        {r.bajo_minimo && !esMostrador && (
          <span className="chip bajo-minimo" title={`Mínimo ${r.minimo_efectivo ?? ''}${r.minimo_por_universal ? ' (del universal, sobre la suma)' : r.minimo_origen === 'general' ? ' (general)' : ''}`}>Bajo mínimo</span>
        )}
        {r.existencia}
      </div>
      {puedeGestionar && accionesReferencia(r)}
      {confirmando === r.id && (
        <div className="fila-flotante" role="group" aria-label={`Desactivar ${r.codigo_referencia}`} onKeyDown={(e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); setConfirmando(null); enfocarCampo() } }}>
          <span>¿Desactivar {r.codigo_referencia}?</span>
          <button type="button" className="peligro" disabled={cambiarEstado.isPending} onClick={() => cambiarEstado.mutate({ r, accion: 'desactivar' })}>Sí</button>
          <button type="button" className="secundario" autoFocus onClick={() => { setConfirmando(null); enfocarCampo() }}>No</button>
        </div>
      )}
      {errorFila?.id === r.id && (
        <div className="fila-flotante error" role="alert">
          <span>{errorFila.texto}</span>
          <button type="button" className="secundario" onClick={() => { setErrorFila(null); enfocarCampo() }}>Entendido</button>
        </div>
      )}
    </>
  )

  /** Fila agrupadora del universal: flecha, código, chip, total y (gestión) editar e historial. */
  const filaUniversal = (u: UniversalInventario) => {
    const abierto = desplegados.has(u.id)
    const refs = `${u.cantidad_referencias} ${u.cantidad_referencias === 1 ? 'ref.' : 'refs.'}`
    const resumen = `Universal ${u.codigo}, ${u.cantidad_referencias} ${u.cantidad_referencias === 1 ? 'referencia' : 'referencias'}`
    return (
      <>
        <div role="cell" className="corta celda-referencia celda-universal" title={resumen}>
          <button
            type="button"
            id={`flecha-${u.id}`}
            className={`flecha-universal${abierto ? ' abierta' : ''}`}
            aria-expanded={abierto}
            aria-controls={abierto && u.referencias.length > 0 ? u.referencias.map((r) => idHija(u.id, r.id)).join(' ') : undefined}
            aria-label={`${abierto ? 'Ocultar' : 'Mostrar'} referencias de ${resumen}`}
            onClick={(e) => { e.stopPropagation(); alternar(u) }}
          >
            <Chevron />
          </button>
          <span className="solo-lector">Universal </span>
          <strong>{u.codigo}</strong>
          <span className="chip chip-universal">{refs}</span>
        </div>
        <div role="cell" data-etiqueta="Descripción" className="corta" title={u.descripcion ?? undefined}>{u.descripcion ?? '—'}</div>
        <div role="cell" data-etiqueta="Proveedor" className="corta" title={u.proveedores.map((p) => p.nombre).join(', ')}>{textoProveedores(u)}</div>
        {completa && <div role="cell" className="corta" title={u.categoria?.nombre}>{u.categoria?.nombre}</div>}
        {/* S-82 (ajuste): precio, costo e IVA son de cada referencia: en el universal quedan vacíos. */}
        <div role="cell" className="col-numero celda-vacia" />
        {verCostos.ultimo && <div role="cell" className="col-numero celda-vacia" />}
        {verIva && (completa || modo === 'tarjetas') && <div role="cell" className="col-numero celda-vacia" />}
        <div role="cell" data-etiqueta="Existencia" className={u.existencia_total === 0 ? 'agotado col-numero celda-existencia' : 'col-numero celda-existencia'} title="Suma de sus referencias">
          {u.bajo_minimo && !esMostrador && (
            <span className="chip bajo-minimo" title={`Mínimo ${u.minimo_efectivo ?? ''} sobre la suma${u.minimo_origen === 'general' ? ' (general)' : ''}`}>Bajo mínimo</span>
          )}
          <strong>{u.existencia_total}</strong>
        </div>
        {puedeGestionar && (
          <div role="cell" className="acciones-fila">
            <button type="button" className="icono-accion" aria-label={`Editar universal ${u.codigo}`} title="Editar universal" onClick={(e) => { e.stopPropagation(); setUniversalEditando(u) }}>
              <Icono nombre="editar" />
            </button>
            {esAdmin && (
              <Link className="icono-accion" to={`/admin/auditoria?tabla=codigos_universales&registro_id=${u.id}`} aria-label={`Historial del universal ${u.codigo}`} title="Historial" onClick={(e) => e.stopPropagation()}>
                <Icono nombre="auditoria" />
              </Link>
            )}
          </div>
        )}
      </>
    )
  }

  return (
    <div className="pagina-tabla-fija">
      <div className="pagina-encabezado">
        <h1>Inventario</h1>
        <p>El catálogo llega por páginas. Los códigos universales agrupan sus referencias: la existencia es la suma. El escáner localiza la unidad en el momento.</p>
      </div>

      <Resumen
        clave="productos-resumen"
        compacto={(
          <>
            <span>
              <strong>{info ? info.total.toLocaleString('es-CO') : '—'}</strong> {info?.total === 1 ? 'resultado' : 'resultados'}
              {universalesPagina.length > 0 && <> · <strong>{universalesPagina.length}</strong> {universalesPagina.length === 1 ? 'universal' : 'universales'}</>}
            </span>
            <span>Pág. <strong>{info ? `${info.actual}/${info.ultima}` : '—'}</strong></span>
            <span className={resultado && agotadosPagina > 0 ? 'resumen-agotados' : ''}>
              <strong>{resultado ? agotadosPagina : '—'}</strong> agotados
            </span>
            <span>Lectura <strong>{lectura?.titulo ?? '—'}</strong></span>
          </>
        )}
      >
            <article className="medida">
              <div>
                <span className="medida-etiqueta">Resultados</span>
                <strong className="medida-numero">{info ? info.total.toLocaleString('es-CO') : '—'}</strong>
              </div>
              <span className="medida-icono azul"><Icono nombre="caja" /></span>
            </article>
            <article className="medida">
              <div>
                <span className="medida-etiqueta">Página</span>
                <strong className="medida-numero">{info ? `${info.actual} / ${info.ultima}` : '—'}</strong>
              </div>
              <span className="medida-icono amarillo"><Icono nombre="catalogo" /></span>
            </article>
            <article className="medida">
              <div>
                <span className="medida-etiqueta">Agotados en esta página</span>
                <strong className="medida-numero">{resultado ? agotadosPagina : '—'}</strong>
              </div>
              <span className="medida-icono rojo"><Icono nombre="agotados" /></span>
            </article>
            <article className="medida">
              <div>
                <span className="medida-etiqueta">Última lectura</span>
                <strong className="medida-numero medida-codigo">{lectura?.titulo ?? '—'}</strong>
                <span className="medida-pie">{lectura ? `${lectura.codigo} · ${lectura.metodo}` : 'Esperando un código'}</span>
              </div>
              <span className="medida-icono verde"><Icono nombre="etiqueta" /></span>
            </article>
      </Resumen>

      <section ref={tarjeta.ref} className={`tarjeta-pagina tarjeta-tabla listado-${modo}`} style={{ ['--cols' as string]: columnas }}>
        <div className="fila-titulo">
          <h2>Listado</h2>
          {universalesPagina.length > 0 && (
            <button type="button" className="enlace desplegar-todos" aria-pressed={todosDesplegados} onClick={desplegarTodos}>
              {todosDesplegados ? 'Plegar todos' : 'Desplegar todos'}
            </button>
          )}
        </div>

        <div className="barra-busqueda">
          <div className="campo-unico">
            <Icono nombre="buscar" />
            <input
              ref={lectorRef}
              className="escaner"
              placeholder="Escanee o busque por código, código universal o descripción"
              onInput={(e) => { setQ(e.currentTarget.value); setResaltadas([]); setUniversalResaltado(null); setAviso(null); setErrorFila(null) }}
              onKeyDown={(e) => {
                // Esc con texto limpia el campo, el filtro y el resaltado.
                if (e.key === 'Escape' && e.currentTarget.value !== '') { e.preventDefault(); quitarFiltro(); return }
                scanner.onKeyDown(e)
              }}
              onPaste={scanner.onPaste}
              autoFocus
              autoComplete="off"
              aria-label="Escanear o buscar productos"
            />
            {q && (
              <button type="button" className="busca-limpiar" aria-label="Limpiar la búsqueda" title="Limpiar (Esc)" onClick={quitarFiltro}>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
              </button>
            )}
          </div>
          <div className="controles-listado">
            <SelectorProveedor className="filtro" aria-label="Proveedor" value={proveedorId} onChange={setProveedorId} opcionTodos="Todos los proveedores" />
            {puedeGestionar && (
              <>
                <label className="check ver-inactivas">
                  <input type="checkbox" checked={verInactivas} onChange={(e) => setVerInactivas(e.target.checked)} /> Ver inactivas
                </label>
                <button type="button" className="secundario" onClick={() => setUniversalEditando('nuevo')}>Nuevo universal</button>
                <button type="button" className="primario" onClick={() => setEditando({ id: null, datos: vacio })}>Nueva referencia</button>
              </>
            )}
          </div>
        </div>
        {universalParam && (
          <div className="aviso info" role="status">
            Mostrando un código universal.{' '}
            <button type="button" className="enlace" onClick={quitarUniversalParam}>Ver todo el inventario</button>
          </div>
        )}
        {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

        <div className="tabla-contenedor">
        <div className={`tabla-scroll tabla-viva llena${cargando ? ' actualizando' : ''}`} role="table" aria-label="Productos" aria-rowcount={info?.total} ref={ventana.ref} onScroll={ventana.onScroll}>
          <div role="row" className="tabla-rejilla tabla-cabeza">
            <div role="columnheader">Referencia</div>
            <div role="columnheader">Descripción</div>
            <div role="columnheader">Proveedor</div>
            {completa && <div role="columnheader">Categoría</div>}
            <div role="columnheader" className="col-numero" title="Precio de venta con IVA incluido">Precio (IVA incl.)</div>
            {verCostos.ultimo && <div role="columnheader" className="col-numero" title="Último costo de compra, sin IVA">Costo</div>}
            {verIva && completa && <div role="columnheader" className="col-numero">IVA</div>}
            <div role="columnheader" className="col-numero">Existencia</div>
            {puedeGestionar && <div role="columnheader" className="col-acciones">Acciones</div>}
          </div>
          <div>
            {filas.length === 0 && !cargando && <div className="vacio">Sin productos para esta búsqueda.</div>}
            {filas.length > 0 && (
              <div style={{ height: ventana.altoTotal }}>
                <div style={{ transform: `translateY(${ventana.desplazamiento}px)` }}>
                  {ventana.visibles.map((f) => {
                    if (f.clase === 'universal') {
                      const u = f.u
                      return (
                        <div
                          role="row"
                          key={claveFila(f)}
                          className={`tabla-rejilla tabla-fila fila-universal${desplegados.has(u.id) ? ' desplegada' : ''}${universalResaltado === u.id ? ' resaltada' : ''}`}
                          style={{ height: alto }}
                          onClick={(e) => { if (!(e.target as HTMLElement).closest('button, a, input, select')) alternar(u) }}
                          onKeyDown={(e) => plegarConEsc(e, u)}
                        >
                          {filaUniversal(u)}
                        </div>
                      )
                    }
                    const r = f.r
                    const hija = f.clase === 'hija'
                    return (
                      <div
                        role="row"
                        key={claveFila(f)}
                        id={hija ? idHija(f.u.id, r.id) : undefined}
                        className={`tabla-rejilla tabla-fila${hija ? ` fila-hija${f.ultima ? ' ultima' : ''}${r.activo !== false && r.existencia === 0 ? ' agotada' : ''}` : ''}${resaltadas.includes(r.id) ? ' resaltada' : ''}${r.activo === false ? ' inactiva' : ''}`}
                        style={{ height: alto }}
                        onKeyDown={hija ? (e) => plegarConEsc(e, f.u) : undefined}
                      >
                        {celdasReferencia(r)}
                      </div>
                    )
                  })}
                </div>
              </div>
            )}
          </div>
        </div>
        </div>
        <Paginacion pagina={resultado} onCambiar={(n) => { irA(n); setResaltadas([]) }} />
      </section>

      {editando && (
        <FormularioReferencia
          id={editando.id}
          inicial={editando.datos}
          onListo={(guardada) => {
            setEditando(null)
            refrescarLista()
            // S-82: con universal, su grupo queda desplegado y la referencia resaltada.
            if (guardada?.codigo_universal_id) {
              const uid = guardada.codigo_universal_id
              setDesplegados((antes) => new Set(antes).add(uid))
              setResaltadas([guardada.id])
            }
            enfocarCampo()
          }}
          onCerrar={() => { setEditando(null); enfocarCampo() }}
        />
      )}
      {universalEditando && (
        <DialogoUniversal
          universal={universalEditando === 'nuevo' ? null : universalEditando}
          esAdmin={esAdmin}
          onGuardado={(id) => {
            setUniversalEditando(null)
            setDesplegados((antes) => new Set(antes).add(id))
            setUniversalResaltado(id)
            setAviso({ tipo: 'ok', texto: 'Código universal guardado.' })
            enfocarCampo()
          }}
          onEliminado={() => { setUniversalEditando(null); setAviso({ tipo: 'ok', texto: 'Código universal eliminado.' }); enfocarCampo() }}
          onCerrar={() => { setUniversalEditando(null); enfocarCampo() }}
        />
      )}
    </div>
  )
}

/**
 * S-71: último costo de compra (neto unitario sin IVA), solo el valor; la fecha está en el
 * historial. Con ver-historial-costos (admin, logística) es enlace al historial; para el mostrador, texto.
 */
function CeldaUltimoCosto({ r, conEnlace }: { r: Referencia; conEnlace: boolean }) {
  const c = r.ultimo_costo
  if (!c) return <span className="costos-sin-dato" title="Sin compras confirmadas">—</span>
  const valor = `$ ${formatoNumero(c.valor)}`
  return conEnlace
    ? <Link to={`${RUTA_COSTOS}?referencia=${r.id}`} title={`Ver el historial de costos de ${r.codigo_referencia}`}>{valor}</Link>
    : <span>{valor}</span>
}

function FormularioReferencia({ id, inicial, onListo, onCerrar }: { id: number | null; inicial: Formulario; onListo: (guardada: Referencia | undefined) => void; onCerrar: () => void }) {
  const [f, setF] = useState(inicial)
  /** S-25: códigos consultados al salir del campo (aviso si existen con otro proveedor). */
  const [consultados, setConsultados] = useState<string[]>([])
  const consultar = () => setConsultados([f.codigo_referencia, f.codigo_fabricante])
  const categorias = useCategorias()
  const categoria = categorias.data?.find((c) => String(c.id) === f.categoria_id)
  const admiteUniversal = categoria?.admite_codigo_universal ?? false

  const guardar = useMutation({
    mutationFn: async () => {
      const conUniversal = admiteUniversal && f.codigo_universal_id !== ''
      const datos = {
        ...f,
        precio_venta: leerNumero(f.precio_venta), // S-67: sin el formato
        codigo_fabricante: f.codigo_fabricante.trim() || null,
        calidad: f.calidad || null,
        codigo_universal_id: conUniversal ? Number(f.codigo_universal_id) : null,
        // RF-22: con universal, el mínimo es del universal; la referencia no lleva uno propio.
        stock_minimo: conUniversal || f.stock_minimo.trim() === '' ? null : Number(f.stock_minimo),
      }
      const r = id ? await api.put<Referencia | { data: Referencia }>(`/referencias/${id}`, datos) : await api.post<Referencia | { data: Referencia }>('/referencias', datos)
      return 'data' in r.data ? r.data.data : r.data
    },
    onSuccess: (guardada) => onListo(guardada),
  })

  const errorPrecioVenta = f.precio_venta === '' ? null : (leerNumero(f.precio_venta) ?? 0) > 0 ? null : 'El precio de venta debe ser mayor que 0.'
  const universalElegido = admiteUniversal && f.codigo_universal_id !== ''
  const errorMinimo = errorStockMinimo(f.stock_minimo)
  const campo = (k: keyof Formulario) => ({ value: f[k], onChange: (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value }) })

  return (
    // Marco compartido: X de cerrar, Escape, foco atrapado y bloqueo del scroll.
    <Dialogo
      titulo={id ? 'Editar referencia' : 'Nueva referencia'}
      pendiente={guardar.isPending}
      onCerrar={onCerrar}
      onEnviar={() => guardar.mutate()}
      pie={(
        <>
          <BotonCancelar />
          <button type="submit" className="primario" disabled={guardar.isPending || errorPrecioVenta !== null || (!universalElegido && errorMinimo !== null)}>Guardar</button>
        </>
      )}
    >
      <DialogoSeccion>
        <label>Referencia del proveedor
          <input required={f.codigo_fabricante.trim() === ''} maxLength={80} aria-describedby={f.codigo_fabricante.trim() ? 'referencia-ayuda' : undefined} onBlur={consultar} {...campo('codigo_referencia')} />
        </label>
        {f.codigo_fabricante.trim() !== '' && (
          <p id="referencia-ayuda" className="ayuda">Si el producto no trae otra referencia, déjela vacía y se usará el código de barras.</p>
        )}
        <label>Código de barras o QR del producto (si lo trae)
          <input maxLength={120} autoComplete="off" onBlur={consultar} {...campo('codigo_fabricante')} />
        </label>
        <AvisoCoincidencias codigos={consultados} proveedorId={f.proveedor_id || null} accion={id ? 'guardarla' : 'crearla'} />
        <label>Descripción<input required {...campo('descripcion')} /></label>
        <label>Calidad
          <select {...campo('calidad')}>
            <option value="">—</option>
            <option value="fino">Fino</option>
            <option value="generico">Genérico</option>
          </select>
        </label>
        <label>Proveedor
          <SelectorProveedor required permitirCrear value={f.proveedor_id} onChange={(proveedor_id) => setF((v) => ({ ...v, proveedor_id }))} />
        </label>
        <SelectorCategoria value={f.categoria_id} onChange={(categoria_id) => setF((v) => ({ ...v, categoria_id }))} />
        {admiteUniversal && (
          // S-59: elegir o crear el universal en línea, con la categoría de la referencia.
          <SelectorUniversal categoriaId={f.categoria_id} value={f.codigo_universal_id} onChange={(codigo_universal_id) => setF((v) => ({ ...v, codigo_universal_id }))} />
        )}
        <label>Precio de venta
          <CampoMoneda required aria-invalid={errorPrecioVenta !== null} aria-describedby="precio-venta-ayuda" {...campo('precio_venta')} />
        </label>
        <p id="precio-venta-ayuda" className={errorPrecioVenta ? 'ayuda error' : 'ayuda'}>
          {errorPrecioVenta ?? 'Con IVA incluido. Mayor que 0: una referencia sin precio no se puede vender.'}
        </p>
        {universalElegido ? (
          <p className="ayuda">Stock mínimo: se define en el código universal y se evalúa sobre la suma de sus referencias.</p>
        ) : (
          <>
            <label>Stock mínimo (opcional)
              <input type="number" min="0" max="9999" step="1" inputMode="numeric" aria-invalid={errorMinimo !== null} aria-describedby="stock-minimo-ayuda" {...campo('stock_minimo')} />
            </label>
            <p id="stock-minimo-ayuda" className={errorMinimo ? 'ayuda error' : 'ayuda'}>
              {errorMinimo ?? 'Vacío = usa el general; 0 = este producto no maneja stock mínimo.'}
            </p>
          </>
        )}
        {guardar.isError && <div className="aviso error">{mensajeError(guardar.error)}</div>}
      </DialogoSeccion>
    </Dialogo>
  )
}
