import { memo, useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { RUTA_CARTERA, rutaInicio } from '../../rutas'
import { AxiosError } from 'axios'
import { api, mensajeError } from '../../api/cliente'
import { NOMBRE_CALIDAD, type Calidad, type Cliente, type MetodoCaptura, type OpcionReferenciaVenta, type PagoVentaNuevo, type Pagina, type Referencia, type RespuestaEscaneo, type Venta as TVenta, type VentaAbierta, type VentaItem } from '../../api/tipos'
import { useAviso } from '../../componentes/Avisos'
import { useAuth } from '../../auth/AuthProvider'
import CampoCantidad from '../../componentes/CampoCantidad'
import { sumarDias } from '../../componentes/fechas'
import ChipCalidad from '../../componentes/ChipCalidad'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import ListaEleccion, { type FilaEleccion } from '../../componentes/ListaEleccion'
import DialogoElegirReferencia from '../../componentes/mostrador/DialogoElegirReferencia'
import FormaPagoVenta from '../../componentes/mostrador/FormaPagoVenta'
import { aPagos, evaluarPago, filasIniciales, type FilaPago } from '../../utilidades/formaPago'
import { useCuentasPago } from '../../hooks/useCatalogos'
import Paginacion from '../../componentes/Paginacion'
import { useScanner } from '../../hooks/useScanner'
import { useAngosta } from '../../hooks/useAngosta'
import { CLAVE_ABIERTAS, esDeDiaAnterior, useVentasAbiertas } from '../../hooks/useVentasAbiertas'
import { hoyEnBogota } from '../../utilidades/periodos'
import { formatearFecha, formatearFechaHora, formatearHora } from '../../utilidades/fechas'
import { rotuloIvaIncluido } from '../../utilidades/cartera'
import { useDebounce } from '../../hooks/useDebounce'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { formatoNumero, leerNumero, pesos } from '../../componentes/numeros'
import CampoMoneda from '../../componentes/CampoMoneda' // S-67

interface ReversoPendiente { item?: VentaItem; tipo: 'devolucion' | 'anulacion' }
interface DatosCredito { cliente_id: number; factura_contapyme: string | null; cliente: { id: number; nombre: string; documento: string | null } }
/**
 * S-46: venta que todavía no existe. Solo vive en la pantalla (forma de pago y cliente elegidos); el
 * primer escaneo exitoso la crea con su ítem (POST /ventas). Si ese escaneo falla, no queda nada.
 */
type Borrador = { forma_pago: 'contado' } | { forma_pago: 'credito'; credito: DatosCredito }
const BORRADOR_CONTADO: Borrador = { forma_pago: 'contado' }
/** A dónde va una lectura: una venta abierta (id) o un borrador (crea la venta). */
type Destino = number | Borrador

/** El mostrador elige de a 15: el diálogo no carga el padrón entero. */
const CLIENTES_POR_PAGINA = 15

/** vence_el llega como aaaa-mm-dd: es un día del calendario, no se convierte de zona (S-38). */
const fechaCorta = formatearFecha

/** Validación en vivo. El backend vuelve a exigir precio > 0 (S-18). */
function errorPrecio(texto: string): string | null {
  const n = leerNumero(texto)
  if (n === null) return 'Escriba el precio.'
  if (Number.isNaN(n)) return 'Use solo números; los centavos van después de la coma (máximo 2).'
  if (n <= 0) return 'El precio debe ser mayor que 0.'
  return null
}


/** S-40: máximo de unidades por lectura con el código del proveedor (igual que el backend). */
const MAX_CANTIDAD = 50

/** Pregunta antes de empezar una venta igual a una que ya está abierta (S-40). */
interface Duplicada { existente: VentaAbierta; abrirOtra: () => void }

/** "Nueva · Contado" / "Nueva · Crédito · Cliente" (ficha y título del borrador, S-46). */
const textoBorrador = (b: Borrador) => (b.forma_pago === 'credito' ? `Nueva · Crédito · ${b.credito.cliente.nombre}` : 'Nueva · Contado')

/**
 * RF-06 / S-33: agrega a la venta en caché los ítems de una lectura, con los totales del backend.
 * Dos lecturas casi seguidas (p. ej. las de la cola de S-46) pueden responder en otro orden. El
 * backend las atiende de a una (bloquea la venta, igual que los reversos), así que la respuesta con
 * el ítem de id más alto trae el total más nuevo, que ya incluye los anteriores. Una respuesta
 * atrasada agrega su ítem en su lugar (por id), pero no pisa ese total.
 */
function agregarItems(venta: TVenta, nuevos: VentaItem[], totales: RespuestaEscaneo['totales']): TVenta {
  const yaEstan = new Set(venta.items.map((i) => i.id))
  const faltan = nuevos.filter((n) => !yaEstan.has(n.id))
  if (faltan.length === 0) return venta
  const ultimoEnVenta = venta.items.reduce((max, i) => Math.max(max, i.id), 0)
  if (faltan.some((n) => n.id > ultimoEnVenta)) {
    return { ...venta, items: [...venta.items, ...faltan], totales: totales ?? venta.totales }
  }
  return { ...venta, items: [...venta.items, ...faltan].sort((a, b) => a.id - b.id) }
}

/**
 * RF-06: venta de mostrador. Cada escaneo descuenta de inmediato (no al cerrar).
 * RF-12: input siempre enfocado para el lector, y búsqueda manual de respaldo (queda "digitado").
 * RF-07: reversos con motivo obligatorio; nada se borra.
 * S-40: el mostrador escanea a lo largo del día y cierra al final; puede tener varias ventas
 * abiertas (la de contado del día y las de crédito) y cambia entre ellas desde la barra de fichas.
 * S-46: "Nueva venta" no llama al servidor: deja un borrador local y la venta nace con el primer
 * escaneo. Sin ventas abiertas, la pantalla entra en un borrador de contado: se escanea de una vez.
 */
export default function Venta() {
  const qc = useQueryClient()
  const avisos = useAviso()
  const { usuario } = useAuth()
  // RF-18: ?venta=ID abre esa venta (enlace desde la cartera del cliente).
  const [params] = useSearchParams()
  const pedida = Number(params.get('venta')) || null
  const [ventaId, setVentaId] = useState<number | null>(pedida)
  // S-43: el admin no vende. Entra solo con ?venta=ID (enlaces de Cartera) y ve la venta en modo
  // consulta: detalle, totales y reversos con motivo; sin escanear, abrir, cerrar ni editar precios.
  const consulta = usuario?.rol === 'admin'
  const navegar = useNavigate()
  const [respaldo, setRespaldo] = useState(false)
  const [reverso, setReverso] = useState<ReversoPendiente | null>(null)
  const [eligiendoCliente, setEligiendoCliente] = useState(false)
  /** RF-14 / S-18: ítem cuyo precio se está editando (solo en una venta a crédito abierta). */
  const [editandoPrecio, setEditandoPrecio] = useState<number | null>(null)
  /** S-31: el código del proveedor está en varias referencias; se espera que quien vende elija. */
  const [eligiendoRef, setEligiendoRef] = useState<{ codigo: string; metodo: MetodoCaptura; cantidad: number; destino: Destino; referencias: OpcionReferenciaVenta[] } | null>(null)
  /** S-40: abrir una venta igual a una ya abierta pregunta antes. */
  const [duplicada, setDuplicada] = useState<Duplicada | null>(null)
  /** S-40: cerrar pide confirmación con el resumen. */
  const [confirmandoCierre, setConfirmandoCierre] = useState(false)
  /** S-41: botón "Cerrar venta"; al volver del modal recibe el foco. */
  const botonCerrarRef = useRef<HTMLButtonElement>(null)
  const focoAlCerrarDialogo = useRef<HTMLElement | null>(null)
  /** S-40: unidades por lectura con el código del proveedor (con una etiqueta siempre es 1). */
  const [cantidad, setCantidad] = useState(1)
  const inputRef = useRef<HTMLInputElement>(null)
  // A 360 px el placeholder largo se corta; el aria-label sigue diciendo qué códigos acepta.
  const angosta = useAngosta()

  /** S-46: borrador de la venta por empezar (ventaId null). Por defecto, de contado. */
  const [borrador, setBorrador] = useState<Borrador>(BORRADOR_CONTADO)
  /** S-46: el mostrador eligió empezar una venta nueva: no se le retoma otra abierta. */
  const [eligioNueva, setEligioNueva] = useState(false)

  // S-40: todas las ventas abiertas (las del usuario; el admin, las de todos). Sin una pedida, se
  // retoma la más antigua CON ítems; sin ninguna, queda el borrador de contado (S-46).
  const abiertas = useVentasAbiertas(!consulta)
  const listaAbiertas = abiertas.data ?? []
  useEffect(() => {
    const retomar = abiertas.data?.find((a) => a.items_count > 0)
    if (ventaId === null && !eligioNueva && retomar) setVentaId(retomar.id)
  }, [abiertas.data, ventaId, eligioNueva])

  const venta = useQuery({
    queryKey: ['venta', ventaId],
    queryFn: async () => (await api.get<TVenta>(`/ventas/${ventaId}`)).data,
    enabled: ventaId !== null,
  })

  const recientes = useQuery({
    queryKey: ['ventas', 'cerradas'],
    queryFn: async () => (await api.get<Pagina<VentaReciente>>('/ventas', { params: { estado: 'cerrada' } })).data,
    enabled: !consulta,
  })

  const enfocarLector = () => setTimeout(() => inputRef.current?.focus(), 0)

  // S-46: empezar una venta nueva no llama al servidor: deja el borrador y enfoca el lector.
  const empezar = useCallback((b: Borrador) => {
    setBorrador(b)
    setEligioNueva(true)
    setVentaId(null)
    setEditandoPrecio(null)
    enfocarLector()
  }, [])

  // S-40: las ventas abiertas propias (el admin ve todas, pero solo pregunta por las suyas).
  const propias = listaAbiertas.filter((a) => a.usuario?.id === usuario?.id)
  const nuevaContado = () => {
    const existente = propias.find((a) => a.forma_pago === 'contado' && a.items_count > 0)
    if (existente) setDuplicada({ existente, abrirOtra: () => empezar(BORRADOR_CONTADO) })
    else empezar(BORRADOR_CONTADO)
  }
  const nuevaCredito = (datos: DatosCredito) => {
    const existente = propias.find((a) => a.forma_pago === 'credito' && a.cliente?.id === datos.cliente_id && a.items_count > 0)
    setEligiendoCliente(false)
    const nueva: Borrador = { forma_pago: 'credito', credito: datos }
    if (existente) setDuplicada({ existente, abrirOtra: () => empezar(nueva) })
    else empezar(nueva)
  }
  const cambiarA = useCallback((id: number) => {
    setVentaId(id)
    setEligioNueva(false)
    setEditandoPrecio(null)
    enfocarLector()
  }, [])

  /**
   * S-46: mientras el primer escaneo crea la venta (POST /ventas), las lecturas siguientes esperan en
   * cola y luego van a la venta nueva: dos lecturas rápidas nunca crean dos ventas.
   */
  const creandoRef = useRef(false)
  const colaRef = useRef<Array<{ codigo: string; metodo: MetodoCaptura; cantidad: number }>>([])

  // S-31: el código puede ser la etiqueta del local o el del proveedor/fabricante; el backend
  // decide. Con referenciaId se resuelve un código que está en varias referencias. S-40: con
  // cantidad > 1 agrega N unidades del código del proveedor. S-46: con un borrador, la lectura va a
  // POST /ventas y crea la venta con su primer ítem (o nada, si falla).
  const escanear = useMutation({
    mutationFn: async ({ codigo, metodo, referenciaId, cantidad: n, destino }: { codigo: string; metodo: MetodoCaptura; referenciaId?: number; cantidad: number; destino: Destino }) => {
      const lectura = { codigo_barras: codigo, metodo_captura: metodo, referencia_id: referenciaId, cantidad: n }
      if (typeof destino === 'number') return (await api.post<RespuestaEscaneo>(`/ventas/${destino}/escanear`, lectura)).data
      const venta = destino.forma_pago === 'credito'
        ? { forma_pago: 'credito', cliente_id: destino.credito.cliente_id, factura_contapyme: destino.credito.factura_contapyme }
        : { forma_pago: 'contado' }
      return (await api.post<RespuestaEscaneo>('/ventas', { ...venta, ...lectura })).data
    },
    onSuccess: (r, { destino }) => {
      const nuevos = r.items?.length ? r.items : [r.item]
      const porReferencia = r.origen_codigo === 'referencia' ? ' · por referencia' : ''
      const cuales = nuevos.length === 1 ? r.unidad.codigo_barras : `${nuevos.length} unidades`
      const nace = typeof destino === 'number' ? '' : `Venta #${r.venta_id} · `
      avisos.mostrar('ok', `✓ ${nace}${cuales}${porReferencia} · ${r.referencia.codigo_referencia} · ${[r.referencia.proveedor, r.referencia.calidad && NOMBRE_CALIDAD[r.referencia.calidad as Calidad]].filter(Boolean).join(' · ')} — quedan ${r.referencia.existencia} (${r.metodo_captura})`)
      setCantidad(1)
      const clave = ['venta', r.venta_id]
      if (typeof destino !== 'number') {
        // S-46: la venta acaba de nacer y llega completa: queda en caché antes de pasar a ser la
        // activa, así la tabla aparece de una vez, sin pedir GET /ventas/{id} y sin parpadeo.
        if (r.venta) qc.setQueryData(clave, r.venta)
        setVentaId(r.venta_id)
        setEligioNueva(false)
        setBorrador(BORRADOR_CONTADO)
        qc.invalidateQueries({ queryKey: CLAVE_ABIERTAS })
        creandoRef.current = false
        const cola = colaRef.current.splice(0)
        cola.forEach((l) => escanear.mutate({ ...l, destino: r.venta_id }))
      }
      // RF-06: una sola petición por escaneo. Los ítems llegan completos y se agregan a la venta en
      // caché, con los totales nuevos (S-33) que calcula el backend; la ficha de la venta también.
      if (!qc.getQueryData<TVenta>(clave)) {
        qc.invalidateQueries({ queryKey: clave })
        qc.setQueryData<VentaAbierta[]>(CLAVE_ABIERTAS, (lista) => lista?.map((a) => (a.id === r.venta_id
          ? { ...a, items_count: a.items_count + nuevos.length, total: r.totales?.total ?? a.total }
          : a)))
        return
      }
      void qc.cancelQueries({ queryKey: clave })
      const actualizada = qc.setQueryData<TVenta>(clave, (actual) => (actual ? agregarItems(actual, nuevos, r.totales) : actual))
      // La ficha copia la venta ya actualizada: una respuesta atrasada tampoco le pisa el total.
      qc.setQueryData<VentaAbierta[]>(CLAVE_ABIERTAS, (lista) => lista?.map((a) => (a.id === r.venta_id && actualizada
        ? { ...a, items_count: actualizada.items.filter((i) => !i.reverso_id).length, total: actualizada.totales?.total ?? a.total }
        : a)))
    },
    onError: (e, { codigo, metodo, referenciaId, cantidad: n, destino }) => {
      const datos = e instanceof AxiosError ? e.response?.data as { tipo?: string; message?: string; referencias?: OpcionReferenciaVenta[] } | undefined : undefined
      if (datos?.tipo === 'elegir_referencia' && datos.referencias?.length) {
        avisos.limpiar()
        creandoRef.current = false
        setEligiendoRef({ codigo, metodo, cantidad: n, destino, referencias: datos.referencias })
        return
      }
      // S-40: con una etiqueta la cantidad no aplica: se vende esa unidad (1).
      if (n > 1 && datos?.message?.startsWith('Cada etiqueta es una unidad')) {
        setCantidad(1)
        escanear.mutate({ codigo, metodo, referenciaId, cantidad: 1, destino })
        return
      }
      avisos.mostrar('error', mensajeError(e))
      // S-46: con un borrador no hay venta que refrescar (ni que limpiar): el borrador sigue igual y
      // la siguiente lectura en cola intenta crear la venta.
      if (typeof destino === 'number') {
        qc.invalidateQueries({ queryKey: ['venta', destino] })
        qc.invalidateQueries({ queryKey: CLAVE_ABIERTAS })
        return
      }
      const siguiente = colaRef.current.shift()
      creandoRef.current = siguiente !== undefined
      if (siguiente) escanear.mutate({ ...siguiente, destino })
    },
  })

  const cerrar = useMutation({
    // RF-24 (S-48): de contado lleva la forma de pago; a crédito, nada.
    mutationFn: async (pagos?: PagoVentaNuevo[]) => (await api.post<TVenta>(`/ventas/${ventaId}/cerrar`, pagos ? { pagos } : {})).data,
    onSuccess: (v) => {
      qc.setQueryData(['venta', v.id], v)
      qc.invalidateQueries({ queryKey: ['ventas'] })
      setConfirmandoCierre(false)
      avisos.mostrar(
        'ok',
        v.forma_pago === 'credito'
          ? `Venta #${v.id} a crédito cerrada.${v.vence_el ? ` Vence el ${fechaCorta(v.vence_el)}.` : ''} Facture en Contapyme.`
          : `Venta #${v.id} cerrada. Facture en Contapyme.`,
      )
    },
    onError: (e) => { setConfirmandoCierre(false); avisos.mostrar('error', mensajeError(e)) },
  })

  // mutate es estable; el objeto de la mutación cambia en cada render y rehacía leer, onCodigo y el lector.
  const mutarEscaneo = escanear.mutate
  const cantidadRef = useRef(cantidad)
  cantidadRef.current = cantidad
  // S-46: a dónde va la lectura. Una venta abierta en pantalla recibe el ítem; sin venta, el borrador
  // elegido; si se está viendo una venta cerrada, la lectura empieza una venta nueva de contado.
  const destinoActual = useCallback((): Destino => {
    if (ventaId === null) return borrador
    // Mientras carga la venta elegida (p. ej. la que acaba de nacer) la lectura va a ella.
    if (venta.data?.id !== ventaId) return ventaId
    return venta.data.estado === 'abierta' ? ventaId : BORRADOR_CONTADO
  }, [ventaId, borrador, venta.data?.id, venta.data?.estado])
  /** Envía una lectura; si crearía una venta y ya hay una naciendo, espera en la cola. */
  const leer = useCallback((l: { codigo: string; metodo: MetodoCaptura; cantidad: number; referenciaId?: number }, destino: Destino) => {
    if (typeof destino !== 'number') {
      if (creandoRef.current && l.referenciaId === undefined) {
        colaRef.current.push(l)
        return
      }
      creandoRef.current = true
    }
    mutarEscaneo({ ...l, destino })
  }, [mutarEscaneo])
  const onCodigo = useCallback(
    (codigo: string, metodo: MetodoCaptura) => {
      if (inputRef.current) inputRef.current.value = ''
      if (consulta) return
      leer({ codigo, metodo, cantidad: cantidadRef.current }, destinoActual())
    },
    [destinoActual, consulta, leer],
  )
  const scanner = useScanner(onCodigo)

  const v = venta.data
  const abierta = v?.estado === 'abierta'
  const credito = v?.forma_pago === 'credito'
  // S-18: el precio solo se cambia en crédito abierto y en ítems vigentes. Si la venta se cierra
  // o el ítem se reversa mientras se edita, la edición deja de contar.
  const precioEditable = (i: VentaItem) => !consulta && credito && abierta && !i.reverso_id
  const precioEnEdicion = editandoPrecio !== null && (v?.items.some((i) => i.id === editandoPrecio && precioEditable(i)) ?? false)

  // El input del lector recupera el foco siempre que no haya un diálogo abierto.
  // Editar un precio cuenta como diálogo: el lector no le roba el foco al campo.
  const hayDialogo = respaldo || reverso !== null || eligiendoCliente || precioEnEdicion || eligiendoRef !== null || duplicada !== null || confirmandoCierre
  useEffect(() => {
    if (hayDialogo) return
    // S-41: si un diálogo pidió devolver el foco a su botón (p. ej. "Volver" del cierre), va ahí.
    const destino = focoAlCerrarDialogo.current
    focoAlCerrarDialogo.current = null
    if (destino?.isConnected && !(destino as HTMLButtonElement).disabled) destino.focus()
    else inputRef.current?.focus()
  }, [hayDialogo, ventaId, abierta, escanear.isPending])
  // Al pulsar un botón que abre un diálogo, el blur llega antes que el clic: el timeout debe leer
  // si hay diálogo cuando corre, no cuando se pintó el input, o le roba el foco al diálogo.
  const hayDialogoRef = useRef(hayDialogo)
  hayDialogoRef.current = hayDialogo
  const reenfocar = () => setTimeout(() => {
    // El stepper de cantidad y la barra de ventas también se usan con teclado: no se les roba el foco.
    const activo = document.activeElement
    if (activo?.closest('.campo-cantidad, .ventas-abiertas')) return
    if (!hayDialogoRef.current) inputRef.current?.focus()
  }, 0)

  const vigentes = useMemo(() => v?.items.filter((i) => !i.reverso_id) ?? [], [v?.items])
  // Solo cierra la edición de ese ítem: si ya se abrió la de otro, la deja abierta.
  const dejarDeEditar = useCallback((id: number) => setEditandoPrecio((actual) => (actual === id ? null : actual)), [])
  // S-40: callbacks estables para que, con 200 ítems, un escaneo solo pinte la fila nueva (React.memo).
  const pedirReverso = useCallback((item: VentaItem, tipo: ReversoPendiente['tipo']) => setReverso({ item, tipo }), [])
  const precioGuardado = useCallback((nueva: TVenta, item: VentaItem, precio: number) => {
    qc.setQueryData(['venta', nueva.id], nueva)
    dejarDeEditar(item.id)
    avisos.mostrar('ok', `Precio de ${item.unidad.codigo_barras} cambiado a $${formatoNumero(precio)}.`)
  }, [qc, dejarDeEditar, avisos])

  // S-41 / S-46: el aviso de venta vieja solo cuando tiene ítems vigentes (una vacía no apura nada).
  const vieja = abierta && v ? esDeDiaAnterior(v) && vigentes.length > 0 : false
  // S-46: una venta descartada (sin ítems vigentes) no se retoma: el mostrador vuelve al borrador, sin avisos.
  const descartada = v?.estado === 'descartada'
  useEffect(() => {
    if (!consulta && descartada && pedida === null) {
      setVentaId(null)
      qc.invalidateQueries({ queryKey: CLAVE_ABIERTAS })
    }
  }, [consulta, descartada, pedida, qc])
  /** S-46: el mostrador está en un borrador (la venta aún no existe). */
  const enBorrador = !consulta && ventaId === null

  // S-43: sin una venta pedida el admin no tiene nada que hacer aquí: va a su inicio (Cartera).
  if (consulta && pedida === null) return <Navigate to={rutaInicio('admin')} replace />
  // "Volver" regresa al origen (la cartera del cliente); si se entró directo, a Cartera.
  const volver = () => { if (window.history.state?.idx > 0) navegar(-1); else navegar(RUTA_CARTERA) }

  return (
    <div>
      <div className="pagina-encabezado">
        <h1>{consulta ? 'Consulta de venta' : 'Venta de mostrador'}</h1>
        {consulta
          ? (
            <p className="aviso-consulta" role="note">
              Modo consulta (administrador): puede revisar la venta y reversarla con motivo; no se escanea, cierra ni cambia precios.
              {' '}<Link to={RUTA_CARTERA} onClick={(e) => { e.preventDefault(); volver() }}>Volver</Link>
            </p>
          )
          : <p>Cada lectura descuenta la unidad al momento. Puede escanear durante el día y cerrar al final. La venta no se borra: se reversa.</p>}
      </div>
      <div className={consulta ? 'venta venta-consulta' : 'venta'}>
      <section className="panel">
        <div className="fila-titulo">
          <div className="venta-titulo">
            <h2>{v ? `Venta #${v.id}` : enBorrador ? 'Nueva venta' : 'Sin venta'}</h2>
            {v && <p className="venta-subtitulo">{v.estado} · {credito ? 'Crédito' : 'Contado'} · {formatearFecha(v.created_at)}</p>}
            {enBorrador && <p className="venta-subtitulo">{borrador.forma_pago === 'credito' ? 'Crédito' : 'Contado'} · se crea con el primer escaneo</p>}
          </div>
          <div className="acciones">
            {!consulta && (
              <>
                <button type="button" className="secundario boton-44" onClick={nuevaContado}>Nueva venta</button>
                <button type="button" className="secundario boton-44" onClick={() => setEligiendoCliente(true)}>Nueva venta a crédito</button>
                {abierta && <button type="button" className="secundario boton-44" onClick={() => setRespaldo(true)}>Buscar sin escáner</button>}
              </>
            )}
            {v && !abierta && vigentes.length > 0 && (
              <button type="button" className="peligro boton-44" onClick={() => setReverso({ tipo: 'anulacion' })}>Anular venta completa</button>
            )}
          </div>
        </div>

        {!consulta && listaAbiertas.length > 0 && (
          <BarraVentasAbiertas
            ventas={listaAbiertas}
            activa={ventaId === null ? null : (v?.id ?? null)}
            borrador={enBorrador ? borrador : null}
            conUsuario={usuario?.rol === 'admin'}
            onElegir={cambiarA}
          />
        )}

        {vieja && v && (
          <div className="aviso atencion" role="status">
            Esta venta se abrió el {formatearFecha(v.created_at)} y sigue abierta. Ciérrala cuando termines de descargar
            {credito ? '; el vencimiento se cuenta desde el cierre.' : '.'}
          </div>
        )}

        {enBorrador && borrador.forma_pago === 'credito' && (
          <p className="venta-credito">
            Cliente: <strong>{borrador.credito.cliente.nombre}</strong>
            {borrador.credito.cliente.documento && <> · {borrador.credito.cliente.documento}</>}
            {borrador.credito.factura_contapyme && <> · Factura Contapyme {borrador.credito.factura_contapyme}</>}
          </p>
        )}

        {v && descartada && (
          <p className="venta-credito">Descartada el {formatearFecha(v.descartada_at ?? v.created_at)} (no tenía ítems vigentes).</p>
        )}

        {v && credito && (
          <p className="venta-credito">
            Cliente: <strong>{v.cliente?.nombre ?? '—'}</strong>
            {v.cliente?.documento && <> · {v.cliente.documento}</>}
            {v.factura_contapyme && <> · Factura Contapyme {v.factura_contapyme}</>}
            {v.estado === 'cerrada' && v.vence_el && <> · <strong>Vence el {fechaCorta(v.vence_el)}</strong></>}
          </p>
        )}

        {!consulta && (
        <div className="lector-con-cantidad venta-lector">
          <div className="lector-campo">
            <input
              ref={inputRef}
              className="escaner"
              placeholder={abierta ? (angosta ? 'Escanee o digite el código' : 'Escanee la etiqueta o el código del proveedor') : (angosta ? 'Escanee para empezar' : 'Escanee para empezar la venta')}
              aria-label="Etiqueta del local o código del proveedor"
              onKeyDown={scanner.onKeyDown}
              onPaste={scanner.onPaste}
              onBlur={reenfocar}
              autoComplete="off"
            />
          </div>
          <div className="venta-cantidad">
            <CampoCantidad
              id="venta-cantidad"
              etiqueta="Cantidad"
              valor={cantidad}
              max={MAX_CANTIDAD}
              onCambio={setCantidad}
              compacto
              ayuda="Con el código del proveedor. Cada etiqueta es una unidad."
            />
          </div>
        </div>
        )}
        {consulta && venta.isError && <p className="aviso error" role="alert">{mensajeError(venta.error)}</p>}
        {enBorrador && <p className="venta-borrador vacio">Escanee el primer producto: la venta se crea con él.</p>}
        {v && (
          <div className="venta-cuerpo">
            {/* La tabla se desplaza por dentro: la página nunca se desborda. */}
            <div className="tabla-venta-scroll">
            <table className="tabla-apilable tabla-venta">
              <thead>
                <tr><th>Código</th><th>Referencia</th><th className="col-numero" title="Precio con IVA incluido">Precio (IVA incl.)</th><th><span className="solo-lector">Acciones</span></th></tr>
              </thead>
              <tbody>
                {v.items.map((i) => (
                  <FilaItem
                    key={i.id}
                    item={i}
                    ventaId={v.id}
                    abierta={abierta}
                    editable={precioEditable(i)}
                    editando={editandoPrecio === i.id}
                    onEditar={setEditandoPrecio}
                    onTerminar={dejarDeEditar}
                    onGuardado={precioGuardado}
                    onReverso={pedirReverso}
                  />
                ))}
                {v.items.length === 0 && <tr><td colSpan={4} className="vacio">Sin ítems todavía.</td></tr>}
              </tbody>
            </table>
            </div>

            {/* S-41: barra delgada con ítems y total; el desglose base / IVA va en el modal de cierre. */}
            <div className="barra-total" aria-live="polite">
              <span>{textoUnidades(vigentes)} · Total <strong>{pesos(v.totales?.total ?? 0)}</strong> (IVA incl.)</span>
              {abierta && !consulta && (
                <button
                  ref={botonCerrarRef}
                  type="button"
                  className="primario boton-44"
                  onClick={() => setConfirmandoCierre(true)}
                  disabled={cerrar.isPending || vigentes.length === 0}
                  title={vigentes.length === 0 ? 'La venta no tiene ítems' : undefined}
                >Cerrar venta</button>
              )}
            </div>
          </div>
        )}

        {v && v.reversos.length > 0 && (
          <>
            <h3>Reversos</h3>
            <ul className="reversos">
              {v.reversos.map((r) => (
                <li key={r.id}>
                  <strong>{r.tipo}</strong> de {r.venta_item_ids.length} ítem(s) · {r.usuario.name} · {formatearFechaHora(r.created_at)} — “{r.motivo}”
                </li>
              ))}
            </ul>
          </>
        )}
      </section>

      {!consulta && <VentasRecientes ventas={recientes.data?.data} onElegir={setVentaId} />}
      </div>

      {respaldo && (
        <BusquedaRespaldo
          onElegir={(codigo) => {
            setRespaldo(false)
            leer({ codigo, metodo: 'digitado', cantidad: 1 }, destinoActual())
          }}
          onCerrar={() => setRespaldo(false)}
        />
      )}

      {eligiendoRef && (
        <DialogoElegirReferencia
          codigo={eligiendoRef.codigo}
          referencias={eligiendoRef.referencias}
          onElegir={(referenciaId) => {
            const { codigo, metodo, cantidad: n, destino } = eligiendoRef
            setEligiendoRef(null)
            leer({ codigo, metodo, referenciaId, cantidad: n }, destino)
          }}
          onCerrar={() => { setEligiendoRef(null); colaRef.current = [] }}
        />
      )}

      {eligiendoCliente && (
        <DialogoCredito
          onConfirmar={nuevaCredito}
          onCerrar={() => setEligiendoCliente(false)}
        />
      )}

      {duplicada && (
        <Dialogo
          titulo={duplicada.existente.forma_pago === 'credito' ? 'Ya hay una venta a crédito abierta de este cliente' : 'Ya tienes una venta de contado abierta'}
          variante="confirmar"
          onCerrar={() => setDuplicada(null)}
          pie={(
            <>
              <button type="button" className="secundario boton-44" onClick={() => { const otra = duplicada.abrirOtra; setDuplicada(null); otra() }}>Empezar otra</button>
              <button type="button" className="primario boton-44" autoFocus onClick={() => { cambiarA(duplicada.existente.id); setDuplicada(null) }}>Continuar con esa</button>
            </>
          )}
        >
          <DialogoSeccion>
            <p>
              {duplicada.existente.forma_pago === 'credito' ? `${duplicada.existente.cliente?.nombre ?? 'El cliente'} ya tiene` : 'Ya tienes'} una venta {duplicada.existente.forma_pago === 'credito' ? 'a crédito' : 'de contado'} abierta
              {' '}(#{duplicada.existente.id}, {duplicada.existente.items_count} {duplicada.existente.items_count === 1 ? 'ítem' : 'ítems'}, {pesos(duplicada.existente.total)}). ¿Continuar con ella o empezar otra?
            </p>
          </DialogoSeccion>
        </Dialogo>
      )}

      {confirmandoCierre && v && (
        <DialogoCerrarVenta
          venta={v}
          vieja={esDeDiaAnterior(v)}
          onVentaActualizada={(nueva) => qc.setQueryData(['venta', nueva.id], nueva)}
          pendiente={cerrar.isPending}
          onCerrarVenta={(pagos) => cerrar.mutate(pagos)}
          onSeguir={() => { focoAlCerrarDialogo.current = botonCerrarRef.current; setConfirmandoCierre(false) }}
        />
      )}

      {reverso && v && (
        <DialogoReverso
          ventaId={v.id}
          pendiente={reverso}
          onListo={(nueva) => {
            qc.setQueryData(['venta', nueva.id], nueva)
            qc.invalidateQueries({ queryKey: CLAVE_ABIERTAS })
            setReverso(null)
            avisos.mostrar('ok', 'Reverso registrado; la unidad volvió al inventario.')
          }}
          onCerrar={() => setReverso(null)}
        />
      )}
    </div>
  )
}

/** Fila de "Ventas cerradas recientes" (GET /ventas?estado=cerrada). */
interface VentaReciente {
  id: number
  created_at: string
  items_count: number
  total: string | number
  forma_pago: TVenta['forma_pago']
  cliente: { id: number; nombre: string } | null
}

/**
 * Ventas cerradas recientes, al lado. Memoizada: un escaneo vuelve a pintar la venta 2 o 3 veces y
 * esta lista no cambia (sus props son los datos en caché y setVentaId, ambos estables).
 */
const VentasRecientes = memo(function VentasRecientes({ ventas, onElegir }: {
  ventas: VentaReciente[] | undefined
  onElegir: (id: number) => void
}) {
  return (
    <aside className="panel lateral">
      <h3>Ventas cerradas recientes</h3>
      <ul className="lista lista-recientes">
        {ventas?.map((r) => (
          <li key={r.id}>
            <span className="reciente-linea">
              <button className="enlace" onClick={() => onElegir(r.id)}>#{r.id}</button>
              {r.forma_pago === 'credito' ? <span className="chip credito">Crédito</span> : <span className="chip">Contado</span>}
              {r.forma_pago === 'credito' && <span className="reciente-cliente">{r.cliente?.nombre}</span>}
            </span>
            <small className="reciente-detalle">
              {r.items_count} {r.items_count === 1 ? 'ítem' : 'ítems'} · <span title="Total con IVA incluido">{pesos(r.total)}</span> · {formatearFechaHora(r.created_at)}
            </small>
          </li>
        ))}
      </ul>
    </aside>
  )
})

/** S-40: máximo de fichas de venta visibles en la barra (las demás, en "Ver todas"). */
const MAX_FICHAS = 5

/** "#6 · Contado · 0 ítems" (select angosto y listado). */
const textoOpcion = (v: VentaAbierta) =>
  `#${v.id} · ${v.forma_pago === 'credito' ? `Crédito · ${v.cliente?.nombre ?? '—'}` : 'Contado'} · ${v.items_count} ${v.items_count === 1 ? 'ítem' : 'ítems'}`

/**
 * S-40: barra compacta de ventas abiertas. Hasta 5 fichas: la activa primero y luego las que tienen
 * ítems, de la más reciente a la más antigua. Las vacías (salvo la activa) van en una sola ficha
 * "Vacías (N)" y "Ver todas (N)" abre el listado con búsqueda. A <= 480 px es un select. Flechas,
 * Inicio y Fin recorren las fichas; al elegir, el foco vuelve al lector (lo hace onElegir).
 * Una venta de un día anterior CON ítems vigentes lleva un punto de aviso con la fecha en el title.
 * S-46: el borrador en uso aparece como ficha activa "Nueva · …"; "Vacías (N)" queda como defensa
 * (las ventas ya nacen con su primer ítem y las vacías se descartan solas).
 * Memoizada: solo se vuelve a pintar cuando cambian las ventas abiertas o la activa, no en cada
 * render del escaneo (onElegir es estable).
 */
const BarraVentasAbiertas = memo(function BarraVentasAbiertas({ ventas, activa, borrador, conUsuario, onElegir }: {
  ventas: VentaAbierta[]
  activa: number | null
  borrador: Borrador | null
  conUsuario: boolean
  onElegir: (id: number) => void
}) {
  const muyAngosta = useAngosta(480)
  const botones = useRef<Array<HTMLButtonElement | null>>([])
  const [listado, setListado] = useState<'todas' | 'vacias' | null>(null)

  const actual = ventas.find((v) => v.id === activa)
  const conItems = ventas.filter((v) => v.id !== activa && v.items_count > 0).sort((a, b) => b.id - a.id)
  const vacias = ventas.filter((v) => v.id !== activa && v.items_count === 0)
  const espacio = MAX_FICHAS - (actual || borrador ? 1 : 0) - (vacias.length > 0 ? 1 : 0)
  const visibles = [...(actual ? [actual] : []), ...conItems.slice(0, Math.max(espacio, 0))]
  const conBorrador = borrador ? 1 : 0

  if (muyAngosta) {
    return (
      <label className="ventas-abiertas-select">Venta abierta
        <select value={activa ?? ''} onChange={(e) => onElegir(Number(e.target.value))}>
          {activa === null && <option value="">{borrador ? textoBorrador(borrador) : 'Elija una venta'}</option>}
          {[...ventas].sort((a, b) => b.id - a.id).map((v) => <option key={v.id} value={v.id}>{textoOpcion(v)}</option>)}
        </select>
      </label>
    )
  }

  const total = conBorrador + visibles.length + (vacias.length > 0 ? 1 : 0) + 1
  const mover = (e: KeyboardEvent<HTMLButtonElement>, i: number) => {
    const destino = { ArrowRight: i + 1, ArrowDown: i + 1, ArrowLeft: i - 1, ArrowUp: i - 1, Home: 0, End: total - 1 }[e.key]
    if (destino === undefined) return
    e.preventDefault()
    botones.current[(destino + total) % total]?.focus()
  }
  const indiceActiva = 0
  const propsBoton = (i: number) => ({
    ref: (b: HTMLButtonElement | null) => { botones.current[i] = b },
    tabIndex: i === indiceActiva ? 0 : -1,
    onKeyDown: (e: KeyboardEvent<HTMLButtonElement>) => mover(e, i),
  })

  return (
    <div className="ventas-abiertas" role="group" aria-label={`Ventas abiertas (${ventas.length})`}>
      <span className="ventas-abiertas-titulo">Ventas abiertas</span>
      <div className="ventas-abiertas-fichas">
        {borrador && (
          <button type="button" className="ficha-venta activa ficha-borrador" aria-pressed {...propsBoton(0)}>
            <span className="ficha-venta-linea">
              <strong>Nueva</strong>
              <span className={borrador.forma_pago === 'credito' ? 'chip credito' : 'chip'}>{borrador.forma_pago === 'credito' ? 'Crédito' : 'Contado'}</span>
            </span>
            <span className="ficha-venta-linea ficha-venta-detalle">
              <span className="ficha-venta-cliente">{borrador.forma_pago === 'credito' ? borrador.credito.cliente.nombre : 'Mostrador'}</span>
              <span className="ficha-venta-cifras">Sin ítems</span>
            </span>
          </button>
        )}
        {visibles.map((v, i) => (
          <button
            key={v.id}
            type="button"
            className={v.id === activa ? 'ficha-venta activa' : 'ficha-venta'}
            aria-pressed={v.id === activa}
            onClick={() => onElegir(v.id)}
            {...propsBoton(i + conBorrador)}
          >
            <span className="ficha-venta-linea">
              <strong>
                #{v.id}
                {esDeDiaAnterior(v) && v.items_count > 0 && <span className="punto-alerta" title={`Abierta el ${formatearFecha(v.created_at)}`}><span className="solo-lector">, abierta el {formatearFecha(v.created_at)}</span></span>}
              </strong>
              <span className={v.forma_pago === 'credito' ? 'chip credito' : 'chip'}>{v.forma_pago === 'credito' ? 'Crédito' : 'Contado'}</span>
            </span>
            <span className="ficha-venta-linea ficha-venta-detalle">
              <span className="ficha-venta-cliente">{v.forma_pago === 'credito' ? (v.cliente?.nombre ?? '—') : 'Mostrador'}{conUsuario ? ` · ${v.usuario?.name}` : ''}</span>
              <span className="ficha-venta-cifras">{v.items_count} · <strong>{pesos(v.total)}</strong></span>
            </span>
          </button>
        ))}
        {vacias.length > 0 && (
          <button type="button" className="ficha-venta ficha-resumen" onClick={() => setListado('vacias')} {...propsBoton(conBorrador + visibles.length)}>
            <strong>Vacías ({vacias.length})</strong>
            <small>Sin ítems todavía</small>
          </button>
        )}
        <button type="button" className="enlace boton-44 ver-todas" onClick={() => setListado('todas')} title="Buscar por número o cliente" {...propsBoton(total - 1)}>
          Ver todas ({ventas.length})
        </button>
      </div>

      {listado && (
        <ListadoVentasAbiertas
          ventas={listado === 'vacias' ? vacias : ventas}
          titulo={listado === 'vacias' ? `Ventas abiertas sin ítems (${vacias.length})` : `Ventas abiertas (${ventas.length})`}
          activa={activa}
          conUsuario={conUsuario}
          onElegir={(id) => { setListado(null); onElegir(id) }}
          onCerrar={() => setListado(null)}
        />
      )}
    </div>
  )
})

/** S-40: todas las ventas abiertas, con búsqueda por número o cliente, para cambiar de venta. */
function ListadoVentasAbiertas({ ventas, titulo, activa, conUsuario, onElegir, onCerrar }: {
  ventas: VentaAbierta[]
  titulo: string
  activa: number | null
  conUsuario: boolean
  onElegir: (id: number) => void
  onCerrar: () => void
}) {
  const [q, setQ] = useState('')
  const t = q.trim().replace(/^#\s*/, '').toLowerCase()
  const filas: FilaEleccion[] = [...ventas]
    .sort((a, b) => b.id - a.id)
    .filter((v) => t === '' || String(v.id).startsWith(t) || (v.cliente?.nombre ?? 'mostrador').toLowerCase().includes(t))
    .map((v) => ({
      id: v.id,
      titulo: `#${v.id}${v.id === activa ? ' (actual)' : ''}`,
      meta: [v.forma_pago === 'credito' ? `Crédito · ${v.cliente?.nombre ?? '—'}` : 'Contado', `${v.items_count} ${v.items_count === 1 ? 'ítem' : 'ítems'}`, pesos(v.total), conUsuario ? v.usuario?.name : null].filter(Boolean).join(' · '),
      detalle: `Abierta el ${formatearFechaHora(v.created_at)}`,
      onElegir: () => onElegir(v.id),
    }))

  return (
    <Dialogo
      titulo={titulo}
      descripcion="Elija la venta para seguir escaneando."
      variante="ancha"
      onCerrar={onCerrar}
      pie={<button type="button" className="secundario boton-44" onClick={onCerrar}>Cerrar</button>}
    >
      <DialogoSeccion>
        <input autoFocus placeholder="N.º de venta o cliente" aria-label="Buscar venta por número o cliente" value={q} onChange={(e) => setQ(e.target.value)} />
        <ListaEleccion filas={filas} pendiente={false} vacio="Ninguna venta abierta coincide." />
      </DialogoSeccion>
    </Dialogo>
  )
}

/**
 * S-40: una fila de la tabla. Memoizada: con 200 ítems, escanear o quitar solo vuelve a pintar las
 * filas que cambian (los callbacks que recibe son estables).
 */
const FilaItem = memo(function FilaItem({ item: i, ventaId, abierta, editable, editando, onEditar, onTerminar, onGuardado, onReverso }: {
  item: VentaItem
  ventaId: number
  abierta: boolean
  editable: boolean
  editando: boolean
  onEditar: (id: number) => void
  onTerminar: (id: number) => void
  onGuardado: (nueva: TVenta, item: VentaItem, precio: number) => void
  onReverso: (item: VentaItem, tipo: ReversoPendiente['tipo']) => void
}) {
  return (
    <tr className={i.reverso_id ? 'reversado' : ''}>
      <td data-etiqueta="Código">
        <span className="mono codigo-unidad">{i.unidad.codigo_barras}</span>
        {i.origen_codigo === 'referencia' && <> <span className="chip por-referencia" title="Se vendió con el código del proveedor; el sistema eligió esta unidad">por referencia</span></>}
      </td>
      <td data-etiqueta="Referencia">
        <span className="celda-referencia-venta">
          <span><strong>{i.unidad.referencia?.codigo_referencia}</strong> <small>{i.unidad.referencia?.descripcion}</small></span>
          <small className="fila-detalle-venta">
            {i.unidad.referencia?.proveedor?.nombre} <ChipCalidad calidad={i.unidad.referencia?.calidad} />
            {' · '}<span className={`chip ${i.metodo_captura}`}>{i.metodo_captura}</span>
            {' · '}{i.usuario.name} · {formatearHora(i.created_at)}
          </small>
        </span>
      </td>
      <td data-etiqueta="Precio" className="col-numero">
        {editable && editando ? (
          <CeldaPrecio
            ventaId={ventaId}
            item={i}
            onGuardado={(nueva, precio) => onGuardado(nueva, i, precio)}
            onTerminar={() => onTerminar(i.id)}
          />
        ) : editable ? (
          <button type="button" className="enlace" title="Cambiar el precio de este ítem" onClick={() => onEditar(i.id)}>
            {i.precio_unitario === null ? '—' : formatoNumero(i.precio_unitario)}
          </button>
        ) : (
          i.precio_unitario === null ? '—' : formatoNumero(i.precio_unitario)
        )}
        {i.iva_porcentaje !== null && <> <small>{Number(i.iva_porcentaje) === 0 ? 'sin IVA' : `IVA ${Number(i.iva_porcentaje)} %`}</small></>}
      </td>
      <td className="celda-accion">
        {i.reverso_id ? <em>reversado</em> : (
          <button className="enlace" onClick={() => onReverso(i, abierta ? 'anulacion' : 'devolucion')}>
            {abierta ? 'Quitar' : 'Devolver'}
          </button>
        )}
      </td>
    </tr>
  )
})

/** S-42: "N unidades · M referencias" (cada etiqueta es una unidad). */
function textoUnidades(items: VentaItem[], union = ' · '): string {
  const n = items.length
  const m = new Set(items.map((i) => i.unidad.referencia?.id ?? i.unidad.referencia_id)).size
  return `${n} ${n === 1 ? 'unidad' : 'unidades'}${union}${m} ${m === 1 ? 'referencia' : 'referencias'}`
}

/** S-41: ítems vigentes agrupados por referencia y precio para el resumen del cierre. */
interface GrupoCierre { clave: string; item: VentaItem; ids: number[]; precio: number }

function agruparParaCierre(items: VentaItem[]): GrupoCierre[] {
  const grupos = new Map<string, GrupoCierre>()
  for (const i of items) {
    if (i.reverso_id) continue // los reversados quedan auditados en la venta; no se cobran
    const precio = Number(i.precio_unitario ?? 0)
    const clave = `${i.unidad.referencia?.id ?? i.unidad.referencia_id}-${precio}`
    const g = grupos.get(clave)
    if (g) g.ids.push(i.id)
    else grupos.set(clave, { clave, item: i, ids: [i.id], precio })
  }
  return [...grupos.values()]
}

/**
 * S-42: precio con IVA incluido de un grupo, editable en crédito abierto. Guarda al salir del campo o
 * con Enter (todas las unidades del grupo a la vez); con error vuelve al valor anterior y lo dice.
 */
function PrecioGrupo({ ventaId, grupo, onGuardado, onEstado }: {
  ventaId: number
  grupo: GrupoCierre
  onGuardado: (v: TVenta) => void
  /** Avisa si hay una edición sin guardar o inválida (bloquea "Confirmar cierre"). */
  onEstado: (clave: string, pendiente: boolean) => void
}) {
  // El precio va en la clave del grupo (key de la fila): si cambia, el campo se monta de nuevo con el
  // valor nuevo. Por eso no hace falta un efecto que copie el precio al texto.
  const [texto, setTexto] = useState(() => formatoNumero(grupo.precio))
  const guardar = useMutation({
    mutationFn: async (precio: number) =>
      (await api.patch<TVenta>(`/ventas/${ventaId}/items/precio`, { ids: grupo.ids, precio_unitario: precio })).data,
    onSuccess: onGuardado,
    onError: () => setTexto(formatoNumero(grupo.precio)),
  })
  const error = errorPrecio(texto)
  const n = leerNumero(texto)
  const cambiado = n !== null && !Number.isNaN(n) && n !== grupo.precio
  const pendiente = guardar.isPending || error !== null || cambiado
  useEffect(() => { onEstado(grupo.clave, pendiente) }, [grupo.clave, pendiente, onEstado])
  useEffect(() => () => onEstado(grupo.clave, false), [grupo.clave, onEstado])

  const enviar = () => { if (!error && cambiado && n !== null && !guardar.isPending) guardar.mutate(n) }

  return (
    <span className="precio-grupo">
      <CampoMoneda
        value={texto}
        aria-label={`Precio con IVA incluido de ${grupo.item.unidad.referencia?.codigo_referencia ?? 'la referencia'} (${grupo.ids.length} ${grupo.ids.length === 1 ? 'unidad' : 'unidades'})`}
        aria-invalid={error ? true : undefined}
        disabled={guardar.isPending}
        onChange={(e) => { setTexto(e.target.value); guardar.reset() }}
        onBlur={enviar}
        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); enviar() } }}
      />
      {guardar.isPending && <small className="ayuda">Guardando…</small>}
      {error && <small className="ayuda error">{error}</small>}
      {guardar.isError && <small className="ayuda error" role="alert">{mensajeError(guardar.error)}</small>}
    </span>
  )
}

