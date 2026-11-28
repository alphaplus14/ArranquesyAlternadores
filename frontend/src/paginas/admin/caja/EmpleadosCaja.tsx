import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AvisoPagina } from '../../../componentes/AvisoPagina'
import { api, erroresCampo, mensajeError } from '../../../api/cliente'
import type { EmpleadoResumen, EstadoCuentaEmpleado, FilaEstadoEmpleado, ResumenEmpleados } from '../../../api/tipos'
import { useAvisoTexto } from '../../../componentes/Avisos'
import Dialogo, { DialogoSeccion } from '../../../componentes/Dialogo'
import DialogoApilado from '../../../componentes/DialogoApilado'
import Icono from '../../../componentes/Icono'
import Medida from '../../../componentes/Medida'
import Paginacion from '../../../componentes/Paginacion'
import { fechaCorta, hoy } from '../../../componentes/fechas'
import { leerNumero, pesos } from '../../../componentes/numeros'
import { useDebounce } from '../../../hooks/useDebounce'
import { useListaPaginada } from '../../../hooks/useListaPaginada'
import BotonCancelar from '../../../componentes/BotonCancelar'

const CLAVE = ['empleados'] as const

/**
 * RF-27 (S-55 a S-57): pestaña Empleados de la Caja del admin. Saldo por empleado (activos primero),
 * total pendiente, alta con saldo de apertura opcional, activar/desactivar y estado de cuenta con
 * "Anular" (motivo obligatorio). Los préstamos y abonos los registra el mostrador.
 */
export default function EmpleadosCaja() {
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const termino = useDebounce(q.trim())
  const [creando, setCreando] = useState(false)
  const [detalle, setDetalle] = useState<EmpleadoResumen | null>(null)

  const { filas, resultado, irA, cargando } = useListaPaginada<EmpleadoResumen>({
    clave: [...CLAVE, 'resumen', termino],
    filtros: [q],
    porPagina: { defecto: 50 }, // S-70
    traer: async (pagina, porPagina) => (await api.get<ResumenEmpleados>('/empleados/resumen', { params: { q: termino || undefined, page: pagina, por_pagina: porPagina } })).data,
  })
  const totales = (resultado as ResumenEmpleados | undefined)?.totales
  const refrescar = () => qc.invalidateQueries({ queryKey: CLAVE })

  const alternar = useMutation({
    mutationFn: (e: EmpleadoResumen) => api.put(`/empleados/${e.id}`, { activo: !e.activo }),
    onSuccess: refrescar,
  })
  useAvisoTexto(alternar.isError ? mensajeError(alternar.error) : null)

  return (
    <div className="pila" id="panel-empleados">
      <div className="medidas medidas-empleados">
        <Medida etiqueta="Total prestado pendiente" valor={totales ? pesos(totales.total_pendiente) : '—'} tono="amarillo" icono="billetera" />
        <Medida etiqueta="Empleados con saldo" valor={totales ? totales.empleados_con_saldo : '—'} tono="azul" icono="persona" />
      </div>

      <div className="catalogo-barra">
        <button type="button" className="primario boton-alta" onClick={() => setCreando(true)}>Nuevo empleado</button>
        <label className="busca">
          <Icono nombre="buscar" />
          <input placeholder="Nombre o documento" aria-label="Buscar empleado" value={q} onChange={(e) => setQ(e.target.value)} />
        </label>
      </div>

      <table className="tabla-apilable tabla-empleados">
        <thead><tr><th>Nombre</th><th className="col-numero">Saldo</th><th>Último movimiento</th><th>Estado</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
        <tbody>
          {filas.map((e) => (
            <tr key={e.id} className={e.activo ? '' : 'reversado'}>
              <td data-etiqueta="Nombre"><strong>{e.nombre}</strong>{e.documento && <small className="bloque">{e.documento}</small>}</td>
              <td data-etiqueta="Saldo" className="col-numero">{pesos(e.saldo)}</td>
              <td data-etiqueta="Último movimiento">{e.ultimo_movimiento ? fechaCorta(e.ultimo_movimiento) : '—'}</td>
              <td data-etiqueta="Estado">
                <button type="button" className="enlace" disabled={alternar.isPending} onClick={() => alternar.mutate(e)}>
                  {e.activo ? 'Activo · desactivar' : 'Inactivo · activar'}
                </button>
              </td>
              <td className="celda-accion">
                <button type="button" className="enlace" onClick={() => setDetalle(e)}>Estado de cuenta</button>
              </td>
            </tr>
          ))}
          {filas.length === 0 && !cargando && <tr><td colSpan={5} className="vacio">Sin empleados para esta búsqueda.</td></tr>}
        </tbody>
      </table>
      <Paginacion pagina={resultado} onCambiar={irA} />

      {creando && <DialogoNuevoEmpleado onCerrar={() => setCreando(false)} onCreado={() => { setCreando(false); refrescar() }} />}
      {detalle && <DialogoEstadoCuenta empleado={detalle} onCerrar={() => setDetalle(null)} onCambio={refrescar} />}
    </div>
  )
}

