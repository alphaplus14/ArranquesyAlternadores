import { lazy, memo, Suspense, useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import { useAviso, useAvisoTexto } from '../../componentes/Avisos'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import type { CorreccionIngreso, FormaPago, Ingreso, IngresoItem } from '../../api/tipos'
import { fechaCorta, sumarDias } from '../../componentes/fechas'
import CampoDescuento, { type ModoDescuento } from '../../componentes/CampoDescuento'
import { formatoNumero, leerNumero, pesos } from '../../componentes/numeros'
import CampoMoneda from '../../componentes/CampoMoneda'
import { useScanner } from '../../hooks/useScanner'
import Icono from '../../componentes/Icono'
import BotonCancelar from '../../componentes/BotonCancelar'
import { useAltoLector } from './useAltoLector'
import {
  AYUDA_DESCUENTO_DOCUMENTO,
  AYUDA_DESCUENTO_LINEA,
  CAMPOS,
  IVAS,
  REGLAS,
  cambiosDe,
  erroresDe,
  valoresDe,
  type Campo,
  type Fila,
  type LineaEditable,
} from './lineaIngreso'

const DialogoReferencia = lazy(() => import('./DialogoReferencia'))
const PanelCorreccion = lazy(() => import('./PanelCorreccion').then((m) => ({ default: m.PanelCorreccion })))
const HistorialCorrecciones = lazy(() => import('./PanelCorreccion').then((m) => ({ default: m.HistorialCorrecciones })))

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
  /** Última lectura, en una sola línea: no mueve la tabla. */
  const [ultima, setUltima] = useState<string | null>(null)
  const ordenEscaneo = useRef(0)
  const ordenAplicado = useRef(0)
  const inputRef = useRef<HTMLInputElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  /** RF-28: al cerrar la corrección (cancelar o guardar) el foco vuelve a "Corregir". */
  const botonCorregir = useRef<HTMLButtonElement>(null)
  const terminarCorreccion = () => {
    setCorrigiendo(false)
    requestAnimationFrame(() => botonCorregir.current?.focus())
  }
  const zonaLector = useRef<HTMLDivElement>(null)
  // Las filas memoizadas reciben funciones estables que llaman a la versión vigente.
  const accionesRef = useRef<AccionesLinea | null>(null)
  const acciones = useMemo<AccionesLinea>(() => ({
    abrir: (it, campo) => accionesRef.current?.abrir(it, campo),
    pedirQuitar: (it) => accionesRef.current?.pedirQuitar(it),
    cancelarQuitar: () => accionesRef.current?.cancelarQuitar(),
    quitar: (itemId) => accionesRef.current?.quitar(itemId),
  }), [])

  const ingreso = useQuery({
    queryKey: ['ingreso', id],
    queryFn: async () => (await api.get<Ingreso>(`/ingresos/${id}`)).data,
  })
  const i = ingreso.data
  const borrador = i?.estado === 'borrador'
  // S-22 (borrador): descuento del documento y descuentos de línea son excluyentes; un 0 no cuenta.
  const descuentoBloqueado = !!i && borrador && (Number(i.descuento_documento) > 0 || Number(i.descuento_documento_porcentaje ?? 0) > 0)
  const lineasConDescuento = !!i && borrador && i.items.some((it) => Number(it.descuento_porcentaje) > 0)
  useAltoLector(panelRef, zonaLector, borrador)

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
    ponerFila({ descuentoBloqueado, itemId: it.id, codigo: it.referencia?.codigo_referencia ?? '', foco, valores, originales: valores, intentado: false, errorServidor: null, guardando: false })
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
      const campos = erroresCampo(e)
      const mensaje = campos.descuento_porcentaje ?? mensajeError(e)
      if (actual?.itemId === f.itemId) ponerFila({ ...actual, foco, guardando: false, intentado: true, errorServidor: mensaje })
      else avisos.mostrar('error', `No se guardó la línea ${f.codigo}: ${mensaje}`)
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
    // RF-20: el POST ya trae el ingreso (líneas y totales). No se vuelve a pedir el documento.
    mutationFn: async ({ codigo, referenciaId }: { codigo: string; referenciaId?: number }) => {
      const orden = ++ordenEscaneo.current
      const data = (await api.post<Ingreso & { item_escaneado_id: number }>(`/ingresos/${id}/escanear`, { codigo, referencia_id: referenciaId })).data
      const { item_escaneado_id: itemId, ...documento } = data
      return { documento, orden, itemId }
    },
    onSuccess: ({ documento, orden, itemId }) => {
      if (orden < ordenAplicado.current) return
      ordenAplicado.current = orden
      actualizar(documento)
      const linea = documento.items.find((it) => it.id === itemId)
      if (!linea) return
      const sinCosto = Number(linea.costo_unitario) <= 0
      // El foco sale del lector solo si falta el costo. Con costo, la ráfaga sigue.
      if (sinCosto) abrirFila(linea, 'costo_unitario')
      const texto = `${linea.referencia?.codigo_referencia} · ${linea.referencia?.descripcion} — cantidad ${linea.cantidad}`
      setUltima(sinCosto ? `${texto} · escriba el costo` : texto)
      avisos.mostrar(
        sinCosto ? 'atencion' : 'ok',
        `✓ ${texto}${sinCosto ? ' · escriba el costo en la tabla' : ''}`,
        undefined,
        5000, // S-65: el aviso del ítem agregado se va solo a los 5 s, también si falta el costo
      )
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


  /** Quitar: directo con cantidad 1 y sin costo; si no, confirmación breve en la fila. */
  const pedirQuitar = (it: IngresoItem) => {
    if (it.cantidad > 1 || Number(it.costo_unitario) > 0) setQuitando(it.id)
    else quitar.mutate(it.id)
  }
  accionesRef.current = { abrir: abrirFila, pedirQuitar, cancelarQuitar: () => setQuitando(null), quitar: (itemId) => quitar.mutate(itemId) }

  return (
    // RF-28: en corrección, un solo scroll (el de la página); ver .detalle-compra.corrigiendo en index.css.
    <div className={`pila detalle-compra${corrigiendo ? ' corrigiendo' : ''}`}>
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
        <Suspense fallback={<div className="panel">Cargando…</div>}>
          <PanelCorreccion
            ingreso={i}
            onCancelar={terminarCorreccion}
            onCorregido={(r) => {
              actualizar(r.ingreso)
              setCorregido(r.correccion)
              terminarCorreccion()
              qc.invalidateQueries({ queryKey: ['ingresos'] })
              qc.invalidateQueries({ queryKey: ['ingreso', String(i.id), 'unidades'] })
            }}
          />
        </Suspense>
      )}
      <div ref={panelRef} className={borrador ? 'panel ingreso-con-barra' : 'panel'} hidden={corrigiendo}>
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

        {!borrador && <CifrasIngreso ingreso={i} borrador={false} />}

        {borrador && (
          <div ref={zonaLector} className="ingreso-lector">
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
            <p className="ultima-lectura" aria-live="polite">{ultima ?? ''}</p>
            <details className="ayuda-lector">
              <summary>Cómo se agrega una línea</summary>
              <p className="ayuda">Se busca entre las referencias de {i.proveedor?.nombre}. Leer el mismo código otra vez suma 1. Haga clic en un valor o en el lápiz para editar la línea: Tab recorre, Enter guarda y Esc cancela.</p>
            </details>
            {ambiguo && <EleccionAmbigua ambiguo={ambiguo} onElegir={(o) => { setAmbiguo(null); escanear.mutate({ codigo: ambiguo.codigo, referenciaId: o.id }) }} onCancelar={() => setAmbiguo(null)} />}
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
        <div className="tabla-desplazable ingreso-tabla">
        <table ref={tablaRef} className="tabla-lineas">
          <thead><tr><th>Referencia</th><th>Cant.</th><th>Costo</th><th>Desc. %</th><th>IVA %</th><th>Neto</th><th>IVA</th><th className="encabezado-normal">acciones</th></tr></thead>
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
              return (
                <FilaLinea
                  key={it.id}
                  it={it}
                  borrador={borrador}
                  descuentoBloqueado={descuentoBloqueado}
                  confirmandoQuitar={quitando === it.id}
                  quitandoPendiente={quitar.isPending}
                  acciones={acciones}
                />
              )
            })}
            {i.items.length === 0 && <tr><td colSpan={8} className="vacio">Escanee el primer producto.</td></tr>}
          </tbody>
        </table>
        </div>

        {borrador && i.totales && (
          <details className="desglose-compra">
            <summary>Ver desglose</summary>
            <CifrasIngreso ingreso={i} borrador />
          </details>
        )}

        {borrador ? (
          // Barra fija al pie del área visible: con muchas líneas el botón sigue a la vista.
          <div className="ingreso-barra">
            <span className="ingreso-barra-resumen">
              {i.items.length} {i.items.length === 1 ? 'línea' : 'líneas'}
              {i.totales && <> · Total {pesos(i.totales.total_visible)}</>}
            </span>
            <div className="ingreso-barra-acciones">
              <button type="button" className="primario" disabled={i.items.length === 0 || confirmar.isPending} onClick={() => confirmar.mutate()}>
                Confirmar ingreso y crear unidades
              </button>
            </div>
          </div>
        ) : (
          <div className="acciones">
            {/* RF-28: única vía para cambiar un ingreso confirmado (auditada). */}
            <button ref={botonCorregir} type="button" className="secundario" onClick={() => { setCorregido(null); setCorrigiendo(true) }}>Corregir</button>
            <Link className="boton primario" to={`/logistica/etiquetas?ingreso=${i.id}`}>Imprimir etiquetas</Link>
          </div>
        )}
      </div>

      {!borrador && (i.correcciones?.length ?? 0) > 0 && (
        <Suspense fallback={null}>
          <HistorialCorrecciones correcciones={i.correcciones ?? []} />
        </Suspense>
      )}

      {condiciones && (
        <DialogoCondiciones
          ingreso={i}
          descuentoBloqueado={lineasConDescuento}
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
        <Suspense fallback={<div className="panel">Cargando…</div>}>
          <DialogoReferencia
            codigo={creando}
            proveedor={i.proveedor}
            onCreada={() => { setCreando(null); escanear.mutate({ codigo: creando }) }}
            onCerrar={() => setCreando(null)}
          />
        </Suspense>
      )}
    </div>
  )
}

