import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, mensajeError } from '../../api/cliente'
import {
  NOMBRE_MOTIVO,
  type AjusteInventario,
  type ConsultaDevolucion,
  type Devolucion,
  type GrupoDevolucion,
  type MetodoCaptura,
  type MotivoAjuste,
  type MotivoAlta,
  type MotivoBaja,
  type MotivoReintegro,
  type OpcionReferenciaVenta,
  type OrigenCodigo,
  type Pagina,
  type ReferenciaEntrada,
  type ReferenciaUnidad,
  type ResultadoDevolucion,
  type TipoAjuste,
  type Unidad,
} from '../../api/tipos'
import { useScanner } from '../../hooks/useScanner'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { useDebounce } from '../../hooks/useDebounce'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import ChipCalidad from '../../componentes/ChipCalidad'
import CampoCantidad from '../../componentes/CampoCantidad'
import ImprimirEtiquetas from '../../componentes/ImprimirEtiquetas'
import Icono from '../../componentes/Icono'
import DialogoElegirReferencia from '../../componentes/mostrador/DialogoElegirReferencia'
import Paginacion from '../../componentes/Paginacion'
import { atraparTab } from '../../componentes/foco'
import { pesos } from '../../componentes/numeros'
import FiltroPeriodo, { type ValorPeriodo } from '../../componentes/FiltroPeriodo'
import { PERIODO_POR_DEFECTO, errorRango, esPeriodo, hoyEnBogota, rangoDe } from '../../utilidades/periodos'
import { formatearFechaHora } from '../../utilidades/fechas'
import { textoSaldo } from '../../utilidades/cartera'

/*
 * RF-21 (S-27): logística y admin sacan unidades del inventario (salidas), registran devoluciones
 * de clientes (RF-07) y corrigen una baja hecha por error (reintegro). El backend decide si una
 * unidad puede entrar; esta pantalla solo muestra su mensaje. Nada se borra y nada se exporta.
 */

type Pestana = 'salidas' | 'entradas' | 'devoluciones' | 'historial'

const PESTANAS: { valor: Pestana; texto: string }[] = [
  { valor: 'salidas', texto: 'Salidas' },
  { valor: 'entradas', texto: 'Entradas' }, // S-39
  { valor: 'devoluciones', texto: 'Devoluciones de clientes' },
  { valor: 'historial', texto: 'Historial' },
]

const MOTIVOS_BAJA: MotivoBaja[] = ['garantia', 'devolucion_proveedor', 'dano', 'perdida', 'faltante_inventario', 'otro']
const MOTIVOS_REINTEGRO: MotivoReintegro[] = ['error_de_registro', 'otro']

/**
 * Una unidad del lote de salida, con el método con que llegó su código (RF-12). S-34: `codigo` es
 * siempre la etiqueta de la unidad; con código de proveedor, `leido` es lo escaneado y `origen`
 * 'referencia' (la unidad la asignó el sistema).
 */
interface ItemLote {
  codigo: string
  unidadId: number
  leido: string
  origen: OrigenCodigo
  metodo: MetodoCaptura
  referencia: ReferenciaUnidad
  error?: string
}

type Aviso = { tipo: 'ok' | 'error' | 'atencion'; texto: string } | null

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

  // S-38: "Retirar estas unidades" lleva al lote de Salidas las unidades recién devueltas (por etiqueta).
  const retirarDevuelta = (unidades: { id: number; codigo: string }[], referencia: ReferenciaUnidad, metodo: MetodoCaptura) => {
    setLote((l) => [
      ...unidades.filter((u) => !l.some((i) => i.codigo === u.codigo))
        .map((u): ItemLote => ({ codigo: u.codigo, unidadId: u.id, leido: u.codigo, origen: 'etiqueta', metodo, referencia })),
      ...l,
    ])
    cambiar('salidas')
  }

  return (
    <div className="pila ajustes">
      <div className="pagina-encabezado">
        <h1>Ajustes de inventario</h1>
        <p>Salidas por garantía, devolución a proveedor, daño, pérdida o faltante; entradas por inventario físico; devoluciones de clientes y reintegros. Nada se borra.</p>
      </div>
      <section className="panel">
        <ControlSegmentado id="ajustes-seccion" etiqueta="Sección" opciones={PESTANAS} valor={pestana} onCambio={cambiar} />
        {pestana === 'salidas' && <Salidas lote={lote} setLote={setLote} />}
        {pestana === 'entradas' && <Entradas />}
        {pestana === 'devoluciones' && <Devoluciones onRetirar={retirarDevuelta} />}
        {pestana === 'historial' && <Historial />}
      </section>
    </div>
  )
}

/* ─────────────────────────────── Salidas ─────────────────────────────── */

/** Lectura pendiente de verificar (S-34: con referenciaId si se eligió entre varias; S-38: cantidad). */
interface Lectura { codigo: string; metodo: MetodoCaptura; cantidad: number; referenciaId?: number }

/** S-38: el lote se muestra agrupado por referencia, en el orden en que entró cada una. */
function agruparLote(lote: ItemLote[]): { referencia: ReferenciaUnidad; unidades: ItemLote[] }[] {
  const grupos = new Map<number, { referencia: ReferenciaUnidad; unidades: ItemLote[] }>()
  for (const item of [...lote].reverse()) {
    const g = grupos.get(item.referencia.id) ?? { referencia: item.referencia, unidades: [] }
    g.unidades.push(item)
    grupos.set(item.referencia.id, g)
  }
  return [...grupos.values()].reverse()
}

const MAX_CANTIDAD = 200

