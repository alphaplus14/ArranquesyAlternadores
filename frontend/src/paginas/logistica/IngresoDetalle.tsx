import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import type { FormaPago, Ingreso, IngresoItem, Opcion, Pagina } from '../../api/tipos'
import { fechaCorta, sumarDias } from '../../componentes/fechas'
import CampoDescuento, { type ModoDescuento } from '../../componentes/CampoDescuento'
import Medida from '../../componentes/Medida'
import { formatoNumero, leerNumero, pesos } from '../../componentes/numeros'
import { useCategorias, useMarcas } from '../../hooks/useCatalogos'
import { useScanner } from '../../hooks/useScanner'

interface Aviso { tipo: 'ok' | 'error' | 'atencion'; texto: string }
type Campo = 'cantidad' | 'costo_unitario' | 'descuento_porcentaje' | 'iva_porcentaje'
interface Edicion { itemId: number; campo: Campo }

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
 * RF-16: forma de pago y vencimiento (diálogo "Cambiar condiciones"); el total se fija al confirmar.
 * RF-03: al confirmar se crean las unidades y se pasa a imprimir etiquetas.
 * Todas las cifras (subtotal, base, IVA, total) vienen del backend: aquí no se recalculan.
 */
export default function IngresoDetalle() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [aviso, setAviso] = useState<Aviso | null>(null)
  const [creando, setCreando] = useState<string | null>(null)
  const [editando, setEditando] = useState<Edicion | null>(null)
  const [condiciones, setCondiciones] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)

  const ingreso = useQuery({
    queryKey: ['ingreso', id],
    queryFn: async () => (await api.get<Ingreso>(`/ingresos/${id}`)).data,
  })
  const i = ingreso.data
  const borrador = i?.estado === 'borrador'

  const actualizar = (nuevo: Ingreso) => qc.setQueryData(['ingreso', id], nuevo)

  const escanear = useMutation({
    mutationFn: async (codigo: string) => (await api.post<IngresoItem>(`/ingresos/${id}/escanear`, { codigo })).data,
    onSuccess: (linea) => {
      const sinCosto = Number(linea.costo_unitario) <= 0
      setAviso({
        tipo: sinCosto ? 'atencion' : 'ok',
        texto: `✓ ${linea.referencia?.codigo_referencia} · ${linea.referencia?.descripcion} — cantidad ${linea.cantidad}${sinCosto ? ' · escriba el costo en la tabla' : ''}`,
      })
      qc.invalidateQueries({ queryKey: ['ingreso', id] })
    },
    onError: (e, codigo) => {
      const inexistente = codigoInexistente(e)
      if (inexistente !== null) {
        setAviso({ tipo: 'atencion', texto: `El código ${inexistente} no es una referencia de este proveedor. Créela para agregarla.` })
        setCreando(inexistente || codigo)
        return
      }
      setAviso({ tipo: 'error', texto: mensajeError(e) })
    },
  })

  const quitar = useMutation({
    mutationFn: async (itemId: number) => (await api.delete<Ingreso>(`/ingresos/${id}/items/${itemId}`)).data,
    onSuccess: actualizar,
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeError(e) }),
  })
  const confirmar = useMutation({
    mutationFn: async () => (await api.post<Ingreso>(`/ingresos/${id}/confirmar`)).data,
    onSuccess: (nuevo) => { actualizar(nuevo); qc.invalidateQueries({ queryKey: ['ingresos'] }); setAviso(null) },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeError(e) }),
  })

  const onCodigo = useCallback((codigo: string) => {
    if (inputRef.current) inputRef.current.value = ''
    escanear.mutate(codigo)
  }, [escanear])
  const scanner = useScanner(onCodigo)

  // El lector recupera el foco salvo que haya un diálogo abierto o se esté editando una celda.
  const hayDialogo = creando !== null || editando !== null || condiciones
  useEffect(() => {
    if (!hayDialogo && borrador) inputRef.current?.focus()
  }, [hayDialogo, borrador, escanear.isPending])
  // El blur llega antes que el clic que abre el diálogo: el timeout lee el valor vigente.
  const hayDialogoRef = useRef(hayDialogo)
  hayDialogoRef.current = hayDialogo
  const reenfocar = () => setTimeout(() => { if (!hayDialogoRef.current) inputRef.current?.focus() }, 0)

  const dejarDeEditar = (e: Edicion) =>
    setEditando((actual) => (actual?.itemId === e.itemId && actual.campo === e.campo ? null : actual))

  if (!i) return <div className="panel">Cargando…</div>

  const unidades = i.items.reduce((suma, it) => suma + it.cantidad, 0)
  const lineasSinCosto = i.items.filter((it) => Number(it.costo_unitario) <= 0).length

  const celda = (it: IngresoItem, campo: Campo, texto: string) => {
    const edicion = { itemId: it.id, campo }
    if (!borrador) return texto
    if (editando?.itemId === it.id && editando.campo === campo) {
      return (
        <CeldaEditable
          etiqueta={`${REGLAS[campo].etiqueta} de ${it.referencia?.codigo_referencia ?? ''}`}
          inicial={formatoNumero(it[campo])}
          validar={(t) => REGLAS[campo].validar(leerNumero(t))}
          guardar={async (t) => (await api.patch<Ingreso>(`/ingresos/${id}/items/${it.id}`, { [campo]: leerNumero(t) })).data}
          onGuardado={(nuevo) => {
            actualizar(nuevo)
            dejarDeEditar(edicion)
            // Un error anterior (por ejemplo, "falta el costo") ya no aplica tras corregir.
            setAviso((a) => (a?.tipo === 'error' ? null : a))
          }}
          onTerminar={() => dejarDeEditar(edicion)}
        />
      )
    }
    return (
      <button type="button" className="enlace" title={`Corregir ${REGLAS[campo].etiqueta.toLowerCase()}`} onClick={() => setEditando(edicion)}>
        {texto}
      </button>
    )
  }

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>{i.tipo === 'factura' ? 'Factura' : 'Remisión'} {i.numero_documento}</h1>
        <p>{i.proveedor?.nombre} · {i.fecha.slice(0, 10)}</p>
      </div>
      <section className="medidas">
        <Medida etiqueta="Estado" valor={i.estado} tono={borrador ? 'amarillo' : 'verde'} icono="ingreso" />
        <Medida etiqueta="Unidades" valor={unidades} pie={`${i.items.length} línea(s)`} tono="azul" icono="caja" />
        <Medida etiqueta="Total" valor={i.total ? pesos(i.total) : i.totales ? pesos(i.totales.total) : '—'} pie={i.total ? 'fijado al confirmar' : undefined} tono="verde" icono="etiqueta" />
        <Medida etiqueta="Sin costo" valor={lineasSinCosto} pie="líneas por completar" tono={lineasSinCosto > 0 ? 'rojo' : 'amarillo'} icono="agotados" />
      </section>
      <div className="panel">
        <div className="fila-titulo">
          <h2>Líneas <span className={`chip ${i.estado}`}>{i.estado}</span></h2>
          <span>{i.proveedor?.nombre}</span>
        </div>

        <p className="venta-credito">
          {i.forma_pago === 'credito'
            ? <><span className="chip credito">Crédito</span> Vence el <strong>{i.vence_el ? fechaCorta(i.vence_el) : '—'}</strong></>
            : <span className="chip">Contado</span>}
          {' · '}Descuento del documento {i.descuento_documento_porcentaje !== null ? `${formatoNumero(i.descuento_documento_porcentaje)} % (${pesos(i.descuento_documento)})` : pesos(i.descuento_documento)}
          {borrador && <> · <button type="button" className="enlace" onClick={() => setCondiciones(true)}>Cambiar condiciones</button></>}
        </p>

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
            <p className="ayuda">Se busca entre las referencias de {i.proveedor?.nombre}. Leer el mismo código otra vez suma 1. Haga clic en un valor de la tabla para corregirlo.</p>
          </>
        )}
        {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

        <table className="tabla-lineas">
          <thead><tr><th>Referencia</th><th>Cant.</th><th>Costo</th><th>Desc. %</th><th>IVA %</th><th>Neto</th><th>IVA</th><th></th></tr></thead>
          <tbody>
            {i.items.map((it) => {
              const exenta = Number(it.iva_porcentaje) === 0
              return (
                <tr key={it.id}>
                  <td data-label="Referencia">{it.referencia?.codigo_referencia} <small>{it.referencia?.descripcion}</small></td>
                  <td data-label="Cant.">{celda(it, 'cantidad', String(it.cantidad))}</td>
                  <td data-label="Costo" className={Number(it.costo_unitario) <= 0 ? 'agotado' : undefined}>
                    {celda(it, 'costo_unitario', Number(it.costo_unitario) <= 0 ? 'Sin costo' : pesos(it.costo_unitario))}
                  </td>
                  <td data-label="Desc. %">{celda(it, 'descuento_porcentaje', `${formatoNumero(it.descuento_porcentaje)} %`)}</td>
                  <td data-label="IVA %">
                    <span>
                      {celda(it, 'iva_porcentaje', `${formatoNumero(it.iva_porcentaje)} %`)}
                      {exenta && <> <span className="chip">sin IVA</span></>}
                    </span>
                  </td>
                  <td data-label="Neto">{it.neto === null ? '—' : pesos(it.neto)}</td>
                  <td data-label="IVA">{it.iva === null ? '—' : pesos(it.iva)}</td>
                  <td data-label="">{borrador && <button type="button" className="enlace" disabled={quitar.isPending} onClick={() => quitar.mutate(it.id)}>Quitar</button>}</td>
                </tr>
              )
            })}
            {i.items.length === 0 && <tr><td colSpan={8} className="vacio">Escanee el primer producto.</td></tr>}
          </tbody>
        </table>

        {i.totales && (
          <dl className="totales">
            <dt>Subtotal</dt><dd>{pesos(i.totales.subtotal_bruto)}</dd>
            <dt>Descuento de líneas</dt><dd>{pesos(i.totales.descuento_lineas)}</dd>
            <dt>Descuento del documento</dt><dd>{pesos(i.totales.descuento_documento)}</dd>
            <dt>Base gravada</dt><dd>{pesos(i.totales.base_gravada)}</dd>
            <dt>Base sin IVA</dt><dd>{pesos(i.totales.base_sin_iva)}</dd>
            <dt>IVA</dt><dd>{pesos(i.totales.iva)}</dd>
            <dt>Líneas sin IVA</dt><dd>{i.totales.lineas_sin_iva}</dd>
            <dt>Total</dt><dd><strong>{pesos(i.totales.total)}</strong></dd>
            {i.total && <><dt>Total guardado al confirmar</dt><dd><strong>{pesos(i.total)}</strong></dd></>}
          </dl>
        )}

        <div className="acciones">
          {borrador ? (
            <button type="button" className="primario" disabled={i.items.length === 0 || confirmar.isPending} onClick={() => confirmar.mutate()}>
              Confirmar ingreso y crear unidades
            </button>
          ) : (
            <Link className="boton primario" to={`/logistica/etiquetas?ingreso=${i.id}`}>Imprimir etiquetas</Link>
          )}
        </div>
      </div>

      {condiciones && (
        <DialogoCondiciones
          ingreso={i}
          onGuardado={(nuevo) => { actualizar(nuevo); setCondiciones(false); setAviso(null) }}
          onCerrar={() => setCondiciones(false)}
        />
      )}

      {creando !== null && i.proveedor && (
        <DialogoReferencia
          codigo={creando}
          proveedor={i.proveedor}
          onCreada={() => { setCreando(null); escanear.mutate(creando) }}
          onCerrar={() => setCreando(null)}
        />
      )}
    </div>
  )
}

