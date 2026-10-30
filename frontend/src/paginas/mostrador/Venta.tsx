import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { MetodoCaptura, Pagina, Referencia, Venta as TVenta, VentaItem } from '../../api/tipos'
import { useScanner } from '../../hooks/useScanner'
import { useDebounce } from '../../hooks/useDebounce'

interface Aviso { tipo: 'ok' | 'error'; texto: string }
interface ReversoPendiente { item?: VentaItem; tipo: 'devolucion' | 'anulacion' }

/**
 * RF-06: venta de mostrador. Cada escaneo descuenta de inmediato (no al cerrar).
 * RF-12: input siempre enfocado para el lector, y búsqueda manual de respaldo (queda "digitado").
 * RF-07: reversos con motivo obligatorio; nada se borra.
 */
export default function Venta() {
  const qc = useQueryClient()
  const [ventaId, setVentaId] = useState<number | null>(null)
  const [aviso, setAviso] = useState<Aviso | null>(null)
  const [respaldo, setRespaldo] = useState(false)
  const [reverso, setReverso] = useState<ReversoPendiente | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  // Retoma la última venta abierta del usuario.
  const abiertas = useQuery({
    queryKey: ['ventas', 'abiertas'],
    queryFn: async () => (await api.get<Pagina<{ id: number }>>('/ventas', { params: { estado: 'abierta' } })).data,
  })
  useEffect(() => {
    if (ventaId === null && abiertas.data?.data[0]) setVentaId(abiertas.data.data[0].id)
  }, [abiertas.data, ventaId])

  const venta = useQuery({
    queryKey: ['venta', ventaId],
    queryFn: async () => (await api.get<TVenta>(`/ventas/${ventaId}`)).data,
    enabled: ventaId !== null,
  })

  const recientes = useQuery({
    queryKey: ['ventas', 'cerradas'],
    queryFn: async () => (await api.get<Pagina<{ id: number; created_at: string; items_count: number }>>('/ventas', { params: { estado: 'cerrada' } })).data,
  })

  const abrir = useMutation({
    mutationFn: async () => (await api.post<TVenta>('/ventas')).data,
    onSuccess: (v) => { setVentaId(v.id); qc.setQueryData(['venta', v.id], v); setAviso(null) },
  })

  const escanear = useMutation({
    mutationFn: async ({ codigo, metodo }: { codigo: string; metodo: MetodoCaptura }) =>
      (await api.post(`/ventas/${ventaId}/escanear`, { codigo_barras: codigo, metodo_captura: metodo })).data,
    onSuccess: (r) => {
      setAviso({
        tipo: 'ok',
        texto: `✓ ${r.unidad.codigo_barras} · ${r.referencia.codigo_referencia} ${r.referencia.marca} — quedan ${r.referencia.existencia} (${r.metodo_captura})`,
      })
      qc.invalidateQueries({ queryKey: ['venta', ventaId] })
    },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeError(e) }),
  })

  const cerrar = useMutation({
    mutationFn: async () => (await api.post<TVenta>(`/ventas/${ventaId}/cerrar`)).data,
    onSuccess: (v) => {
      qc.setQueryData(['venta', v.id], v)
      qc.invalidateQueries({ queryKey: ['ventas'] })
      setAviso({ tipo: 'ok', texto: `Venta #${v.id} cerrada. Facture en Contapyme.` })
    },
    onError: (e) => setAviso({ tipo: 'error', texto: mensajeError(e) }),
  })

  const onCodigo = useCallback(
    (codigo: string, metodo: MetodoCaptura) => {
      if (inputRef.current) inputRef.current.value = ''
      if (ventaId === null || venta.data?.estado !== 'abierta') {
        setAviso({ tipo: 'error', texto: 'Abra una venta antes de escanear.' })
        return
      }
      escanear.mutate({ codigo, metodo })
    },
    [ventaId, venta.data?.estado, escanear],
  )
  const scanner = useScanner(onCodigo)

  // El input del lector recupera el foco siempre que no haya un diálogo abierto.
  const hayDialogo = respaldo || reverso !== null
  useEffect(() => {
    if (!hayDialogo) inputRef.current?.focus()
  }, [hayDialogo, ventaId, escanear.isPending])
  const reenfocar = () => setTimeout(() => { if (!hayDialogo) inputRef.current?.focus() }, 0)

  const v = venta.data
  const abierta = v?.estado === 'abierta'
  const vigentes = v?.items.filter((i) => !i.reverso_id) ?? []

  return (
    <div className="venta">
      <section className="panel">
        <div className="fila-titulo">
          <h1>Venta {v ? `#${v.id}` : ''} {v && <span className={`chip ${v.estado}`}>{v.estado}</span>}</h1>
          <div className="acciones">
            <button onClick={() => abrir.mutate()} disabled={abrir.isPending}>Nueva venta</button>
            {abierta && <button onClick={() => setRespaldo(true)}>Buscar sin escáner</button>}
            {abierta && <button className="primario" onClick={() => cerrar.mutate()} disabled={cerrar.isPending}>Cerrar venta</button>}
            {v && !abierta && vigentes.length > 0 && (
              <button className="peligro" onClick={() => setReverso({ tipo: 'anulacion' })}>Anular venta completa</button>
            )}
          </div>
        </div>

        <input
          ref={inputRef}
          className="escaner"
          placeholder={abierta ? 'Escanee la etiqueta (o digite el código y Enter)' : 'Abra una venta para escanear'}
          onKeyDown={scanner.onKeyDown}
          onPaste={scanner.onPaste}
          onBlur={reenfocar}
          disabled={!abierta}
          autoComplete="off"
        />
        {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

        {v && (
          <table>
            <thead>
              <tr><th>Código</th><th>Referencia</th><th>Marca</th><th>Captura</th><th>Usuario</th><th>Hora</th><th></th></tr>
            </thead>
            <tbody>
              {v.items.map((i) => (
                <tr key={i.id} className={i.reverso_id ? 'reversado' : ''}>
                  <td className="mono">{i.unidad.codigo_barras}</td>
                  <td>{i.unidad.referencia?.codigo_referencia} <small>{i.unidad.referencia?.descripcion}</small></td>
                  <td>{i.unidad.referencia?.marca?.nombre}</td>
                  <td><span className={`chip ${i.metodo_captura}`}>{i.metodo_captura}</span></td>
                  <td>{i.usuario.name}</td>
                  <td>{new Date(i.created_at).toLocaleTimeString()}</td>
                  <td>
                    {i.reverso_id ? <em>reversado</em> : (
                      <button className="enlace" onClick={() => setReverso({ item: i, tipo: abierta ? 'anulacion' : 'devolucion' })}>
                        {abierta ? 'Quitar' : 'Devolver'}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              {v.items.length === 0 && <tr><td colSpan={7} className="vacio">Sin ítems todavía.</td></tr>}
            </tbody>
          </table>
        )}

        {v && v.reversos.length > 0 && (
          <>
            <h3>Reversos</h3>
            <ul className="reversos">
              {v.reversos.map((r) => (
                <li key={r.id}>
                  <strong>{r.tipo}</strong> de {r.venta_item_ids.length} ítem(s) · {r.usuario.name} · {new Date(r.created_at).toLocaleString()} — “{r.motivo}”
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
              <button className="enlace" onClick={() => setVentaId(r.id)}>#{r.id}</button> · {r.items_count} ítems · {new Date(r.created_at).toLocaleString()}
            </li>
          ))}
        </ul>
      </aside>

      {respaldo && (
        <BusquedaRespaldo
          onElegir={(codigo) => { setRespaldo(false); escanear.mutate({ codigo, metodo: 'digitado' }) }}
          onCerrar={() => setRespaldo(false)}
        />
      )}

      {reverso && v && (
        <DialogoReverso
          ventaId={v.id}
          pendiente={reverso}
          onListo={(nueva) => { qc.setQueryData(['venta', nueva.id], nueva); setReverso(null); setAviso({ tipo: 'ok', texto: 'Reverso registrado; la unidad volvió al inventario.' }) }}
          onCerrar={() => setReverso(null)}
        />
      )}
    </div>
  )
}

/** RF-12: buscar por referencia o código universal y elegir la unidad. */
function BusquedaRespaldo({ onElegir, onCerrar }: { onElegir: (codigo: string) => void; onCerrar: () => void }) {
  const [q, setQ] = useState('')
  const termino = useDebounce(q.trim())

  const resultado = useQuery({
    queryKey: ['buscar', termino],
    queryFn: async () => (await api.get<{ unidad: unknown; referencias: Referencia[] }>('/buscar', { params: { q: termino } })).data,
    enabled: termino.length >= 2,
  })

  return (
    <div className="modal" onKeyDown={(e) => e.key === 'Escape' && onCerrar()}>
      <div className="modal-caja ancha">
        <h2>Buscar sin escáner</h2>
        <input autoFocus placeholder="Código de barras, referencia o código universal" value={q} onChange={(e) => setQ(e.target.value)} />
        {resultado.isFetching && <p>Buscando…</p>}
        {resultado.data?.referencias.map((r) => (
          <div key={r.id} className="resultado">
            <div>
              <strong>{r.codigo_referencia}</strong> {r.codigo_universal && <span className="chip">{r.codigo_universal.codigo}</span>} · {r.marca?.nombre} · {r.proveedor?.nombre} · existencia {r.existencia}
            </div>
            <div className="unidades">
              {r.unidades_disponibles?.map((u) => (
                <button key={u.id} className="mono" onClick={() => onElegir(u.codigo_barras)}>{u.codigo_barras}</button>
              ))}
              {r.unidades_disponibles?.length === 0 && <em>Sin unidades disponibles</em>}
            </div>
          </div>
        ))}
        {resultado.data && resultado.data.referencias.length === 0 && <p>Sin resultados.</p>}
        <div className="acciones"><button onClick={onCerrar}>Cerrar</button></div>
      </div>
    </div>
  )
}

/** RF-07: el motivo es obligatorio. */
function DialogoReverso({ ventaId, pendiente, onListo, onCerrar }: {
  ventaId: number
  pendiente: ReversoPendiente
  onListo: (v: TVenta) => void
  onCerrar: () => void
}) {
  const [motivo, setMotivo] = useState('')
  const reversar = useMutation({
    mutationFn: async () =>
      (await api.post<TVenta>(`/ventas/${ventaId}/reversos`, {
        tipo: pendiente.tipo,
        motivo,
        venta_item_id: pendiente.item?.id ?? null,
      })).data,
    onSuccess: onListo,
  })

  const titulo = pendiente.item
    ? `${pendiente.tipo === 'anulacion' ? 'Quitar' : 'Devolver'} ${pendiente.item.unidad.codigo_barras}`
    : 'Anular la venta completa'

  return (
    <div className="modal">
      <form className="modal-caja" onSubmit={(e) => { e.preventDefault(); reversar.mutate() }}>
        <h2>{titulo}</h2>
        <p>La venta no se borra: queda un reverso con su usuario, fecha y motivo.</p>
        <label>Motivo (obligatorio)
          <textarea autoFocus required minLength={3} value={motivo} onChange={(e) => setMotivo(e.target.value)} />
        </label>
        {reversar.isError && <div className="aviso error">{mensajeError(reversar.error)}</div>}
        <div className="acciones">
          <button type="button" onClick={onCerrar}>Cancelar</button>
          <button className="peligro" disabled={motivo.trim().length < 3 || reversar.isPending}>Confirmar reverso</button>
        </div>
      </form>
    </div>
  )
}