function Salidas({ lote, setLote }: { lote: ItemLote[]; setLote: (f: (l: ItemLote[]) => ItemLote[]) => void }) {
  const qc = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const notaRef = useRef<HTMLTextAreaElement>(null)
  const [motivo, setMotivo] = useState<MotivoBaja>('garantia')
  const [nota, setNota] = useState('')
  const [aviso, setAviso] = useState<Aviso>(null)
  const [confirmando, setConfirmando] = useState(false)
  const [verificando, setVerificando] = useState(false)
  /** S-38: cuántas unidades tomar con un código de proveedor (con una etiqueta siempre es 1). */
  const [cantidad, setCantidad] = useState(1)
  const cantidadRef = useRef(cantidad)
  cantidadRef.current = cantidad
  /** S-34: el código de proveedor está en varias referencias; se espera la elección. */
  const [eligiendo, setEligiendo] = useState<{ lectura: Lectura; referencias: OpcionReferenciaVenta[] } | null>(null)
  const loteRef = useRef(lote)
  loteRef.current = lote
  // S-34: las lecturas se verifican una tras otra. Así dos lecturas seguidas del mismo código de
  // proveedor excluyen las unidades que tomó la anterior (excluir[]) y nunca piden las mismas.
  const cola = useRef<Lectura[]>([])
  const ocupado = useRef(false)

  const enfocar = () => setTimeout(() => inputRef.current?.focus(), 0)
  useEffect(() => { inputRef.current?.focus() }, [])

  const aReferencia = (r: NonNullable<Unidad['referencia']>): ReferenciaUnidad => ({
    id: r.id,
    codigo_referencia: r.codigo_referencia,
    descripcion: r.descripcion,
    calidad: r.calidad,
    proveedor: r.proveedor ?? { id: r.proveedor_id, nombre: '' },
  })

  const procesar = async () => {
    if (ocupado.current) return
    ocupado.current = true
    setVerificando(true)
    while (cola.current.length > 0) {
      const lectura = cola.current.shift()!
      try {
        const { unidades } = (await api.get<{ unidades: Unidad[] }>('/ajustes/unidad', {
          params: {
            tipo: 'baja' satisfies TipoAjuste,
            codigo: lectura.codigo,
            referencia_id: lectura.referenciaId,
            excluir: loteRef.current.map((i) => i.unidadId),
            cantidad: lectura.cantidad,
          },
        })).data
        const nuevas: ItemLote[] = unidades
          .filter((u) => u.referencia && !loteRef.current.some((i) => i.codigo === u.codigo_barras))
          .map((u) => ({ codigo: u.codigo_barras, unidadId: u.id, leido: lectura.codigo, origen: u.origen_codigo ?? 'etiqueta', metodo: lectura.metodo, referencia: aReferencia(u.referencia!) }))
        if (nuevas.length === 0) {
          setAviso({ tipo: 'atencion', texto: `La unidad ${lectura.codigo} ya está en el lote.` })
          continue
        }
        loteRef.current = [...nuevas, ...loteRef.current]
        setLote((l) => [...nuevas.filter((n) => !l.some((i) => i.codigo === n.codigo)), ...l])
        const r = nuevas[0].referencia
        setAviso({
          tipo: 'ok',
          texto: nuevas[0].origen === 'referencia'
            ? `${nuevas.length === 1 ? `${nuevas[0].codigo} agregada` : `${nuevas.length} unidades agregadas`} al lote por referencia (${lectura.codigo}) · ${r.codigo_referencia} · ${r.proveedor.nombre}`
            : `${nuevas[0].codigo} agregada al lote · ${r.codigo_referencia} · ${r.proveedor.nombre}`,
        })
        setCantidad(1)
      } catch (e) {
        const datos = e instanceof AxiosError ? e.response?.data as { tipo?: string; codigo?: string; message?: string; referencias?: OpcionReferenciaVenta[] } | undefined : undefined
        if (datos?.tipo === 'elegir_referencia' && datos.referencias?.length) {
          // Lo que siga en la cola espera a la elección.
          setAviso(null)
          setEligiendo({ lectura, referencias: datos.referencias })
          break
        }
        // S-38: con una etiqueta la cantidad no aplica: se agrega esa unidad (1) y se avisa.
        if (lectura.cantidad > 1 && datos?.message?.startsWith('Cada etiqueta es una unidad')) {
          cola.current.unshift({ ...lectura, cantidad: 1 })
          setCantidad(1)
          continue
        }
        setAviso({ tipo: 'error', texto: mensajeError(e) })
      }
    }
    ocupado.current = false
    setVerificando(false)
  }

  const encolar = (lectura: Lectura) => {
    cola.current.push(lectura)
    void procesar()
  }

  const onCodigo = (codigo: string, metodo: MetodoCaptura) => {
    if (inputRef.current) inputRef.current.value = ''
    // Una etiqueta ya leída no entra dos veces; un código de proveedor sí (otras unidades).
    if (loteRef.current.some((i) => i.codigo === codigo)) {
      setAviso({ tipo: 'atencion', texto: `La unidad ${codigo} ya está en el lote.` })
      return
    }
    setConfirmando(false)
    encolar({ codigo, metodo, cantidad: cantidadRef.current })
  }
  const scanner = useScanner(onCodigo)

  const faltaNota = motivo === 'otro' && nota.trim() === ''

  const registrar = useMutation({
    mutationFn: async () =>
      (await api.post<AjusteInventario>('/ajustes/salidas', {
        motivo,
        nota: nota.trim() || null,
        // Una fila por unidad. Con código de proveedor va lo leído y la unidad asignada en la verificación.
        codigos: lote.map((i) => (i.origen === 'referencia'
          ? { codigo: i.leido, metodo_captura: i.metodo, origen_codigo: 'referencia', unidad_codigo: i.codigo }
          : { codigo: i.codigo, metodo_captura: i.metodo, origen_codigo: 'etiqueta' })),
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

  const quitar = (codigos: string[]) => {
    setLote((l) => l.filter((i) => !codigos.includes(i.codigo)))
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
  const grupos = agruparLote(lote)
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

      <div className="lector-con-cantidad">
        <div className="lector-campo">
          <label htmlFor="ajustes-lector" className="etiqueta-campo">Etiqueta del local o código del proveedor</label>
          <input
            id="ajustes-lector"
            ref={inputRef}
            className="escaner"
            placeholder="Escanee o digite el código"
            onKeyDown={scanner.onKeyDown}
            onPaste={scanner.onPaste}
            autoComplete="off"
          />
        </div>
        <CampoCantidad
          id="salida-cantidad"
          etiqueta="Cantidad"
          valor={cantidad}
          max={MAX_CANTIDAD}
          onCambio={setCantidad}
          ayuda="Con el código del proveedor. Cada etiqueta es una unidad."
        />
      </div>
      <p className="ayuda">Con el código del proveedor el sistema elige las unidades: primero las que no tienen etiqueta impresa, la más antigua.</p>
      {verificando && <p className="ayuda" role="status">Verificando…</p>}
      {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

      {n === 0 ? (
        <p className="vacio">El lote está vacío. Escanee las unidades que salen del inventario.</p>
      ) : (
        <table className="tabla-apilable tabla-lote">
          <caption className="solo-lector">Unidades del lote de salida, agrupadas por referencia</caption>
          <thead><tr><th>Producto</th><th className="col-numero">Cantidad</th><th>Unidades</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
          <tbody>
            {grupos.map(({ referencia: r, unidades }) => {
              const conError = unidades.some((u) => u.error)
              const porReferencia = unidades.some((u) => u.origen === 'referencia')
              return (
                <tr key={r.id} className={conError ? 'fila-con-error' : undefined}>
                  <td data-etiqueta="Producto">
                    <span>
                      <strong>{r.codigo_referencia}</strong> <ChipCalidad calidad={r.calidad} />
                      <small className="bloque">{r.descripcion}</small>
                      <small className="bloque">{r.proveedor.nombre}</small>
                    </span>
                  </td>
                  <td data-etiqueta="Cantidad" className="col-numero"><strong className="cantidad-lote">× {unidades.length}</strong></td>
                  <td data-etiqueta="Unidades" className="celda-unidades">
                    <details open={conError || undefined}>
                      <summary>
                        Ver {unidades.length === 1 ? 'la unidad' : `las ${unidades.length} unidades`}
                        {porReferencia && <> <span className="chip por-referencia">por referencia</span></>}
                      </summary>
                      <ul className="unidades-lote">
                        {unidades.map((u) => (
                          <li key={u.codigo}>
                            <span className="codigo-unidad">
                              <span className="mono">{u.codigo}</span>
                              {u.metodo === 'digitado' && <span className="chip digitado">digitado</span>}
                            </span>
                            <button type="button" className="secundario boton-44" onClick={() => quitar([u.codigo])} aria-label={`Quitar ${u.codigo} del lote`}>Quitar</button>
                            {u.error && <span className="ayuda error bloque">{u.error}</span>}
                          </li>
                        ))}
                      </ul>
                    </details>
                  </td>
                  <td className="celda-accion">
                    <button type="button" className="secundario boton-44" onClick={() => quitar(unidades.map((u) => u.codigo))} aria-label={`Quitar las ${unidades.length} unidades de ${r.codigo_referencia}`}>
                      {unidades.length === 1 ? 'Quitar' : 'Quitar todas'}
                    </button>
                  </td>
                </tr>
              )
            })}
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
            <button type="button" className="primario boton-44" disabled={n === 0 || registrar.isPending || verificando} onClick={pedirConfirmacion}>{textoBoton}</button>
          </div>
        )}
      </div>

      {eligiendo && (
        <DialogoElegirReferencia
          titulo="¿Cuál referencia sale?"
          codigo={eligiendo.lectura.codigo}
          referencias={eligiendo.referencias}
          onElegir={(referenciaId) => {
            const { lectura } = eligiendo
            setEligiendo(null)
            // La elegida va primero, con la misma cantidad; lo que esperaba en la cola sigue después.
            cola.current.unshift({ ...lectura, referenciaId })
            void procesar()
            enfocar()
          }}
          onCerrar={() => {
            setEligiendo(null)
            enfocar()
            void procesar()
          }}
        />
      )}
    </div>
  )
}

/* ─────────────────────────────── Entradas ─────────────────────────────── */

/** S-39: motivos de una entrada por ajuste; el primero va por defecto. "Otro" exige nota. */
const MOTIVOS_ALTA: MotivoAlta[] = ['sobrante_inventario', 'sin_factura', 'otro']
const MAX_ENTRADA = 500

/**
 * S-39: entrada por ajuste de inventario físico. Se elige la referencia (escaneando o digitando el
 * código del proveedor o fabricante, o buscando por referencia o descripción), la cantidad (1 a 500)
 * y el motivo. Crea unidades nuevas con su código del local; no es una compra. Después se imprimen
 * sus etiquetas.
 */
function Entradas() {
  const qc = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const notaRef = useRef<HTMLTextAreaElement>(null)
  const [texto, setTexto] = useState('')
  const termino = useDebounce(texto.trim())
  const [elegida, setElegida] = useState<{ referencia: ReferenciaEntrada; metodo: MetodoCaptura } | null>(null)
  /** Varias referencias con el código escaneado: se elige una. */
  const [opciones, setOpciones] = useState<{ codigo: string; metodo: MetodoCaptura; referencias: ReferenciaEntrada[] } | null>(null)
  const [cantidad, setCantidad] = useState(1)
  const [motivo, setMotivo] = useState<MotivoAlta>('sobrante_inventario')
  const [nota, setNota] = useState('')
  const [confirmando, setConfirmando] = useState(false)
  const [aviso, setAviso] = useState<Aviso>(null)
  const [hecha, setHecha] = useState<{ ajuste: AjusteInventario; referencia: ReferenciaEntrada; existencia: number } | null>(null)

  const enfocar = () => setTimeout(() => inputRef.current?.focus(), 0)
  useEffect(() => { inputRef.current?.focus() }, [])

  const elegir = (referencia: ReferenciaEntrada, metodo: MetodoCaptura) => {
    setElegida({ referencia, metodo })
    setOpciones(null)
    setTexto('')
    setCantidad(1)
    setConfirmando(false)
    setAviso(null)
  }

  // Escáner o Enter: código exacto del proveedor o del fabricante.
  const porCodigo = useMutation({
    mutationFn: async ({ codigo }: { codigo: string; metodo: MetodoCaptura }) =>
      (await api.get<{ referencias: ReferenciaEntrada[] }>('/ajustes/referencia', { params: { codigo } })).data.referencias,
    onSuccess: (refs, { codigo, metodo }) => {
      if (refs.length === 1) elegir(refs[0], metodo)
      else { setOpciones({ codigo, metodo, referencias: refs }); setAviso(null) }
    },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeError(e) }),
  })

  const onCodigo = (codigo: string, metodo: MetodoCaptura) => {
    setHecha(null)
    porCodigo.mutate({ codigo, metodo })
  }
  const scanner = useScanner(onCodigo)

  // Lo que se escribe a mano busca por referencia o descripción (máx. 10).
  const busqueda = useQuery({
    queryKey: ['ajustes', 'referencia', termino],
    queryFn: async () => (await api.get<{ referencias: ReferenciaEntrada[] }>('/ajustes/referencia', { params: { q: termino } })).data.referencias,
    enabled: termino.length >= 2 && !elegida,
  })

  const faltaNota = motivo === 'otro' && nota.trim() === ''

  const registrar = useMutation({
    mutationFn: async () => {
      if (!elegida) throw new Error('Elija la referencia.')
      return (await api.post<AjusteInventario>('/ajustes/entradas', {
        referencia_id: elegida.referencia.id,
        cantidad,
        motivo,
        nota: nota.trim() || null,
        metodo_captura: elegida.metodo,
      })).data
    },
    onSuccess: (ajuste) => {
      if (elegida) setHecha({ ajuste, referencia: elegida.referencia, existencia: elegida.referencia.existencia + ajuste.cantidad })
      setElegida(null)
      setCantidad(1)
      setNota('')
      setMotivo('sobrante_inventario')
      setConfirmando(false)
      setAviso(null)
      qc.invalidateQueries({ queryKey: ['ajustes'] })
      qc.invalidateQueries({ queryKey: ['referencias'] })
      enfocar()
    },
    onError: (e) => { setConfirmando(false); setAviso({ tipo: 'error', texto: mensajeError(e) }) },
  })

  const pedirConfirmacion = () => {
    if (faltaNota) {
      setAviso({ tipo: 'error', texto: 'Escriba una nota: el motivo "Otro" la exige.' })
      notaRef.current?.focus()
      return
    }
    setConfirmando(true)
  }

  const r = elegida?.referencia
  const textoBoton = `Registrar entrada de ${cantidad} ${cantidad === 1 ? 'unidad' : 'unidades'}`

  return (
    <div className="ajustes-seccion">
      <p className="ayuda">
        Para unidades que aparecen en un inventario físico y no vienen de ninguna factura ni remisión. Se crean con su
        código del local; no es una compra (no tienen costo ni generan deuda). Un error se corrige con una salida.
      </p>

      <label htmlFor="entrada-referencia" className="etiqueta-campo">Referencia</label>
      <input
        id="entrada-referencia"
        ref={inputRef}
        className="escaner"
        placeholder="Escanee o escriba código o descripción"
        value={texto}
        onChange={(e) => { setTexto(e.target.value); setHecha(null) }}
        onKeyDown={(e) => {
          // Enter con lo escrito a mano: se toma como código exacto (como el escáner).
          scanner.onKeyDown(e)
          if (e.key === 'Enter' && texto.trim() !== '') setTexto('')
        }}
        onPaste={scanner.onPaste}
        autoComplete="off"
        aria-describedby="entrada-referencia-ayuda"
      />
      <small id="entrada-referencia-ayuda" className="ayuda">Código del proveedor o del fabricante (Enter), o parte de la referencia o la descripción.</small>
      {porCodigo.isPending && <p className="ayuda" role="status">Buscando la referencia…</p>}
      {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

      {!elegida && termino.length >= 2 && (
        busqueda.isFetching && !busqueda.data ? <p className="ayuda" role="status">Buscando…</p>
          : (busqueda.data?.length ?? 0) === 0 ? <p className="vacio">Ninguna referencia activa coincide.</p> : (
            <ul className="lista-candidatas" aria-label="Referencias encontradas">
              {busqueda.data!.map((ref) => <OpcionReferencia key={ref.id} r={ref} onElegir={() => elegir(ref, 'digitado')} />)}
            </ul>
          )
      )}

      {opciones && (
        <section className="elegir-venta" aria-labelledby="entrada-opciones-titulo">
          <h3 id="entrada-opciones-titulo">El código {opciones.codigo} está en {opciones.referencias.length} referencias</h3>
          <ul className="lista-candidatas">
            {opciones.referencias.map((ref, n) => <OpcionReferencia key={ref.id} r={ref} autoFocus={n === 0} onElegir={() => elegir(ref, opciones.metodo)} />)}
          </ul>
          <div className="acciones">
            <button type="button" className="secundario boton-44" onClick={() => { setOpciones(null); enfocar() }}>Cancelar</button>
          </div>
        </section>
      )}

      {hecha && (
        <div className="tarjeta-devolucion devolucion-hecha" role="status">
          <p>
            <strong>Entrada #{hecha.ajuste.id} registrada</strong> · {hecha.referencia.codigo_referencia} · {hecha.referencia.descripcion}:{' '}
            {hecha.ajuste.cantidad === 1 ? '1 unidad nueva' : `${hecha.ajuste.cantidad} unidades nuevas`}. Existencia: <strong>{hecha.existencia}</strong>.
          </p>
          <details>
            <summary>Ver {hecha.ajuste.cantidad === 1 ? 'el código creado' : `los ${hecha.ajuste.cantidad} códigos creados`}</summary>
            <ul className="codigos-lista">{hecha.ajuste.unidades.map((u) => <li key={u.id} className="mono codigo-unidad">{u.codigo_barras}</li>)}</ul>
          </details>
          <ImprimirEtiquetas cuerpo={{ ajuste_id: hecha.ajuste.id }} cantidad={hecha.ajuste.cantidad} />
          <div className="acciones acciones-izquierda">
            <button type="button" className="secundario boton-44" onClick={() => { setHecha(null); enfocar() }}>Nueva entrada</button>
          </div>
        </div>
      )}

      {r && (
        <form className="tarjeta-devolucion" onSubmit={(e) => { e.preventDefault(); pedirConfirmacion() }}>
          <dl className="datos-fijos datos-devolucion">
            <div><dt>Referencia</dt><dd><strong>{r.codigo_referencia}</strong> <ChipCalidad calidad={r.calidad} /></dd></div>
            <div><dt>Descripción</dt><dd>{r.descripcion}</dd></div>
            <div><dt>Proveedor</dt><dd>{r.proveedor?.nombre ?? '—'}</dd></div>
            <div><dt>Existencia actual</dt><dd>{r.existencia}</dd></div>
          </dl>
          {!r.tiene_precio && (
            <div className="aviso atencion" role="status">Sin precio de venta: no se podrá vender hasta que el administrador se lo asigne.</div>
          )}

          <div className="entrada-cantidad">
            <CampoCantidad id="entrada-cantidad" etiqueta="Cantidad" valor={cantidad} max={MAX_ENTRADA} onCambio={(v) => { setCantidad(v); setConfirmando(false) }} ayuda={`De 1 a ${MAX_ENTRADA}.`} />
            <p className="vista-previa" aria-live="polite">Existencia: <strong>{r.existencia} → {r.existencia + cantidad}</strong></p>
          </div>

          <div className="ajustes-motivo">
            <label>Motivo
              <select value={motivo} onChange={(e) => { setMotivo(e.target.value as MotivoAlta); setConfirmando(false) }}>
                {MOTIVOS_ALTA.map((m) => <option key={m} value={m}>{NOMBRE_MOTIVO[m]}</option>)}
              </select>
            </label>
            <label>Nota {motivo === 'otro' ? '(obligatoria)' : '(opcional)'}
              <textarea ref={notaRef} rows={2} maxLength={500} value={nota} aria-invalid={faltaNota && confirmando ? true : undefined} onChange={(e) => setNota(e.target.value)} />
            </label>
          </div>

          {confirmando ? (
            <div className="confirmacion" role="alertdialog" aria-label="Confirmar entrada">
              <p>¿Dar entrada a {cantidad} {cantidad === 1 ? 'unidad nueva' : 'unidades nuevas'} de <strong>{r.codigo_referencia}</strong> por {NOMBRE_MOTIVO[motivo]}? No se borra: queda en el historial.</p>
              <div className="acciones">
                <button type="button" className="secundario boton-44" onClick={() => { setConfirmando(false); enfocar() }}>Cancelar</button>
                <button type="button" className="primario boton-44" autoFocus disabled={registrar.isPending} onClick={() => registrar.mutate()}>
                  {registrar.isPending ? 'Registrando…' : 'Confirmar entrada'}
                </button>
              </div>
            </div>
          ) : (
            <div className="acciones">
              <button type="button" className="secundario boton-44" onClick={() => { setElegida(null); setAviso(null); enfocar() }}>Cambiar referencia</button>
              <button className="primario boton-44">{textoBoton}</button>
            </div>
          )}
        </form>
      )}
    </div>
  )
}

/** Una referencia para elegir: código, descripción, proveedor, calidad y existencia. */
function OpcionReferencia({ r, onElegir, autoFocus }: { r: ReferenciaEntrada; onElegir: () => void; autoFocus?: boolean }) {
  return (
    <li>
      <button type="button" className="candidata-venta" autoFocus={autoFocus} onClick={onElegir} aria-label={`Elegir ${r.codigo_referencia}, ${r.descripcion}`}>
        <span className="candidata-linea">
          <strong>{r.codigo_referencia} <ChipCalidad calidad={r.calidad} /></strong>
          <span className="chip disponibles">Existencia {r.existencia}</span>
        </span>
        <small>{r.descripcion} · {r.proveedor?.nombre ?? '—'}{r.tiene_precio ? '' : ' · sin precio de venta'}</small>
      </button>
    </li>
  )
}

/* ─────────────────────────── Devoluciones de clientes ─────────────────────────── */

/** S-38: motivos frecuentes; el primero va por defecto. Nada es obligatorio. */
const MOTIVOS_DEVOLUCION = ['Devolución del cliente', 'Producto equivocado', 'Producto defectuoso o garantía', 'Ya no lo necesita', 'Otro']

/** Lo que se va a devolver: con etiqueta, la venta de esa unidad (1); con código de proveedor, un grupo (S-38). */
type Elegida =
  | { tipo: 'etiqueta'; datos: Devolucion & { saldo_actual?: string | null }; metodo: MetodoCaptura }
  | { tipo: 'grupo'; grupo: GrupoDevolucion; metodo: MetodoCaptura }

/** Datos comunes de la tarjeta de resumen. */
function resumenDe(e: Elegida) {
  if (e.tipo === 'etiqueta') {
    const d = e.datos
    return {
      venta: d.venta.id, fecha: d.venta.cerrada_at ?? d.venta.created_at, cliente: d.venta.cliente, forma: d.venta.forma_pago,
      referencia: `${d.referencia.codigo_referencia} · ${d.referencia.descripcion}`, proveedor: d.referencia.proveedor.nombre, calidad: d.referencia.calidad,
      precio: d.precio_unitario, saldo: d.saldo_actual ?? null, max: 1, codigos: [d.unidad.codigo_barras],
    }
  }
  const g = e.grupo
  return {
    venta: g.venta_id, fecha: g.cerrada_at, cliente: g.cliente, forma: g.forma_pago,
    referencia: `${g.referencia.codigo_referencia} · ${g.referencia.descripcion}`, proveedor: g.referencia.proveedor ?? '', calidad: g.referencia.calidad,
    precio: g.precio_unitario, saldo: g.saldo_actual, max: g.cantidad_disponible, codigos: g.codigos,
  }
}

/** "$155.000 → $75.000 (baja $80.000)"; si queda negativo, a favor. */
function textoAntesDespues(antes: number, baja: number): string {
  const despues = antes - baja
  return `${textoSaldo(antes)} → ${textoSaldo(despues)} (baja ${pesos(baja)})`
}

function Devoluciones({ onRetirar }: { onRetirar: (unidades: { id: number; codigo: string }[], referencia: ReferenciaUnidad, metodo: MetodoCaptura) => void }) {
  const qc = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const notaRef = useRef<HTMLInputElement>(null)
  const [elegida, setElegida] = useState<Elegida | null>(null)
  const [cantidad, setCantidad] = useState(1)
  const [motivo, setMotivo] = useState(MOTIVOS_DEVOLUCION[0])
  const [nota, setNota] = useState('')
  const [conNota, setConNota] = useState(false)
  const [hecha, setHecha] = useState<{ datos: ResultadoDevolucion; metodo: MetodoCaptura } | null>(null)
  const [aviso, setAviso] = useState<Aviso>(null)
  /** S-34 / S-38: grupos candidatos de un código de proveedor; nunca se elige uno sin preguntar. */
  const [candidatas, setCandidatas] = useState<{ codigo: string; metodo: MetodoCaptura; ventas: GrupoDevolucion[] } | null>(null)
  const [buscarVenta, setBuscarVenta] = useState('')
  const ventaBuscada = useDebounce(buscarVenta.trim().replace(/^#\s*/, ''))

  const enfocar = () => setTimeout(() => inputRef.current?.focus(), 0)
  useEffect(() => { inputRef.current?.focus() }, [])

  const limpiarFormulario = () => {
    setElegida(null)
    setCantidad(1)
    setMotivo(MOTIVOS_DEVOLUCION[0])
    setNota('')
    setConNota(false)
  }

  const consultar = useMutation({
    mutationFn: async ({ codigo }: { codigo: string; metodo: MetodoCaptura }) =>
      (await api.get<ConsultaDevolucion>('/devoluciones/consulta', { params: { codigo } })).data,
    onSuccess: (datos, { metodo }) => {
      setAviso(null)
      limpiarFormulario()
      if ('tipo' in datos && datos.tipo === 'elegir_venta') {
        setBuscarVenta('')
        setCandidatas({ codigo: datos.codigo, metodo, ventas: datos.ventas })
        return
      }
      setCandidatas(null)
      setElegida({ tipo: 'etiqueta', datos: datos as Devolucion & { saldo_actual?: string | null }, metodo })
    },
    onError: (e) => {
      limpiarFormulario()
      setCandidatas(null)
      setAviso({ tipo: 'error', texto: mensajeError(e) })
    },
  })

  // Buscar por n.º de venta: primero entre los grupos cargados; si no está, se pregunta al backend.
  const filtradas = candidatas?.ventas.filter((v) => ventaBuscada === '' || String(v.venta_id).startsWith(ventaBuscada)) ?? []
  const buscarEnServidor = candidatas !== null && /^\d+$/.test(ventaBuscada) && filtradas.length === 0
  const porVenta = useQuery({
    queryKey: ['devoluciones', 'candidatas', candidatas?.codigo, ventaBuscada],
    queryFn: async () => (await api.get<ConsultaDevolucion>('/devoluciones/consulta', { params: { codigo: candidatas?.codigo, venta: ventaBuscada } })).data,
    enabled: buscarEnServidor,
  })
  const listaVentas = buscarEnServidor
    ? (porVenta.data && 'tipo' in porVenta.data ? porVenta.data.ventas : [])
    : filtradas

  const onCodigo = (codigo: string, metodo: MetodoCaptura) => {
    if (inputRef.current) inputRef.current.value = ''
    setHecha(null)
    consultar.mutate({ codigo, metodo })
  }
  const scanner = useScanner(onCodigo)

  const elegirGrupo = (g: GrupoDevolucion) => {
    if (!candidatas) return
    setElegida({ tipo: 'grupo', grupo: g, metodo: candidatas.metodo })
    setCantidad(1)
    setCandidatas(null)
  }

  const registrar = useMutation({
    mutationFn: async () => {
      if (!elegida) throw new Error('Escanee la unidad que devuelve el cliente.')
      const comun = { metodo_captura: elegida.metodo, motivo, nota: conNota ? nota.trim() || null : null }
      const cuerpo = elegida.tipo === 'etiqueta'
        ? { ...comun, codigo: elegida.datos.unidad.codigo_barras }
        : { ...comun, venta_id: elegida.grupo.venta_id, referencia_id: elegida.grupo.referencia.id, precio_unitario: elegida.grupo.precio_unitario, cantidad }
      return (await api.post<ResultadoDevolucion>('/devoluciones', cuerpo)).data
    },
    onSuccess: (datos) => {
      setHecha({ datos, metodo: elegida?.metodo ?? 'digitado' })
      limpiarFormulario()
      setAviso(null)
      qc.invalidateQueries({ queryKey: ['referencias'] })
      qc.invalidateQueries({ queryKey: ['cartera'] })
      qc.invalidateQueries({ queryKey: ['cartera-cliente'] })
      enfocar()
    },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeError(e) }),
  })

  const r = elegida ? resumenDe(elegida) : null
  const credito = r?.forma === 'credito'
  const precio = r?.precio ? Number(r.precio) : 0
  const codigosElegidos = r ? r.codigos.slice(0, elegida?.tipo === 'grupo' ? cantidad : 1) : []

  return (
    <div className="ajustes-seccion">
      <p className="ayuda">
        Escanee la etiqueta de la unidad. Sin etiqueta, escanee el código del proveedor y elija la venta.
        Solo ventas cerradas; si la venta sigue abierta, anule el ítem desde Mostrador.
      </p>
      <label htmlFor="devolucion-lector" className="etiqueta-campo">Unidad que devuelve</label>
      <input
        id="devolucion-lector"
        ref={inputRef}
        className="escaner"
        placeholder="Escanee o digite el código"
        onKeyDown={scanner.onKeyDown}
        onPaste={scanner.onPaste}
        autoComplete="off"
      />
      {consultar.isPending && <p className="ayuda" role="status">Buscando la venta…</p>}
      {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

      {hecha && (
        <div className="tarjeta-devolucion devolucion-hecha" role="status">
          <p>
            <strong>Devolución registrada</strong> · venta #{hecha.datos.venta.id} ·{' '}
            {hecha.datos.cantidad === 1 ? '1 unidad devuelta' : `${hecha.datos.cantidad} unidades devueltas`} ({pesos(hecha.datos.total)}).
            Ya están en el inventario.
          </p>
          {hecha.datos.cantidad > 1 && (
            <details>
              <summary>Ver las {hecha.datos.cantidad} unidades</summary>
              <ul className="codigos-lista">{hecha.datos.devueltas.map((c) => <li key={c} className="mono codigo-unidad">{c}</li>)}</ul>
            </details>
          )}
          {hecha.datos.cantidad === 1 && <p className="mono codigo-unidad">{hecha.datos.devueltas[0]}</p>}
          {hecha.datos.saldo_cliente && (
            <p>Nuevo saldo de {hecha.datos.venta.cliente?.nombre ?? 'el cliente'}: <strong>{textoSaldo(hecha.datos.saldo_cliente.despues)}</strong> (antes {textoSaldo(hecha.datos.saldo_cliente.antes)}).</p>
          )}
          <div className="acciones acciones-izquierda">
            <button
              type="button"
              className="secundario boton-44"
              onClick={() => onRetirar(hecha.datos.devueltas.map((codigo, i) => ({ id: hecha.datos.devueltas_ids[i], codigo })), hecha.datos.referencia, hecha.metodo)}
            >
              {hecha.datos.cantidad === 1 ? 'Retirar esta unidad (garantía, daño…)' : `Retirar estas ${hecha.datos.cantidad} unidades (garantía, daño…)`}
            </button>
            <button type="button" className="secundario boton-44" onClick={() => { setHecha(null); enfocar() }}>Nueva devolución</button>
          </div>
        </div>
      )}

      {candidatas && (
        <section className="elegir-venta" aria-labelledby="elegir-venta-titulo">
          <h3 id="elegir-venta-titulo">Elija la venta</h3>
          <p className="ayuda">
            Ventas cerradas con unidades de <strong className="mono">{candidatas.codigo}</strong> sin devolver, de la más reciente a la más antigua. Cada fila agrupa las del mismo precio.
          </p>
          <label className="busca">
            <Icono nombre="buscar" />
            <input value={buscarVenta} onChange={(e) => setBuscarVenta(e.target.value)} placeholder="N.º de venta" inputMode="numeric" aria-label="Buscar por número de venta" />
          </label>
          {buscarEnServidor && porVenta.isFetching ? <p className="ayuda" role="status">Buscando la venta…</p> : listaVentas.length === 0 ? (
            <p className="vacio">Ninguna venta coincide.</p>
          ) : (
            <ul className="lista-candidatas">
              {listaVentas.map((g) => (
                <li key={`${g.venta_id}-${g.referencia.id}-${g.precio_unitario}`}>
                  <button
                    type="button"
                    className="candidata-venta"
                    onClick={() => elegirGrupo(g)}
                    aria-label={`Elegir la venta #${g.venta_id}: ${g.cantidad_disponible} de ${g.referencia.codigo_referencia} a ${g.precio_unitario ? pesos(g.precio_unitario) : 'sin precio'}`}
                  >
                    <span className="candidata-linea">
                      <strong>Venta #{g.venta_id}</strong>
                      <strong>{g.precio_unitario ? pesos(g.precio_unitario) : '—'}</strong>
                    </span>
                    <span className="candidata-linea">
                      <span>{g.cliente?.nombre ?? 'Mostrador'}</span>
                      <span className={g.forma_pago === 'credito' ? 'chip credito' : 'chip'}>{g.forma_pago === 'credito' ? 'Crédito' : 'Contado'}</span>
                    </span>
                    <span className="candidata-linea">
                      <small>{formatearFechaHora(g.cerrada_at)} · {g.referencia.codigo_referencia}{g.referencia.proveedor ? ` · ${g.referencia.proveedor}` : ''}</small>
                      <span className="chip disponibles">{g.cantidad_disponible} {g.cantidad_disponible === 1 ? 'disponible' : 'disponibles'}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          <div className="acciones">
            <button type="button" className="secundario boton-44" onClick={() => { setCandidatas(null); enfocar() }}>Cancelar</button>
          </div>
        </section>
      )}

      {elegida && r && (
        <form className="tarjeta-devolucion" onSubmit={(e) => { e.preventDefault(); registrar.mutate() }}>
          <dl className="datos-fijos datos-devolucion">
            <div><dt>Venta</dt><dd>#{r.venta}</dd></div>
            <div><dt>Fecha</dt><dd>{formatearFechaHora(r.fecha)}</dd></div>
            <div><dt>Cliente</dt><dd>{r.cliente?.nombre ?? 'Mostrador'}</dd></div>
            <div><dt>Forma de pago</dt><dd><span className={credito ? 'chip credito' : 'chip'}>{credito ? 'Crédito' : 'Contado'}</span></dd></div>
            <div><dt>Referencia</dt><dd>{r.referencia} <small className="bloque">{r.proveedor} <ChipCalidad calidad={r.calidad} /></small></dd></div>
            <div><dt>Precio</dt><dd>{r.precio ? pesos(r.precio) : '—'}</dd></div>
            <div className="dato-unidades">
              <dt>{codigosElegidos.length === 1 ? 'Unidad' : 'Unidades'}</dt>
              <dd>
                {codigosElegidos.length === 1 ? (
                  <>
                    <span className="mono codigo-unidad">{codigosElegidos[0]}</span>
                    {elegida.tipo === 'grupo' && <span className="chip por-referencia bloque-chip">elegida por referencia</span>}
                  </>
                ) : (
                  <details>
                    <summary>{codigosElegidos.length} unidades <span className="chip por-referencia">elegidas por referencia</span></summary>
                    <ul className="codigos-lista">{codigosElegidos.map((c) => <li key={c} className="mono codigo-unidad">{c}</li>)}</ul>
                  </details>
                )}
              </dd>
            </div>
          </dl>

          {elegida.tipo === 'grupo' ? (
            <CampoCantidad
              id="devolucion-cantidad"
              etiqueta="Cantidad a devolver"
              valor={cantidad}
              max={r.max}
              onCambio={setCantidad}
              ayuda={`De 1 a ${r.max}: ${r.max === 1 ? 'queda 1 unidad sin devolver' : `quedan ${r.max} sin devolver`} en esta venta a este precio.`}
            />
          ) : (
            <p className="ayuda">Con la etiqueta se devuelve esa unidad (cantidad 1).</p>
          )}

          {credito && r.saldo !== null && (
            <div className="aviso atencion aviso-saldo" role="status" aria-live="polite">
              Saldo de {r.cliente?.nombre ?? 'el cliente'}: <strong>{textoAntesDespues(Number(r.saldo), precio * codigosElegidos.length)}</strong>
              {Number(r.saldo) - precio * codigosElegidos.length < 0 && <> · queda saldo a favor del cliente</>}
            </div>
          )}

          <div className="motivo-devolucion">
            <label>Motivo
              <select value={motivo} onChange={(e) => setMotivo(e.target.value)}>
                {MOTIVOS_DEVOLUCION.map((m) => <option key={m} value={m}>{m}</option>)}
              </select>
            </label>
            {conNota ? (
              <label>Nota (opcional)
                <input ref={notaRef} maxLength={255} value={nota} onChange={(e) => setNota(e.target.value)} />
              </label>
            ) : (
              <button type="button" className="enlace boton-44 agregar-nota" onClick={() => { setConNota(true); setTimeout(() => notaRef.current?.focus(), 0) }}>+ Agregar nota</button>
            )}
          </div>

          <div className="acciones">
            <button type="button" className="secundario boton-44" onClick={() => { limpiarFormulario(); enfocar() }}>Cancelar</button>
            <button className="primario boton-44" disabled={registrar.isPending}>
              {registrar.isPending ? 'Registrando…' : codigosElegidos.length > 1 ? `Registrar devolución de ${codigosElegidos.length} unidades` : 'Registrar devolución'}
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
  const [params, setParams] = useSearchParams()
  const [reintegrando, setReintegrando] = useState<AjusteInventario | null>(null)
  const [aviso, setAviso] = useState<Aviso>(null)

  // El período vive en la URL (?periodo=…; desde y hasta solo si es personalizado) para que
  // recargar o compartir el enlace conserve el filtro.
  const crudo = params.get('periodo')
  const periodo: ValorPeriodo = {
    periodo: esPeriodo(crudo) ? crudo : PERIODO_POR_DEFECTO,
    desde: params.get('desde') ?? '',
    hasta: params.get('hasta') ?? '',
  }
  const rango = rangoDe(periodo.periodo, hoyEnBogota(), periodo)
  const errorFechas = errorRango(rango)

  const cambiarPeriodo = (v: ValorPeriodo) => setParams((actual) => {
    const siguiente = new URLSearchParams(actual)
    siguiente.set('periodo', v.periodo)
    for (const campo of ['desde', 'hasta'] as const) {
      if (v.periodo === 'personalizado' && v[campo]) siguiente.set(campo, v[campo])
      else siguiente.delete(campo)
    }
    return siguiente
  }, { replace: true })

  // Cambiar cualquier filtro vuelve a la página 1 (useListaPaginada). Con un rango imposible no se consulta.
  const filtros = { tipo, motivo, ...rango }
  const { filas, resultado, irA, cargando } = useListaPaginada<AjusteInventario>({
    clave: ['ajustes', filtros],
    filtros,
    enabled: errorFechas === null,
    traer: async (page) => (await api.get<Pagina<AjusteInventario>>('/ajustes', {
      // "todo" no envía fechas; el backend las toma como días de Bogotá, hasta inclusivo.
      params: { tipo: tipo || undefined, motivo: motivo || undefined, desde: rango.desde ?? undefined, hasta: rango.hasta ?? undefined, page },
    })).data,
  })

  const motivos: MotivoAjuste[] = tipo === 'reintegro' ? MOTIVOS_REINTEGRO : tipo === 'baja' ? MOTIVOS_BAJA : tipo === 'alta' ? MOTIVOS_ALTA
    : [...MOTIVOS_BAJA, 'error_de_registro', 'sobrante_inventario', 'sin_factura']

  return (
    <div className="ajustes-seccion">
      <div className="formulario en-linea filtros-ajustes">
        <label>Tipo
          <select value={tipo} onChange={(e) => { setTipo(e.target.value as '' | TipoAjuste); setMotivo('') }}>
            <option value="">Todos</option>
            <option value="baja">Salidas</option>
            <option value="alta">Entradas</option>
            <option value="reintegro">Reintegros</option>
          </select>
        </label>
        <label>Motivo
          <select value={motivo} onChange={(e) => setMotivo(e.target.value as '' | MotivoAjuste)}>
            <option value="">Todos</option>
            {motivos.map((m) => <option key={m} value={m}>{NOMBRE_MOTIVO[m]}</option>)}
          </select>
        </label>
        <FiltroPeriodo id="historial-periodo" valor={periodo} onCambio={cambiarPeriodo} />
      </div>
      {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

      {errorFechas ? (
        <p className="vacio">Corrija el rango de fechas para ver el historial.</p>
      ) : filas.length === 0 ? (
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
                  <td data-etiqueta="Fecha">{formatearFechaHora(a.created_at)}<div className="ayuda">#{a.id}</div></td>
                  <td data-etiqueta="Tipo y motivo">
                    <span className={`chip ${a.tipo === 'baja' ? 'salida' : a.tipo === 'alta' ? 'entrada' : 'reintegro'}`}>{a.tipo === 'baja' ? 'Salida' : a.tipo === 'alta' ? 'Entrada' : 'Reintegro'}</span>{' '}
                    {NOMBRE_MOTIVO[a.motivo]}
                    {a.nota && <div className="ayuda nota-ajuste">{a.nota}</div>}
                  </td>
                  <td data-etiqueta="Usuario">{a.usuario.name}</td>
                  <td data-etiqueta={`Unidades (${a.cantidad})`}>
                    {a.tipo === 'alta' && a.unidades[0] ? (
                      // S-39: una entrada es de una sola referencia: × N y los códigos creados en un desplegable.
                      <div className="entrada-historial">
                        <span><strong>× {a.cantidad}</strong> · <strong>{a.unidades[0].referencia.codigo_referencia}</strong> · {a.unidades[0].referencia.descripcion} · {a.unidades[0].referencia.proveedor.nombre}</span>
                        <details>
                          <summary>Ver {a.cantidad === 1 ? 'el código creado' : `los ${a.cantidad} códigos creados`}</summary>
                          <ul className="codigos-lista">{a.unidades.map((u) => <li key={u.id} className="mono codigo-unidad">{u.codigo_barras} <span className={`chip estado-${u.estado}`}>{u.estado}</span></li>)}</ul>
                        </details>
                      </div>
                    ) : (
                    <ul className="unidades-ajuste">
                      {a.unidades.map((u) => (
                        <li key={u.id}>
                          <span className="mono">{u.codigo_barras}</span>
                          {u.origen_codigo === 'referencia' && <> <span className="chip por-referencia" title="Se leyó el código del proveedor; el sistema eligió esta unidad">por referencia</span></>}
                          {' '}· <strong>{u.referencia.codigo_referencia}</strong> · {u.referencia.proveedor.nombre}{' '}
                          <ChipCalidad calidad={u.referencia.calidad} />
                          <span className={`chip estado-${u.estado}`} title="Estado actual de la unidad">{u.estado}</span>
                        </li>
                      ))}
                    </ul>
                    )}
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
      {!errorFechas && <Paginacion pagina={resultado} onCambiar={irA} />}

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
