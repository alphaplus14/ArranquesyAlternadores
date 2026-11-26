import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import { useAviso, useAvisoTexto } from '../../componentes/Avisos'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import type { CorreccionIngreso, FormaPago, Ingreso, IngresoItem, LineaCorreccionEnvio, Opcion, Pagina, Referencia, VistaPreviaCorreccion } from '../../api/tipos'
import SelectorUniversal from '../../componentes/SelectorUniversal'
import DetalleCorreccion from '../../componentes/ingresos/DetalleCorreccion'
import { formatearFechaHora } from '../../utilidades/fechas'
import { fechaCorta, sumarDias } from '../../componentes/fechas'
import CampoDescuento, { type ModoDescuento } from '../../componentes/CampoDescuento'
import { formatoNumero, leerNumero, pesos } from '../../componentes/numeros'
import CampoMoneda from '../../componentes/CampoMoneda'
import { useCategorias } from '../../hooks/useCatalogos'
import SelectorCategoria from '../../componentes/SelectorCategoria'
import ControlSegmentado, { type OpcionSegmento } from '../../componentes/ControlSegmentado'
import AvisoCoincidencias from '../../componentes/AvisoCoincidencias'
import { useScanner } from '../../hooks/useScanner'
import { useBloquearScroll } from '../../hooks/useBloquearScroll'
import { atraparTab } from '../../componentes/foco'
import Icono from '../../componentes/Icono'

type Campo = 'cantidad' | 'costo_unitario' | 'descuento_porcentaje' | 'iva_porcentaje'
const CAMPOS: Campo[] = ['cantidad', 'costo_unitario', 'descuento_porcentaje', 'iva_porcentaje']
/** IVA de una línea de compra (S-22): 0 = sin IVA. Si la línea trae otro valor, se agrega. */
const IVAS = [19, 5, 0]

/** Una fila de líneas en edición: todos sus campos a la vez (solo una fila a la vez). */
interface Fila {
  itemId: number
  codigo: string
  /** Campo donde empieza el foco (el que se tocó). */
  foco: Campo
  valores: Record<Campo, string>
  originales: Record<Campo, string>
  /** Se intentó guardar: se muestran todos los errores, no solo los de lo editado. */
  intentado: boolean
  errorServidor: string | null
  guardando: boolean
}

type LineaEditable = Pick<IngresoItem, 'id' | 'referencia' | 'cantidad' | 'costo_unitario' | 'descuento_porcentaje' | 'iva_porcentaje'>

function valoresDe(it: LineaEditable): Record<Campo, string> {
  return {
    cantidad: String(it.cantidad),
    costo_unitario: formatoNumero(it.costo_unitario),
    descuento_porcentaje: formatoNumero(it.descuento_porcentaje),
    iva_porcentaje: String(Number(it.iva_porcentaje)),
  }
}

function erroresDe(f: Fila): Record<Campo, string | null> {
  return Object.fromEntries(CAMPOS.map((c) => [c, REGLAS[c].validar(leerNumero(f.valores[c]))])) as Record<Campo, string | null>
}

/** Solo los campos que cambiaron, como números: se guardan en una sola petición (PATCH acepta varios). */
function cambiosDe(f: Fila): Partial<Record<Campo, number>> {
  const cambios: Partial<Record<Campo, number>> = {}
  for (const c of CAMPOS) {
    const nuevo = leerNumero(f.valores[c])
    if (nuevo !== null && nuevo !== leerNumero(f.originales[c])) cambios[c] = nuevo
  }
  return cambios
}

/** Validación en vivo de cada celda. El backend vuelve a validar al guardar y al confirmar. */
const REGLAS: Record<Campo, { etiqueta: string; validar: (n: number | null) => string | null }> = {
  cantidad: {
    etiqueta: 'Cantidad',
    validar: (n) => (n === null ? 'Escriba la cantidad.' : !Number.isInteger(n) || n < 1 ? 'Debe ser un número entero, 1 o más.' : n > 10000 ? 'Máximo 10.000.' : null),
  },
  costo_unitario: {
    etiqueta: 'Costo',
    validar: (n) => (n === null ? 'Escriba el costo.' : Number.isNaN(n) ? 'Costo no válido.' : n <= 0 ? 'El costo debe ser mayor que 0.' : null),
  },
  descuento_porcentaje: {
    etiqueta: 'Desc. %',
    validar: (n) => (n === null ? 'Escriba el descuento (0 si no hay).' : Number.isNaN(n) || n > 100 ? 'Entre 0 y 100.' : null),
  },
  iva_porcentaje: {
    etiqueta: 'IVA %',
    validar: (n) => (n === null ? 'Escriba el IVA.' : Number.isNaN(n) || n > 100 ? 'Entre 0 y 100.' : null),
  },
}

interface OpcionAmbigua { id: number; codigo_referencia: string; codigo_fabricante: string | null; descripcion: string }
interface Ambiguo { codigo: string; opciones: OpcionAmbigua[] }

/** S-25: 422 del escaneo cuando el código coincide con varias referencias del mismo proveedor. */
function codigoAmbiguo(error: unknown): Ambiguo | null {
  if (!(error instanceof AxiosError) || error.response?.status !== 422) return null
  const datos = error.response.data as { motivo?: string; codigo?: string; opciones?: OpcionAmbigua[] } | undefined
  return datos?.motivo === 'codigo_ambiguo' && datos.opciones ? { codigo: datos.codigo ?? '', opciones: datos.opciones } : null
}

/** Cuerpo del 404 del escaneo cuando el código no es una referencia del proveedor (RF-20). */
function codigoInexistente(error: unknown): string | null {
  if (!(error instanceof AxiosError) || error.response?.status !== 404) return null
  const datos = error.response.data as { motivo?: string; codigo?: string } | undefined
  return datos?.motivo === 'referencia_inexistente' ? datos.codigo ?? null : null
}

/**
 * RF-04 / RF-05: remisión o factura digitada a mano. RF-20: los ítems se agregan escaneando el
 * código de referencia del proveedor; si no existe, se ofrece crearla y se vuelve a escanear.
 * RF-09 / RF-10: descuentos y líneas sin IVA, corregibles en la tabla mientras es borrador.
 * RF-16: forma de pago y vencimiento (diálogo "Editar condiciones"); el total se fija al confirmar.
 * RF-03: al confirmar se crean las unidades y se pasa a imprimir etiquetas.
 * Todas las cifras (subtotal, base, IVA, total) vienen del backend: aquí no se recalculan.
 */
