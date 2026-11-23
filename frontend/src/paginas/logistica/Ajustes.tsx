import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, mensajeError } from '../../api/cliente'
import {
  NOMBRE_MOTIVO,
  type AjusteInventario,
  type Devolucion,
  type MetodoCaptura,
  type MotivoAjuste,
  type MotivoBaja,
  type MotivoReintegro,
  type Pagina,
  type ReferenciaUnidad,
  type TipoAjuste,
  type Unidad,
} from '../../api/tipos'
import { useScanner } from '../../hooks/useScanner'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import ChipCalidad from '../../componentes/ChipCalidad'
import Paginacion from '../../componentes/Paginacion'
import { atraparTab } from '../../componentes/foco'
import { pesos } from '../../componentes/numeros'

/*
 * RF-21 (S-27): logística y admin sacan unidades del inventario (salidas), registran devoluciones
 * de clientes (RF-07) y corrigen una baja hecha por error (reintegro). El backend decide si una
 * unidad puede entrar; esta pantalla solo muestra su mensaje. Nada se borra y nada se exporta.
 */

type Pestana = 'salidas' | 'devoluciones' | 'historial'

const PESTANAS: { valor: Pestana; texto: string }[] = [
  { valor: 'salidas', texto: 'Salidas' },
  { valor: 'devoluciones', texto: 'Devoluciones de clientes' },
  { valor: 'historial', texto: 'Historial' },
]

const MOTIVOS_BAJA: MotivoBaja[] = ['garantia', 'devolucion_proveedor', 'dano', 'perdida', 'otro']
const MOTIVOS_REINTEGRO: MotivoReintegro[] = ['error_de_registro', 'otro']

/** Una unidad del lote de salida, con el método con que llegó su código (RF-12). */
interface ItemLote {
  codigo: string
  metodo: MetodoCaptura
  referencia: ReferenciaUnidad
  error?: string
}

type Aviso = { tipo: 'ok' | 'error' | 'atencion'; texto: string } | null

const fechaHora = (iso: string) =>
  new Date(iso).toLocaleString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })

/** Código de la unidad que rechazó el backend (422 con `codigo`), si lo trae. */
function codigoRechazado(error: unknown): string | null {
  if (!(error instanceof AxiosError)) return null
  const datos = error.response?.data as { codigo?: string } | undefined
  return datos?.codigo ?? null
}

export default function Ajustes() {
  const [params, setParams] = useSearchParams()
  const pestana = (PESTANAS.find((p) => p.valor === params.get('p'))?.valor ?? 'salidas') as Pestana
  // El lote vive aquí para que "Retirar esta unidad" (devoluciones) lo encuentre en Salidas.
  const [lote, setLote] = useState<ItemLote[]>([])

  const cambiar = (p: Pestana) => setParams(p === 'salidas' ? {} : { p }, { replace: true })

  const retirarDevuelta = (d: Devolucion, metodo: MetodoCaptura) => {
    setLote((l) => (l.some((i) => i.codigo === d.unidad.codigo_barras) ? l : [{ codigo: d.unidad.codigo_barras, metodo, referencia: d.referencia }, ...l]))
    cambiar('salidas')
  }

  return (
    <div className="pila ajustes">
      <div className="pagina-encabezado">
        <h1>Ajustes de inventario</h1>
        <p>Salidas por garantía, devolución a proveedor, daño o pérdida; devoluciones de clientes y reintegros. Nada se borra.</p>
      </div>
      <section className="panel">
        <ControlSegmentado id="ajustes-seccion" etiqueta="Sección" opciones={PESTANAS} valor={pestana} onCambio={cambiar} />
        {pestana === 'salidas' && <Salidas lote={lote} setLote={setLote} />}
        {pestana === 'devoluciones' && <Devoluciones onRetirar={retirarDevuelta} />}
        {pestana === 'historial' && <Historial />}
      </section>
    </div>
  )
}

/* ─────────────────────────────── Salidas ─────────────────────────────── */