interface AccionesLinea {
  abrir: (it: LineaEditable, campo: Campo) => void
  pedirQuitar: (it: IngresoItem) => void
  cancelarQuitar: () => void
  quitar: (itemId: number) => void
}

/**
 * Una línea del borrador (o del ingreso confirmado, solo lectura). Memoizada: editar otra fila o
 * escanear no vuelve a pintar las demás (mismo `it` y acciones estables).
 */
const FilaLinea = memo(function FilaLinea({ it, borrador, descuentoBloqueado, confirmandoQuitar, quitandoPendiente, acciones }: {
  it: IngresoItem
  borrador: boolean
  descuentoBloqueado: boolean
  confirmandoQuitar: boolean
  quitandoPendiente: boolean
  acciones: AccionesLinea
}) {
  const exenta = Number(it.iva_porcentaje) === 0
  const codigo = it.referencia?.codigo_referencia ?? ''

  /** Valor de una línea en borrador: se ve como celda editable; clic abre la fila completa. */
  const valor = (campo: Campo, texto: string) => {
    if (!borrador) return texto
    if (campo === 'descuento_porcentaje' && descuentoBloqueado) {
      return <span className="celda-bloqueada" title={AYUDA_DESCUENTO_LINEA}>{texto}</span>
    }
    if (campo === 'costo_unitario' && Number(it.costo_unitario) <= 0) {
      return (
        <button type="button" className="celda-editable pendiente" title="Clic para editar" aria-label={`Falta el costo de ${codigo}. Editar la línea`} onClick={() => acciones.abrir(it, campo)}>
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2.25" strokeLinecap="round" aria-hidden><path d="M12 7v6M12 17h.01" /><circle cx="12" cy="12" r="9" /></svg>
          Costo
        </button>
      )
    }
    return (
      <button type="button" className="celda-editable" title="Clic para editar" aria-label={`${REGLAS[campo].etiqueta} de ${codigo}: ${texto}. Editar la línea`} onClick={() => acciones.abrir(it, campo)}>
        {texto}
      </button>
    )
  }

  return (
    <tr>
      <td data-label="Referencia">{codigo} <small>{it.referencia?.descripcion}</small></td>
      <td data-label="Cant.">{valor('cantidad', String(it.cantidad))}</td>
      <td data-label="Costo">
        {!borrador && Number(it.costo_unitario) <= 0 ? <span className="agotado">Sin costo</span> : valor('costo_unitario', pesos(it.costo_unitario))}
      </td>
      <td data-label="Desc. %">{valor('descuento_porcentaje', `${formatoNumero(it.descuento_porcentaje)} %`)}</td>
      <td data-label="IVA %">
        <span>
          {valor('iva_porcentaje', `${formatoNumero(it.iva_porcentaje)} %`)}
          {exenta && <> <span className="chip">sin IVA</span></>}
        </span>
      </td>
      <td data-label="Neto">{it.neto === null ? '—' : pesos(it.neto)}</td>
      <td data-label="IVA">{it.iva === null ? '—' : pesos(it.iva)}</td>
      <td data-label="" className="acciones-linea">
        {borrador && (confirmandoQuitar ? (
          <span className="confirmar-quitar" role="group" aria-label={`Quitar ${codigo}`} onKeyDown={(e) => { if (e.key === 'Escape') { e.preventDefault(); acciones.cancelarQuitar() } }}>
            ¿Quitar?
            <button type="button" className="peligro" disabled={quitandoPendiente} onClick={() => acciones.quitar(it.id)}>Sí</button>
            <button type="button" className="secundario" autoFocus onClick={() => acciones.cancelarQuitar()}>No</button>
          </span>
        ) : (
          <>
            <button type="button" className="icono-accion" title="Editar" aria-label={`Editar la línea ${codigo}`} onClick={() => acciones.abrir(it, 'cantidad')}>
              <Icono nombre="editar" />
            </button>
            <button type="button" className="icono-accion peligro" title="Quitar" aria-label={`Quitar la línea ${codigo}`} disabled={quitandoPendiente} onClick={() => acciones.pedirQuitar(it)}>
              <Icono nombre="quitar" />
            </button>
          </>
        ))}
      </td>
    </tr>
  )
})