/**
 * S-40 / S-41: confirmación del cierre en un modal centrado: todos los ítems vigentes agrupados por
 * referencia y precio (cantidad, precio con IVA incluido, subtotal) y, en el pie, base, IVA incluido
 * y total (los calcula el backend, S-33). En crédito, el vencimiento que se fijará (hoy + plazo, S-11).
 * "Volver" o Esc no cierran la venta; mientras se cierra, el modal no se puede cerrar.
 * S-42: en crédito abierto el precio de cada grupo se corrige aquí (todas sus unidades, auditado);
 * en contado es de solo lectura. "Confirmar cierre" espera a que no quede una edición pendiente.
 * RF-24 (S-48): en contado, "Forma de pago" (cuentas y valores, pago dividido); el botón se habilita
 * solo cuando los pagos suman exactamente el total. En crédito la sección no aparece.
 */
function DialogoCerrarVenta({ venta: v, vieja, pendiente, onCerrarVenta, onSeguir, onVentaActualizada }: {
  venta: TVenta
  vieja: boolean
  pendiente: boolean
  onCerrarVenta: (pagos?: PagoVentaNuevo[]) => void
  onSeguir: () => void
  onVentaActualizada: (v: TVenta) => void
}) {
  const credito = v.forma_pago === 'credito'
  const editable = credito && v.estado === 'abierta'
  const vence = credito && v.cliente ? sumarDias(hoyEnBogota(), v.cliente.plazo_dias) : null
  const grupos = useMemo(() => agruparParaCierre(v.items), [v.items])
  const vigentes = useMemo(() => v.items.filter((i) => !i.reverso_id), [v.items])
  const t = v.totales
  const [ediciones, setEdiciones] = useState<Record<string, boolean>>({})
  const marcar = useCallback((clave: string, pend: boolean) => {
    setEdiciones((e) => (Boolean(e[clave]) === pend ? e : { ...e, [clave]: pend }))
  }, [])
  const hayEdicion = Object.values(ediciones).some(Boolean)

  const cuentas = useCuentasPago()
  const totalVenta = t?.total ?? '0'
  const [filas, setFilas] = useState<FilaPago[] | null>(null)
  useEffect(() => {
    if (!credito && filas === null && cuentas.data) setFilas(filasIniciales(cuentas.data, totalVenta))
  }, [credito, filas, cuentas.data, totalVenta])
  const sinValor = Number(totalVenta) <= 0
  const pago = filas ? evaluarPago(filas, totalVenta) : null
  const pagoListo = credito || sinValor || (pago?.listo ?? false)

  return (
    <Dialogo
      titulo={`Cerrar venta #${v.id}`}
      descripcion={(
        <p className="cierre-subtitulo">
          {credito ? 'Crédito' : 'Contado'} · abierta el {formatearFecha(v.created_at)}
          {credito && <> · {v.cliente?.nombre ?? '—'}{v.cliente?.documento ? ` (${v.cliente.documento})` : ''}</>}
        </p>
      )}
      variante="cierre"
      onCerrar={onSeguir}
      pendiente={pendiente}
      pie={(
        <div className="cierre-pie">
          <div className="cierre-resumen">
            <span className="cierre-unidades">{textoUnidades(vigentes, ' de ')}</span>
            <dl className="cierre-totales">
              <div><dt>Base (sin IVA)</dt><dd>{pesos(t?.base ?? 0)}</dd></div>
              <div><dt>{rotuloIvaIncluido(t?.iva_porcentajes ?? [])}</dt><dd>{pesos(t?.iva_incluido ?? 0)}</dd></div>
              <div className="cierre-total"><dt>Total</dt><dd>{pesos(t?.total ?? 0)}</dd></div>
            </dl>
          </div>
          <div className="cierre-botones">
            <button type="button" className="secundario boton-44" disabled={pendiente} onClick={onSeguir}>Volver</button>
            <button
              type="button"
              className="primario boton-44"
              autoFocus
              disabled={pendiente || hayEdicion || !pagoListo}
              title={hayEdicion ? 'Termine de corregir el precio (Enter o salir del campo) antes de cerrar' : !pagoListo ? 'Los pagos deben sumar exactamente el total' : undefined}
              onClick={() => onCerrarVenta(credito || sinValor || !filas ? undefined : aPagos(filas))}
            >{pendiente ? 'Cerrando…' : 'Confirmar cierre'}</button>
          </div>
        </div>
      )}
    >
      <section className="modal-seccion cierre-cuerpo" aria-label="Ítems de la venta">
        {vieja && <p className="aviso atencion">Esta venta se abrió el {formatearFecha(v.created_at)}.{credito ? ' El vencimiento se cuenta desde el cierre.' : ''}</p>}
        {vence && <p className="ayuda">Vencerá el <strong>{fechaCorta(vence)}</strong> ({v.cliente?.plazo_dias} días).</p>}
        {editable && <p className="ayuda">Puede corregir el precio de cada grupo (todas sus unidades); queda en la auditoría.</p>}
        <table className="tabla-cierre">
          <caption className="solo-lector">Ítems vigentes agrupados por referencia y precio</caption>
          <thead><tr><th>Referencia</th><th className="col-numero">Cantidad</th><th className="col-numero">Precio unitario (IVA incl.)</th><th className="col-numero">Subtotal</th></tr></thead>
          <tbody>
            {grupos.map((g) => (
              <tr key={g.clave}>
                <td data-etiqueta="Referencia">
                  <span>
                    <strong>{g.item.unidad.referencia?.codigo_referencia}</strong> <small>{g.item.unidad.referencia?.descripcion}</small>
                    <small className="bloque">{g.item.unidad.referencia?.proveedor?.nombre} <ChipCalidad calidad={g.item.unidad.referencia?.calidad} /></small>
                  </span>
                </td>
                <td data-etiqueta="Cantidad" className="col-numero">{g.ids.length}</td>
                <td data-etiqueta="Precio" className="col-numero">
                  {editable
                    ? <PrecioGrupo ventaId={v.id} grupo={g} onGuardado={onVentaActualizada} onEstado={marcar} />
                    : pesos(g.precio)}
                </td>
                <td data-etiqueta="Subtotal" className="col-numero"><strong>{pesos(g.precio * g.ids.length)}</strong></td>
              </tr>
            ))}
          </tbody>
        </table>
        {!credito && !sinValor && (
          cuentas.isError ? <p className="aviso error">No se pudieron cargar las cuentas de pago: {mensajeError(cuentas.error)}</p>
            : filas && cuentas.data
              ? <FormaPagoVenta cuentas={cuentas.data} total={totalVenta} filas={filas} deshabilitado={pendiente} onCambio={setFilas} />
              : <p className="ayuda">Cargando las cuentas de pago…</p>
        )}
        <p className="ayuda">Al cerrar, los precios quedan fijos y la venta no se puede editar; solo reversar.</p>
      </section>
    </Dialogo>
  )
}

