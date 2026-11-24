import { memo, useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { AxiosError } from 'axios'
import { api, mensajeError } from '../../api/cliente'
import { NOMBRE_CALIDAD, type Calidad, type Cliente, type MetodoCaptura, type OpcionReferenciaVenta, type Pagina, type Referencia, type RespuestaEscaneo, type Venta as TVenta, type VentaAbierta, type VentaItem } from '../../api/tipos'
import { useAviso } from '../../componentes/Avisos'
import { useAuth } from '../../auth/AuthProvider'
import CampoCantidad from '../../componentes/CampoCantidad'
import { sumarDias } from '../../componentes/fechas'
import ChipCalidad from '../../componentes/ChipCalidad'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import ListaEleccion, { type FilaEleccion } from '../../componentes/ListaEleccion'
import DialogoElegirReferencia from '../../componentes/mostrador/DialogoElegirReferencia'
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

interface ReversoPendiente { item?: VentaItem; tipo: 'devolucion' | 'anulacion' }
interface DatosCredito { cliente_id: number; factura_contapyme: string | null }

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

/** Pregunta antes de abrir una venta igual a una que ya está abierta (S-40). */
interface Duplicada { existente: VentaAbierta; abrirOtra: () => void }

/**
 * RF-06: venta de mostrador. Cada escaneo descuenta de inmediato (no al cerrar).
 * RF-12: input siempre enfocado para el lector, y búsqueda manual de respaldo (queda "digitado").
 * RF-07: reversos con motivo obligatorio; nada se borra.
 * S-40: el mostrador escanea a lo largo del día y cierra al final; puede tener varias ventas
 * abiertas (la de contado del día y las de crédito) y cambia entre ellas desde la barra de fichas.
 */
export default function Venta() {
  const qc = useQueryClient()
  const avisos = useAviso()
  const { usuario } = useAuth()
  // RF-18: ?venta=ID abre esa venta (enlace desde la cartera del cliente).
  const [params] = useSearchParams()
  const pedida = Number(params.get('venta')) || null
  const [ventaId, setVentaId] = useState<number | null>(pedida)
  const [respaldo, setRespaldo] = useState(false)
  const [reverso, setReverso] = useState<ReversoPendiente | null>(null)
  const [eligiendoCliente, setEligiendoCliente] = useState(false)
  /** RF-14 / S-18: ítem cuyo precio se está editando (solo en una venta a crédito abierta). */
  const [editandoPrecio, setEditandoPrecio] = useState<number | null>(null)
  /** S-31: el código del proveedor está en varias referencias; se espera que quien vende elija. */
  const [eligiendoRef, setEligiendoRef] = useState<{ codigo: string; metodo: MetodoCaptura; cantidad: number; referencias: OpcionReferenciaVenta[] } | null>(null)
  /** S-40: abrir una venta igual a una ya abierta pregunta antes. */
  const [duplicada, setDuplicada] = useState<Duplicada | null>(null)
  /** S-40: cerrar pide confirmación con el resumen. */
  const [confirmandoCierre, setConfirmandoCierre] = useState(false)
  /** S-40: unidades por lectura con el código del proveedor (con una etiqueta siempre es 1). */
  const [cantidad, setCantidad] = useState(1)
  const inputRef = useRef<HTMLInputElement>(null)
  // A 360 px el placeholder largo se corta; el aria-label sigue diciendo qué códigos acepta.
  const angosta = useAngosta()

  // S-40: todas las ventas abiertas (las del usuario; el admin, las de todos). Sin una pedida, se
  // retoma la más antigua.
  const abiertas = useVentasAbiertas()
  const listaAbiertas = abiertas.data ?? []
  useEffect(() => {
    if (ventaId === null && abiertas.data?.[0]) setVentaId(abiertas.data[0].id)
  }, [abiertas.data, ventaId])

  const venta = useQuery({
    queryKey: ['venta', ventaId],
    queryFn: async () => (await api.get<TVenta>(`/ventas/${ventaId}`)).data,
    enabled: ventaId !== null,
  })

  const recientes = useQuery({
    queryKey: ['ventas', 'cerradas'],
    queryFn: async () => (await api.get<Pagina<{ id: number; created_at: string; items_count: number; total: string | number; forma_pago: TVenta['forma_pago']; cliente: { id: number; nombre: string } | null }>>('/ventas', { params: { estado: 'cerrada' } })).data,
  })

  const enfocarLector = () => setTimeout(() => inputRef.current?.focus(), 0)

  // Sin cuerpo = contado, igual que antes del crédito (RF-14).
  const abrir = useMutation({
    mutationFn: async (credito?: DatosCredito) =>
      (await api.post<TVenta>('/ventas', credito ? { forma_pago: 'credito', ...credito } : undefined)).data,
    onSuccess: (v) => {
      setVentaId(v.id)
      qc.setQueryData(['venta', v.id], v)
      qc.invalidateQueries({ queryKey: CLAVE_ABIERTAS })
      setEditandoPrecio(null)
      const deQuien = v.forma_pago === 'credito' && v.cliente?.nombre ? ` de ${v.cliente.nombre}` : ''
      avisos.mostrar(
        'ok',
        v.forma_pago === 'credito'
          ? `Se abrió la venta #${v.id} a crédito${deQuien}. Ya puede escanear.`
          : `Se abrió la venta #${v.id} de contado. Ya puede escanear.`,
      )
    },
    onError: (e) => avisos.mostrar('error', mensajeError(e)),
  })

  // S-40: las ventas abiertas propias (el admin ve todas, pero solo pregunta por las suyas).
  const propias = listaAbiertas.filter((a) => a.usuario?.id === usuario?.id)
  const nuevaContado = () => {
    const existente = propias.find((a) => a.forma_pago === 'contado')
    if (existente) setDuplicada({ existente, abrirOtra: () => abrir.mutate(undefined) })
    else abrir.mutate(undefined)
  }
  const nuevaCredito = (datos: DatosCredito) => {
    const existente = propias.find((a) => a.forma_pago === 'credito' && a.cliente?.id === datos.cliente_id)
    setEligiendoCliente(false)
    if (existente) setDuplicada({ existente, abrirOtra: () => abrir.mutate(datos) })
    else abrir.mutate(datos)
  }
  const cambiarA = useCallback((id: number) => {
    setVentaId(id)
    setEditandoPrecio(null)
    enfocarLector()
  }, [])

  // S-31: el código puede ser la etiqueta del local o el del proveedor/fabricante; el backend
  // decide. Con referenciaId se resuelve un código que está en varias referencias. S-40: con
  // cantidad > 1 agrega N unidades del código del proveedor.
  const escanear = useMutation({
    mutationFn: async ({ codigo, metodo, referenciaId, cantidad: n }: { codigo: string; metodo: MetodoCaptura; referenciaId?: number; cantidad: number }) =>
      (await api.post<RespuestaEscaneo>(`/ventas/${ventaId}/escanear`, { codigo_barras: codigo, metodo_captura: metodo, referencia_id: referenciaId, cantidad: n })).data,
    onSuccess: (r) => {
      const nuevos = r.items?.length ? r.items : [r.item]
      const porReferencia = r.origen_codigo === 'referencia' ? ' · por referencia' : ''
      const cuales = nuevos.length === 1 ? r.unidad.codigo_barras : `${nuevos.length} unidades`
      avisos.mostrar('ok', `✓ ${cuales}${porReferencia} · ${r.referencia.codigo_referencia} · ${[r.referencia.proveedor, r.referencia.calidad && NOMBRE_CALIDAD[r.referencia.calidad as Calidad]].filter(Boolean).join(' · ')} — quedan ${r.referencia.existencia} (${r.metodo_captura})`)
      setCantidad(1)
      // RF-06: una sola petición por escaneo. Los ítems llegan completos y se agregan a la venta en
      // caché, con los totales nuevos (S-33) que calcula el backend; la ficha de la venta también.
      const clave = ['venta', r.venta_id]
      qc.setQueryData<VentaAbierta[]>(CLAVE_ABIERTAS, (lista) => lista?.map((a) => (a.id === r.venta_id
        ? { ...a, items_count: a.items_count + nuevos.length, total: r.totales?.total ?? a.total }
        : a)))
      if (!qc.getQueryData<TVenta>(clave)) {
        qc.invalidateQueries({ queryKey: clave })
        return
      }
      void qc.cancelQueries({ queryKey: clave })
      qc.setQueryData<TVenta>(clave, (actual) => {
        if (!actual) return actual
        const faltan = nuevos.filter((n) => !actual.items.some((i) => i.id === n.id))
        return faltan.length ? { ...actual, items: [...actual.items, ...faltan], totales: r.totales ?? actual.totales } : actual
      })
    },
    onError: (e, { codigo, metodo, referenciaId, cantidad: n }) => {
      const datos = e instanceof AxiosError ? e.response?.data as { tipo?: string; message?: string; referencias?: OpcionReferenciaVenta[] } | undefined : undefined
      if (datos?.tipo === 'elegir_referencia' && datos.referencias?.length) {
        avisos.limpiar()
        setEligiendoRef({ codigo, metodo, cantidad: n, referencias: datos.referencias })
        return
      }
      // S-40: con una etiqueta la cantidad no aplica: se vende esa unidad (1).
      if (n > 1 && datos?.message?.startsWith('Cada etiqueta es una unidad')) {
        setCantidad(1)
        escanear.mutate({ codigo, metodo, referenciaId, cantidad: 1 })
        return
      }
      avisos.mostrar('error', mensajeError(e))
      qc.invalidateQueries({ queryKey: ['venta', ventaId] })
    },
  })

  const cerrar = useMutation({
    mutationFn: async () => (await api.post<TVenta>(`/ventas/${ventaId}/cerrar`)).data,
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

  const cantidadRef = useRef(cantidad)
  cantidadRef.current = cantidad
  const onCodigo = useCallback(
    (codigo: string, metodo: MetodoCaptura) => {
      if (inputRef.current) inputRef.current.value = ''
      if (ventaId === null || venta.data?.estado !== 'abierta') {
        avisos.mostrar('error', 'Abra una venta antes de escanear.')
        return
      }
      escanear.mutate({ codigo, metodo, cantidad: cantidadRef.current })
    },
    [ventaId, venta.data?.estado, escanear, avisos],
  )
  const scanner = useScanner(onCodigo)

  const v = venta.data
  const abierta = v?.estado === 'abierta'
  const credito = v?.forma_pago === 'credito'
  // S-18: el precio solo se cambia en crédito abierto y en ítems vigentes. Si la venta se cierra
  // o el ítem se reversa mientras se edita, la edición deja de contar.
  const precioEditable = (i: VentaItem) => credito && abierta && !i.reverso_id
  const precioEnEdicion = editandoPrecio !== null && (v?.items.some((i) => i.id === editandoPrecio && precioEditable(i)) ?? false)

  // El input del lector recupera el foco siempre que no haya un diálogo abierto.
  // Editar un precio cuenta como diálogo: el lector no le roba el foco al campo.
  const hayDialogo = respaldo || reverso !== null || eligiendoCliente || precioEnEdicion || eligiendoRef !== null || duplicada !== null || confirmandoCierre
  useEffect(() => {
    if (!hayDialogo) inputRef.current?.focus()
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

  const vieja = abierta && v ? esDeDiaAnterior(v) : false

  return (
    <div>
      <div className="pagina-encabezado">
        <h1>Venta de mostrador</h1>
        <p>Cada lectura descuenta la unidad al momento. Puede escanear durante el día y cerrar al final. La venta no se borra: se reversa.</p>
      </div>
      <div className="venta">
      <section className="panel">
        <div className="fila-titulo">
          <div className="venta-titulo">
            <h2>{v ? `Venta #${v.id}` : 'Sin venta abierta'}</h2>
            {v && <p className="venta-subtitulo">{v.estado} · {credito ? 'Crédito' : 'Contado'} · {formatearFecha(v.created_at)}</p>}
          </div>
          <div className="acciones">
            <button type="button" className="secundario boton-44" onClick={nuevaContado} disabled={abrir.isPending}>
              {abrir.isPending && !eligiendoCliente ? 'Abriendo…' : 'Nueva venta'}
            </button>
            <button type="button" className="secundario boton-44" onClick={() => setEligiendoCliente(true)} disabled={abrir.isPending}>Nueva venta a crédito</button>
            {abierta && <button type="button" className="secundario boton-44" onClick={() => setRespaldo(true)}>Buscar sin escáner</button>}
            {v && !abierta && vigentes.length > 0 && (
              <button type="button" className="peligro boton-44" onClick={() => setReverso({ tipo: 'anulacion' })}>Anular venta completa</button>
            )}
          </div>
        </div>

        {listaAbiertas.length > 0 && (
          <BarraVentasAbiertas ventas={listaAbiertas} activa={v?.id ?? null} conUsuario={usuario?.rol === 'admin'} onElegir={cambiarA} />
        )}

        {vieja && v && (
          <div className="aviso atencion" role="status">
            Esta venta se abrió el {formatearFecha(v.created_at)} y sigue abierta. Ciérrala cuando termines de descargar
            {credito ? '; el vencimiento se cuenta desde el cierre.' : '.'}
          </div>
        )}

        {v && credito && (
          <p className="venta-credito">
            Cliente: <strong>{v.cliente?.nombre ?? '—'}</strong>
            {v.cliente?.documento && <> · {v.cliente.documento}</>}
            {v.factura_contapyme && <> · Factura Contapyme {v.factura_contapyme}</>}
            {v.estado === 'cerrada' && v.vence_el && <> · <strong>Vence el {fechaCorta(v.vence_el)}</strong></>}
          </p>
        )}

        <div className="lector-con-cantidad">
          <div className="lector-campo">
            <input
              ref={inputRef}
              className="escaner"
              placeholder={abierta ? (angosta ? 'Escanee o digite el código' : 'Escanee la etiqueta o el código del proveedor') : 'Abra una venta para escanear'}
              aria-label="Etiqueta del local o código del proveedor"
              onKeyDown={scanner.onKeyDown}
              onPaste={scanner.onPaste}
              onBlur={reenfocar}
              disabled={!abierta}
              autoComplete="off"
            />
          </div>
          <CampoCantidad
            id="venta-cantidad"
            etiqueta="Cantidad"
            valor={cantidad}
            max={MAX_CANTIDAD}
            onCambio={setCantidad}
            disabled={!abierta}
            ayuda="Con el código del proveedor. Cada etiqueta es una unidad."
          />
        </div>
        {v && (
          <div className="venta-cuerpo">
            {/* La tabla se desplaza por dentro: la página nunca se desborda. */}
            <div className="tabla-venta-scroll">
            <table className="tabla-apilable tabla-venta">
              <thead>
                <tr><th>Código</th><th>Referencia</th><th>Proveedor</th><th title="Precio con IVA incluido">Precio (IVA incl.)</th><th>Captura</th><th>Usuario</th><th>Hora</th><th></th></tr>
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
                {v.items.length === 0 && <tr><td colSpan={8} className="vacio">Sin ítems todavía.</td></tr>}
              </tbody>
            </table>
            </div>

            <aside className="venta-totales" aria-label="Totales de la venta">
              <div className="totales-venta">
                <p className="ayuda">Precios con IVA incluido</p>
                <dl>
                  <div><dt>Ítems</dt><dd>{vigentes.length}</dd></div>
                  <div><dt>Base (sin IVA)</dt><dd>{pesos(v.totales?.base ?? 0)}</dd></div>
                  <div><dt>{rotuloIvaIncluido(v.totales?.iva_porcentajes ?? [])}</dt><dd>{pesos(v.totales?.iva_incluido ?? 0)}</dd></div>
                  <div className="linea-total"><dt>Total</dt><dd>{pesos(v.totales?.total ?? 0)}</dd></div>
                </dl>
                {(v.totales?.items_sin_iva ?? 0) > 0 && <p className="ayuda">{v.totales?.items_sin_iva} ítem(s) antiguos sin IVA registrado: no se desglosan.</p>}
              </div>
              {abierta && (
                <button type="button" className="primario boton-44 boton-cerrar-venta" onClick={() => setConfirmandoCierre(true)} disabled={cerrar.isPending}>Cerrar venta</button>
              )}
            </aside>
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

      <aside className="panel lateral">
        <h3>Ventas cerradas recientes</h3>
        <ul className="lista">
          {recientes.data?.data.map((r) => (
            <li key={r.id}>
              <button className="enlace" onClick={() => setVentaId(r.id)}>#{r.id}</button>
              {r.forma_pago === 'credito' && <> <span className="chip credito">Crédito</span> {r.cliente?.nombre}</>} · {r.items_count} ítems · <span title="Total con IVA incluido">{pesos(r.total)}</span> · {formatearFechaHora(r.created_at)}
            </li>
          ))}
        </ul>
      </aside>
      </div>

      {respaldo && (
        <BusquedaRespaldo
          onElegir={(codigo) => { setRespaldo(false); escanear.mutate({ codigo, metodo: 'digitado', cantidad: 1 }) }}
          onCerrar={() => setRespaldo(false)}
        />
      )}

      {eligiendoRef && (
        <DialogoElegirReferencia
          codigo={eligiendoRef.codigo}
          referencias={eligiendoRef.referencias}
          onElegir={(referenciaId) => {
            const { codigo, metodo, cantidad: n } = eligiendoRef
            setEligiendoRef(null)
            escanear.mutate({ codigo, metodo, referenciaId, cantidad: n })
          }}
          onCerrar={() => setEligiendoRef(null)}
        />
      )}

      {eligiendoCliente && (
        <DialogoCredito
          abriendo={abrir.isPending}
          onConfirmar={nuevaCredito}
          onCerrar={() => { setEligiendoCliente(false); abrir.reset() }}
        />
      )}

      {duplicada && (
        <Dialogo
          titulo={duplicada.existente.forma_pago === 'credito' ? 'Ya hay una venta a crédito abierta de este cliente' : 'Ya tienes una venta de contado abierta'}
          variante="confirmar"
          onCerrar={() => setDuplicada(null)}
          pie={(
            <>
              <button type="button" className="secundario boton-44" onClick={() => { const otra = duplicada.abrirOtra; setDuplicada(null); otra() }}>Abrir otra</button>
              <button type="button" className="primario boton-44" autoFocus onClick={() => { cambiarA(duplicada.existente.id); setDuplicada(null) }}>Continuar con esa</button>
            </>
          )}
        >
          <DialogoSeccion>
            <p>
              {duplicada.existente.forma_pago === 'credito' ? `${duplicada.existente.cliente?.nombre ?? 'El cliente'} ya tiene` : 'Ya tienes'} una venta {duplicada.existente.forma_pago === 'credito' ? 'a crédito' : 'de contado'} abierta
              {' '}(#{duplicada.existente.id}, {duplicada.existente.items_count} {duplicada.existente.items_count === 1 ? 'ítem' : 'ítems'}, {pesos(duplicada.existente.total)}). ¿Continuar con ella o abrir otra?
            </p>
          </DialogoSeccion>
        </Dialogo>
      )}

      {confirmandoCierre && v && (
        <DialogoCerrarVenta
          venta={v}
          items={vigentes.length}
          pendiente={cerrar.isPending}
          onCerrarVenta={() => cerrar.mutate()}
          onSeguir={() => setConfirmandoCierre(false)}
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
 * Una venta de un día anterior lleva un punto de aviso con la fecha en el title.
 */
function BarraVentasAbiertas({ ventas, activa, conUsuario, onElegir }: {
  ventas: VentaAbierta[]
  activa: number | null
  conUsuario: boolean
  onElegir: (id: number) => void
}) {
  const muyAngosta = useAngosta(480)
  const botones = useRef<Array<HTMLButtonElement | null>>([])
  const [listado, setListado] = useState<'todas' | 'vacias' | null>(null)

  const actual = ventas.find((v) => v.id === activa)
  const conItems = ventas.filter((v) => v.id !== activa && v.items_count > 0).sort((a, b) => b.id - a.id)
  const vacias = ventas.filter((v) => v.id !== activa && v.items_count === 0)
  const espacio = MAX_FICHAS - (actual ? 1 : 0) - (vacias.length > 0 ? 1 : 0)
  const visibles = [...(actual ? [actual] : []), ...conItems.slice(0, Math.max(espacio, 0))]

  if (muyAngosta) {
    return (
      <label className="ventas-abiertas-select">Venta abierta
        <select value={activa ?? ''} onChange={(e) => onElegir(Number(e.target.value))}>
          {activa === null && <option value="">Elija una venta</option>}
          {[...ventas].sort((a, b) => b.id - a.id).map((v) => <option key={v.id} value={v.id}>{textoOpcion(v)}</option>)}
        </select>
      </label>
    )
  }

  const total = visibles.length + (vacias.length > 0 ? 1 : 0) + 1
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
        {visibles.map((v, i) => (
          <button
            key={v.id}
            type="button"
            className={v.id === activa ? 'ficha-venta activa' : 'ficha-venta'}
            aria-pressed={v.id === activa}
            onClick={() => onElegir(v.id)}
            {...propsBoton(i)}
          >
            <span className="ficha-venta-linea">
              <strong>
                #{v.id}
                {esDeDiaAnterior(v) && <span className="punto-alerta" title={`Abierta el ${formatearFecha(v.created_at)}`}><span className="solo-lector">, abierta el {formatearFecha(v.created_at)}</span></span>}
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
          <button type="button" className="ficha-venta ficha-resumen" onClick={() => setListado('vacias')} {...propsBoton(visibles.length)}>
            <strong>Vacías ({vacias.length})</strong>
            <small>Sin ítems todavía</small>
          </button>
        )}
        <button type="button" className="ficha-venta ficha-resumen" onClick={() => setListado('todas')} {...propsBoton(total - 1)}>
          <strong>Ver todas ({ventas.length})</strong>
          <small>Buscar por número o cliente</small>
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
}

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
      <td data-etiqueta="Referencia"><span>{i.unidad.referencia?.codigo_referencia} <small>{i.unidad.referencia?.descripcion}</small></span></td>
      <td data-etiqueta="Proveedor"><span>{i.unidad.referencia?.proveedor?.nombre} <ChipCalidad calidad={i.unidad.referencia?.calidad} /></span></td>
      <td data-etiqueta="Precio">
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
      <td data-etiqueta="Captura"><span className={`chip ${i.metodo_captura}`}>{i.metodo_captura}</span></td>
      <td data-etiqueta="Usuario">{i.usuario.name}</td>
      <td data-etiqueta="Hora">{formatearHora(i.created_at)}</td>
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

/**
 * S-40: confirmación antes de cerrar: ítems, total con IVA incluido, forma de pago, cliente y, en
 * crédito, el vencimiento que se fijará (hoy + plazo del cliente, S-11). Al cerrar, los precios
 * quedan fijos.
 */
function DialogoCerrarVenta({ venta: v, items, pendiente, onCerrarVenta, onSeguir }: {
  venta: TVenta
  items: number
  pendiente: boolean
  onCerrarVenta: () => void
  onSeguir: () => void
}) {
  const credito = v.forma_pago === 'credito'
  const vence = credito && v.cliente ? sumarDias(hoyEnBogota(), v.cliente.plazo_dias) : null

  return (
    <Dialogo
      titulo={`Cerrar la venta #${v.id}`}
      variante="confirmar"
      onCerrar={onSeguir}
      pendiente={pendiente}
      pie={(
        <>
          <button type="button" className="secundario boton-44" disabled={pendiente} onClick={onSeguir}>Seguir escaneando</button>
          <button type="button" className="primario boton-44" autoFocus disabled={pendiente} onClick={onCerrarVenta}>{pendiente ? 'Cerrando…' : 'Cerrar venta'}</button>
        </>
      )}
    >
      <DialogoSeccion>
        <dl className="datos-fijos resumen-cierre">
          <div><dt>Ítems</dt><dd>{items}</dd></div>
          <div><dt>Total (IVA incl.)</dt><dd><strong>{v.totales ? pesos(v.totales.total) : '—'}</strong></dd></div>
          <div><dt>Forma de pago</dt><dd>{credito ? 'Crédito' : 'Contado'}</dd></div>
          {credito && <div><dt>Cliente</dt><dd>{v.cliente?.nombre ?? '—'}</dd></div>}
          {vence && <div><dt>Vencimiento</dt><dd>{fechaCorta(vence)} ({v.cliente?.plazo_dias} días)</dd></div>}
        </dl>
        <p>Al cerrar, los precios quedan fijos y la venta no se puede editar; solo reversar.</p>
        {items === 0 && <p className="aviso atencion">La venta no tiene ítems vigentes.</p>}
      </DialogoSeccion>
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
function DialogoCredito({ abriendo, onConfirmar, onCerrar }: {
  abriendo: boolean
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
      descripcion="La factura de Contapyme es solo una referencia: este sistema no factura."
      variante="ancha"
      onCerrar={onCerrar}
      pendiente={abriendo}
      pie={(
        <>
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button
            type="button"
            className="primario"
            disabled={!elegido || abriendo}
            onClick={() => elegido && onConfirmar({ cliente_id: elegido.id, factura_contapyme: factura.trim() || null })}
          >
            {abriendo ? 'Abriendo…' : 'Abrir venta a crédito'}
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
      <input
        ref={campoRef}
        autoFocus
        inputMode="decimal"
        autoComplete="off"
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