/** S-25: el código leído coincide con varias referencias del mismo proveedor; se elige una. */
function EleccionAmbigua({ ambiguo, onElegir, onCancelar }: { ambiguo: Ambiguo; onElegir: (o: OpcionAmbigua) => void; onCancelar: () => void }) {
  return (
    <div
      className="aviso atencion eleccion-codigo"
      role="group"
      aria-labelledby="codigo-ambiguo-titulo"
      onKeyDown={(e) => { if (e.key === 'Escape') { e.preventDefault(); onCancelar() } }}
    >
      <p id="codigo-ambiguo-titulo">Código ambiguo: <strong>{ambiguo.codigo}</strong> coincide con varias referencias de este proveedor. ¿Cuál es?</p>
      <div className="opciones-codigo">
        {ambiguo.opciones.map((o, n) => (
          <button key={o.id} type="button" className="secundario" autoFocus={n === 0} onClick={() => onElegir(o)}>
            <strong>{o.codigo_referencia}</strong>
            {o.codigo_fabricante && <small> · código de barras {o.codigo_fabricante}</small>}
            <small> · {o.descripcion}</small>
          </button>
        ))}
        <button type="button" className="enlace" onClick={onCancelar}>Cancelar</button>
      </div>
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
        <td data-label="Desc. %">
          {fila.descuentoBloqueado
            ? <span className="celda-bloqueada" title={AYUDA_DESCUENTO_LINEA}>{formatoNumero(fila.originales.descuento_porcentaje)} %</span>
            : numero('descuento_porcentaje', 'decimal')}
        </td>
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

/** Cifras del backend (TotalesIngreso). En el borrador van detrás de «Ver desglose»; el total vive en la barra. */
function CifrasIngreso({ ingreso: i, borrador }: { ingreso: Ingreso; borrador: boolean }) {
  const t = i.totales
  if (!t) return null
  const pendiente = Number(t.descuento_pendiente)

  return (
    <div className="totales compacto" aria-label="Totales del documento">
      <div><span>Subtotal</span><strong>{pesos(t.subtotal_bruto)}</strong></div>
      <div><span>Descuento de líneas</span><strong>{pesos(t.descuento_lineas)}</strong></div>
      <div><span>Descuento del documento</span><strong>{pesos(borrador ? t.descuento_aplicado : t.descuento_documento)}</strong></div>
      {borrador && pendiente > 0 && <div><span>Descuento por aplicar</span><strong>{pesos(t.descuento_pendiente)}</strong></div>}
      <div><span>Base gravada</span><strong>{pesos(t.base_gravada)}</strong></div>
      <div><span>Base sin IVA</span><strong>{pesos(t.base_sin_iva)}</strong></div>
      <div><span>IVA</span><strong>{pesos(t.iva)}</strong></div>
      <div><span>Líneas sin IVA</span><strong>{t.lineas_sin_iva}</strong></div>
      <div className="totales-total"><span>Total</span><strong>{pesos(borrador ? t.total_visible : t.total)}</strong></div>
      {i.total && <div><span>Total guardado al confirmar</span><strong>{pesos(i.total)}</strong></div>}
    </div>
  )
}

/**
 * RF-16 / RF-09: forma de pago, vencimiento y descuento del documento de un borrador.
 * A crédito, el vencimiento se prellena con fecha + plazo del proveedor y se corrige con el que
 * trae el documento. Las reglas (obligatorio, no antes de la fecha, descuento ≤ subtotal) las
 * valida el backend; sus mensajes salen junto a cada campo.
 */
function DialogoCondiciones({ ingreso, descuentoBloqueado, onGuardado, onCerrar }: {
  ingreso: Ingreso
  /** Hay líneas con descuento: el del documento queda deshabilitado (S-22). */
  descuentoBloqueado: boolean
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
          <BotonCancelar />
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
        {/* El fieldset deshabilita el selector de unidad y el valor sin tocar CampoDescuento. */}
        <fieldset className="campo-bloqueable" disabled={descuentoBloqueado}>
          <CampoDescuento
            id="descuento-condiciones"
            modo={modo}
            valor={descuento}
            error={errores.descuento_documento ?? errores.descuento_documento_porcentaje}
            ayuda={descuentoBloqueado ? AYUDA_DESCUENTO_DOCUMENTO : undefined}
            onModo={cambiarModo}
            onValor={setDescuento}
          />
        </fieldset>
        <small className="ayuda">Se reparte entre todas las líneas antes del IVA. Si todavía no hay líneas, se guarda y se aplica cuando las agregue.</small>
      </DialogoSeccion>
    </Dialogo>
  )
}