/**
 * RF-12: buscar por código de barras, referencia, código universal o descripción y elegir la unidad.
 * El orden lo da el backend (código antes que descripción, con existencia primero); aquí no se reordena.
 */
function BusquedaRespaldo({ onElegir, onCerrar }: { onElegir: (codigo: string) => void; onCerrar: () => void }) {
  // Es el tipo Unidad de api/tipos; se deriva aquí para no tocar los imports del archivo.
  type Unidad = NonNullable<Referencia['unidades_disponibles']>[number]
  const [q, setQ] = useState('')
  const termino = useDebounce(q.trim())

  const resultado = useQuery({
    queryKey: ['buscar', termino],
    queryFn: async () => (await api.get<{ unidad: Unidad | null; referencias: Referencia[] }>('/buscar', { params: { q: termino } })).data,
    enabled: termino.length >= 2,
  })

  const filas: FilaEleccion[] = []
  const unidad = resultado.data?.unidad
  if (unidad) {
    filas.push({
      id: `unidad-${unidad.id}`,
      titulo: unidad.codigo_barras,
      meta: [unidad.referencia?.codigo_referencia, unidad.referencia?.descripcion].filter(Boolean).join(' · '),
      detalle: unidad.estado === 'disponible' ? 'Unidad leída' : 'Esta unidad ya no está disponible',
      onElegir: unidad.estado === 'disponible' ? () => onElegir(unidad.codigo_barras) : undefined,
    })
  }
  for (const r of resultado.data?.referencias ?? []) {
    const disponibles = r.unidades_disponibles ?? []
    const meta = [r.codigo_universal?.codigo, r.calidad && NOMBRE_CALIDAD[r.calidad], r.proveedor?.nombre, `existencia ${r.existencia}`].filter(Boolean).join(' · ')
    if (disponibles.length === 1) {
      filas.push({
        id: r.id,
        titulo: r.codigo_referencia,
        meta,
        detalle: r.descripcion,
        agotada: r.existencia <= 0,
        onElegir: () => onElegir(disponibles[0].codigo_barras),
      })
    } else {
      filas.push({
        id: r.id,
        titulo: r.codigo_referencia,
        meta,
        detalle: disponibles.length === 0 ? 'Sin unidades disponibles' : r.descripcion,
        agotada: r.existencia <= 0,
        acciones: disponibles.map((u) => ({ id: String(u.id), texto: u.codigo_barras, mono: true, onElegir: () => onElegir(u.codigo_barras) })),
      })
    }
  }

  return (
    <Dialogo
      titulo="Buscar sin escáner"
      descripcion="Código de barras, referencia, universal o descripción"
      variante="ancha"
      onCerrar={onCerrar}
      pie={<button type="button" className="secundario" onClick={onCerrar}>Cerrar</button>}
    >
      <DialogoSeccion>
        <input autoFocus placeholder="Ej. alternador hilux" aria-label="Buscar unidad" value={q} onChange={(e) => setQ(e.target.value)} />
        <ListaEleccion
          filas={termino.length < 2 ? [] : filas}
          pendiente={resultado.isFetching}
          vacio={termino.length < 2 ? 'Escriba al menos 2 caracteres.' : 'Sin resultados.'}
        />
      </DialogoSeccion>
    </Dialogo>
  )
}

