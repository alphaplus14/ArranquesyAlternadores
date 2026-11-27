import { memo, useCallback, useEffect, useRef, useState, type FocusEvent, type KeyboardEvent } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import type { CorreccionIngreso, Ingreso, LineaCorreccionEnvio, Pagina, Referencia, VistaPreviaCorreccion } from '../../api/tipos'
import DetalleCorreccion from '../../componentes/ingresos/DetalleCorreccion'
import { formatearFechaHora } from '../../utilidades/fechas'
import { formatoNumero, leerNumero, pesos } from '../../componentes/numeros'
import { useAuth } from '../../auth/AuthProvider'
import { UMBRAL_RAFAGA_MS, useScanner } from '../../hooks/useScanner'
import BotonCancelar from '../../componentes/BotonCancelar'
import DialogoReferencia from './DialogoReferencia'
import { CAMPOS, IVAS, REGLAS, valoresDe, type Campo } from './lineaIngreso'
import { useAltoLector } from './useAltoLector'

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
 * RF-20: un código que no es referencia del proveedor se ofrece crear (mismo diálogo del borrador)
 * y queda como línea nueva; uno desactivado se reactiva en Inventario (no se duplica).
 */
export function PanelCorreccion({ ingreso, onCancelar, onCorregido }: {
  ingreso: Ingreso
  onCancelar: () => void
  onCorregido: (r: { ingreso: Ingreso; correccion: CorreccionIngreso }) => void
}) {
  const { tieneRol } = useAuth()
  const puedeCrear = tieneRol('logistica') // logística y admin (S-26)
  const soloEncabezado = ingreso.tipo === 'remision' && ingreso.factura !== null
  const nombreProveedor = ingreso.proveedor?.nombre ?? 'este proveedor'
  const [enc, setEnc] = useState({
    numero_documento: ingreso.numero_documento,
    fecha: ingreso.fecha.slice(0, 10),
    forma_pago: ingreso.forma_pago,
    vence_el: ingreso.vence_el?.slice(0, 10) ?? '',
    descuento: formatoNumero(ingreso.descuento_documento),
  })
  const [lineas, setLineas] = useState<LineaCorr[]>(() => lineasIniciales(ingreso))
  const lineasRef = useRef(lineas)
  lineasRef.current = lineas
  /** Resultado de una búsqueda sin coincidencia exacta: opciones del proveedor y el código escrito. */
  const [opciones, setOpciones] = useState<{ texto: string; refs: Referencia[] } | null>(null)
  const [avisoLector, setAvisoLector] = useState<{ tono: 'error' | 'atencion'; texto: string } | null>(null)
  const [creando, setCreando] = useState<string | null>(null)
  const [vista, setVista] = useState<VistaPreviaCorreccion | null>(null)
  const [enfocarCosto, setEnfocarCosto] = useState<string | null>(null)
  const lector = useRef<HTMLInputElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const zonaLector = useRef<HTMLDivElement>(null)
  const tablaRef = useRef<HTMLDivElement>(null)
  const barraRef = useRef<HTMLDivElement>(null)
  const costos = useRef(new Map<string, HTMLInputElement>())
  useAltoLector(panelRef, zonaLector, !soloEncabezado)

  const credito = enc.forma_pago === 'credito'
  const descuento = leerNumero(enc.descuento)
  const conErrores = lineas.filter((l) => Object.values(erroresLinea(l)).some(Boolean))
  const vigentes = lineas.filter((l) => !l.quitada)
  const nuevas = lineas.filter((l) => l.id === null).length
  const quitadas = lineas.length - vigentes.length

  /** Por qué "Revisar cambios" está deshabilitado, en una línea corta (null = se puede revisar). */
  const motivoBloqueo = (() => {
    if (enc.numero_documento.trim() === '') return 'Falta el número del documento'
    if (credito && enc.vence_el === '') return 'Falta el vencimiento'
    if (!soloEncabezado && descuento !== null && Number.isNaN(descuento)) return 'Descuento del documento no válido'
    if (vigentes.length === 0) return 'Debe quedar al menos una línea'
    if (conErrores.length === 1) {
      const errores = erroresLinea(conErrores[0])
      const soloCosto = CAMPOS.every((c) => c === 'costo_unitario' || !errores[c])
      return soloCosto ? `Falta el costo de ${conErrores[0].codigo}` : `Faltan datos en ${conErrores[0].codigo}`
    }
    if (conErrores.length > 1) return `Faltan datos en ${conErrores.length} líneas`
    return null
  })()

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
  const reiniciarRevision = useRef(revisar.reset)
  reiniciarRevision.current = revisar.reset

  const cambiarEnc = (k: keyof typeof enc, v: string) => { revisar.reset(); setEnc((e) => ({ ...e, [k]: v })) }
  // Estables: la fila memoizada solo se vuelve a pintar si cambia su propia línea.
  const cambiarValor = useCallback((clave: string, campo: Campo, valor: string) => {
    reiniciarRevision.current()
    setLineas((ls) => ls.map((l) => (l.clave === clave ? { ...l, valores: { ...l.valores, [campo]: valor } } : l)))
  }, [])
  /** Una línea existente se marca quitada (se puede deshacer); una nueva se elimina de la lista. */
  const alternarQuitada = useCallback((clave: string) => {
    reiniciarRevision.current()
    setLineas((ls) => ls.flatMap((l) => (l.clave !== clave ? [l] : l.id === null ? [] : [{ ...l, quitada: !l.quitada }])))
  }, [])
  const registrarCosto = useCallback((clave: string, el: HTMLInputElement | null) => {
    if (el) costos.current.set(clave, el)
    else costos.current.delete(clave)
  }, [])

  /**
   * Agrega una referencia del proveedor: si ya hay una línea vigente suya, suma 1 y el foco vuelve
   * al lector; si no, línea nueva (cantidad 1, IVA de la referencia) con el foco en su costo.
   */
  const agregar = (r: Referencia) => {
    revisar.reset()
    setOpciones(null)
    setAvisoLector(null)
    const existente = lineasRef.current.find((l) => l.referencia_id === r.id && !l.quitada)
    if (existente) {
      setLineas((ls) => ls.map((l) => (l.clave === existente.clave
        ? { ...l, valores: { ...l.valores, cantidad: String((leerNumero(l.valores.cantidad) ?? 0) + 1) } }
        : l)))
      lector.current?.focus()
      return
    }
    secuenciaLineaNueva += 1
    const clave = `n${secuenciaLineaNueva}`
    setLineas((ls) => [...ls, {
      clave,
      id: null,
      referencia_id: r.id,
      codigo: r.codigo_referencia,
      descripcion: r.descripcion,
      valores: { cantidad: '1', costo_unitario: '', descuento_porcentaje: '0', iva_porcentaje: String(Number(r.iva_venta_porcentaje ?? 0)) },
      quitada: false,
    }])
    setEnfocarCosto(clave)
  }
  useEffect(() => {
    if (enfocarCosto === null) return
    const el = costos.current.get(enfocarCosto)
    if (el) { el.focus(); el.select(); el.scrollIntoView({ block: 'nearest' }) }
    setEnfocarCosto(null)
  }, [enfocarCosto])

  /**
   * Escaneado o escrito: busca entre las referencias del proveedor del ingreso (nunca de otro).
   * Exacta y activa: agrega. Varias o parecidas: se elige (o se crea la del código escrito).
   * Desactivada: se reactiva en Inventario. Ninguna: se ofrece crearla (RF-20).
   */
  const buscar = async (codigo: string) => {
    if (lector.current) lector.current.value = ''
    const texto = codigo.trim()
    if (texto === '') return
    try {
      const encontradas = (await api.get<Pagina<Referencia>>('/referencias', {
        params: { proveedor_id: ingreso.proveedor_id, q: texto, por_pagina: 10, inactivas: 1 },
      })).data.data
      const coincide = (r: Referencia) => [r.codigo_referencia, r.codigo_fabricante ?? ''].some((c) => c.toUpperCase() === texto.toUpperCase())
      const activas = encontradas.filter((r) => r.activo !== false)
      const exactas = activas.filter(coincide)
      if (exactas.length === 1) { agregar(exactas[0]); return }
      if (exactas.length > 1) { setOpciones({ texto, refs: exactas }); setAvisoLector(null); return }
      if (encontradas.some((r) => r.activo === false && coincide(r))) {
        setOpciones(null)
        setAvisoLector({ tono: 'atencion', texto: `El código ${texto} está desactivado para ${nombreProveedor}. Reactívelo en Inventario.` })
        return
      }
      if (activas.length > 0) { setOpciones({ texto, refs: activas }); setAvisoLector(null); return }
      setOpciones(null)
      if (puedeCrear) { setAvisoLector(null); setCreando(texto) } else {
        setAvisoLector({ tono: 'error', texto: `${texto} no es una referencia activa de ${nombreProveedor}.` })
      }
    } catch (e) {
      setAvisoLector({ tono: 'error', texto: mensajeError(e) })
    }
  }
  const buscarRef = useRef(buscar)
  buscarRef.current = buscar
  const scanner = useScanner(useCallback((codigo: string) => { void buscarRef.current(codigo) }, []))

  // El lector recupera el foco salvo con un diálogo abierto, las opciones a la vista o una celda en
  // edición. Mientras se crea una referencia, el diálogo atrapa el foco: el lector no captura nada.
  const hayDialogo = creando !== null || vista !== null || opciones !== null
  const hayDialogoRef = useRef(hayDialogo)
  hayDialogoRef.current = hayDialogo
  useEffect(() => {
    if (hayDialogo || soloEncabezado) return
    const activo = document.activeElement
    if (!activo || activo === document.body || activo === lector.current) lector.current?.focus()
  }, [hayDialogo, soloEncabezado])
  /** Si el foco quedó en ninguna parte (clic en un espacio vacío), vuelve al lector. */
  const reenfocar = () => setTimeout(() => {
    if (hayDialogoRef.current) return
    const activo = document.activeElement
    if (!activo || activo === document.body) lector.current?.focus()
  }, 0)

  /**
   * Teclado dentro de la tabla. Enter o Esc devuelven el foco al lector. Una ráfaga de lector
   * (< 30 ms entre teclas y Enter) con el foco en una celda no se escribe en ella: la celda vuelve
   * a su valor y el código se busca como si se hubiera leído en el lector.
   */
  const rafaga = useRef<{ t: number; texto: string; bloqueadas: number; celda: { clave: string; campo: Campo; valor: string } | null }>({ t: 0, texto: '', bloqueadas: 0, celda: null })
  const alTeclearEnTabla = (e: KeyboardEvent<HTMLDivElement>) => {
    const el = e.target as HTMLElement
    const clave = el.dataset.clave
    const campo = el.dataset.campo as Campo | undefined
    if (!clave || !campo) return
    const r = rafaga.current
    const ahora = performance.now()
    if (e.key === 'Enter' || e.key === 'Escape') {
      e.preventDefault()
      const escaneo = e.key === 'Enter' && r.celda !== null && r.bloqueadas >= 2 && ahora - r.t < 100
      if (escaneo && r.celda) {
        const c = r.celda
        setLineas((ls) => ls.map((l) => (l.clave === c.clave ? { ...l, valores: { ...l.valores, [c.campo]: c.valor } } : l)))
        void buscar(r.texto)
      }
      rafaga.current = { t: 0, texto: '', bloqueadas: 0, celda: null }
      lector.current?.focus()
      return
    }
    if (!(el instanceof HTMLInputElement) || e.key.length !== 1 || e.ctrlKey || e.metaKey || e.altKey) {
      rafaga.current = { t: 0, texto: '', bloqueadas: 0, celda: null }
      return
    }
    if (r.celda && ahora - r.t < UMBRAL_RAFAGA_MS) {
      e.preventDefault()
      r.texto += e.key
      r.bloqueadas += 1
    } else {
      const linea = lineasRef.current.find((l) => l.clave === clave)
      rafaga.current = { t: ahora, texto: e.key, bloqueadas: 0, celda: linea ? { clave, campo, valor: linea.valores[campo] } : null }
      return
    }
    r.t = ahora
  }
  /** Tab o clic fuera de la tabla: el foco vuelve al lector, salvo que vaya a la barra de acciones o a un campo del encabezado. */
  const alSalirDeTabla = (e: FocusEvent<HTMLDivElement>) => {
    const destino = e.relatedTarget as Node | null
    if (destino && (tablaRef.current?.contains(destino) || barraRef.current?.contains(destino) || zonaLector.current?.contains(destino))) return
    if (destino && panelRef.current?.querySelector('.encabezado-correccion')?.contains(destino)) return
    setTimeout(() => { if (!hayDialogoRef.current) lector.current?.focus() }, 0)
  }

  const resumen = [
    `${vigentes.length} ${vigentes.length === 1 ? 'línea' : 'líneas'}`,
    `${nuevas} ${nuevas === 1 ? 'nueva' : 'nuevas'}`,
    `${quitadas} ${quitadas === 1 ? 'quitada' : 'quitadas'}`,
    ...(conErrores.length > 0 ? [`${conErrores.length} con errores`] : []),
  ].join(' · ')

  return (
    <div ref={panelRef} className="panel panel-correccion ingreso-con-barra">
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
        // Fijo bajo la barra superior: con muchas líneas se sigue escaneando sin subir.
        <div ref={zonaLector} className="ingreso-lector">
          <input
            ref={lector}
            className="escaner"
            placeholder="Agregar línea: escanee el código o escriba la referencia y pulse Enter"
            aria-label="Agregar línea por código o búsqueda"
            // RF-28: al abrir "Corregir", el foco va al lector.
            autoFocus
            onKeyDown={scanner.onKeyDown}
            onPaste={scanner.onPaste}
            onBlur={reenfocar}
            autoComplete="off"
          />
          {avisoLector && <div className={`aviso ${avisoLector.tono}`} role="alert">{avisoLector.texto}</div>}
          {opciones && (
            <div
              className="aviso atencion eleccion-codigo"
              role="group"
              aria-label="Elija la referencia"
              onKeyDown={(e) => { if (e.key === 'Escape') { e.preventDefault(); setOpciones(null) } }}
            >
              <p>Elija la referencia:</p>
              <div className="opciones-codigo">
                {opciones.refs.map((r, n) => (
                  <button key={r.id} type="button" className="secundario" autoFocus={n === 0} onClick={() => agregar(r)}>
                    <strong>{r.codigo_referencia}</strong><small> · {r.descripcion}</small>
                  </button>
                ))}
                {puedeCrear && !opciones.refs.some((r) => r.codigo_referencia.toUpperCase() === opciones.texto.toUpperCase()) && (
                  <button type="button" className="secundario" onClick={() => { const t = opciones.texto; setOpciones(null); setCreando(t) }}>
                    Crear referencia <strong>{opciones.texto}</strong>
                  </button>
                )}
                <button type="button" className="enlace" onClick={() => setOpciones(null)}>Cancelar</button>
              </div>
            </div>
          )}
        </div>
      )}

      <div ref={tablaRef} className="tabla-desplazable ingreso-tabla" onKeyDown={alTeclearEnTabla} onBlur={alSalirDeTabla}>
        <table className="tabla-lineas tabla-correccion">
          <thead><tr><th>Referencia</th><th>Cant.</th><th>Costo</th><th>Desc. %</th><th>IVA %</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
          <tbody>
            {lineas.map((l) => (
              <FilaCorreccion
                key={l.clave}
                linea={l}
                soloEncabezado={soloEncabezado}
                onCambiar={cambiarValor}
                onQuitar={alternarQuitada}
                registrarCosto={registrarCosto}
              />
            ))}
          </tbody>
        </table>
      </div>

      {revisar.isError && <div className="aviso error" role="alert">{mensajeError(revisar.error)}</div>}
      {/* Barra fija al pie del área visible: resumen y botones siempre a la vista. */}
      <div ref={barraRef} className="ingreso-barra">
        <span className="ingreso-barra-resumen">{resumen}</span>
        <div className="ingreso-barra-acciones">
          {motivoBloqueo && <small className="ingreso-barra-motivo" role="status">{motivoBloqueo}</small>}
          <button type="button" className="secundario" onClick={onCancelar}>Cancelar</button>
          <button type="button" className="primario" disabled={motivoBloqueo !== null || revisar.isPending} onClick={() => revisar.mutate()}>
            {revisar.isPending ? 'Revisando…' : 'Revisar cambios'}
          </button>
        </div>
      </div>

      {creando !== null && ingreso.proveedor && (
        <DialogoReferencia
          codigo={creando}
          proveedor={ingreso.proveedor}
          onCreada={(r) => { setCreando(null); agregar(r) }}
          onCerrar={() => { setCreando(null); requestAnimationFrame(() => lector.current?.focus()) }}
        />
      )}
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