/** Una celda numérica en edición: Enter o salir del campo guarda; Escape cancela. */
function CeldaEditable({ etiqueta, inicial, validar, guardar, onGuardado, onTerminar }: {
  etiqueta: string
  inicial: string
  validar: (texto: string) => string | null
  guardar: (texto: string) => Promise<Ingreso>
  onGuardado: (ingreso: Ingreso) => void
  onTerminar: () => void
}) {
  const [texto, setTexto] = useState(inicial)
  const campoRef = useRef<HTMLInputElement>(null)
  // Evita guardar dos veces (Enter y luego el blur al desmontarse) o guardar tras cancelar.
  const listo = useRef(false)
  const error = validar(texto)

  const mutacion = useMutation({
    mutationFn: guardar,
    onSuccess: onGuardado,
    onError: () => { listo.current = false; campoRef.current?.focus() },
  })

  const cancelar = () => { listo.current = true; onTerminar() }

  const confirmarCambio = (alSalir: boolean) => {
    if (listo.current || mutacion.isPending) return
    if (error) {
      // Al salir con un valor inválido se descarta; con Enter se queda para corregirlo.
      if (alSalir) cancelar()
      return
    }
    if (leerNumero(texto) === leerNumero(inicial)) {
      cancelar()
      return
    }
    listo.current = true
    mutacion.mutate(texto)
  }

  return (
    <div className="precio-editable">
      <input
        ref={campoRef}
        autoFocus
        inputMode="decimal"
        autoComplete="off"
        aria-label={etiqueta}
        aria-invalid={error !== null}
        value={texto}
        disabled={mutacion.isPending}
        onFocus={(e) => e.target.select()}
        onChange={(e) => { setTexto(e.target.value); mutacion.reset() }}
        onKeyDown={(e) => {
          if (e.key === 'Enter') { e.preventDefault(); confirmarCambio(false) }
          if (e.key === 'Escape') { e.preventDefault(); cancelar() }
        }}
        onBlur={() => confirmarCambio(true)}
      />
      <small className={error || mutacion.isError ? 'ayuda error' : 'ayuda'}>
        {mutacion.isError ? mensajeError(mutacion.error) : error ?? 'Enter guarda · Esc cancela'}
      </small>
    </div>
  )
}