/** S-55: nombre, documento opcional y saldo de apertura opcional con su fecha (no futura). */
function DialogoNuevoEmpleado({ onCerrar, onCreado }: { onCerrar: () => void; onCreado: () => void }) {
  const [nombre, setNombre] = useState('')
  const [documento, setDocumento] = useState('')
  const [saldo, setSaldo] = useState('')
  const [fecha, setFecha] = useState(hoy())
  const valor = leerNumero(saldo)
  const saldoInvalido = saldo.trim() !== '' && (valor === null || Number.isNaN(valor) || valor <= 0 || !Number.isInteger(valor))

  const crear = useMutation({
    mutationFn: () => api.post('/empleados', {
      nombre: nombre.trim(),
      documento: documento.trim() || null,
      ...(valor ? { saldo_apertura: valor, fecha_apertura: fecha } : {}),
    }),
    onSuccess: onCreado,
  })
  const errores = crear.isError ? erroresCampo(crear.error) : {}
  const listo = nombre.trim().length >= 2 && !saldoInvalido && !crear.isPending

  return (
    <Dialogo
      titulo="Nuevo empleado"
      onCerrar={onCerrar}
      pendiente={crear.isPending}
      onEnviar={() => { if (listo) crear.mutate() }}
      pie={(
        <>
          <BotonCancelar />
          <button type="submit" className="primario" disabled={!listo}>{crear.isPending ? 'Creando…' : 'Crear empleado'}</button>
        </>
      )}
    >
      <DialogoSeccion>
        <label>
          <span className="etiqueta-campo">Nombre<span className="obligatorio" aria-hidden="true">*</span></span>
          <input required autoFocus maxLength={120} value={nombre} aria-invalid={!!errores.nombre} onChange={(e) => { setNombre(e.target.value); crear.reset() }} />
          {errores.nombre && <small className="ayuda error">{errores.nombre}</small>}
        </label>
        <label>
          <span className="etiqueta-campo">Documento (opcional)</span>
          <input maxLength={30} value={documento} aria-invalid={!!errores.documento} onChange={(e) => { setDocumento(e.target.value); crear.reset() }} />
          {errores.documento && <small className="ayuda error">{errores.documento}</small>}
        </label>
        <label>
          <span className="etiqueta-campo">Saldo de apertura (opcional)</span>
          <input inputMode="numeric" placeholder="$ 0" value={saldo} aria-invalid={saldoInvalido || !!errores.saldo_apertura} aria-describedby="saldo-apertura-ayuda" onChange={(e) => { setSaldo(e.target.value); crear.reset() }} />
          {/* S-55: antes era el subtítulo de la cabecera (se truncaba); ahora va completo bajo su campo. */}
          <small id="saldo-apertura-ayuda" className="ayuda">Es lo que ya debía antes de usar el sistema: no mueve la caja y solo se registra ahora.</small>
          {saldoInvalido && <small className="ayuda error">Escriba un valor en pesos enteros mayor que 0.</small>}
          {!saldoInvalido && valor ? <small className="ayuda">{pesos(valor)}</small> : null}
          {errores.saldo_apertura && <small className="ayuda error">{errores.saldo_apertura}</small>}
        </label>
        {valor ? (
          <label>
            <span className="etiqueta-campo">Fecha del saldo de apertura</span>
            <input type="date" required max={hoy()} value={fecha} onChange={(e) => setFecha(e.target.value)} />
          </label>
        ) : null}
        {crear.isError && Object.keys(errores).length === 0 && <AvisoPagina tono="error">{mensajeError(crear.error)}</AvisoPagina>}
      </DialogoSeccion>
    </Dialogo>
  )
}

function descripcionFila(f: FilaEstadoEmpleado): string {
  if (f.tipo === 'anulacion') return `Anulación de ${f.anula === 'prestamo' ? 'préstamo' : 'abono'}`
  if (f.tipo === 'prestamo') return f.apertura ? 'Saldo de apertura' : 'Préstamo (efectivo)'
  return f.forma === 'nomina' ? 'Abono por nómina' : `Abono por caja${f.cuenta ? ` · ${f.cuenta}` : ''}`
}