/** RF-07: el motivo es obligatorio. */
function DialogoReverso({ ventaId, pendiente, onListo, onCerrar }: {
  ventaId: number
  pendiente: ReversoPendiente
  onListo: (v: TVenta) => void
  onCerrar: () => void
}) {
  const avisos = useAviso()
  const [motivo, setMotivo] = useState('')
  const reversar = useMutation({
    mutationFn: async () =>
      (await api.post<TVenta>(`/ventas/${ventaId}/reversos`, {
        tipo: pendiente.tipo,
        motivo,
        venta_item_id: pendiente.item?.id ?? null,
      })).data,
    onSuccess: onListo,
    onError: (e) => avisos.mostrar('error', mensajeError(e)),
  })

  const titulo = pendiente.item
    ? `${pendiente.tipo === 'anulacion' ? 'Quitar' : 'Devolver'} ${pendiente.item.unidad.codigo_barras}`
    : 'Anular la venta completa'

  return (
    <Dialogo
      titulo={titulo}
      descripcion="La venta no se borra: queda un reverso con su usuario, fecha y motivo."
      onCerrar={onCerrar}
      pendiente={reversar.isPending}
      onEnviar={() => reversar.mutate()}
      pie={(
        <>
          <button type="button" className="secundario" disabled={reversar.isPending} onClick={onCerrar}>Cancelar</button>
          <button type="submit" className="peligro" disabled={motivo.trim().length < 3 || reversar.isPending}>Confirmar reverso</button>
        </>
      )}
    >
      <DialogoSeccion>
        <label>Motivo (obligatorio)
          <textarea autoFocus required minLength={3} value={motivo} onChange={(e) => setMotivo(e.target.value)} />
        </label>
      </DialogoSeccion>
    </Dialogo>
  )
}