export default function IngresoDetalle() {
  const { id } = useParams()
  const qc = useQueryClient()
  const avisos = useAviso()
  const [creando, setCreando] = useState<string | null>(null)
  const [fila, setFilaEstado] = useState<Fila | null>(null)
  // Copia sincrónica: el blur de una fila y el clic en otra llegan en el mismo ciclo.
  const filaRef = useRef<Fila | null>(null)
  const ponerFila = (f: Fila | null) => { filaRef.current = f; setFilaEstado(f) }
  const [quitando, setQuitando] = useState<number | null>(null)
  /** S-25: el código leído coincide con varias referencias; se elige una. */
  const [ambiguo, setAmbiguo] = useState<Ambiguo | null>(null)
  const [condiciones, setCondiciones] = useState(false)
  /** RF-28: modo corrección de un ingreso confirmado y la última corrección hecha aquí. */
  const [corrigiendo, setCorrigiendo] = useState(false)
  const [corregido, setCorregido] = useState<CorreccionIngreso | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  const ingreso = useQuery({
    queryKey: ['ingreso', id],
    queryFn: async () => (await api.get<Ingreso>(`/ingresos/${id}`)).data,
  })
  const i = ingreso.data
  const borrador = i?.estado === 'borrador'

  const actualizar = (nuevo: Ingreso) => qc.setQueryData(['ingreso', id], nuevo)

  const guardarLinea = async (f: Fila, cambios: Partial<Record<Campo, number>>) =>
    (await api.patch<Ingreso>(`/ingresos/${id}/items/${f.itemId}`, cambios)).data

  /** Al salir de una fila sin Enter ni Esc: guarda si es válida y cambió; si no, la descarta. */
  const guardarAlSalir = (f: Fila) => {
    const cambios = cambiosDe(f)
    if (Object.values(erroresDe(f)).some(Boolean) || Object.keys(cambios).length === 0) return
    guardarLinea(f, cambios)
      .then(actualizar)
      .catch((e) => avisos.mostrar('error', `No se guardó la línea ${f.codigo}: ${mensajeError(e)}`))
  }

  const abrirFila = (it: LineaEditable, foco: Campo) => {
    const actual = filaRef.current
    if (actual?.itemId === it.id) return
    if (actual && !actual.guardando) guardarAlSalir(actual)
    setQuitando(null)
    const valores = valoresDe(it)
    ponerFila({ itemId: it.id, codigo: it.referencia?.codigo_referencia ?? '', foco, valores, originales: valores, intentado: false, errorServidor: null, guardando: false })
  }

  const cambiarValor = (campo: Campo, valor: string) => {
    const f = filaRef.current
    if (f) ponerFila({ ...f, valores: { ...f.valores, [campo]: valor }, errorServidor: null })
  }

  /** Enter o "Guardar": la fila completa en una petición. Con error, la fila sigue abierta. */
  const guardarFila = async () => {
    const f = filaRef.current
    if (!f || f.guardando) return
    if (Object.values(erroresDe(f)).some(Boolean)) { ponerFila({ ...f, intentado: true }); return }
    const cambios = cambiosDe(f)
    if (Object.keys(cambios).length === 0) { ponerFila(null); return }
    ponerFila({ ...f, guardando: true, errorServidor: null })
    try {
      actualizar(await guardarLinea(f, cambios))
      if (filaRef.current?.itemId === f.itemId) ponerFila(null)
      // Un error anterior (por ejemplo, "falta el costo") ya no aplica tras corregir.
      avisos.limpiarSi('error')
    } catch (e) {
      const actual = filaRef.current
      // El foco va al campo que señala el backend (422), si lo señala.
      const conError = Object.keys(erroresCampo(e))
      const foco = CAMPOS.find((c) => conError.includes(c)) ?? f.foco
      if (actual?.itemId === f.itemId) ponerFila({ ...actual, foco, guardando: false, intentado: true, errorServidor: mensajeError(e) })
      else avisos.mostrar('error', `No se guardó la línea ${f.codigo}: ${mensajeError(e)}`)
    }
  }

  const cancelarFila = () => ponerFila(null)

  const salirDeFila = (itemId: number) => {
    const f = filaRef.current
    if (!f || f.itemId !== itemId || f.guardando) return
    ponerFila(null)
    guardarAlSalir(f)
  }

  const escanear = useMutation({
    mutationFn: async ({ codigo, referenciaId }: { codigo: string; referenciaId?: number }) =>
      (await api.post<IngresoItem>(`/ingresos/${id}/escanear`, { codigo, referencia_id: referenciaId })).data,
    onSuccess: (linea) => {
      const sinCosto = Number(linea.costo_unitario) <= 0
      // Captura rápida: línea nueva o sin costo → se abre su costo con el foco (Enter guarda).
      const nueva = !qc.getQueryData<Ingreso>(['ingreso', id])?.items.some((x) => x.id === linea.id)
      if (nueva || sinCosto) abrirFila(linea, 'costo_unitario')
      avisos.mostrar(
        sinCosto ? 'atencion' : 'ok',
        `✓ ${linea.referencia?.codigo_referencia} · ${linea.referencia?.descripcion} — cantidad ${linea.cantidad}${sinCosto ? ' · escriba el costo en la tabla' : ''}`,
        undefined,
        5000, // S-65: el aviso del ítem agregado se va solo a los 5 s, también si falta el costo
      )
      qc.invalidateQueries({ queryKey: ['ingreso', id] })
    },
    onError: (e, { codigo }) => {
      const inexistente = codigoInexistente(e)
      if (inexistente !== null) {
        avisos.mostrar('atencion', `El código ${inexistente} no es una referencia de este proveedor. Créela para agregarla.`)
        setCreando(inexistente || codigo)
        return
      }
      const varias = codigoAmbiguo(e)
      if (varias) {
        avisos.limpiar()
        setAmbiguo(varias)
        return
      }
      avisos.mostrar('error', mensajeError(e))
    },
  })

  const quitar = useMutation({
    mutationFn: async (itemId: number) => (await api.delete<Ingreso>(`/ingresos/${id}/items/${itemId}`)).data,
    onSuccess: actualizar,
    onError: (e) => avisos.mostrar('error', mensajeError(e)),
    onSettled: () => { setQuitando(null); inputRef.current?.focus() },
  })
  const confirmar = useMutation({
    mutationFn: async () => (await api.post<Ingreso>(`/ingresos/${id}/confirmar`)).data,
    onSuccess: (nuevo) => { actualizar(nuevo); qc.invalidateQueries({ queryKey: ['ingresos'] }); avisos.limpiar() },
    onError: (e) => avisos.mostrar('error', mensajeError(e)),
  })

  const onCodigo = useCallback((codigo: string) => {
    if (inputRef.current) inputRef.current.value = ''
    escanear.mutate({ codigo })
  }, [escanear])
  const scanner = useScanner(onCodigo)

  // El lector recupera el foco salvo que haya un diálogo abierto, una fila en edición o una
  // confirmación de quitar. Así lo que se digita en ellos no lo captura el escáner.
  const hayDialogo = creando !== null || fila !== null || quitando !== null || ambiguo !== null || condiciones
  useEffect(() => {
    if (!hayDialogo && borrador) inputRef.current?.focus()
  }, [hayDialogo, borrador, escanear.isPending])
  // El blur llega antes que el clic que abre el diálogo: el timeout lee el valor vigente. Si el
  // foco pasó a la tabla de líneas (Tab o clic en un valor), se deja ahí; si no, vuelve al lector.
  const hayDialogoRef = useRef(hayDialogo)
  hayDialogoRef.current = hayDialogo
  const tablaRef = useRef<HTMLTableElement>(null)
  const reenfocar = () => setTimeout(() => {
    if (hayDialogoRef.current) return
    if (tablaRef.current?.contains(document.activeElement)) return
    inputRef.current?.focus()
  }, 0)

  useAvisoTexto(ingreso.isError ? mensajeError(ingreso.error) : null)
  if (ingreso.isError) return <div className="panel">No se pudo cargar el ingreso.</div>
  if (!i) return <div className="panel">Cargando…</div>

  // S-66 (ajuste): el borrador muestra el total visible (nunca negativo); confirmado, el de siempre.
  const pendiente = i.totales ? Number(i.totales.descuento_pendiente) : 0
  const descuentoDoc = Number(i.descuento_documento)

  /** Valor de una línea en borrador: se ve como celda editable; clic abre la fila completa. */
  const valor = (it: IngresoItem, campo: Campo, texto: string) => {
    if (!borrador) return texto
    const codigo = it.referencia?.codigo_referencia ?? ''
    if (campo === 'costo_unitario' && Number(it.costo_unitario) <= 0) {
      return (
        <button type="button" className="celda-editable pendiente" title="Clic para editar" aria-label={`Falta el costo de ${codigo}. Editar la línea`} onClick={() => abrirFila(it, campo)}>
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2.25" strokeLinecap="round" aria-hidden><path d="M12 7v6M12 17h.01" /><circle cx="12" cy="12" r="9" /></svg>
          Costo
        </button>
      )
    }
    return (
      <button type="button" className="celda-editable" title="Clic para editar" aria-label={`${REGLAS[campo].etiqueta} de ${codigo}: ${texto}. Editar la línea`} onClick={() => abrirFila(it, campo)}>
        {texto}
      </button>
    )
  }

  /** Quitar: directo con cantidad 1 y sin costo; si no, confirmación breve en la fila. */
  const pedirQuitar = (it: IngresoItem) => {
    if (it.cantidad > 1 || Number(it.costo_unitario) > 0) setQuitando(it.id)
    else quitar.mutate(it.id)
  }

  return (
    <div className="pila detalle-compra">
      <div className="pagina-encabezado">
        <h1>{i.tipo === 'factura' ? 'Factura' : 'Remisión'} {i.numero_documento}</h1>
        <p>{i.proveedor?.nombre} · {i.fecha.slice(0, 10)}{i.proveedor?.asesor && <small className="asesor">Asesor: {i.proveedor.asesor}</small>}</p>
      </div>
      {corregido && !corrigiendo && (
        <div className="aviso ok" role="status">
          Corrección guardada: {corregido.unidades_creadas} unidad(es) creada(s) y {corregido.unidades_retiradas} retirada(s). Total {pesos(corregido.total_nuevo)}.
          {corregido.unidades_creadas > 0 && <> <Link to={`/logistica/etiquetas?ingreso=${i.id}&pendientes=1`}>Imprimir etiquetas pendientes</Link></>}
        </div>
      )}
      {corrigiendo && (
        <PanelCorreccion
          ingreso={i}
          onCancelar={() => setCorrigiendo(false)}
          onCorregido={(r) => {
            actualizar(r.ingreso)
            setCorregido(r.correccion)
            setCorrigiendo(false)
            qc.invalidateQueries({ queryKey: ['ingresos'] })
            qc.invalidateQueries({ queryKey: ['ingreso', String(i.id), 'unidades'] })
          }}
        />
      )}
      <div className="panel" hidden={corrigiendo}>
        <div className="fila-titulo">
          <h2>Líneas <span className={`chip ${i.estado}`}>{i.estado}</span>{i.corregido && <span className="chip corregida">Corregida</span>}</h2>
          <span>{i.proveedor?.nombre}</span>
        </div>

        <div className="condiciones-documento">
          <ul className="condiciones-chips" aria-label="Condiciones del documento">
            {i.forma_pago === 'credito' ? (
              <>
                <li><span className="chip credito">Crédito</span></li>
                <li>{i.vence_el ? <span className="chip">Vence {fechaCorta(i.vence_el)}</span> : <span className="chip atencion">Falta el vencimiento</span>}</li>
              </>
            ) : <li><span className="chip">Contado</span></li>}
            <li>
              <span className="chip">
                {i.descuento_documento_porcentaje !== null && Number(i.descuento_documento_porcentaje) > 0
                  ? `Descuento ${formatoNumero(i.descuento_documento_porcentaje)} % (${pesos(i.descuento_documento)})`
                  : descuentoDoc > 0 ? `Descuento ${pesos(i.descuento_documento)}` : 'Sin descuento'}
              </span>
            </li>
          </ul>
          {/* En confirmado no hay botón: la corrección va por RF-28. */}
          {borrador && (
            <button type="button" className="secundario boton-44" aria-label="Editar las condiciones del documento" onClick={() => setCondiciones(true)}>
              <Icono nombre="editar" /> Editar condiciones
            </button>
          )}
        </div>

        {i.totales && (
          <div className="totales" aria-label="Totales del documento">
            <div><span>Subtotal</span><strong>{pesos(i.totales.subtotal_bruto)}</strong></div>
            <div><span>Descuento de líneas</span><strong>{pesos(i.totales.descuento_lineas)}</strong></div>
            <div><span>Descuento del documento</span><strong>{pesos(borrador ? i.totales.descuento_aplicado : i.totales.descuento_documento)}</strong></div>
            {borrador && pendiente > 0 && <div><span>Descuento por aplicar</span><strong>{pesos(i.totales.descuento_pendiente)}</strong></div>}
            <div><span>Base gravada</span><strong>{pesos(i.totales.base_gravada)}</strong></div>
            <div><span>Base sin IVA</span><strong>{pesos(i.totales.base_sin_iva)}</strong></div>
            <div><span>IVA</span><strong>{pesos(i.totales.iva)}</strong></div>
            <div><span>Líneas sin IVA</span><strong>{i.totales.lineas_sin_iva}</strong></div>
            <div className="totales-total"><span>Total</span><strong>{pesos(borrador ? i.totales.total_visible : i.totales.total)}</strong></div>
            {i.total && <div><span>Total guardado al confirmar</span><strong>{pesos(i.total)}</strong></div>}
          </div>
        )}
        {borrador && i.totales && i.items.length === 0 && descuentoDoc > 0 && (
          <p className="aviso info" role="status">
            El descuento de {pesos(i.totales.descuento_documento)} se guardó y se aplicará cuando agregue las líneas.
          </p>
        )}
        {borrador && i.totales && i.items.length > 0 && pendiente > 0 && (
          <p className="aviso atencion" role="status">
            El descuento ({pesos(i.totales.descuento_documento)}) es mayor que el subtotal de las líneas ({pesos(i.totales.subtotal_neto)}):
            faltan {pesos(i.totales.descuento_pendiente)} por aplicar. Agregue líneas o corrija el descuento antes de confirmar.
          </p>
        )}

        {borrador && (
          <>
            <input
              ref={inputRef}
              className="escaner"
              placeholder="Escanee el código del producto (o escríbalo y pulse Enter)"
              aria-label="Lector de código de referencia"
              onKeyDown={scanner.onKeyDown}
              onPaste={scanner.onPaste}
              onBlur={reenfocar}
              autoComplete="off"
            />
            <p className="ayuda">Se busca entre las referencias de {i.proveedor?.nombre}. Leer el mismo código otra vez suma 1. Haga clic en un valor o en el lápiz para editar la línea: Tab recorre, Enter guarda y Esc cancela.</p>
          </>
        )}
        {ambiguo && (
          <div
            className="aviso atencion eleccion-codigo"
            role="group"
            aria-labelledby="codigo-ambiguo-titulo"
            onKeyDown={(e) => { if (e.key === 'Escape') { e.preventDefault(); setAmbiguo(null) } }}
          >
            <p id="codigo-ambiguo-titulo">Código ambiguo: <strong>{ambiguo.codigo}</strong> coincide con varias referencias de este proveedor. ¿Cuál es?</p>
            <div className="opciones-codigo">
              {ambiguo.opciones.map((o, n) => (
                <button
                  key={o.id}
                  type="button"
                  className="secundario"
                  autoFocus={n === 0}
                  onClick={() => { setAmbiguo(null); escanear.mutate({ codigo: ambiguo.codigo, referenciaId: o.id }) }}
                >
                  <strong>{o.codigo_referencia}</strong>
                  {o.codigo_fabricante && <small> · código de barras {o.codigo_fabricante}</small>}
                  <small> · {o.descripcion}</small>
                </button>
              ))}
              <button type="button" className="enlace" onClick={() => setAmbiguo(null)}>Cancelar</button>
            </div>
          </div>
        )}

        <div className="tabla-desplazable">
        <table ref={tablaRef} className="tabla-lineas">
          <thead><tr><th>Referencia</th><th>Cant.</th><th>Costo</th><th>Desc. %</th><th>IVA %</th><th>Neto</th><th>IVA</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
          <tbody>
            {i.items.map((it) => {
              if (borrador && fila?.itemId === it.id) {
                return (
                  <FilaEnEdicion
                    key={it.id}
                    it={it}
                    fila={fila}
                    onCambiar={cambiarValor}
                    onGuardar={guardarFila}
                    onCancelar={cancelarFila}
                    onSalir={() => salirDeFila(it.id)}
                  />
                )
              }
              const exenta = Number(it.iva_porcentaje) === 0
              const codigo = it.referencia?.codigo_referencia ?? ''
              return (
                <tr key={it.id}>
                  <td data-label="Referencia">{codigo} <small>{it.referencia?.descripcion}</small></td>
                  <td data-label="Cant.">{valor(it, 'cantidad', String(it.cantidad))}</td>
                  <td data-label="Costo">
                    {!borrador && Number(it.costo_unitario) <= 0 ? <span className="agotado">Sin costo</span> : valor(it, 'costo_unitario', pesos(it.costo_unitario))}
                  </td>
                  <td data-label="Desc. %">{valor(it, 'descuento_porcentaje', `${formatoNumero(it.descuento_porcentaje)} %`)}</td>
                  <td data-label="IVA %">
                    <span>
                      {valor(it, 'iva_porcentaje', `${formatoNumero(it.iva_porcentaje)} %`)}
                      {exenta && <> <span className="chip">sin IVA</span></>}
                    </span>
                  </td>
                  <td data-label="Neto">{it.neto === null ? '—' : pesos(it.neto)}</td>
                  <td data-label="IVA">{it.iva === null ? '—' : pesos(it.iva)}</td>
                  <td data-label="" className="acciones-linea">
                    {borrador && (quitando === it.id ? (
                      <span className="confirmar-quitar" role="group" aria-label={`Quitar ${codigo}`} onKeyDown={(e) => { if (e.key === 'Escape') { e.preventDefault(); setQuitando(null) } }}>
                        ¿Quitar?
                        <button type="button" className="peligro" disabled={quitar.isPending} onClick={() => quitar.mutate(it.id)}>Sí</button>
                        <button type="button" className="secundario" autoFocus onClick={() => setQuitando(null)}>No</button>
                      </span>
                    ) : (
                      <>
                        <button type="button" className="icono-accion" title="Editar" aria-label={`Editar la línea ${codigo}`} onClick={() => abrirFila(it, 'cantidad')}>
                          <Icono nombre="editar" />
                        </button>
                        <button type="button" className="enlace" disabled={quitar.isPending} onClick={() => pedirQuitar(it)}>Quitar</button>
                      </>
                    ))}
                  </td>
                </tr>
              )
            })}
            {i.items.length === 0 && <tr><td colSpan={8} className="vacio">Escanee el primer producto.</td></tr>}
          </tbody>
        </table>
        </div>

        <div className="acciones">
          {borrador ? (
            <button type="button" className="primario" disabled={i.items.length === 0 || confirmar.isPending} onClick={() => confirmar.mutate()}>
              Confirmar ingreso y crear unidades
            </button>
          ) : (
            <>
              {/* RF-28: única vía para cambiar un ingreso confirmado (auditada). */}
              <button type="button" className="secundario" onClick={() => { setCorregido(null); setCorrigiendo(true) }}>Corregir</button>
              <Link className="boton primario" to={`/logistica/etiquetas?ingreso=${i.id}`}>Imprimir etiquetas</Link>
            </>
          )}
        </div>
      </div>

      {!borrador && (i.correcciones?.length ?? 0) > 0 && <HistorialCorrecciones correcciones={i.correcciones ?? []} />}

      {condiciones && (
        <DialogoCondiciones
          ingreso={i}
          onGuardado={(nuevo) => {
            actualizar(nuevo)
            setCondiciones(false)
            avisos.limpiar()
            requestAnimationFrame(() => inputRef.current?.focus())
          }}
          onCerrar={() => setCondiciones(false)}
        />
      )}

      {creando !== null && i.proveedor && (
        // S-25 / S-24: el diálogo del ingreso pregunta si el código es de barras o de referencia y
        // avisa coincidencias en otros proveedores (la ficha general de Productos no lo hace).
        <DialogoReferencia
          codigo={creando}
          proveedor={i.proveedor}
          onCreada={() => { setCreando(null); escanear.mutate({ codigo: creando }) }}
          onCerrar={() => setCreando(null)}
        />
      )}
    </div>
  )
}