/** Estado de cuenta en orden de fecha con saldo corrido; cada movimiento vigente se puede anular. */
function DialogoEstadoCuenta({ empleado, onCerrar, onCambio }: { empleado: EmpleadoResumen; onCerrar: () => void; onCambio: () => void }) {
  const [anulando, setAnulando] = useState<FilaEstadoEmpleado | null>(null)
  const estado = useQuery({
    queryKey: [...CLAVE, 'estado-cuenta', empleado.id],
    queryFn: async () => (await api.get<EstadoCuentaEmpleado>(`/empleados/${empleado.id}/estado-cuenta`)).data,
  })
  const filas = estado.data?.movimientos ?? []

  return (
    <Dialogo
      titulo={`Estado de cuenta · ${empleado.nombre}`}
      descripcion={estado.data ? `Saldo: ${pesos(estado.data.saldo)}` : 'Cargando…'}
      variante="ancha"
      onCerrar={onCerrar}
      confirmarSalida={false}
      pie={<BotonCancelar>Cerrar</BotonCancelar>}
    >
      <DialogoSeccion>
      {estado.isError && <div className="aviso error" role="alert">{mensajeError(estado.error)}</div>}
      <div className="tabla-desplazable">
      <table className="tabla-apilable tabla-empleados">
        <thead><tr><th>Fecha</th><th>Movimiento</th><th className="col-numero">Préstamo</th><th className="col-numero">Abono</th><th className="col-numero">Saldo</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
        <tbody>
          {filas.map((f) => (
            <tr key={f.clave} className={f.anulado ? 'reversado' : ''}>
              <td data-etiqueta="Fecha">{fechaCorta(f.fecha)}</td>
              <td data-etiqueta="Movimiento">
                {descripcionFila(f)}
                {f.nota && <small className="bloque">{f.nota}</small>}
                {f.tipo === 'anulacion' && f.motivo && <small className="bloque">Motivo: {f.motivo}</small>}
                {f.usuario && <small className="bloque">{f.usuario}</small>}
                {f.anulacion && <small className="bloque">Anulado el {fechaCorta(f.anulacion.fecha)} por {f.anulacion.usuario ?? '—'}: {f.anulacion.motivo}</small>}
              </td>
              <td data-etiqueta="Préstamo" className="col-numero">{f.prestamo ? pesos(f.prestamo) : ''}</td>
              <td data-etiqueta="Abono" className="col-numero">{f.abono ? pesos(f.abono) : ''}</td>
              <td data-etiqueta="Saldo" className="col-numero"><strong>{pesos(f.saldo)}</strong></td>
              <td className="celda-accion">
                {f.tipo !== 'anulacion' && !f.anulado && (
                  <button type="button" className="enlace" onClick={() => setAnulando(f)}>Anular</button>
                )}
              </td>
            </tr>
          ))}
          {filas.length === 0 && !estado.isLoading && <tr><td colSpan={6} className="vacio">Sin movimientos.</td></tr>}
        </tbody>
      </table>
      </div>
      </DialogoSeccion>

      {anulando && (
        <DialogoAnularMovimiento
          fila={anulando}
          onCerrar={() => setAnulando(null)}
          onAnulado={() => { setAnulando(null); estado.refetch(); onCambio() }}
        />
      )}
    </Dialogo>
  )
}

/** Regla 8: la anulación es un movimiento nuevo con motivo; el original queda visible, tachado. */
function DialogoAnularMovimiento({ fila, onCerrar, onAnulado }: { fila: FilaEstadoEmpleado; onCerrar: () => void; onAnulado: () => void }) {
  const [motivo, setMotivo] = useState('')
  const anular = useMutation({
    mutationFn: () => api.post(`/empleados/movimientos/${fila.movimiento_id}/anulaciones`, { motivo: motivo.trim() }),
    onSuccess: onAnulado,
  })
  const errores = anular.isError ? erroresCampo(anular.error) : {}
  const listo = motivo.trim().length >= 3 && !anular.isPending
  const valor = fila.prestamo ?? fila.abono ?? '0'

  return (
    <DialogoApilado
      titulo={`Anular ${fila.tipo === 'prestamo' ? 'préstamo' : 'abono'}`}
      nota={<p className="nota-obligatorio">No se borra: queda visible como anulado y deja de contar en el saldo.</p>}
      onCerrar={onCerrar}
      onEnviar={() => { if (listo) anular.mutate() }}
      pie={(
        <>
          <BotonCancelar />
          <button type="submit" className="peligro" disabled={!listo}>{anular.isPending ? 'Anulando…' : 'Anular'}</button>
        </>
      )}
    >
      <dl className="datos-fijos">
        <div><dt>Movimiento</dt><dd>{descripcionFila(fila)}</dd></div>
        <div><dt>Valor</dt><dd>{pesos(valor)}</dd></div>
        <div><dt>Fecha</dt><dd>{fechaCorta(fila.fecha)}</dd></div>
      </dl>
      <label>
        <span className="etiqueta-campo">Motivo<span className="obligatorio" aria-hidden="true">*</span></span>
        <textarea required autoFocus minLength={3} maxLength={500} rows={3} value={motivo} aria-invalid={!!errores.motivo} onChange={(e) => { setMotivo(e.target.value); anular.reset() }} />
        {errores.motivo && <small className="ayuda error">{errores.motivo}</small>}
      </label>
      {anular.isError && !errores.motivo && <AvisoPagina tono="error">{mensajeError(anular.error)}</AvisoPagina>}
    </DialogoApilado>
  )
}