/**
 * RF-14: la venta a crédito exige un cliente activo (RF-13). El mostrador puede crearlo al vuelo;
 * editarlo es del admin. La factura de Contapyme es solo un texto de referencia.
 */
function DialogoCredito({ onConfirmar, onCerrar }: {
  onConfirmar: (datos: DatosCredito) => void
  onCerrar: () => void
}) {
  const qc = useQueryClient()
  const avisos = useAviso()
  const [q, setQ] = useState('')
  const [elegido, setElegido] = useState<Cliente | null>(null)
  const [factura, setFactura] = useState('')
  const [creando, setCreando] = useState(false)
  const [nuevo, setNuevo] = useState({ nombre: '', documento: '', telefono: '' })
  const termino = useDebounce(q.trim())

  const { filas: clientes, resultado, info, irA, cargando } = useListaPaginada<Cliente>({
    clave: ['clientes', 'activos', termino],
    filtros: q,
    traer: async (pagina) => (await api.get<Pagina<Cliente>>('/clientes', {
      params: { q: termino || undefined, activo: 1, page: pagina, por_pagina: CLIENTES_POR_PAGINA },
    })).data,
  })

  const crear = useMutation({
    mutationFn: async () => (await api.post<Cliente>('/clientes', {
      nombre: nuevo.nombre.trim(),
      documento: nuevo.documento.trim() || null,
      telefono: nuevo.telefono.trim() || null,
    })).data,
    onSuccess: (c) => {
      setElegido(c)
      setCreando(false)
      setNuevo({ nombre: '', documento: '', telefono: '' })
      qc.invalidateQueries({ queryKey: ['clientes'] })
    },
    onError: (e) => avisos.mostrar('error', mensajeError(e)),
  })

  const filas: FilaEleccion[] = clientes.map((c) => ({
    id: c.id,
    titulo: c.nombre,
    meta: [c.documento, `plazo ${c.plazo_dias} días`].filter(Boolean).join(' · '),
    onElegir: () => setElegido(c),
  }))

  return (
    <Dialogo
      titulo="Nueva venta a crédito"
      descripcion="La venta se crea con el primer escaneo. La factura de Contapyme es solo una referencia: este sistema no factura."
      variante="ancha"
      onCerrar={onCerrar}
      pie={(
        <>
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button
            type="button"
            className="primario"
            disabled={!elegido}
            onClick={() => elegido && onConfirmar({
              cliente_id: elegido.id,
              factura_contapyme: factura.trim() || null,
              cliente: { id: elegido.id, nombre: elegido.nombre, documento: elegido.documento ?? null },
            })}
          >
            Empezar venta a crédito
          </button>
        </>
      )}
    >
      <DialogoSeccion titulo={creando ? 'Crear cliente rápido' : 'Cliente'}>
        {creando ? (
          <form className="formulario" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
            <label>Nombre<input autoFocus required maxLength={150} value={nuevo.nombre} onChange={(e) => setNuevo({ ...nuevo, nombre: e.target.value })} /></label>
            <label>Documento (opcional)<input maxLength={30} value={nuevo.documento} onChange={(e) => setNuevo({ ...nuevo, documento: e.target.value })} /></label>
            <label>Teléfono (opcional)<input maxLength={30} inputMode="tel" value={nuevo.telefono} onChange={(e) => setNuevo({ ...nuevo, telefono: e.target.value })} /></label>
            <p className="ayuda">El sistema le pone el plazo por defecto; el administrador lo cambia en Clientes.</p>
            <div className="acciones">
              <button type="button" className="secundario" onClick={() => { setCreando(false); crear.reset() }}>Volver a buscar</button>
              <button className="primario" disabled={crear.isPending || nuevo.nombre.trim() === ''}>Crear y elegir</button>
            </div>
          </form>
        ) : (
          <>
            {!elegido && (
              <>
                <input autoFocus placeholder="Buscar cliente por nombre o documento" aria-label="Buscar cliente" value={q} onChange={(e) => setQ(e.target.value)} />
                <button type="button" className="eleccion-alta" onClick={() => { setCreando(true); setNuevo((n) => ({ ...n, nombre: q.trim() })) }}>
                  Crear cliente rápido
                </button>
              </>
            )}
            <ListaEleccion
              filas={filas}
              pendiente={cargando && !elegido}
              vacio={resultado ? 'Sin clientes activos para esta búsqueda.' : undefined}
              elegida={elegido ? {
                titulo: elegido.nombre,
                meta: [elegido.documento, `plazo ${elegido.plazo_dias} días`].filter(Boolean).join(' · '),
                onCambiar: () => setElegido(null),
              } : undefined}
            />
            {!elegido && info && info.ultima > 1 && <Paginacion pagina={resultado} onCambiar={irA} />}
          </>
        )}
        <label>Factura de Contapyme (opcional)
          <input maxLength={40} value={factura} onChange={(e) => setFactura(e.target.value)} />
        </label>
      </DialogoSeccion>
    </Dialogo>
  )
}

