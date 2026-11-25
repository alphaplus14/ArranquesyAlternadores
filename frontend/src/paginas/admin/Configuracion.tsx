import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import CampoStockMinimo from '../../componentes/CampoStockMinimo'
import { aValorMinimo, errorStockMinimo } from '../../utilidades/stockMinimo'
import Confirmar from '../../componentes/Confirmar'
import { leerNumero, pesos } from '../../componentes/numeros'
import { hoyEnBogota } from '../../utilidades/periodos'

/** RF-22 (S-28), S-45 y RF-25 (S-49, S-51): parámetros que fija el administrador. Cada cambio queda auditado. */
export default function Configuracion() {
  const qc = useQueryClient()
  const general = useQuery({
    queryKey: ['configuracion', 'stock-minimo'],
    queryFn: async () => (await api.get<{ valor: number | null }>('/configuracion/stock-minimo')).data,
  })
  const [valor, setValor] = useState('')
  const [guardado, setGuardado] = useState(false)

  useEffect(() => {
    if (general.data) setValor(general.data.valor == null ? '' : String(general.data.valor))
  }, [general.data])

  const guardar = useMutation({
    mutationFn: async () => (await api.put<{ valor: number | null }>('/configuracion/stock-minimo', { valor: aValorMinimo(valor) })).data,
    onSuccess: (d) => {
      qc.setQueryData(['configuracion', 'stock-minimo'], d)
      // Cambia el mínimo efectivo de muchos productos.
      qc.invalidateQueries({ queryKey: ['referencias'] })
      qc.invalidateQueries({ queryKey: ['bajo-minimo'] })
      setGuardado(true)
    },
  })

  const actual = general.data?.valor
  const sinCambios = (actual == null ? '' : String(actual)) === valor.trim()

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Configuración</h1>
        <p>Parámetros del almacén. Solo el administrador los cambia; cada cambio queda en la auditoría.</p>
      </div>
      <section className="tarjeta-pagina">
        <h2>Stock mínimo general</h2>
        <form
          className="formulario campo-configuracion"
          onSubmit={(e) => { e.preventDefault(); setGuardado(false); guardar.mutate() }}
        >
          <CampoStockMinimo
            id="stock-minimo-general"
            etiqueta="Stock mínimo general"
            ayuda="Se aplica a los productos que no tengan uno propio. 0 o vacío = sin mínimo general."
            valor={valor}
            onCambio={(v) => { setValor(v); setGuardado(false) }}
          />
          {general.isError && <div className="aviso error">{mensajeError(general.error)}</div>}
          {guardar.isError && <div className="aviso error">{mensajeError(guardar.error)}</div>}
          {guardado && <div className="aviso ok" role="status">Guardado. El informe Bajo mínimo ya usa el nuevo valor.</div>}
          <div className="acciones acciones-izquierda">
            <button className="primario boton-44" disabled={general.isLoading || guardar.isPending || sinCambios || errorStockMinimo(valor) !== null}>Guardar</button>
            <Link to="/admin/auditoria?tabla=configuraciones">Ver cambios anteriores</Link>
          </div>
        </form>
      </section>
      <DiasSinMovimiento />
      <MetaVentaDiaria />
      <SaldoCajaInicial />
    </div>
  )
}

/** S-45 e: días sin venta para que el panel considere estancada una referencia (30 a 730; 120 por defecto). */
const DIAS_MIN = 30
const DIAS_MAX = 730

function errorDias(texto: string): string | null {
  const t = texto.trim()
  if (t === '') return 'Escriba los días.'
  if (!/^\d+$/.test(t)) return 'Use un número entero de días.'
  const n = Number(t)
  return n < DIAS_MIN || n > DIAS_MAX ? `Entre ${DIAS_MIN} y ${DIAS_MAX} días.` : null
}