/**
 * Una línea del ingreso en edición: Cant., Costo, Desc. % e IVA % a la vez. Tab recorre los
 * campos sin guardar; Enter guarda la fila completa; Esc la cancela. Salir de la fila (clic en
 * otra o fuera) guarda si es válida y cambió, o la descarta. El error del backend queda en la fila.
 */
function FilaEnEdicion({ it, fila, onCambiar, onGuardar, onCancelar, onSalir }: {
  it: IngresoItem
  fila: Fila
  onCambiar: (campo: Campo, valor: string) => void
  onGuardar: () => void
  onCancelar: () => void
  onSalir: () => void
}) {
  const filaRef = useRef<HTMLTableRowElement>(null)
  const campos = useRef<Partial<Record<Campo, HTMLInputElement | HTMLSelectElement | null>>>({})
  const errores = erroresDe(fila)
  const base = `linea-${it.id}`
  const ivas = IVAS.includes(Number(fila.originales.iva_porcentaje)) ? IVAS : [...IVAS, Number(fila.originales.iva_porcentaje)]

  // El foco empieza en el valor que se tocó, con el texto seleccionado.
  useEffect(() => {
    const el = campos.current[fila.foco]
    el?.focus()
    if (el instanceof HTMLInputElement) el.select()
  }, [fila.foco])

  // Tras un error (de validación o del backend), el foco vuelve al primer campo con problema.
  useEffect(() => {
    if (!fila.intentado || fila.guardando) return
    const primero = CAMPOS.find((c) => errores[c]) ?? (fila.errorServidor ? fila.foco : undefined)
    if (primero) campos.current[primero]?.focus()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fila.intentado, fila.errorServidor, fila.guardando])

  const mostrar = (c: Campo) => (fila.intentado || fila.valores[c] !== fila.originales[c] ? errores[c] : null)

  const alPulsar = (e: KeyboardEvent<HTMLTableRowElement>) => {
    if (e.key === 'Enter' && !(e.target instanceof HTMLButtonElement)) { e.preventDefault(); onGuardar() }
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); onCancelar() }
  }

  // Salir de la fila: el foco no quedó dentro (otra fila, fuera de la tabla, otro control).
  const alSalirDelFoco = () => setTimeout(() => {
    if (!document.hasFocus()) return // cambiar de ventana no cuenta como salir
    if (filaRef.current?.contains(document.activeElement)) return
    onSalir()
  }, 0)

  const numero = (c: Campo, modo: 'numeric' | 'decimal') => {
    const error = mostrar(c)
    const comunes = {
      ref: (el: HTMLInputElement | null) => { campos.current[c] = el },
      'aria-label': `${REGLAS[c].etiqueta} de ${fila.codigo}`,
      'aria-invalid': error !== null,
      'aria-describedby': error ? `${base}-${c}-error` : undefined,
      value: fila.valores[c],
      disabled: fila.guardando,
      onFocus: (e: { target: HTMLInputElement }) => e.target.select(),
      onChange: (e: { target: { value: string } }) => onCambiar(c, e.target.value),
    }
    return (
      <div className="campo-linea">
        {/* S-67: el costo se escribe con separador de miles. */}
        {c === 'costo_unitario' ? <CampoMoneda {...comunes} /> : <input inputMode={modo} autoComplete="off" {...comunes} />}
        {error && <small id={`${base}-${c}-error`} className="ayuda error">{error}</small>}
      </div>
    )
  }

  return (
    <>
      <tr ref={filaRef} className="fila-edicion" onKeyDown={alPulsar} onBlur={alSalirDelFoco}>
        <td data-label="Referencia">{fila.codigo} <small>{it.referencia?.descripcion}</small></td>
        <td data-label="Cant.">{numero('cantidad', 'numeric')}</td>
        <td data-label="Costo">{numero('costo_unitario', 'decimal')}</td>
        <td data-label="Desc. %">{numero('descuento_porcentaje', 'decimal')}</td>
        <td data-label="IVA %">
          <div className="campo-linea">
            <select
              ref={(el) => { campos.current.iva_porcentaje = el }}
              aria-label={`IVA % de ${fila.codigo}`}
              value={fila.valores.iva_porcentaje}
              disabled={fila.guardando}
              onChange={(e) => onCambiar('iva_porcentaje', e.target.value)}
            >
              {ivas.map((v) => <option key={v} value={String(v)}>{v === 0 ? '0 % · sin IVA' : `${formatoNumero(String(v))} %`}</option>)}
            </select>
          </div>
        </td>
        <td data-label="Neto">{it.neto === null ? '—' : pesos(it.neto)}</td>
        <td data-label="IVA">{it.iva === null ? '—' : pesos(it.iva)}</td>
        <td data-label="" className="acciones-linea">
          <button type="button" className="primario" disabled={fila.guardando} onClick={onGuardar}>{fila.guardando ? 'Guardando…' : 'Guardar'}</button>
          <button type="button" className="secundario" disabled={fila.guardando} onClick={onCancelar}>Cancelar</button>
        </td>
      </tr>
      {fila.errorServidor && (
        <tr className="fila-error">
          <td colSpan={8} role="alert">{fila.errorServidor}</td>
        </tr>
      )}
    </>
  )
}