function erroresLinea(l: LineaCorr): Partial<Record<Campo, string | null>> {
  return l.quitada ? {} : Object.fromEntries(CAMPOS.map((c) => [c, REGLAS[c].validar(leerNumero(l.valores[c]))]))
}

/**
 * Una línea de la corrección. Memoizada: escribir en una celda solo vuelve a pintar su fila (las
 * demás conservan el mismo objeto y las mismas funciones).
 */
const FilaCorreccion = memo(function FilaCorreccion({ linea: l, soloEncabezado, onCambiar, onQuitar, registrarCosto }: {
  linea: LineaCorr
  soloEncabezado: boolean
  onCambiar: (clave: string, campo: Campo, valor: string) => void
  onQuitar: (clave: string) => void
  registrarCosto: (clave: string, el: HTMLInputElement | null) => void
}) {
  const errores = erroresLinea(l)
  return (
    <tr className={l.quitada ? 'reversado' : ''}>
      <td data-label="Referencia">
        {l.codigo} <small>{l.descripcion}</small>
        {l.id === null && <span className="chip corregida">Nueva</span>}
      </td>
      {CAMPOS.map((c) => (
        <td key={c} data-label={REGLAS[c].etiqueta}>
          {soloEncabezado || l.quitada ? (
            <span>{l.valores[c] || '—'}</span>
          ) : c === 'iva_porcentaje' ? (
            <select data-clave={l.clave} data-campo={c} aria-label={`${REGLAS[c].etiqueta} de ${l.codigo}`} value={l.valores[c]} onChange={(e) => onCambiar(l.clave, c, e.target.value)}>
              {[...new Set([...IVAS.map(String), l.valores[c]])].map((v) => <option key={v} value={v}>{v} %</option>)}
            </select>
          ) : (
            <>
              <input
                ref={c === 'costo_unitario' ? (el) => registrarCosto(l.clave, el) : undefined}
                data-clave={l.clave}
                data-campo={c}
                className="celda-numero"
                inputMode={c === 'cantidad' ? 'numeric' : 'decimal'}
                autoComplete="off"
                aria-label={`${REGLAS[c].etiqueta} de ${l.codigo}`}
                aria-invalid={!!errores[c]}
                value={l.valores[c]}
                onChange={(e) => onCambiar(l.clave, c, e.target.value)}
              />
              {errores[c] && <small className="ayuda error">{errores[c]}</small>}
            </>
          )}
        </td>
      ))}
      <td data-label="" className="acciones-linea">
        {!soloEncabezado && (
          <button type="button" className="enlace" onClick={() => onQuitar(l.clave)}>{l.quitada ? 'Deshacer' : 'Quitar'}</button>
        )}
      </td>
    </tr>
  )
})

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
          <BotonCancelar>Volver a editar</BotonCancelar>
          <button type="submit" className="primario" disabled={!listo}>{corregir.isPending ? 'Guardando…' : 'Confirmar corrección'}</button>
        </>
      )}
    >
      <DialogoSeccion>
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
      </DialogoSeccion>
    </Dialogo>
  )
}

/** RF-28: historial de correcciones del ingreso (más reciente primero), con lo que cambió. */
export function HistorialCorrecciones({ correcciones }: { correcciones: CorreccionIngreso[] }) {
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