/**
 * RF-14 / S-18: precio libre de un ítem en una venta a crédito abierta, mayor que 0 y sin piso.
 * Enter o salir del campo guarda; Escape cancela. El backend audita cada cambio.
 * El lector no escucha este campo: useScanner solo está en el input del escáner.
 */
function CeldaPrecio({ ventaId, item, onGuardado, onTerminar }: {
  ventaId: number
  item: VentaItem
  onGuardado: (venta: TVenta, precio: number) => void
  onTerminar: () => void
}) {
  const [texto, setTexto] = useState(item.precio_unitario === null ? '' : formatoNumero(item.precio_unitario))
  const campoRef = useRef<HTMLInputElement>(null)
  // Evita guardar dos veces (Enter y luego el blur al desmontarse) o guardar tras cancelar.
  const listo = useRef(false)
  const error = errorPrecio(texto)

  const guardar = useMutation({
    mutationFn: async (precio: number) =>
      (await api.patch<TVenta>(`/ventas/${ventaId}/items/${item.id}`, { precio_unitario: precio })).data,
    onSuccess: (nueva, precio) => onGuardado(nueva, precio),
    onError: () => { listo.current = false; campoRef.current?.focus() },
  })

  const cancelar = () => { listo.current = true; onTerminar() }

  const confirmar = (alSalir: boolean) => {
    if (listo.current || guardar.isPending) return
    const precio = leerNumero(texto)
    if (error || precio === null) {
      // Al salir con un valor inválido se descarta; con Enter se queda para corregirlo.
      if (alSalir) cancelar()
      return
    }
    if (item.precio_unitario !== null && precio === Number(item.precio_unitario)) {
      cancelar()
      return
    }
    listo.current = true
    guardar.mutate(precio)
  }

  return (
    <div className="precio-editable">
      <CampoMoneda
        ref={campoRef}
        autoFocus
        aria-label={`Precio de ${item.unidad.codigo_barras}`}
        aria-invalid={error !== null}
        aria-describedby={`precio-${item.id}-ayuda`}
        value={texto}
        disabled={guardar.isPending}
        onFocus={(e) => e.target.select()}
        onChange={(e) => { setTexto(e.target.value); guardar.reset() }}
        onKeyDown={(e) => {
          if (e.key === 'Enter') { e.preventDefault(); confirmar(false) }
          if (e.key === 'Escape') { e.preventDefault(); cancelar() }
        }}
        onBlur={() => confirmar(true)}
      />
      <small id={`precio-${item.id}-ayuda`} className={error || guardar.isError ? 'ayuda error' : 'ayuda'}>
        {guardar.isError ? mensajeError(guardar.error) : error ?? 'Con IVA incluido · Enter guarda · Esc cancela'}
      </small>
    </div>
  )
}