/** Candado pequeño de los datos fijos (decorativo: el texto ya dice que es fijo). */
function Candado() {
  return (
    <svg className="candado" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
      <rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" />
    </svg>
  )
}

type TipoCodigo = 'barras' | 'referencia'
const TIPOS_CODIGO: OpcionSegmento<TipoCodigo>[] = [
  { valor: 'barras', texto: 'Código de barras o QR' },
  { valor: 'referencia', texto: 'Referencia del proveedor' },
]

/**
 * RF-20: crea la referencia que no existe con el código leído y el proveedor del ingreso (ambos
 * fijos). Precio de venta > 0 (S-20). Calidad opcional (S-23). El IVA de venta no se pide: el
 * backend lo deja en 19 % (S-15). S-24: logística también puede crear la categoría (sin código universal).
 */
function DialogoReferencia({ codigo, proveedor, onCreada, onCerrar }: {
  codigo: string
  proveedor: Opcion
  onCreada: () => void
  onCerrar: () => void
}) {
  const categorias = useCategorias()
  const campoCategoria = useRef<HTMLSelectElement>(null)
  const campoCalidad = useRef<HTMLSelectElement>(null)
  const [f, setF] = useState({ calidad: '', categoria_id: '', descripcion: '', precio_venta: '', codigo_universal_id: '' })
  // S-25: el código leído puede ser el de barras del producto o la referencia del proveedor.
  // De 8 a 14 dígitos se toma como código de barras; si no, como referencia.
  const [tipo, setTipo] = useState<TipoCodigo>(/^\d{8,14}$/.test(codigo) ? 'barras' : 'referencia')
  /** El otro código (el que no se leyó), opcional; y el que se consulta al salir del campo. */
  const [otro, setOtro] = useState('')
  const [otroConsultado, setOtroConsultado] = useState('')
  useBloquearScroll()
  // El foco empieza en Categoría también si algo lo movió durante el montaje.
  useEffect(() => {
    const cuadro = requestAnimationFrame(() => campoCategoria.current?.focus())
    return () => cancelAnimationFrame(cuadro)
  }, [])
  const categoria = categorias.data?.find((c) => String(c.id) === f.categoria_id)
  const admiteUniversal = categoria?.admite_codigo_universal ?? false

  const crear = useMutation({
    mutationFn: async () => api.post('/referencias', {
      // Leído como código de barras: la referencia es opcional (vacía = el mismo código, S-25).
      codigo_referencia: tipo === 'referencia' ? codigo : otro.trim(),
      codigo_fabricante: tipo === 'barras' ? codigo : otro.trim() || null,
      proveedor_id: proveedor.id,
      calidad: f.calidad || null,
      categoria_id: Number(f.categoria_id),
      descripcion: f.descripcion.trim(),
      precio_venta: leerNumero(f.precio_venta), // S-67: sin el formato
      codigo_universal_id: admiteUniversal && f.codigo_universal_id ? Number(f.codigo_universal_id) : null,
    }),
    onSuccess: onCreada,
  })

  const errorPrecio = f.precio_venta === '' ? null : (leerNumero(f.precio_venta) ?? 0) > 0 ? null : 'El precio de venta debe ser mayor que 0.'
  const completo = f.categoria_id !== '' && f.descripcion.trim() !== '' && f.precio_venta !== '' && errorPrecio === null
  const campo = (k: keyof typeof f) => ({ value: f[k], onChange: (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value }) })

  return (
    <div className="modal" onKeyDown={(e) => { if (e.key === 'Escape') onCerrar(); else atraparTab(e) }}>
      <form className="modal-caja" role="dialog" aria-modal="true" aria-labelledby="crear-referencia-titulo" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
        <h2 id="crear-referencia-titulo">Crear referencia</h2>
        {/* Datos fijos: no son campos (no se enfocan ni se editan). */}
        <dl className="datos-fijos">
          <div>
            <dt>Código leído</dt>
            <dd><Candado />{codigo}<span className="solo-lector">, fijado por el escaneo</span></dd>
          </div>
          <div>
            <dt>Proveedor</dt>
            <dd><Candado />{proveedor.nombre}<span className="solo-lector">, fijado por el ingreso</span></dd>
          </div>
        </dl>
        <p className="ayuda">El código no existe para este proveedor. Se crea para el proveedor de este ingreso, con el código escaneado, y al guardarla se agrega la línea.</p>
        <ControlSegmentado
          id="tipo-codigo-leido"
          etiqueta="¿Qué es el código leído?"
          opciones={TIPOS_CODIGO}
          valor={tipo}
          onCambio={(nuevo) => { setTipo(nuevo); setOtro(''); setOtroConsultado('') }}
        />
        <div className="codigos-referencia">
          {/* El campo del código leído es un dato fijo (no se enfoca); el otro, opcional y editable. */}
          {tipo === 'referencia' ? (
            <div className="codigo-fijo">
              <span className="etiqueta-campo">Referencia del proveedor</span>
              <span className="valor-fijo"><Candado />{codigo}<span className="solo-lector">, el código leído</span></span>
            </div>
          ) : (
            <>
              <label>Referencia del proveedor (opcional)
                <input maxLength={80} value={otro} autoComplete="off" aria-describedby="referencia-vacia-ayuda" onChange={(e) => setOtro(e.target.value)} onBlur={() => setOtroConsultado(otro)} />
              </label>
              <p id="referencia-vacia-ayuda" className="ayuda">Si el producto no trae otra referencia, déjela vacía y se usará el código de barras.</p>
            </>
          )}
          {tipo === 'barras' ? (
            <div className="codigo-fijo">
              <span className="etiqueta-campo">Código de barras o QR del producto</span>
              <span className="valor-fijo"><Candado />{codigo}<span className="solo-lector">, el código leído</span></span>
            </div>
          ) : (
            <label>Código de barras o QR del producto (si lo trae)
              <input maxLength={120} value={otro} autoComplete="off" onChange={(e) => setOtro(e.target.value)} onBlur={() => setOtroConsultado(otro)} />
            </label>
          )}
        </div>
        <AvisoCoincidencias codigos={[codigo, otroConsultado]} proveedorId={proveedor.id} />
        <SelectorCategoria
          selectRef={campoCategoria}
          autoFocus
          value={f.categoria_id}
          onChange={(categoria_id) => setF((v) => ({ ...v, categoria_id, codigo_universal_id: '' }))}
          alElegir={() => campoCalidad.current?.focus()}
        />
        <label>Calidad (opcional)
          <select ref={campoCalidad} {...campo('calidad')}>
            <option value="">—</option>
            <option value="fino">Fino</option>
            <option value="generico">Genérico</option>
          </select>
        </label>
        {admiteUniversal ? (
          // S-59: logística elige o crea el universal en línea, sin salir de la factura.
          <SelectorUniversal
            etiqueta="Código universal (opcional)"
            categoriaId={f.categoria_id}
            value={f.codigo_universal_id}
            onChange={(codigo_universal_id) => setF((v) => ({ ...v, codigo_universal_id }))}
          />
        ) : f.categoria_id !== '' && (
          <p className="ayuda">Esta categoría no usa código universal. Si este repuesto debería unificarse (regulador, portadiodo u otro que el administrador haya marcado), pídale que active esa opción en Catálogos.</p>
        )}
        <label>Descripción<input required maxLength={255} {...campo('descripcion')} /></label>
        <label>Precio de venta
          <CampoMoneda required aria-invalid={errorPrecio !== null} {...campo('precio_venta')} />
        </label>
        <p className={errorPrecio ? 'ayuda error' : 'ayuda'}>{errorPrecio ?? 'Con IVA incluido. Mayor que 0. Si no lo conoce, consúltelo con el administrador antes de crearla.'}</p>
        {crear.isError && <div className="aviso error">{mensajeError(crear.error)}</div>}
        <div className="acciones">
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button className="primario" disabled={!completo || crear.isPending}>{crear.isPending ? 'Guardando…' : 'Crear y agregar'}</button>
        </div>
      </form>
    </div>
  )
}