function DiasSinMovimiento() {
  const qc = useQueryClient()
  const actual = useQuery({
    queryKey: ['configuracion', 'dias-sin-movimiento'],
    queryFn: async () => (await api.get<{ valor: number }>('/configuracion/dias-sin-movimiento')).data.valor,
  })
  const [valor, setValor] = useState('')
  const [guardado, setGuardado] = useState(false)
  useEffect(() => {
    if (actual.data !== undefined) setValor(String(actual.data))
  }, [actual.data])

  const guardar = useMutation({
    mutationFn: async () => (await api.put<{ valor: number }>('/configuracion/dias-sin-movimiento', { valor: Number(valor.trim()) })).data.valor,
    onSuccess: (v) => {
      qc.setQueryData(['configuracion', 'dias-sin-movimiento'], v)
      qc.invalidateQueries({ queryKey: ['panel', 'sin-movimiento'] })
      setGuardado(true)
    },
  })
  const error = errorDias(valor)
  const sinCambios = actual.data !== undefined && String(actual.data) === valor.trim()

  return (
    <section className="tarjeta-pagina" id="panel-gestion">
      <h2>Panel de gestión</h2>
      <form className="formulario campo-configuracion" onSubmit={(e) => { e.preventDefault(); setGuardado(false); guardar.mutate() }}>
        <label htmlFor="dias-sin-movimiento">Días sin movimiento</label>
        <input
          id="dias-sin-movimiento"
          type="number"
          min={DIAS_MIN}
          max={DIAS_MAX}
          step="1"
          inputMode="numeric"
          value={valor}
          aria-invalid={error !== null}
          aria-describedby="dias-sin-movimiento-ayuda"
          onChange={(e) => { setValor(e.target.value); setGuardado(false) }}
        />
        <p id="dias-sin-movimiento-ayuda" className={error ? 'ayuda error' : 'ayuda'}>
          {error ?? `Una referencia con existencia que no se vende en estos días se considera estancada. Entre ${DIAS_MIN} y ${DIAS_MAX}; 120 por defecto.`}
        </p>
        {actual.isError && <div className="aviso error">{mensajeError(actual.error)}</div>}
        {guardar.isError && <div className="aviso error">{mensajeError(guardar.error)}</div>}
        {guardado && <div className="aviso ok" role="status">Guardado. El stock estancado del panel ya usa el nuevo valor.</div>}
        <div className="acciones acciones-izquierda">
          <button className="primario boton-44" disabled={actual.isLoading || guardar.isPending || sinCambios || error !== null}>Guardar</button>
          <Link to="/admin/auditoria?tabla=configuraciones">Ver cambios anteriores</Link>
        </div>
      </form>
    </section>
  )
}

/** RF-25 (S-51): meta de venta diaria (pesos enteros). La caja compara el total del día contra ella. */
function MetaVentaDiaria() {
  const qc = useQueryClient()
  const actual = useQuery({
    queryKey: ['configuracion', 'meta-venta'],
    queryFn: async () => (await api.get<{ valor: string }>('/configuracion/meta-venta')).data.valor,
  })
  const [valor, setValor] = useState('')
  const [guardado, setGuardado] = useState(false)
  useEffect(() => {
    if (actual.data !== undefined) setValor(String(Math.round(Number(actual.data))))
  }, [actual.data])

  const n = leerNumero(valor)
  const error = valor.trim() === '' ? 'Escriba la meta.' : n === null || Number.isNaN(n) || !Number.isInteger(n) ? 'Use pesos enteros.' : null
  const sinCambios = actual.data !== undefined && n !== null && Number(actual.data) === n
  const guardar = useMutation({
    mutationFn: async () => (await api.put<{ valor: string }>('/configuracion/meta-venta', { valor: n })).data.valor,
    onSuccess: (v) => {
      qc.setQueryData(['configuracion', 'meta-venta'], v)
      qc.invalidateQueries({ queryKey: ['caja'] })
      setGuardado(true)
    },
  })

  return (
    <section className="tarjeta-pagina" id="meta-venta">
      <h2>Meta de venta diaria</h2>
      <form className="formulario campo-configuracion" onSubmit={(e) => { e.preventDefault(); setGuardado(false); guardar.mutate() }}>
        <label htmlFor="meta-venta-valor">Meta diaria (pesos)</label>
        <input id="meta-venta-valor" inputMode="numeric" value={valor} aria-invalid={error !== null} aria-describedby="meta-venta-ayuda" onChange={(e) => { setValor(e.target.value); setGuardado(false) }} />
        <p id="meta-venta-ayuda" className={error ? 'ayuda error' : 'ayuda'}>
          {error ?? `${pesos(n ?? 0)}. La caja compara contra ella el total del día (ventas de contado y a crédito y otros ingresos; sin recaudo de cartera).`}
        </p>
        {actual.isError && <div className="aviso error">{mensajeError(actual.error)}</div>}
        {guardar.isError && <div className="aviso error">{mensajeError(guardar.error)}</div>}
        {guardado && <div className="aviso ok" role="status">Guardado.</div>}
        <div className="acciones acciones-izquierda">
          <button className="primario boton-44" disabled={actual.isLoading || guardar.isPending || sinCambios || error !== null}>Guardar</button>
          <Link to="/admin/auditoria?tabla=configuraciones">Ver cambios anteriores</Link>
        </div>
      </form>
    </section>
  )
}

