import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import { NOMBRE_CALIDAD, type Calidad, type Cliente, type MetodoCaptura, type Pagina, type Referencia, type RespuestaEscaneo, type Venta as TVenta, type VentaItem } from '../../api/tipos'
import { useAviso } from '../../componentes/Avisos'
import ChipCalidad from '../../componentes/ChipCalidad'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import ListaEleccion, { type FilaEleccion } from '../../componentes/ListaEleccion'
import Medida from '../../componentes/Medida'
import Paginacion from '../../componentes/Paginacion'
import Resumen from '../../componentes/Resumen'
import { useScanner } from '../../hooks/useScanner'
import { useDebounce } from '../../hooks/useDebounce'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { formatoNumero, leerNumero } from '../../componentes/numeros'

interface ReversoPendiente { item?: VentaItem; tipo: 'devolucion' | 'anulacion' }
interface DatosCredito { cliente_id: number; factura_contapyme: string | null }

/** El mostrador elige de a 15: el diálogo no carga el padrón entero. */
const CLIENTES_POR_PAGINA = 15

/** vence_el llega como aaaa-mm-dd: se arma la fecha local para que no se corra un día por la zona horaria. */
function fechaCorta(iso: string) {
  const [a, m, d] = iso.split('-').map(Number)
  return new Date(a, m - 1, d).toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

/** Validación en vivo. El backend vuelve a exigir precio > 0 (S-18). */
function errorPrecio(texto: string): string | null {
  const n = leerNumero(texto)
  if (n === null) return 'Escriba el precio.'
  if (Number.isNaN(n)) return 'Use solo números; los centavos van después de la coma (máximo 2).'
  if (n <= 0) return 'El precio debe ser mayor que 0.'
  return null
}


/**
 * RF-06: venta de mostrador. Cada escaneo descuenta de inmediato (no al cerrar).
 * RF-12: input siempre enfocado para el lector, y búsqueda manual de respaldo (queda "digitado").
 * RF-07: reversos con motivo obligatorio; nada se borra.
 */
export default function Venta() {
  const qc = useQueryClient()
  const avisos = useAviso()
  const [ventaId, setVentaId] = useState<number | null>(null)
  const [respaldo, setRespaldo] = useState(false)
  const [reverso, setReverso] = useState<ReversoPendiente | null>(null)
  const [eligiendoCliente, setEligiendoCliente] = useState(false)
  /** RF-14 / S-18: ítem cuyo precio se está editando (solo en una venta a crédito abierta). */
  const [editandoPrecio, setEditandoPrecio] = useState<number | null>(null)
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
    queryFn: async () => (await api.get<Pagina<{ id: number; created_at: string; items_count: number; forma_pago: TVenta['forma_pago']; cliente: { id: number; nombre: string } | null }>>('/ventas', { params: { estado: 'cerrada' } })).data,
  })

  // Sin cuerpo = contado, igual que antes del crédito (RF-14).
  const abrir = useMutation({
    mutationFn: async (credito?: DatosCredito) =>
      (await api.post<TVenta>('/ventas', credito ? { forma_pago: 'credito', ...credito } : undefined)).data,
    onSuccess: (v) => {
      setVentaId(v.id)
      qc.setQueryData(['venta', v.id], v)
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

  const escanear = useMutation({
    mutationFn: async ({ codigo, metodo }: { codigo: string; metodo: MetodoCaptura }) =>
      (await api.post<RespuestaEscaneo>(`/ventas/${ventaId}/escanear`, { codigo_barras: codigo, metodo_captura: metodo })).data,
    onSuccess: (r) => {
      avisos.mostrar('ok', `✓ ${r.unidad.codigo_barras} · ${r.referencia.codigo_referencia} · ${[r.referencia.proveedor, r.referencia.calidad && NOMBRE_CALIDAD[r.referencia.calidad as Calidad]].filter(Boolean).join(' · ')} — quedan ${r.referencia.existencia} (${r.metodo_captura})`)
      // RF-06: una sola petición por escaneo. El ítem llega completo y se agrega a la venta en caché.
      const clave = ['venta', r.venta_id]
      if (!qc.getQueryData<TVenta>(clave)) {
        qc.invalidateQueries({ queryKey: clave })
        return
      }
      void qc.cancelQueries({ queryKey: clave })
      qc.setQueryData<TVenta>(clave, (actual) =>
        actual && !actual.items.some((i) => i.id === r.item.id)
          ? { ...actual, items: [...actual.items, r.item] }
          : actual,
      )
    },
    onError: (e) => {
      avisos.mostrar('error', mensajeError(e))
      qc.invalidateQueries({ queryKey: ['venta', ventaId] })
    },
  })

  const cerrar = useMutation({
    mutationFn: async () => (await api.post<TVenta>(`/ventas/${ventaId}/cerrar`)).data,
    onSuccess: (v) => {
      qc.setQueryData(['venta', v.id], v)
      qc.invalidateQueries({ queryKey: ['ventas'] })
      avisos.mostrar(
        'ok',
        v.forma_pago === 'credito'
          ? `Venta #${v.id} a crédito cerrada.${v.vence_el ? ` Vence el ${fechaCorta(v.vence_el)}.` : ''} Facture en Contapyme.`
          : `Venta #${v.id} cerrada. Facture en Contapyme.`,
      )
    },
    onError: (e) => avisos.mostrar('error', mensajeError(e)),
  })

  const onCodigo = useCallback(
    (codigo: string, metodo: MetodoCaptura) => {
      if (inputRef.current) inputRef.current.value = ''
      if (ventaId === null || venta.data?.estado !== 'abierta') {
        avisos.mostrar('error', 'Abra una venta antes de escanear.')
        return
      }
      escanear.mutate({ codigo, metodo })
    },
    [ventaId, venta.data?.estado, escanear],
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
  const hayDialogo = respaldo || reverso !== null || eligiendoCliente || precioEnEdicion
  useEffect(() => {
    if (!hayDialogo) inputRef.current?.focus()
  }, [hayDialogo, ventaId, abierta, escanear.isPending])
  // Al pulsar un botón que abre un diálogo, el blur llega antes que el clic: el timeout debe leer
  // si hay diálogo cuando corre, no cuando se pintó el input, o le roba el foco al diálogo.
  const hayDialogoRef = useRef(hayDialogo)
  hayDialogoRef.current = hayDialogo
  const reenfocar = () => setTimeout(() => { if (!hayDialogoRef.current) inputRef.current?.focus() }, 0)

  const vigentes = v?.items.filter((i) => !i.reverso_id) ?? []
  const ultimo = [...(v?.items ?? [])].reverse().find((i) => !i.reverso_id)
  // Solo cierra la edición de ese ítem: si ya se abrió la de otro, la deja abierta.
  const dejarDeEditar = (id: number) => setEditandoPrecio((actual) => (actual === id ? null : actual))

  return (
    <div>
      <div className="pagina-encabezado">
        <h1>Venta de mostrador</h1>
        <p>Cada lectura descuenta la unidad al momento. La venta no se borra: se reversa.</p>
      </div>
      <Resumen
        clave="venta-resumen"
        compacto={(
          <>
            <span>Venta <strong>{v ? `#${v.id}` : '—'}</strong>{v ? ` · ${v.estado}` : ' · sin abrir'}</span>
            <span>Ítems <strong>{v ? vigentes.length : '—'}</strong></span>
            <span className={v && v.reversos.length > 0 ? 'resumen-agotados' : ''}>Reversos <strong>{v ? v.reversos.length : '—'}</strong></span>
            <span>Última <strong>{ultimo?.unidad.codigo_barras ?? '—'}</strong></span>
          </>
        )}
      >
        <Medida etiqueta="Venta" valor={v ? `#${v.id}` : '—'} pie={v?.estado ?? 'Sin abrir'} tono="azul" icono="venta" />
        <Medida etiqueta="Ítems vigentes" valor={v ? vigentes.length : '—'} tono="amarillo" icono="caja" />
        <Medida etiqueta="Reversos" valor={v ? v.reversos.length : '—'} tono="rojo" icono="auditoria" />
        <Medida etiqueta="Última unidad" valor={ultimo?.unidad.codigo_barras ?? '—'} pie={ultimo?.unidad.referencia?.codigo_referencia} tono="verde" icono="etiqueta" codigo />
      </Resumen>
      <div className="venta">
      <section className="panel">
        <div className="fila-titulo">
          <h2>
            Lectura {v && <span className={`chip ${v.estado}`}>{v.estado}</span>}{' '}
            {v && <span className={credito ? 'chip credito' : 'chip'}>{credito ? 'Crédito' : 'Contado'}</span>}
          </h2>
          <div className="acciones">
            <button type="button" className="secundario" onClick={() => abrir.mutate(undefined)} disabled={abrir.isPending}>
              {abrir.isPending && !eligiendoCliente ? 'Abriendo…' : 'Nueva venta'}
            </button>
            <button type="button" className="secundario" onClick={() => setEligiendoCliente(true)} disabled={abrir.isPending}>Nueva venta a crédito</button>
            {abierta && <button type="button" className="secundario" onClick={() => setRespaldo(true)}>Buscar sin escáner</button>}
            {abierta && <button type="button" className="primario" onClick={() => cerrar.mutate()} disabled={cerrar.isPending}>Cerrar venta</button>}
            {v && !abierta && vigentes.length > 0 && (
              <button type="button" className="peligro" onClick={() => setReverso({ tipo: 'anulacion' })}>Anular venta completa</button>
            )}
          </div>
        </div>

        {v && credito && (
          <p className="venta-credito">
            Cliente: <strong>{v.cliente?.nombre ?? '—'}</strong>
            {v.cliente?.documento && <> · {v.cliente.documento}</>}
            {v.factura_contapyme && <> · Factura Contapyme {v.factura_contapyme}</>}
            {v.estado === 'cerrada' && v.vence_el && <> · <strong>Vence el {fechaCorta(v.vence_el)}</strong></>}
          </p>
        )}

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
        {v && (
          <table>
            <thead>
              <tr><th>Código</th><th>Referencia</th><th>Proveedor</th><th>Precio</th><th>Captura</th><th>Usuario</th><th>Hora</th><th></th></tr>
            </thead>
            <tbody>
              {v.items.map((i) => (
                <tr key={i.id} className={i.reverso_id ? 'reversado' : ''}>
                  <td className="mono">{i.unidad.codigo_barras}</td>
                  <td>{i.unidad.referencia?.codigo_referencia} <small>{i.unidad.referencia?.descripcion}</small></td>
                  <td>{i.unidad.referencia?.proveedor?.nombre} <ChipCalidad calidad={i.unidad.referencia?.calidad} /></td>
                  <td>
                    {v && precioEditable(i) && editandoPrecio === i.id ? (
                      <CeldaPrecio
                        ventaId={v.id}
                        item={i}
                        onGuardado={(nueva, precio) => {
                          qc.setQueryData(['venta', nueva.id], nueva)
                          dejarDeEditar(i.id)
                          avisos.mostrar('ok', `Precio de ${i.unidad.codigo_barras} cambiado a $${formatoNumero(precio)}.`)
                        }}
                        onTerminar={() => dejarDeEditar(i.id)}
                      />
                    ) : precioEditable(i) ? (
                      <button type="button" className="enlace" title="Cambiar el precio de este ítem" onClick={() => setEditandoPrecio(i.id)}>
                        {i.precio_unitario === null ? '—' : formatoNumero(i.precio_unitario)}
                      </button>
                    ) : (
                      i.precio_unitario === null ? '—' : formatoNumero(i.precio_unitario)
                    )}
                    {i.iva_porcentaje !== null && <> <small>{Number(i.iva_porcentaje) === 0 ? 'sin IVA' : `IVA ${Number(i.iva_porcentaje)} %`}</small></>}
                  </td>
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
              {v.items.length === 0 && <tr><td colSpan={8} className="vacio">Sin ítems todavía.</td></tr>}
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
              <button className="enlace" onClick={() => setVentaId(r.id)}>#{r.id}</button>
              {r.forma_pago === 'credito' && <> <span className="chip credito">Crédito</span> {r.cliente?.nombre}</>} · {r.items_count} ítems · {new Date(r.created_at).toLocaleString()}
            </li>
          ))}
        </ul>
      </aside>
      </div>

      {respaldo && (
        <BusquedaRespaldo
          onElegir={(codigo) => { setRespaldo(false); escanear.mutate({ codigo, metodo: 'digitado' }) }}
          onCerrar={() => setRespaldo(false)}
        />
      )}

      {eligiendoCliente && (
        <DialogoCredito
          abriendo={abrir.isPending}
          onConfirmar={(datos) => abrir.mutate(datos, { onSuccess: () => setEligiendoCliente(false) })}
          onCerrar={() => { setEligiendoCliente(false); abrir.reset() }}
        />
      )}

      {reverso && v && (
        <DialogoReverso
          ventaId={v.id}
          pendiente={reverso}
          onListo={(nueva) => { qc.setQueryData(['venta', nueva.id], nueva); setReverso(null); avisos.mostrar('ok', 'Reverso registrado; la unidad volvió al inventario.') }}
          onCerrar={() => setReverso(null)}
        />
      )}
    </div>
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
        {guardar.isError ? mensajeError(guardar.error) : error ?? 'Enter guarda · Esc cancela'}
      </small>
    </div>
  )
}