/**
 * RF-16 / RF-09: forma de pago, vencimiento y descuento del documento de un borrador.
 * A crédito, el vencimiento se prellena con fecha + plazo del proveedor y se corrige con el que
 * trae el documento. Las reglas (obligatorio, no antes de la fecha, descuento ≤ subtotal) las
 * valida el backend; sus mensajes salen junto a cada campo.
 */
function DialogoCondiciones({ ingreso, onGuardado, onCerrar }: {
  ingreso: Ingreso
  onGuardado: (ingreso: Ingreso) => void
  onCerrar: () => void
}) {
  const plazo = ingreso.proveedor?.plazo_dias ?? null
  const [forma, setForma] = useState<FormaPago>(ingreso.forma_pago)
  const [vence, setVence] = useState(ingreso.vence_el ?? '')
  const porcentaje = ingreso.descuento_documento_porcentaje
  const [modo, setModo] = useState<ModoDescuento>(porcentaje !== null ? '%' : '$')
  // S-67: en pesos se escribe con separador de miles; en % queda como número simple.
  const [descuento, setDescuento] = useState(porcentaje !== null ? String(Number(porcentaje)) : formatoNumero(ingreso.descuento_documento))
  const cambiarModo = (nuevo: ModoDescuento) => {
    if (nuevo === modo) return
    const n = nuevo === '%' ? leerNumero(descuento) : (descuento.trim() === '' ? null : Number(descuento))
    setModo(nuevo)
    setDescuento(n === null || Number.isNaN(n) ? '' : nuevo === '%' ? String(n) : formatoNumero(n))
  }

  const cambiarForma = (nueva: FormaPago) => {
    setForma(nueva)
    // Prellenado: solo sugiere; el usuario lo corrige con la fecha del documento.
    if (nueva === 'credito' && vence === '' && plazo !== null) setVence(sumarDias(ingreso.fecha, plazo))
  }

  const guardar = useMutation({
    mutationFn: async () => (await api.patch<Ingreso>(`/ingresos/${ingreso.id}`, {
      forma_pago: forma,
      vence_el: forma === 'credito' ? vence || null : null,
      // S-22: uno u otro, nunca los dos.
      [modo === '%' ? 'descuento_documento_porcentaje' : 'descuento_documento']: descuento === '' ? 0 : modo === '$' ? leerNumero(descuento) : descuento,
    })).data,
    onSuccess: onGuardado,
  })
  const errores = guardar.isError ? erroresCampo(guardar.error) : {}
  const general = guardar.isError && Object.keys(errores).length === 0 ? mensajeError(guardar.error) : null
  useAvisoTexto(general)

  return (
    <Dialogo
      titulo="Condiciones del documento"
      onCerrar={onCerrar}
      pendiente={guardar.isPending}
      onEnviar={() => guardar.mutate()}
      pie={(
        <>
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button type="submit" className="primario" disabled={guardar.isPending}>{guardar.isPending ? 'Guardando…' : 'Guardar'}</button>
        </>
      )}
    >
      <DialogoSeccion titulo="Pago">
        <label>Forma de pago
          <select autoFocus value={forma} onChange={(e) => cambiarForma(e.target.value as FormaPago)}>
            <option value="contado">Contado</option>
            <option value="credito">Crédito</option>
          </select>
        </label>
        {forma === 'credito' && (
          <label>Vence el
            <input type="date" aria-invalid={!!errores.vence_el} aria-describedby="vence-ayuda" value={vence} onChange={(e) => setVence(e.target.value)} />
            <small id="vence-ayuda" className={errores.vence_el ? 'ayuda error' : 'ayuda'}>
              {errores.vence_el ?? (plazo !== null
                ? `Sugerido: fecha del documento + ${plazo} días del proveedor. Corríjalo con el que trae el documento.`
                : 'Escriba el vencimiento que trae el documento.')}
            </small>
          </label>
        )}
      </DialogoSeccion>
      <DialogoSeccion titulo="Descuento del documento">
        <CampoDescuento
          id="descuento-condiciones"
          modo={modo}
          valor={descuento}
          error={errores.descuento_documento ?? errores.descuento_documento_porcentaje}
          onModo={cambiarModo}
          onValor={setDescuento}
        />
        <small className="ayuda">Se reparte entre todas las líneas antes del IVA. Si todavía no hay líneas, se guarda y se aplica cuando las agregue.</small>
      </DialogoSeccion>
    </Dialogo>
  )
}