/**
 * RF-25 (S-49): saldo de caja con que empieza el sistema y su fecha. Se fija UNA sola vez (luego es
 * de solo lectura): todos los saldos acumulados de la caja parten de él.
 */
function SaldoCajaInicial() {
  const qc = useQueryClient()
  const hoy = hoyEnBogota()
  const actual = useQuery({
    queryKey: ['configuracion', 'saldo-caja-inicial'],
    queryFn: async () => (await api.get<{ saldo: { valor: string; fecha: string } | null }>('/configuracion/saldo-caja-inicial')).data.saldo,
  })
  const [valor, setValor] = useState('')
  const [fecha, setFecha] = useState(hoy)
  const [confirmando, setConfirmando] = useState(false)
  const n = leerNumero(valor)
  const error = valor.trim() === '' ? null : n === null || Number.isNaN(n) ? 'Escriba un valor en pesos, 0 o mayor.' : null
  const guardar = useMutation({
    mutationFn: async () => (await api.put('/configuracion/saldo-caja-inicial', { valor: n, fecha })).data,
    onSuccess: () => {
      setConfirmando(false)
      qc.invalidateQueries({ queryKey: ['configuracion', 'saldo-caja-inicial'] })
      qc.invalidateQueries({ queryKey: ['caja'] })
    },
  })

  return (
    <section className="tarjeta-pagina" id="saldo-caja-inicial">
      <h2>Saldo inicial de caja</h2>
      {actual.isLoading && <p className="cargando">Cargando…</p>}
      {actual.isError && <div className="aviso error">{mensajeError(actual.error)}</div>}
      {actual.data ? (
        <p>Fijado en <strong>{pesos(actual.data.valor)}</strong> desde el <strong>{actual.data.fecha}</strong>. Ya no se puede cambiar.</p>
      ) : actual.data === null && (
        <form className="formulario campo-configuracion" onSubmit={(e) => { e.preventDefault(); if (n !== null && !error) setConfirmando(true) }}>
          <div className="campos-dos">
            <label>Saldo (pesos)
              <input inputMode="decimal" value={valor} aria-invalid={error !== null} onChange={(e) => setValor(e.target.value)} />
            </label>
            <label>Desde el día
              <input type="date" max={hoy} value={fecha} onChange={(e) => setFecha(e.target.value)} />
            </label>
          </div>
          <p className={error ? 'ayuda error' : 'ayuda'}>{error ?? 'Efectivo que había en la caja al empezar ese día. Se fija una sola vez; el mostrador no puede cerrar la caja sin él.'}</p>
          {guardar.isError && !confirmando && <div className="aviso error">{mensajeError(guardar.error)}</div>}
          <div className="acciones acciones-izquierda">
            <button className="primario boton-44" disabled={n === null || error !== null || !fecha || guardar.isPending}>Fijar saldo inicial</button>
          </div>
        </form>
      )}
      {confirmando && (
        <Confirmar
          titulo="Fijar el saldo inicial de caja"
          texto={`${pesos(n ?? 0)} desde el ${fecha}. Después no se puede cambiar.`}
          accion="Fijar"
          pendiente={guardar.isPending}
          error={guardar.isError ? mensajeError(guardar.error) : null}
          onConfirmar={() => guardar.mutate()}
          onCancelar={() => setConfirmando(false)}
        />
      )}
    </section>
  )
}