/**
 * RF-20: crea la referencia que no existe con el código leído y el proveedor del ingreso (ambos
 * fijos). Precio de venta > 0 (S-20). Logística no crea marcas ni categorías: las crea el admin.
 */
function DialogoReferencia({ codigo, proveedor, onCreada, onCerrar }: {
  codigo: string
  proveedor: Opcion
  onCreada: () => void
  onCerrar: () => void
}) {
  const marcas = useMarcas()
  const categorias = useCategorias()
  const [f, setF] = useState({ marca_id: '', categoria_id: '', descripcion: '', precio_venta: '', iva_venta_porcentaje: '19', codigo_universal_id: '' })
  const categoria = categorias.data?.find((c) => String(c.id) === f.categoria_id)
  const admiteUniversal = categoria?.admite_codigo_universal ?? false

  const universales = useQuery({
    queryKey: ['universales', 'categoria', f.categoria_id],
    queryFn: async () => (await api.get<Pagina<{ id: number; codigo: string; categoria_id: number }>>('/codigos-universales', { params: { por_pagina: 100 } })).data.data,
    enabled: admiteUniversal,
  })

  const crear = useMutation({
    mutationFn: async () => api.post('/referencias', {
      codigo_referencia: codigo,
      proveedor_id: proveedor.id,
      marca_id: Number(f.marca_id),
      categoria_id: Number(f.categoria_id),
      descripcion: f.descripcion.trim(),
      precio_venta: Number(f.precio_venta),
      iva_venta_porcentaje: Number(f.iva_venta_porcentaje),
      codigo_universal_id: admiteUniversal && f.codigo_universal_id ? Number(f.codigo_universal_id) : null,
    }),
    onSuccess: onCreada,
  })

  const errorPrecio = f.precio_venta === '' ? null : Number(f.precio_venta) > 0 ? null : 'El precio de venta debe ser mayor que 0.'
  const sinMarcas = marcas.data?.length === 0
  const sinCategorias = categorias.data?.length === 0
  const completo = f.marca_id !== '' && f.categoria_id !== '' && f.descripcion.trim() !== '' && f.precio_venta !== '' && errorPrecio === null
  const campo = (k: keyof typeof f) => ({ value: f[k], onChange: (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value }) })

  return (
    <div className="modal" onKeyDown={(e) => e.key === 'Escape' && onCerrar()}>
      <form className="modal-caja" role="dialog" aria-modal="true" aria-labelledby="crear-referencia-titulo" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
        <h2 id="crear-referencia-titulo">Crear referencia</h2>
        <p className="ayuda">El código no existe para este proveedor. Al guardarla se agrega la línea al ingreso.</p>
        <label>Código de referencia<input value={codigo} readOnly aria-readonly="true" /></label>
        <label>Proveedor<input value={proveedor.nombre} readOnly aria-readonly="true" /></label>
        <label>Marca
          <select required autoFocus {...campo('marca_id')}>
            <option value="">—</option>
            {marcas.data?.map((m) => <option key={m.id} value={m.id}>{m.nombre}</option>)}
          </select>
        </label>
        <label>Categoría
          <select required {...campo('categoria_id')}>
            <option value="">—</option>
            {categorias.data?.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
          </select>
        </label>
        {(sinMarcas || sinCategorias) && (
          <div className="aviso atencion">No hay {sinMarcas ? 'marcas' : 'categorías'} en el catálogo. Pídale al administrador que las cree.</div>
        )}
        <p className="ayuda">Si la marca o la categoría que necesita no aparece, pídale al administrador que la cree; aquí no se crean.</p>
        {admiteUniversal && (
          <label>Código universal (opcional)
            <select {...campo('codigo_universal_id')}>
              <option value="">Sin universal: la referencia es su código único</option>
              {universales.data?.filter((u) => String(u.categoria_id) === f.categoria_id).map((u) => <option key={u.id} value={u.id}>{u.codigo}</option>)}
            </select>
          </label>
        )}
        <label>Descripción<input required maxLength={255} {...campo('descripcion')} /></label>
        <label>Precio de venta
          <input type="number" min="0.01" step="0.01" required aria-invalid={errorPrecio !== null} {...campo('precio_venta')} />
        </label>
        <p className={errorPrecio ? 'ayuda error' : 'ayuda'}>{errorPrecio ?? 'Mayor que 0. Si no lo conoce, consúltelo con el administrador antes de crearla.'}</p>
        <label>IVA de venta
          <select {...campo('iva_venta_porcentaje')}>
            <option value="19">19 %</option>
            <option value="0">0 % · sin IVA</option>
          </select>
        </label>
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
  const [descuento, setDescuento] = useState(String(Number(porcentaje ?? ingreso.descuento_documento)))

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
      [modo === '%' ? 'descuento_documento_porcentaje' : 'descuento_documento']: descuento === '' ? 0 : descuento,
    })).data,
    onSuccess: onGuardado,
  })
  const errores = guardar.isError ? erroresCampo(guardar.error) : {}
  const general = guardar.isError && Object.keys(errores).length === 0 ? mensajeError(guardar.error) : null

  return (
    <div className="modal" onKeyDown={(e) => e.key === 'Escape' && onCerrar()}>
      <form className="modal-caja" role="dialog" aria-modal="true" aria-labelledby="condiciones-titulo" onSubmit={(e) => { e.preventDefault(); guardar.mutate() }}>
        <h2 id="condiciones-titulo">Condiciones del documento</h2>
        <label>Pago
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
        <CampoDescuento
          id="descuento-condiciones"
          modo={modo}
          valor={descuento}
          error={errores.descuento_documento ?? errores.descuento_documento_porcentaje}
          onModo={setModo}
          onValor={setDescuento}
        />
        {general && <div className="aviso error">{general}</div>}
        <div className="acciones">
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button className="primario" disabled={guardar.isPending}>{guardar.isPending ? 'Guardando…' : 'Guardar'}</button>
        </div>
      </form>
    </div>
  )
}
