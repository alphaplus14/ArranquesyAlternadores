import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Navigate, useSearchParams } from 'react-router-dom'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import { GRUPOS_CAJA, NOMBRE_GRUPO_CAJA, soloEfectivo, type CierreCaja, type DiaCaja, type GrupoCaja, type MovimientoCaja } from '../../api/tipos'
import { useAuth } from '../../auth/AuthProvider'
import { useAviso } from '../../componentes/Avisos'
import Confirmar from '../../componentes/Confirmar'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import { DialogoAnular } from '../../componentes/Cartera'
import Medida from '../../componentes/Medida'
import { leerNumero, pesos } from '../../componentes/numeros'
import { useCategoriasCaja, useCuentasPago } from '../../hooks/useCatalogos'
import { hoyEnBogota } from '../../utilidades/periodos'
import { formatearFecha, formatearFechaHora } from '../../utilidades/fechas'
import { RUTA_CAJA_ADMIN } from '../../rutas'

/**
 * RF-25 / RF-26 (S-49 a S-54): caja del día del mostrador. Registra movimientos, los anula con motivo
 * y cierra el día con el efectivo contado. El admin la ve en modo consulta dentro de su página Caja
 * (pestaña Día, con "Reabrir"), junto al cuadre mensual. Todo en pantalla: no se exporta ni se imprime.
 */
export default function CierreDia() {
  const { usuario } = useAuth()
  if (usuario?.rol === 'admin') return <Navigate to={RUTA_CAJA_ADMIN} replace />

  return (
    <div className="pila caja">
      <div className="pagina-encabezado">
        <h1>Caja del día</h1>
        <p>Lo que entra y sale por el mostrador: ventas, recaudos, pagos, gastos e ingresos. Al final del día, cuente el efectivo y cierre.</p>
      </div>
      <VistaDia soloConsulta={false} />
    </div>
  )
}