function Salidas({ lote, setLote }: { lote: ItemLote[]; setLote: (f: (l: ItemLote[]) => ItemLote[]) => void }) {
  const qc = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const notaRef = useRef<HTMLTextAreaElement>(null)
  const [motivo, setMotivo] = useState<MotivoBaja>('garantia')
  const [nota, setNota] = useState('')
  const [aviso, setAviso] = useState<Aviso>(null)
  const [confirmando, setConfirmando] = useState(false)
  // Códigos que se están verificando: dos lecturas seguidas del mismo no entran dos veces.
  const enCurso = useRef(new Set<string>())
  const loteRef = useRef(lote)
  loteRef.current = lote

  const enfocar = () => setTimeout(() => inputRef.current?.focus(), 0)
  useEffect(() => { inputRef.current?.focus() }, [])

  const verificar = useMutation({
    mutationFn: async ({ codigo }: { codigo: string; metodo: MetodoCaptura }) =>
      (await api.get<Unidad>('/ajustes/unidad', { params: { tipo: 'baja' satisfies TipoAjuste, codigo } })).data,
    onSuccess: (u, { codigo, metodo }) => {
      enCurso.current.delete(codigo)
      const r = u.referencia
      if (!r) return
      const referencia: ReferenciaUnidad = {
        id: r.id,
        codigo_referencia: r.codigo_referencia,
        descripcion: r.descripcion,
        calidad: r.calidad,
        proveedor: r.proveedor ?? { id: r.proveedor_id, nombre: '' },
      }
      setLote((l) => (l.some((i) => i.codigo === u.codigo_barras) ? l : [{ codigo: u.codigo_barras, metodo, referencia }, ...l]))
      setAviso({ tipo: 'ok', texto: `${u.codigo_barras} agregada al lote · ${referencia.codigo_referencia} · ${referencia.proveedor.nombre}` })
    },
    onError: (e, { codigo }) => {
      enCurso.current.delete(codigo)
      setAviso({ tipo: 'error', texto: mensajeError(e) })
    },
  })

  const onCodigo = (codigo: string, metodo: MetodoCaptura) => {
    if (inputRef.current) inputRef.current.value = ''
    if (loteRef.current.some((i) => i.codigo === codigo) || enCurso.current.has(codigo)) {
      setAviso({ tipo: 'atencion', texto: `La unidad ${codigo} ya está en el lote.` })
      return
    }
    enCurso.current.add(codigo)
    setConfirmando(false)
    verificar.mutate({ codigo, metodo })
  }
  const scanner = useScanner(onCodigo)

  const faltaNota = motivo === 'otro' && nota.trim() === ''

  const registrar = useMutation({
    mutationFn: async () =>
      (await api.post<AjusteInventario>('/ajustes/salidas', {
        motivo,
        nota: nota.trim() || null,
        codigos: lote.map((i) => ({ codigo: i.codigo, metodo_captura: i.metodo })),
      })).data,
    onSuccess: (a) => {
      setLote(() => [])
      setNota('')
      setConfirmando(false)
      setAviso({ tipo: 'ok', texto: `Salida #${a.id} registrada: ${a.cantidad} ${a.cantidad === 1 ? 'unidad' : 'unidades'} por ${NOMBRE_MOTIVO[a.motivo]}. Ya no cuentan en la existencia.` })
      qc.invalidateQueries({ queryKey: ['ajustes'] })
      qc.invalidateQueries({ queryKey: ['referencias'] })
      enfocar()
    },
    onError: (e) => {
      setConfirmando(false)
      const codigo = codigoRechazado(e)
      const texto = mensajeError(e)
      // Se marca la unidad que falló y se conserva el resto del lote.
      if (codigo) setLote((l) => l.map((i) => (i.codigo === codigo ? { ...i, error: texto } : { ...i, error: undefined })))
      setAviso({ tipo: 'error', texto: codigo ? `No se registró nada. ${texto} Quítela del lote y vuelva a intentar.` : texto })
      enfocar()
    },
  })

  const quitar = (codigo: string) => {
    setLote((l) => l.filter((i) => i.codigo !== codigo))
    setConfirmando(false)
    enfocar()
  }

  const pedirConfirmacion = () => {
    if (faltaNota) {
      setAviso({ tipo: 'error', texto: 'Escriba una nota: el motivo "Otro" la exige.' })
      notaRef.current?.focus()
      return
    }
    setConfirmando(true)
  }

  const n = lote.length
  const textoBoton = `Registrar salida de ${n} ${n === 1 ? 'unidad' : 'unidades'}`

  return (
    <div className="ajustes-seccion">
      <div className="ajustes-motivo">
        <label>Motivo de la salida
          <select value={motivo} onChange={(e) => { setMotivo(e.target.value as MotivoBaja); setConfirmando(false) }}>
            {MOTIVOS_BAJA.map((m) => <option key={m} value={m}>{NOMBRE_MOTIVO[m]}</option>)}
          </select>
        </label>
        <label>Nota {motivo === 'otro' ? '(obligatoria)' : '(opcional)'}
          <textarea
            ref={notaRef}
            rows={2}
            maxLength={500}
            value={nota}
            aria-invalid={faltaNota && confirmando ? true : undefined}
            onChange={(e) => setNota(e.target.value)}
            placeholder={motivo === 'devolucion_proveedor' ? 'Ej.: guía o número de la nota del proveedor' : ''}
          />
        </label>
      </div>
      {motivo === 'garantia' && <p className="ayuda">Garantía solo registra la salida: el reclamo al fabricante se sigue por fuera del sistema.</p>}
      {motivo === 'devolucion_proveedor' && <p className="ayuda">Solo saca la unidad del inventario: no crea nota crédito ni cambia el saldo del proveedor.</p>}

      <label htmlFor="ajustes-lector">Código de la unidad (etiqueta del local)</label>
      <input
        id="ajustes-lector"
        ref={inputRef}
        className="escaner"
        placeholder="Escanee la etiqueta (o digite el código y Enter)"
        onKeyDown={scanner.onKeyDown}
        onPaste={scanner.onPaste}
        autoComplete="off"
      />
      {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

      {n === 0 ? (
        <p className="vacio">El lote está vacío. Escanee las unidades que salen del inventario.</p>
      ) : (
        <table className="tabla-apilable">
          <caption className="solo-lector">Unidades del lote de salida</caption>
          <thead><tr><th>Unidad</th><th>Referencia</th><th>Descripción</th><th>Proveedor</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
          <tbody>
            {lote.map((i) => (
              <tr key={i.codigo} className={i.error ? 'fila-con-error' : undefined}>
                <td data-etiqueta="Unidad" className="mono">
                  {i.codigo} {i.metodo === 'digitado' && <span className="chip digitado">digitado</span>}
                  {i.error && <div className="ayuda error">{i.error}</div>}
                </td>
                <td data-etiqueta="Referencia"><strong>{i.referencia.codigo_referencia}</strong></td>
                <td data-etiqueta="Descripción">{i.referencia.descripcion}</td>
                <td data-etiqueta="Proveedor">{i.referencia.proveedor.nombre} <ChipCalidad calidad={i.referencia.calidad} /></td>
                <td className="celda-accion">
                  <button type="button" className="secundario boton-44" onClick={() => quitar(i.codigo)} aria-label={`Quitar ${i.codigo} del lote`}>Quitar</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <div className="barra-lote">
        {confirmando ? (
          <div className="confirmacion" role="alertdialog" aria-label="Confirmar salida">
            <p>¿Sacar del inventario {n} {n === 1 ? 'unidad' : 'unidades'} por <strong>{NOMBRE_MOTIVO[motivo]}</strong>? No se borra: queda en el historial.</p>
            <div className="acciones">
              <button type="button" className="secundario boton-44" onClick={() => { setConfirmando(false); enfocar() }}>Cancelar</button>
              <button type="button" className="peligro boton-44" autoFocus disabled={registrar.isPending} onClick={() => registrar.mutate()}>
                {registrar.isPending ? 'Registrando…' : 'Confirmar salida'}
              </button>
            </div>
          </div>
        ) : (
          <div className="acciones">
            <button type="button" className="primario boton-44" disabled={n === 0 || registrar.isPending} onClick={pedirConfirmacion}>{textoBoton}</button>
          </div>
        )}
      </div>
    </div>
  )
}

/* ─────────────────────────── Devoluciones de clientes ─────────────────────────── */

function Devoluciones({ onRetirar }: { onRetirar: (d: Devolucion, metodo: MetodoCaptura) => void }) {
  const qc = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const [encontrada, setEncontrada] = useState<{ datos: Devolucion; metodo: MetodoCaptura } | null>(null)
  const [hecha, setHecha] = useState<{ datos: Devolucion; metodo: MetodoCaptura } | null>(null)
  const [motivo, setMotivo] = useState('')
  const [aviso, setAviso] = useState<Aviso>(null)

  const enfocar = () => setTimeout(() => inputRef.current?.focus(), 0)
  useEffect(() => { inputRef.current?.focus() }, [])

  const consultar = useMutation({
    mutationFn: async ({ codigo }: { codigo: string; metodo: MetodoCaptura }) =>
      (await api.get<Devolucion>('/devoluciones/consulta', { params: { codigo } })).data,
    onSuccess: (datos, { metodo }) => {
      setEncontrada({ datos, metodo })
      setAviso(null)
    },
    onError: (e) => {
      setEncontrada(null)
      setAviso({ tipo: 'error', texto: mensajeError(e) })
    },
  })

  const onCodigo = (codigo: string, metodo: MetodoCaptura) => {
    if (inputRef.current) inputRef.current.value = ''
    setHecha(null)
    setMotivo('')
    consultar.mutate({ codigo, metodo })
  }
  const scanner = useScanner(onCodigo)

  const registrar = useMutation({
    mutationFn: async () => {
      if (!encontrada) throw new Error('Escanee la unidad que devuelve el cliente.')
      return (await api.post<Devolucion>('/devoluciones', {
        codigo: encontrada.datos.unidad.codigo_barras,
        motivo: motivo.trim(),
        metodo_captura: encontrada.metodo,
      })).data
    },
    onSuccess: (datos) => {
      const metodo = encontrada?.metodo ?? 'digitado'
      setHecha({ datos, metodo })
      setEncontrada(null)
      setMotivo('')
      setAviso({ tipo: 'ok', texto: `Devolución registrada: la unidad ${datos.unidad.codigo_barras} volvió al inventario.` })
      qc.invalidateQueries({ queryKey: ['referencias'] })
      enfocar()
    },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeError(e) }),
  })

  const d = encontrada?.datos
  const credito = d?.venta.forma_pago === 'credito'
  const precio = d?.precio_unitario ? pesos(d.precio_unitario) : '—'

  return (
    <div className="ajustes-seccion">
      <p className="ayuda">Solo para ventas cerradas. Si la venta sigue abierta, anule el ítem desde Mostrador.</p>
      <label htmlFor="devolucion-lector">Código de la unidad que devuelve el cliente</label>
      <input
        id="devolucion-lector"
        ref={inputRef}
        className="escaner"
        placeholder="Escanee la etiqueta (o digite el código y Enter)"
        onKeyDown={scanner.onKeyDown}
        onPaste={scanner.onPaste}
        autoComplete="off"
      />
      {consultar.isPending && <p className="ayuda" role="status">Buscando la venta…</p>}
      {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

      {hecha && (
        <div className="acciones acciones-izquierda">
          <button type="button" className="secundario boton-44" onClick={() => onRetirar(hecha.datos, hecha.metodo)}>
            Retirar esta unidad (garantía, daño…)
          </button>
        </div>
      )}

      {d && (
        <form className="tarjeta-devolucion" onSubmit={(e) => { e.preventDefault(); registrar.mutate() }}>
          <dl className="datos-fijos datos-devolucion">
            <div><dt>Venta</dt><dd>#{d.venta.id}</dd></div>
            <div><dt>Fecha</dt><dd>{fechaHora(d.venta.cerrada_at ?? d.venta.created_at)}</dd></div>
            <div><dt>Cliente</dt><dd>{d.venta.cliente?.nombre ?? 'Mostrador'}</dd></div>
            <div><dt>Forma de pago</dt><dd><span className={credito ? 'chip credito' : 'chip'}>{credito ? 'Crédito' : 'Contado'}</span></dd></div>
            <div><dt>Precio</dt><dd>{precio}</dd></div>
            <div><dt>Unidad</dt><dd className="mono">{d.unidad.codigo_barras}</dd></div>
            <div><dt>Referencia</dt><dd>{d.referencia.codigo_referencia} · {d.referencia.descripcion}</dd></div>
            <div><dt>Proveedor</dt><dd>{d.referencia.proveedor.nombre} <ChipCalidad calidad={d.referencia.calidad} /></dd></div>
          </dl>
          {credito && (
            <div className="aviso atencion">Es una venta a crédito: el saldo de {d.venta.cliente?.nombre ?? 'el cliente'} baja en {precio}.</div>
          )}
          <label>Motivo de la devolución (obligatorio)
            <textarea required minLength={3} maxLength={500} rows={2} value={motivo} onChange={(e) => setMotivo(e.target.value)} />
          </label>
          <div className="acciones">
            <button type="button" className="secundario boton-44" onClick={() => { setEncontrada(null); setMotivo(''); enfocar() }}>Cancelar</button>
            <button className="primario boton-44" disabled={motivo.trim().length < 3 || registrar.isPending}>
              {registrar.isPending ? 'Registrando…' : 'Registrar devolución'}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}

/* ─────────────────────────────── Historial ─────────────────────────────── */

function Historial() {
  const [tipo, setTipo] = useState<'' | TipoAjuste>('')
  const [motivo, setMotivo] = useState<'' | MotivoAjuste>('')
  const [desde, setDesde] = useState('')
  const [hasta, setHasta] = useState('')
  const [reintegrando, setReintegrando] = useState<AjusteInventario | null>(null)
  const [aviso, setAviso] = useState<Aviso>(null)

  const filtros = { tipo, motivo, desde, hasta }
  const { filas, resultado, irA, cargando } = useListaPaginada<AjusteInventario>({
    clave: ['ajustes', filtros],
    filtros,
    traer: async (page) => (await api.get<Pagina<AjusteInventario>>('/ajustes', {
      params: { tipo: tipo || undefined, motivo: motivo || undefined, desde: desde || undefined, hasta: hasta || undefined, page },
    })).data,
  })

  const motivos: MotivoAjuste[] = tipo === 'reintegro' ? MOTIVOS_REINTEGRO : tipo === 'baja' ? MOTIVOS_BAJA : [...MOTIVOS_BAJA, 'error_de_registro']

  return (
    <div className="ajustes-seccion">
      <div className="formulario en-linea filtros-ajustes">
        <label>Tipo
          <select value={tipo} onChange={(e) => { setTipo(e.target.value as '' | TipoAjuste); setMotivo('') }}>
            <option value="">Todos</option>
            <option value="baja">Salidas</option>
            <option value="reintegro">Reintegros</option>
          </select>
        </label>
        <label>Motivo
          <select value={motivo} onChange={(e) => setMotivo(e.target.value as '' | MotivoAjuste)}>
            <option value="">Todos</option>
            {motivos.map((m) => <option key={m} value={m}>{NOMBRE_MOTIVO[m]}</option>)}
          </select>
        </label>
        <label>Desde<input type="date" value={desde} max={hasta || undefined} onChange={(e) => setDesde(e.target.value)} /></label>
        <label>Hasta<input type="date" value={hasta} min={desde || undefined} onChange={(e) => setHasta(e.target.value)} /></label>
      </div>
      {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

      {filas.length === 0 ? (
        <p className="vacio">{cargando ? 'Cargando…' : 'No hay ajustes con esos filtros.'}</p>
      ) : (
        <table className={cargando ? 'tabla-apilable actualizando' : 'tabla-apilable'}>
          <caption className="solo-lector">Historial de ajustes de inventario</caption>
          <thead><tr><th>Fecha</th><th>Tipo y motivo</th><th>Usuario</th><th>Unidades</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
          <tbody>
            {filas.map((a) => {
              const reintegrables = a.unidades.filter((u) => u.reintegrable)
              return (
                <tr key={a.id}>
                  <td data-etiqueta="Fecha">{fechaHora(a.created_at)}<div className="ayuda">#{a.id}</div></td>
                  <td data-etiqueta="Tipo y motivo">
                    <span className={a.tipo === 'baja' ? 'chip salida' : 'chip reintegro'}>{a.tipo === 'baja' ? 'Salida' : 'Reintegro'}</span>{' '}
                    {NOMBRE_MOTIVO[a.motivo]}
                    {a.nota && <div className="ayuda nota-ajuste">{a.nota}</div>}
                  </td>
                  <td data-etiqueta="Usuario">{a.usuario.name}</td>
                  <td data-etiqueta={`Unidades (${a.cantidad})`}>
                    <ul className="unidades-ajuste">
                      {a.unidades.map((u) => (
                        <li key={u.id}>
                          <span className="mono">{u.codigo_barras}</span> · <strong>{u.referencia.codigo_referencia}</strong> · {u.referencia.proveedor.nombre}{' '}
                          <ChipCalidad calidad={u.referencia.calidad} />
                          <span className={`chip estado-${u.estado}`} title="Estado actual de la unidad">{u.estado}</span>
                        </li>
                      ))}
                    </ul>
                  </td>
                  <td className="celda-accion">
                    {reintegrables.length > 0 && (
                      <button type="button" className="secundario boton-44" onClick={() => setReintegrando(a)}>
                        Reintegrar{reintegrables.length < a.unidades.length ? ` (${reintegrables.length})` : ''}
                      </button>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
      <Paginacion pagina={resultado} onCambiar={irA} />

      {reintegrando && (
        <DialogoReintegro
          baja={reintegrando}
          onCerrar={() => setReintegrando(null)}
          onListo={(r) => {
            setReintegrando(null)
            setAviso({ tipo: 'ok', texto: `Reintegro #${r.id}: ${r.cantidad} ${r.cantidad === 1 ? 'unidad volvió' : 'unidades volvieron'} al inventario.` })
          }}
        />
      )}
    </div>
  )
}

/** RF-21: corrige una baja hecha por error. Solo las unidades que siguen retiradas por esa baja. */
function DialogoReintegro({ baja, onCerrar, onListo }: {
  baja: AjusteInventario
  onCerrar: () => void
  onListo: (r: AjusteInventario) => void
}) {
  const qc = useQueryClient()
  const [motivo, setMotivo] = useState<MotivoReintegro>('error_de_registro')
  const [nota, setNota] = useState('')
  const unidades = baja.unidades.filter((u) => u.reintegrable)
  const faltaNota = motivo === 'otro' && nota.trim() === ''

  const reintegrar = useMutation({
    mutationFn: async () =>
      (await api.post<AjusteInventario>('/ajustes/reintegros', {
        motivo,
        nota: nota.trim() || null,
        // Elegidas en pantalla, no escaneadas (RF-12).
        codigos: unidades.map((u) => ({ codigo: u.codigo_barras, metodo_captura: 'digitado' satisfies MetodoCaptura })),
      })).data,
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['ajustes'] })
      qc.invalidateQueries({ queryKey: ['referencias'] })
      onListo(r)
    },
  })

  const alTeclear = (e: KeyboardEvent<HTMLFormElement>) => {
    if (e.key === 'Escape') onCerrar()
    atraparTab(e)
  }

  return (
    <div className="modal">
      <form
        className="modal-caja"
        role="dialog"
        aria-modal="true"
        aria-labelledby="reintegro-titulo"
        onKeyDown={alTeclear}
        onSubmit={(e) => { e.preventDefault(); if (!faltaNota) reintegrar.mutate() }}
      >
        <h2 id="reintegro-titulo">Reintegrar la salida #{baja.id}</h2>
        <p>
          {unidades.length === 1 ? 'La unidad vuelve' : `Las ${unidades.length} unidades vuelven`} al inventario y suben la existencia.
          La salida no se borra: queda el reintegro con su usuario, fecha y motivo.
        </p>
        <ul className="unidades-ajuste">
          {unidades.map((u) => (
            <li key={u.id}><span className="mono">{u.codigo_barras}</span> · <strong>{u.referencia.codigo_referencia}</strong> · {u.referencia.proveedor.nombre}</li>
          ))}
        </ul>
        <ControlSegmentado
          id="reintegro-motivo"
          etiqueta="Motivo"
          opciones={MOTIVOS_REINTEGRO.map((m) => ({ valor: m, texto: NOMBRE_MOTIVO[m] }))}
          valor={motivo}
          onCambio={setMotivo}
        />
        <label>Nota {motivo === 'otro' ? '(obligatoria)' : '(opcional)'}
          <textarea rows={2} maxLength={500} value={nota} onChange={(e) => setNota(e.target.value)} autoFocus={false} />
        </label>
        {reintegrar.isError && <div className="aviso error">{mensajeError(reintegrar.error)}</div>}
        <div className="acciones">
          <button type="button" className="secundario boton-44" onClick={onCerrar}>Cancelar</button>
          <button className="primario boton-44" autoFocus disabled={faltaNota || reintegrar.isPending}>
            {reintegrar.isPending ? 'Reintegrando…' : 'Confirmar reintegro'}
          </button>
        </div>
      </form>
    </div>
  )
}