/* ───────────────── RF-28 (S-58, S-60, S-61): corrección de un ingreso confirmado ───────────────── */

interface LineaCorr {
  clave: string
  /** null = línea nueva. */
  id: number | null
  referencia_id: number
  codigo: string
  descripcion: string
  valores: Record<Campo, string>
  quitada: boolean
}

let secuenciaLineaNueva = 0

function lineasIniciales(i: Ingreso): LineaCorr[] {
  return i.items
    .filter((it) => it.cantidad > 0) // las ya quitadas (cantidad 0) quedan como están
    .map((it) => ({
      clave: `i${it.id}`,
      id: it.id,
      referencia_id: it.referencia_id,
      codigo: it.referencia?.codigo_referencia ?? '',
      descripcion: it.referencia?.descripcion ?? '',
      valores: valoresDe(it),
      quitada: false,
    }))
}

/**
 * RF-28: modo de edición de un ingreso confirmado. Todo queda en estado local hasta confirmar:
 * encabezado, líneas (cantidad, costo, descuento %, IVA %), agregar escaneando o buscando y quitar.
 * "Revisar cambios" pide la vista previa (sin escritura); luego se escribe el motivo y se confirma.
 * S-61: una remisión cubierta por una factura solo corrige número y fecha.
 */
function PanelCorreccion({ ingreso, onCancelar, onCorregido }: {
  ingreso: Ingreso
  onCancelar: () => void
  onCorregido: (r: { ingreso: Ingreso; correccion: CorreccionIngreso }) => void
}) {
  const soloEncabezado = ingreso.tipo === 'remision' && ingreso.factura !== null
  const [enc, setEnc] = useState({
    numero_documento: ingreso.numero_documento,
    fecha: ingreso.fecha.slice(0, 10),
    forma_pago: ingreso.forma_pago,
    vence_el: ingreso.vence_el?.slice(0, 10) ?? '',
    descuento: formatoNumero(ingreso.descuento_documento),
  })
  const [lineas, setLineas] = useState<LineaCorr[]>(() => lineasIniciales(ingreso))
  const [opciones, setOpciones] = useState<Referencia[] | null>(null)
  const [avisoLector, setAvisoLector] = useState<string | null>(null)
  const [vista, setVista] = useState<VistaPreviaCorreccion | null>(null)
  const lector = useRef<HTMLInputElement>(null)

  const credito = enc.forma_pago === 'credito'
  const erroresLinea = (l: LineaCorr): Partial<Record<Campo, string | null>> =>
    (l.quitada ? {} : Object.fromEntries(CAMPOS.map((c) => [c, REGLAS[c].validar(leerNumero(l.valores[c]))])))
  const descuento = leerNumero(enc.descuento)
  const hayErrores = lineas.some((l) => Object.values(erroresLinea(l)).some(Boolean))
    || lineas.every((l) => l.quitada)
    || enc.numero_documento.trim() === ''
    || (credito && enc.vence_el === '')
    || (!soloEncabezado && descuento !== null && Number.isNaN(descuento))

  const cuerpo = (): Record<string, unknown> => {
    const base: Record<string, unknown> = { numero_documento: enc.numero_documento.trim(), fecha: enc.fecha }
    if (soloEncabezado) return base
    const items: LineaCorreccionEnvio[] = lineas.filter((l) => !l.quitada).map((l) => ({
      ...(l.id !== null ? { id: l.id } : { referencia_id: l.referencia_id }),
      cantidad: leerNumero(l.valores.cantidad) ?? 0,
      costo_unitario: leerNumero(l.valores.costo_unitario) ?? 0,
      descuento_porcentaje: leerNumero(l.valores.descuento_porcentaje) ?? 0,
      iva_porcentaje: leerNumero(l.valores.iva_porcentaje) ?? 0,
    }))
    return { ...base, forma_pago: enc.forma_pago, vence_el: credito ? enc.vence_el : null, descuento_documento: descuento ?? 0, items }
  }

  const revisar = useMutation({
    mutationFn: async () => (await api.post<VistaPreviaCorreccion>(`/ingresos/${ingreso.id}/correcciones/vista-previa`, cuerpo())).data,
    onSuccess: setVista,
  })

  const cambiarEnc = (k: keyof typeof enc, v: string) => { revisar.reset(); setEnc((e) => ({ ...e, [k]: v })) }
  const cambiarValor = (clave: string, campo: Campo, valor: string) => {
    revisar.reset()
    setLineas((ls) => ls.map((l) => (l.clave === clave ? { ...l, valores: { ...l.valores, [campo]: valor } } : l)))
  }
  /** Una línea existente se marca quitada (se puede deshacer); una nueva se elimina de la lista. */
  const alternarQuitada = (clave: string) => {
    revisar.reset()
    setLineas((ls) => ls.flatMap((l) => (l.clave !== clave ? [l] : l.id === null ? [] : [{ ...l, quitada: !l.quitada }])))
  }

  /** Agrega una referencia del proveedor: si ya hay una línea vigente suya, suma 1. */
  const agregar = (r: Referencia) => {
    revisar.reset()
    setOpciones(null)
    setAvisoLector(null)
    setLineas((ls) => {
      const existente = ls.find((l) => l.referencia_id === r.id && !l.quitada)
      if (existente) {
        const n = (leerNumero(existente.valores.cantidad) ?? 0) + 1
        return ls.map((l) => (l === existente ? { ...l, valores: { ...l.valores, cantidad: String(n) } } : l))
      }
      secuenciaLineaNueva += 1
      return [...ls, {
        clave: `n${secuenciaLineaNueva}`,
        id: null,
        referencia_id: r.id,
        codigo: r.codigo_referencia,
        descripcion: r.descripcion,
        valores: { cantidad: '1', costo_unitario: '', descuento_porcentaje: '0', iva_porcentaje: String(Number(r.iva_venta_porcentaje ?? 0)) },
        quitada: false,
      }]
    })
    lector.current?.focus()
  }

  /** Escaneado o escrito: busca entre las referencias activas del proveedor; exacto agrega, si no ofrece opciones. */
  const buscar = async (codigo: string) => {
    if (lector.current) lector.current.value = ''
    const texto = codigo.trim()
    if (texto === '') return
    try {
      const encontradas = (await api.get<Pagina<Referencia>>('/referencias', { params: { proveedor_id: ingreso.proveedor_id, q: texto, por_pagina: 10 } })).data.data
      const exactas = encontradas.filter((r) => [r.codigo_referencia, r.codigo_fabricante ?? ''].some((c) => c.toUpperCase() === texto.toUpperCase()))
      if (exactas.length === 1) agregar(exactas[0])
      else if (encontradas.length > 0) { setOpciones(exactas.length > 1 ? exactas : encontradas); setAvisoLector(null) }
      else setAvisoLector(`${texto} no es una referencia activa de ${ingreso.proveedor?.nombre ?? 'este proveedor'}.`)
    } catch (e) {
      setAvisoLector(mensajeError(e))
    }
  }
  const scanner = useScanner((codigo) => { void buscar(codigo) })

  return (
    <div className="panel panel-correccion">
      <div className="fila-titulo">
        <h2>Corregir {ingreso.tipo === 'factura' ? 'factura' : 'remisión'} {ingreso.numero_documento}</h2>
      </div>
      <div className="aviso atencion" role="note">La corrección queda auditada y el administrador puede verla.</div>
      {soloEncabezado && (
        <p className="ayuda">Esta remisión está cubierta por la factura {ingreso.factura?.numero_documento}: solo se corrigen el número y la fecha. Sus cantidades y costos se corrigen en la factura.</p>
      )}

      <div className="formulario en-linea encabezado-correccion">
        <label>Número<input required maxLength={60} value={enc.numero_documento} onChange={(e) => cambiarEnc('numero_documento', e.target.value)} /></label>
        <label>Fecha<input type="date" required value={enc.fecha} onChange={(e) => cambiarEnc('fecha', e.target.value)} /></label>
        {!soloEncabezado && (
          <>
            <label>Forma de pago
              <select value={enc.forma_pago} onChange={(e) => cambiarEnc('forma_pago', e.target.value)}>
                <option value="contado">Contado</option>
                <option value="credito">Crédito</option>
              </select>
            </label>
            {credito && <label>Vence el<input type="date" required min={enc.fecha} value={enc.vence_el} onChange={(e) => cambiarEnc('vence_el', e.target.value)} /></label>}
            <label>Descuento del documento ($)<input inputMode="decimal" value={enc.descuento} onChange={(e) => cambiarEnc('descuento', e.target.value)} /></label>
          </>
        )}
      </div>

      {!soloEncabezado && (
        <>
          <input
            ref={lector}
            className="escaner"
            placeholder="Agregar línea: escanee el código o escriba la referencia y pulse Enter"
            aria-label="Agregar línea por código o búsqueda"
            onKeyDown={scanner.onKeyDown}
            onPaste={scanner.onPaste}
            autoComplete="off"
          />
          {avisoLector && <div className="aviso error" role="alert">{avisoLector}</div>}
          {opciones && (
            <div className="aviso atencion eleccion-codigo" role="group" aria-label="Elija la referencia">
              <p>Elija la referencia:</p>
              <div className="opciones-codigo">
                {opciones.map((r, n) => (
                  <button key={r.id} type="button" className="secundario" autoFocus={n === 0} onClick={() => agregar(r)}>
                    <strong>{r.codigo_referencia}</strong><small> · {r.descripcion}</small>
                  </button>
                ))}
                <button type="button" className="enlace" onClick={() => setOpciones(null)}>Cancelar</button>
              </div>
            </div>
          )}
        </>
      )}

      <div className="tabla-desplazable">
        <table className="tabla-lineas tabla-correccion">
          <thead><tr><th>Referencia</th><th>Cant.</th><th>Costo</th><th>Desc. %</th><th>IVA %</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
          <tbody>
            {lineas.map((l) => {
              const errores = erroresLinea(l)
              return (
                <tr key={l.clave} className={l.quitada ? 'reversado' : ''}>
                  <td data-label="Referencia">
                    {l.codigo} <small>{l.descripcion}</small>
                    {l.id === null && <span className="chip corregida">Nueva</span>}
                  </td>
                  {CAMPOS.map((c) => (
                    <td key={c} data-label={REGLAS[c].etiqueta}>
                      {soloEncabezado || l.quitada ? (
                        <span>{l.valores[c] || '—'}</span>
                      ) : c === 'iva_porcentaje' ? (
                        <select aria-label={`${REGLAS[c].etiqueta} de ${l.codigo}`} value={l.valores[c]} onChange={(e) => cambiarValor(l.clave, c, e.target.value)}>
                          {[...new Set([...IVAS.map(String), l.valores[c]])].map((v) => <option key={v} value={v}>{v} %</option>)}
                        </select>
                      ) : (
                        <>
                          <input
                            className="celda-numero"
                            inputMode={c === 'cantidad' ? 'numeric' : 'decimal'}
                            aria-label={`${REGLAS[c].etiqueta} de ${l.codigo}`}
                            aria-invalid={!!errores[c]}
                            value={l.valores[c]}
                            onChange={(e) => cambiarValor(l.clave, c, e.target.value)}
                          />
                          {errores[c] && <small className="ayuda error">{errores[c]}</small>}
                        </>
                      )}
                    </td>
                  ))}
                  <td data-label="" className="acciones-linea">
                    {!soloEncabezado && (
                      <button type="button" className="enlace" onClick={() => alternarQuitada(l.clave)}>{l.quitada ? 'Deshacer' : 'Quitar'}</button>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {revisar.isError && <div className="aviso error" role="alert">{mensajeError(revisar.error)}</div>}
      <div className="acciones">
        <button type="button" className="secundario" onClick={onCancelar}>Cancelar</button>
        <button type="button" className="primario" disabled={hayErrores || revisar.isPending} onClick={() => revisar.mutate()}>
          {revisar.isPending ? 'Revisando…' : 'Revisar cambios'}
        </button>
      </div>

      {vista && (
        <DialogoConfirmarCorreccion
          ingresoId={ingreso.id}
          vista={vista}
          cuerpo={cuerpo()}
          onCerrar={() => setVista(null)}
          onCorregido={(r) => { setVista(null); onCorregido(r) }}
        />
      )}
    </div>
  )
}

/** RF-28: resumen de la vista previa, motivo y confirmación (con etiquetas impresas y diferencia si aplica). */
function DialogoConfirmarCorreccion({ ingresoId, vista, cuerpo, onCerrar, onCorregido }: {
  ingresoId: number
  vista: VistaPreviaCorreccion
  cuerpo: Record<string, unknown>
  onCerrar: () => void
  onCorregido: (r: { ingreso: Ingreso; correccion: CorreccionIngreso }) => void
}) {
  const [motivo, setMotivo] = useState('')
  const [etiquetas, setEtiquetas] = useState(false)
  const [aceptar, setAceptar] = useState(false)
  const [motivoDiferencia, setMotivoDiferencia] = useState('')
  const corregir = useMutation({
    mutationFn: async () => (await api.post<{ ingreso: Ingreso; correccion: CorreccionIngreso }>(`/ingresos/${ingresoId}/correcciones`, {
      ...cuerpo,
      motivo: motivo.trim(),
      ...(vista.requiere_confirmar_etiquetas ? { confirmar_etiquetas_impresas: etiquetas } : {}),
      ...(vista.requiere_aceptar_diferencia ? { aceptar_diferencia: aceptar, diferencia_motivo: motivoDiferencia.trim() } : {}),
    })).data,
    onSuccess: onCorregido,
  })
  const errores = corregir.isError ? erroresCampo(corregir.error) : {}
  const bloqueada = vista.errores.length > 0
  const listo = !bloqueada
    && motivo.trim().length >= 5
    && (!vista.requiere_confirmar_etiquetas || etiquetas)
    && (!vista.requiere_aceptar_diferencia || (aceptar && motivoDiferencia.trim() !== ''))
    && !corregir.isPending

  return (
    <Dialogo
      titulo="Revisar la corrección"
      descripcion="Todavía no se ha guardado nada. Revise el efecto y escriba el motivo."
      variante="ancha"
      pendiente={corregir.isPending}
      onCerrar={onCerrar}
      onEnviar={() => { if (listo) corregir.mutate() }}
      pie={(
        <>
          <button type="button" className="secundario" onClick={onCerrar}>Volver a editar</button>
          <button type="submit" className="primario" disabled={!listo}>{corregir.isPending ? 'Guardando…' : 'Confirmar corrección'}</button>
        </>
      )}
    >
      <dl className="datos-fijos">
        <div><dt>Total anterior</dt><dd>{vista.total_anterior === null ? '—' : pesos(vista.total_anterior)}</dd></div>
        <div><dt>Total nuevo</dt><dd><strong>{pesos(vista.total_nuevo)}</strong></dd></div>
        <div><dt>Unidades a crear</dt><dd>{vista.unidades_crear}</dd></div>
        <div><dt>Unidades a retirar</dt><dd>{vista.unidades_retirar}</dd></div>
      </dl>
      {vista.por_referencia.length > 0 && (
        <ul className="lista-cambios">
          {vista.por_referencia.map((r) => (
            <li key={r.referencia_id}>
              <strong>{r.codigo_referencia}</strong>
              {r.crear > 0 && <> · crear {r.crear}</>}
              {r.retirar > 0 && <> · retirar {r.retirar}{r.con_etiqueta_impresa > 0 && ` (${r.con_etiqueta_impresa} con etiqueta impresa)`}</>}
            </li>
          ))}
        </ul>
      )}
      {vista.errores.map((e, n) => <div key={`e${n}`} className="aviso error" role="alert">{e.mensaje}</div>)}
      {vista.advertencias.map((a, n) => <div key={`a${n}`} className="aviso atencion">{a.mensaje}</div>)}
      {vista.requiere_confirmar_etiquetas && (
        <label className="casilla">
          <input type="checkbox" checked={etiquetas} onChange={(e) => setEtiquetas(e.target.checked)} /> Retiraré esas etiquetas de la mercancía
        </label>
      )}
      {vista.requiere_aceptar_diferencia && (
        <>
          <label className="casilla">
            <input type="checkbox" checked={aceptar} onChange={(e) => setAceptar(e.target.checked)} /> Acepto la diferencia con las remisiones
          </label>
          <label>
            <span className="etiqueta-campo">Motivo de la diferencia<span className="obligatorio" aria-hidden="true">*</span></span>
            <input maxLength={500} value={motivoDiferencia} onChange={(e) => setMotivoDiferencia(e.target.value)} />
          </label>
        </>
      )}
      {!bloqueada && (
        <label>
          <span className="etiqueta-campo">Motivo de la corrección<span className="obligatorio" aria-hidden="true">*</span></span>
          <textarea required autoFocus minLength={5} maxLength={500} rows={3} value={motivo} aria-invalid={!!errores.motivo} onChange={(e) => { setMotivo(e.target.value); corregir.reset() }} />
          {errores.motivo && <small className="ayuda error">{errores.motivo}</small>}
        </label>
      )}
      {corregir.isError && !errores.motivo && <div className="aviso error" role="alert">{mensajeError(corregir.error)}</div>}
    </Dialogo>
  )
}

/** RF-28: historial de correcciones del ingreso (más reciente primero), con lo que cambió. */
function HistorialCorrecciones({ correcciones }: { correcciones: CorreccionIngreso[] }) {
  return (
    <section className="panel">
      <h2>Correcciones</h2>
      <ul className="historial-correcciones">
        {correcciones.map((c) => (
          <li key={c.id}>
            <p>
              <strong>{formatearFechaHora(c.created_at)}</strong> · {c.usuario?.name ?? '—'} · {c.motivo}
              <small className="bloque">
                Unidades +{c.unidades_creadas} / −{c.unidades_retiradas} · Total {c.total_anterior === null ? '—' : pesos(c.total_anterior)} → {pesos(c.total_nuevo)}
              </small>
            </p>
            <DetalleCorreccion antes={c.antes} despues={c.despues} />
          </li>
        ))}
      </ul>
    </section>
  )
}