/** El día de caja; con soloConsulta (admin) no hay escritura salvo "Reabrir" un cierre. */
export function VistaDia({ soloConsulta }: { soloConsulta: boolean }) {
  const hoy = hoyEnBogota()
  const [params, setParams] = useSearchParams()
  const fechaParam = params.get('fecha')
  const fecha = fechaParam && /^\d{4}-\d{2}-\d{2}$/.test(fechaParam) && fechaParam <= hoy ? fechaParam : hoy
  const qc = useQueryClient()
  const avisos = useAviso()
  const [agregando, setAgregando] = useState(false)
  const [anulando, setAnulando] = useState<MovimientoCaja | null>(null)
  const [reabriendo, setReabriendo] = useState<CierreCaja | null>(null)

  const dia = useQuery({
    queryKey: ['caja', 'dia', fecha],
    queryFn: async () => (await api.get<DiaCaja>(`/caja/dias/${fecha}`)).data,
  })
  const refrescar = () => qc.invalidateQueries({ queryKey: ['caja'] })
  const d = dia.data
  const r = d?.resumen
  const cerrado = !!d?.cierre
  const puedeEscribir = !soloConsulta && !!d && !cerrado

  return (
    <>
      <section className="tarjeta-pagina caja-barra">
        <label className="caja-fecha">
          <span className="etiqueta-campo">Día</span>
          <input
            type="date"
            max={hoy}
            value={fecha}
            onChange={(e) => setParams((p) => {
              const n = new URLSearchParams(p)
              if (!e.target.value || e.target.value === hoy) n.delete('fecha'); else n.set('fecha', e.target.value)
              return n
            }, { replace: true })}
          />
        </label>
        {d && (cerrado
          ? (
            <p className="caja-estado cerrado">
              <span className="chip cerrada">Cerrado</span> por <strong>{d.cierre!.usuario?.name ?? '—'}</strong> el {formatearFechaHora(d.cierre!.cerrado_at)}
              {soloConsulta && <button type="button" className="secundario" onClick={() => setReabriendo(d.cierre)}>Reabrir</button>}
            </p>
          )
          : <p className="caja-estado"><span className="chip abierta">Sin cerrar</span>{fecha < hoy ? ' Día anterior sin cierre.' : ''}</p>)}
      </section>

      {dia.isLoading && <p className="cargando">Cargando la caja…</p>}
      {dia.isError && <div className="aviso error">{mensajeError(dia.error)}</div>}

      {d && r && (
        <>
          {!r.saldo_inicial && <div className="aviso atencion">Falta el saldo inicial de caja: el administrador lo fija una sola vez en Configuración. Sin él no se puede cerrar el día.</div>}
          {d.ventas_abiertas.length > 0 && (
            <div className="aviso atencion" role="status">
              Hay ventas abiertas con ítems: {d.ventas_abiertas.map((v) => `#${v.id}${v.usuario ? ` (${v.usuario})` : ''}`).join(', ')}. Ciérrelas o anule sus ítems antes de cerrar el día.
            </div>
          )}

          <section className="medidas">
            <Medida etiqueta="Total ingreso del día" valor={pesos(r.total_ingreso_dia)} pie={`Meta ${pesos(r.meta_venta_diaria)}`} tono="azul" icono="panel" />
            <Medida
              etiqueta={Number(r.faltante_meta) > 0 ? 'Faltante para la meta' : 'Sobre la meta'}
              valor={pesos(Math.abs(Number(r.faltante_meta)))}
              tono={Number(r.faltante_meta) > 0 ? 'amarillo' : 'verde'}
              icono="agotados"
            />
            <Medida etiqueta="Efectivo esperado del día" valor={pesos(r.efectivo_neto_dia)} pie="Efectivo neto (ver desglose)" tono="verde" icono="billetera" />
            <Medida
              etiqueta="Saldo acumulado"
              valor={pesos(r.saldo_acumulado)}
              pie={`Anterior ${pesos(r.saldo_anterior)}${r.saldo_anterior_origen.tipo === 'cierre' ? ` (cierre del ${formatearFecha(r.saldo_anterior_origen.fecha)})` : r.saldo_anterior_origen.tipo === 'inicial' ? ' (saldo inicial)' : ''}`}
              tono="azul"
              icono="caja"
            />
          </section>

          <section className="tarjeta-pagina">
            <h2>Por cuenta</h2>
            <div className="tabla-contenedor-simple">
              <table className="tabla-caja">
                <caption className="solo-lector">Movimiento del día por cuenta</caption>
                <thead>
                  <tr>
                    <th>Cuenta</th><th className="col-numero">Ventas</th><th className="col-numero">Recaudo cartera</th><th className="col-numero">Otros ingresos</th>
                    <th className="col-numero">Ingresos externos</th><th className="col-numero">Pagos a proveedores</th><th className="col-numero">Egresos</th>
                    <th className="col-numero">Devoluciones</th><th className="col-numero">Neto</th>
                  </tr>
                </thead>
                <tbody>
                  {r.cuentas.map((c) => (
                    <tr key={c.cuenta_pago_id ?? 'sin'}>
                      <td>{c.nombre}{c.ventas_sin_detalle && Number(c.ventas_sin_detalle) > 0 && <small className="bloque">Incluye {pesos(c.ventas_sin_detalle)} de ventas sin detalle de pago</small>}</td>
                      <td className="col-numero">{pesos(c.ventas)}</td>
                      <td className="col-numero">{pesos(c.recaudo)}</td>
                      <td className="col-numero">{pesos(c.otros_ingresos)}</td>
                      <td className="col-numero">{pesos(c.ingresos_externos)}</td>
                      <td className="col-numero">{pesos(c.pagos_proveedores)}</td>
                      <td className="col-numero">{pesos(c.egresos)}</td>
                      <td className="col-numero">{pesos(c.devoluciones)}</td>
                      <td className="col-numero"><strong>{pesos(c.neto)}</strong></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="ayuda">Ventas a crédito del día: <strong>{pesos(r.ventas_credito)}</strong> (cuentan para la meta, no entran a ninguna cuenta). El recaudo de cartera y los ingresos externos no cuentan para la meta.</p>
          </section>

          <div className="caja-columnas">
            <section className="tarjeta-pagina">
              <div className="fila-titulo">
                <h2>Movimientos del día</h2>
                {puedeEscribir && <button type="button" className="primario" onClick={() => setAgregando(true)}>Agregar movimiento</button>}
              </div>
              <ListaMovimientos movimientos={d.movimientos} onAnular={puedeEscribir ? setAnulando : undefined} />
            </section>

            <section className="tarjeta-pagina caja-efectivo">
              <h2>Efectivo esperado</h2>
              <dl className="desglose-efectivo">
                <div><dt>Ventas en efectivo</dt><dd>{pesos(r.efectivo.ventas)}</dd></div>
                <div><dt>+ Recaudo de cartera en efectivo</dt><dd>{pesos(r.efectivo.recaudo)}</dd></div>
                <div><dt>+ Otros ingresos en efectivo</dt><dd>{pesos(r.efectivo.otros_ingresos)}</dd></div>
                <div><dt>+ Ingresos externos</dt><dd>{pesos(r.efectivo.ingresos_externos)}</dd></div>
                {r.efectivo.abonos_empleados !== undefined && <div><dt>+ Abonos de empleados en efectivo</dt><dd>{pesos(r.efectivo.abonos_empleados)}</dd></div>}
                <div><dt>− Devoluciones de contado</dt><dd>{pesos(r.efectivo.devoluciones)}</dd></div>
                <div><dt>− Pagos a proveedores en efectivo</dt><dd>{pesos(r.efectivo.pagos_proveedores)}</dd></div>
                <div><dt>− Egresos (gastos, fijos y cuotas)</dt><dd>{pesos(r.efectivo.egresos)}</dd></div>
                {r.efectivo.prestamos_empleados !== undefined && <div><dt>− Préstamos a empleados</dt><dd>{pesos(r.efectivo.prestamos_empleados)}</dd></div>}
                <div className="total"><dt>Efectivo esperado</dt><dd>{pesos(r.efectivo.neto)}</dd></div>
              </dl>
              {cerrado
                ? <CierreGuardado cierre={d.cierre!} />
                : !soloConsulta && <FormularioCierre fecha={fecha} esperado={r.efectivo_neto_dia} bloqueado={d.ventas_abiertas.length > 0 || !r.saldo_inicial} onCerrado={() => { refrescar(); avisos.mostrar('ok', `Caja del ${formatearFecha(fecha)} cerrada.`) }} />}
              {d.cierres_reabiertos.length > 0 && (
                <details className="caja-historial">
                  <summary>Cierres reabiertos ({d.cierres_reabiertos.length})</summary>
                  <ul>
                    {d.cierres_reabiertos.map((c) => (
                      <li key={c.id}>
                        Cerrado por {c.usuario?.name ?? '—'} el {formatearFechaHora(c.cerrado_at)} (contado {pesos(c.efectivo_contado)}, diferencia {pesos(c.diferencia)}).
                        {' '}Reabierto por {c.reapertura?.usuario?.name ?? '—'} el {formatearFechaHora(c.reapertura?.created_at)}: «{c.reapertura?.motivo}»
                      </li>
                    ))}
                  </ul>
                </details>
              )}
            </section>
          </div>
        </>
      )}

      {agregando && <DialogoMovimiento fecha={fecha} onCerrar={() => setAgregando(false)} onGuardado={() => { setAgregando(false); refrescar() }} />}
      {anulando && (
        <DialogoAnular
          titulo="Anular movimiento"
          descripcion={`${anulando.grupo_nombre} · ${anulando.categoria.nombre} · ${pesos(anulando.valor)}. Queda visible como anulado.`}
          ruta={`/caja/movimientos/${anulando.id}/anulaciones`}
          onHecho={() => { setAnulando(null); refrescar() }}
          onCerrar={() => setAnulando(null)}
        />
      )}
      {reabriendo && (
        <DialogoAnular
          titulo={`Reabrir la caja del ${formatearFecha(reabriendo.fecha)}`}
          descripcion="El cierre queda en el historial con su reapertura; el mostrador podrá corregir y volver a cerrar."
          ruta={`/caja/cierres/${reabriendo.id}/reaperturas`}
          onHecho={() => { setReabriendo(null); refrescar(); avisos.mostrar('ok', 'Caja reabierta.') }}
          onCerrar={() => setReabriendo(null)}
        />
      )}
    </>
  )
}

function ListaMovimientos({ movimientos, onAnular }: { movimientos: MovimientoCaja[]; onAnular?: (m: MovimientoCaja) => void }) {
  if (movimientos.length === 0) return <p className="vacio">No hay movimientos este día.</p>
  return (
    <table className="tabla-caja">
      <caption className="solo-lector">Movimientos de caja del día</caption>
      <thead><tr><th>Tipo</th><th>Concepto</th><th>Cuenta</th><th className="col-numero">Valor</th>{onAnular && <th><span className="solo-lector">Acción</span></th>}</tr></thead>
      <tbody>
        {movimientos.map((m) => (
          <tr key={m.id} className={m.anulacion ? 'reversado' : undefined}>
            <td>{m.grupo_nombre}<small className="bloque">{m.categoria.nombre}</small></td>
            <td>
              {m.concepto}{m.referencia && <small className="bloque">Ref. {m.referencia}</small>}
              <small className="bloque">{m.usuario.name} · {formatearFechaHora(m.created_at)}</small>
              {m.anulacion && <small className="bloque">Anulado por {m.anulacion.usuario?.name ?? '—'}: «{m.anulacion.motivo}»</small>}
            </td>
            <td>{m.cuenta.nombre}</td>
            <td className="col-numero">{m.grupo === 'otro_ingreso' || m.grupo === 'ingreso_externo' ? '+' : '−'}{pesos(m.valor)}</td>
            {onAnular && <td>{!m.anulacion && <button type="button" className="enlace" onClick={() => onAnular(m)}>Anular</button>}</td>}
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function CierreGuardado({ cierre }: { cierre: CierreCaja }) {
  const dif = Number(cierre.diferencia)
  return (
    <dl className="desglose-efectivo cierre-guardado">
      <div><dt>Efectivo contado</dt><dd>{pesos(cierre.efectivo_contado)}</dd></div>
      <div><dt>Efectivo esperado</dt><dd>{pesos(cierre.efectivo_esperado)}</dd></div>
      <div className={dif === 0 ? 'total' : 'total diferencia'}><dt>Diferencia</dt><dd>{dif > 0 ? '+' : ''}{pesos(cierre.diferencia)}</dd></div>
      <div><dt>Saldo acumulado</dt><dd>{pesos(cierre.saldo_acumulado)}</dd></div>
      {cierre.observacion && <div><dt>Observación</dt><dd>{cierre.observacion}</dd></div>}
    </dl>
  )
}

/** Efectivo contado con diferencia en vivo, observación y "Cerrar día" con confirmación. */
function FormularioCierre({ fecha, esperado, bloqueado, onCerrado }: { fecha: string; esperado: string; bloqueado: boolean; onCerrado: () => void }) {
  const [contado, setContado] = useState('')
  const [observacion, setObservacion] = useState('')
  const [confirmando, setConfirmando] = useState(false)
  const n = leerNumero(contado)
  const valido = n !== null && !Number.isNaN(n) && n >= 0
  const diferencia = valido ? Math.round((n - Number(esperado)) * 100) / 100 : null

  const cerrar = useMutation({
    mutationFn: async () => (await api.post('/caja/cierres', { fecha, efectivo_contado: n, observacion: observacion.trim() || null })).data,
    onSuccess: () => { setConfirmando(false); onCerrado() },
  })

  return (
    <form className="formulario cierre-dia" onSubmit={(e) => { e.preventDefault(); if (valido && !bloqueado) setConfirmando(true) }}>
      <label>
        <span className="etiqueta-campo">Efectivo contado<span className="obligatorio" aria-hidden="true">*</span></span>
        <input inputMode="decimal" autoComplete="off" placeholder="Ej: 1.250.000" value={contado} aria-invalid={contado.trim() !== '' && !valido} aria-describedby="cierre-diferencia" onChange={(e) => { setContado(e.target.value); cerrar.reset() }} />
        <small id="cierre-diferencia" className={diferencia !== null && diferencia !== 0 ? 'ayuda error' : 'ayuda'} role="status">
          {contado.trim() !== '' && !valido ? 'Escriba un valor en pesos, 0 o mayor.'
            : diferencia === null ? `Esperado ${pesos(esperado)}.`
              : diferencia === 0 ? 'Cuadra con el efectivo esperado.'
                : `${diferencia > 0 ? 'Sobran' : 'Faltan'} ${pesos(Math.abs(diferencia))}.`}
        </small>
      </label>
      <label>
        <span className="etiqueta-campo">Observación (opcional)</span>
        <input maxLength={255} value={observacion} onChange={(e) => setObservacion(e.target.value)} />
      </label>
      {cerrar.isError && !confirmando && <div className="aviso error" role="alert">{mensajeError(cerrar.error)}</div>}
      <div className="acciones">
        <button type="submit" className="primario boton-44" disabled={!valido || bloqueado || cerrar.isPending}>Cerrar día</button>
      </div>
      {confirmando && (
        <Confirmar
          titulo={`Cerrar la caja del ${formatearFecha(fecha)}`}
          texto={`Efectivo contado ${pesos(n ?? 0)}; esperado ${pesos(esperado)}; diferencia ${pesos(diferencia ?? 0)}. Después de cerrar, el día queda en solo lectura (solo el administrador lo reabre).`}
          accion="Cerrar día"
          pendiente={cerrar.isPending}
          error={cerrar.isError ? mensajeError(cerrar.error) : null}
          onConfirmar={() => cerrar.mutate()}
          onCancelar={() => setConfirmando(false)}
        />
      )}
    </form>
  )
}

/**
 * RF-26: agregar un movimiento. Pestañas por grupo (Gasto, Gasto fijo, Cuota bancaria, Ingreso
 * externo, Otro ingreso); categoría, concepto, valor, cuenta y referencia. S-52: solo "Otro ingreso"
 * admite cuentas distintas de efectivo.
 */
function DialogoMovimiento({ fecha, onCerrar, onGuardado }: { fecha: string; onCerrar: () => void; onGuardado: () => void }) {
  const [grupo, setGrupo] = useState<GrupoCaja>('vario')
  const [categoria, setCategoria] = useState<number | ''>('')
  const [concepto, setConcepto] = useState('')
  const [valor, setValor] = useState('')
  const [cuenta, setCuenta] = useState<number | ''>('')
  const [referencia, setReferencia] = useState('')
  const categorias = useCategoriasCaja()
  const cuentas = useCuentasPago()

  const delGrupo = useMemo(() => (categorias.data ?? []).filter((c) => c.grupo === grupo), [categorias.data, grupo])
  const cuentasPermitidas = useMemo(() => (cuentas.data ?? []).filter((c) => !soloEfectivo(grupo) || c.tipo === 'efectivo'), [cuentas.data, grupo])
  const cuentaElegida = cuenta !== '' && cuentasPermitidas.some((c) => c.id === cuenta) ? cuenta : (cuentasPermitidas.find((c) => c.tipo === 'efectivo')?.id ?? '')
  const n = leerNumero(valor)
  const valorMalo = valor.trim() !== '' && (n === null || Number.isNaN(n) || n <= 0)
  const listo = categoria !== '' && concepto.trim().length >= 2 && n !== null && !Number.isNaN(n) && n > 0 && cuentaElegida !== ''

  const guardar = useMutation({
    mutationFn: async () => (await api.post('/caja/movimientos', {
      fecha, categoria_caja_id: categoria, concepto: concepto.trim(), valor: n, cuenta_pago_id: cuentaElegida, referencia: referencia.trim() || null,
    })).data,
    onSuccess: onGuardado,
  })
  const errores = guardar.isError ? erroresCampo(guardar.error) : {}
  const general = guardar.isError && Object.keys(errores).length === 0 ? mensajeError(guardar.error) : null

  return (
    <Dialogo
      titulo="Agregar movimiento"
      descripcion={`Caja del ${formatearFecha(fecha)}. Un movimiento no se edita ni se borra: si hay un error, se anula con un motivo.`}
      variante="ancha"
      onCerrar={onCerrar}
      pendiente={guardar.isPending}
      onEnviar={() => { if (listo && !guardar.isPending) guardar.mutate() }}
      pie={(
        <>
          <button type="button" className="secundario" disabled={guardar.isPending} onClick={onCerrar}>Cancelar</button>
          <button type="submit" className="primario" disabled={!listo || guardar.isPending}>{guardar.isPending ? 'Guardando…' : 'Guardar'}</button>
        </>
      )}
    >
      <DialogoSeccion>
        <ControlSegmentado
          id="movimiento-grupo"
          etiqueta="Tipo"
          opciones={GRUPOS_CAJA.map((g) => ({ valor: g, texto: NOMBRE_GRUPO_CAJA[g] }))}
          valor={grupo}
          onCambio={(g) => { setGrupo(g); setCategoria(''); guardar.reset() }}
        />
        <label>
          <span className="etiqueta-campo">Categoría<span className="obligatorio" aria-hidden="true">*</span></span>
          <select value={categoria} aria-invalid={!!errores.categoria_caja_id} onChange={(e) => setCategoria(e.target.value === '' ? '' : Number(e.target.value))}>
            <option value="">{categorias.isLoading ? 'Cargando…' : 'Elija la categoría…'}</option>
            {delGrupo.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
          </select>
          {errores.categoria_caja_id && <small className="ayuda error">{errores.categoria_caja_id}</small>}
        </label>
        <label>
          <span className="etiqueta-campo">Concepto<span className="obligatorio" aria-hidden="true">*</span></span>
          <input maxLength={150} autoFocus value={concepto} aria-invalid={!!errores.concepto} onChange={(e) => setConcepto(e.target.value)} />
          {errores.concepto && <small className="ayuda error">{errores.concepto}</small>}
        </label>
        <div className="campos-dos">
          <label>
            <span className="etiqueta-campo">Valor<span className="obligatorio" aria-hidden="true">*</span></span>
            <input inputMode="decimal" autoComplete="off" placeholder="Ej: 50.000" value={valor} aria-invalid={valorMalo || !!errores.valor} onChange={(e) => setValor(e.target.value)} />
            <small className={valorMalo || errores.valor ? 'ayuda error' : 'ayuda'}>{errores.valor ?? (valorMalo ? 'Debe ser mayor que 0.' : n ? pesos(n) : 'Mayor que 0.')}</small>
          </label>
          <label>
            <span className="etiqueta-campo">Cuenta<span className="obligatorio" aria-hidden="true">*</span></span>
            <select value={cuentaElegida} disabled={soloEfectivo(grupo) && cuentasPermitidas.length <= 1} onChange={(e) => setCuenta(e.target.value === '' ? '' : Number(e.target.value))}>
              {cuentaElegida === '' && <option value="">Elija la cuenta…</option>}
              {cuentasPermitidas.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
            </select>
            <small className={errores.cuenta_pago_id ? 'ayuda error' : 'ayuda'}>{errores.cuenta_pago_id ?? (soloEfectivo(grupo) ? 'Este tipo solo se registra en efectivo.' : 'Cualquier cuenta activa.')}</small>
          </label>
        </div>
        <label>
          <span className="etiqueta-campo">Referencia (opcional)</span>
          <input maxLength={60} placeholder="Ej: FVE-1234" value={referencia} onChange={(e) => setReferencia(e.target.value)} />
        </label>
        {general && <div className="aviso error" role="alert">{general}</div>}
      </DialogoSeccion>
    </Dialogo>
  )
}
