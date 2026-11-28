import { useId, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AvisoPagina } from '../../componentes/AvisoPagina'
import { Navigate, useSearchParams } from 'react-router-dom'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import { etiquetaCuenta, GRUPOS_CAJA, NOMBRE_GRUPO_CAJA, soloEfectivo, type AperturaCaja, type CierreCaja, type DiaCaja, type EmpleadoOpcion, type GrupoCaja, type MovimientoCaja, type MovimientoEmpleadoDia, type RecaudoDocumento, type ResumenCaja, type TipoDocumentoVenta } from '../../api/tipos'
import { useAuth } from '../../auth/AuthProvider'
import { useAviso } from '../../componentes/Avisos'
import BotonFila from '../../componentes/BotonFila'
import Confirmar from '../../componentes/Confirmar'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import { DialogoAnular } from '../../componentes/Cartera'
import Medida from '../../componentes/Medida'
import Icono from '../../componentes/Icono'
import CampoMoneda from '../../componentes/CampoMoneda'
import { formatoNumero, leerNumero, pesos } from '../../componentes/numeros'
import { useCategoriasCaja, useCuentasPago } from '../../hooks/useCatalogos'
import { hoyEnBogota } from '../../utilidades/periodos'
import { formatearFecha, formatearFechaHora } from '../../utilidades/fechas'
import { RUTA_CAJA_ADMIN } from '../../rutas'
import BotonCancelar from '../../componentes/BotonCancelar'
import TablaBilleteras from '../../componentes/caja/TablaBilleteras'

/**
 * RF-25 / RF-26 (S-49 a S-54): caja del día del mostrador. Registra movimientos, los anula con motivo
 * y cierra el día con el efectivo contado. El admin la ve en modo consulta dentro de su página Caja
 * (pestaña Día, con "Reabrir"), junto al cuadre mensual. Todo en pantalla: no se exporta ni se imprime.
 */
export default function CierreDia() {
  const { usuario } = useAuth()
  if (usuario?.rol === 'admin') return <Navigate to={RUTA_CAJA_ADMIN} replace />

  return <VistaDia soloConsulta={false} titulo="Caja del día" pantalla />
}

/**
 * El día de caja; con soloConsulta (admin) no hay escritura salvo "Reabrir" un cierre.
 * Distribución (solo presentación): encabezado en una fila con fecha, estado y apertura; franja de
 * tres tarjetas; zona de trabajo en dos columnas (Movimientos | Por cuenta a la izquierda; efectivo
 * esperado y cierre a la derecha). Con `pantalla` (mostrador) la vista ocupa el alto útil de la
 * ventana y cada columna desplaza su propio contenido: en escritorio no hay scroll de página.
 */
export function VistaDia({ soloConsulta, titulo, pantalla = false }: { soloConsulta: boolean; titulo?: string; pantalla?: boolean }) {
  const hoy = hoyEnBogota()
  const [params, setParams] = useSearchParams()
  const fechaParam = params.get('fecha')
  const fecha = fechaParam && /^\d{4}-\d{2}-\d{2}$/.test(fechaParam) && fechaParam <= hoy ? fechaParam : hoy
  const qc = useQueryClient()
  const avisos = useAviso()
  const [agregando, setAgregando] = useState(false)
  const [anulando, setAnulando] = useState<MovimientoCaja | null>(null)
  const [recaudando, setRecaudando] = useState(false)
  const [conEmpleados, setConEmpleados] = useState(false)
  const [anulandoRecaudo, setAnulandoRecaudo] = useState<RecaudoDocumento | null>(null)
  const [reabriendo, setReabriendo] = useState<CierreCaja | null>(null)
  /** S-84: diálogo "Cambiar apertura" / "Abrir caja". */
  const [cambiandoApertura, setCambiandoApertura] = useState(false)
  /** S-84: aviso de días sin cerrar descartado (estado local, por día). */
  const [avisoDescartado, setAvisoDescartado] = useState<string | null>(null)

  const dia = useQuery({
    queryKey: ['caja', 'dia', fecha],
    queryFn: async () => (await api.get<DiaCaja>(`/caja/dias/${fecha}`)).data,
  })
  const refrescar = () => qc.invalidateQueries({ queryKey: ['caja'] })
  const d = dia.data
  const r = d?.resumen
  const cerrado = !!d?.cierre
  const puedeEscribir = !soloConsulta && !!d && !cerrado
  // S-84: la apertura es automática (lo contado en el último cierre); el mostrador la cambia a mano
  // mientras el día no tenga cierre vigente. Sin ninguna (primer día), hay que abrir la caja.
  const puedeApertura = !soloConsulta && !!r?.apertura_editable
  const sinApertura = !!r && !r.apertura && puedeApertura
  const alGuardarApertura = (a: AperturaCaja | null) => {
    setCambiandoApertura(false)
    refrescar()
    if (a) avisos.mostrar('ok', `Apertura de la caja del ${formatearFecha(fecha)}: ${pesos(a.valor)}.`)
  }

  return (
    <div className={pantalla ? 'caja-vista caja-vista-pantalla' : 'caja-vista'}>
      <header className="caja-encabezado">
        {titulo && <h1>{titulo}</h1>}
        <label className="caja-encabezado-fecha">
          <span className="solo-lector">Día</span>
          <input
            type="date"
            max={hoy}
            value={fecha}
            onChange={(e) => {
              setCambiandoApertura(false)
              setParams((p) => {
                const n = new URLSearchParams(p)
                if (!e.target.value || e.target.value === hoy) n.delete('fecha'); else n.set('fecha', e.target.value)
                return n
              }, { replace: true })
            }}
          />
        </label>
        {d && (cerrado
          ? <span className="chip cerrada" title={`Por ${d.cierre!.usuario?.name ?? '—'}, ${formatearFechaHora(d.cierre!.cerrado_at)}`}>Cerrado</span>
          : <span className="chip abierta">Sin cerrar</span>)}
        {r?.apertura && (
          <span className="caja-apertura">
            <TextoApertura apertura={r.apertura} cerrado={cerrado} />
            {puedeApertura && (
              <BotonFila
                variante="editar"
                etiqueta="Cambiar apertura"
                descripcion={`Cambiar la apertura de la caja del ${formatearFecha(fecha)}`}
                onClick={() => setCambiandoApertura(true)}
              />
            )}
          </span>
        )}
        {r && !r.apertura && !puedeApertura && <span className="chip">Sin apertura</span>}
      </header>

      {dia.isLoading && <p className="cargando">Cargando la caja…</p>}
      {dia.isError && <div className="aviso error">{mensajeError(dia.error)}</div>}

      {d && r && (
        <>
          {sinApertura && (
            <div className="aviso atencion caja-abrir" role="status">
              <span>No hay un cierre anterior del que tomar la apertura. Registre el efectivo con que abre la caja (puede ser 0).</span>
              <button type="button" className="primario boton-44" onClick={() => setCambiandoApertura(true)}>Abrir caja</button>
            </div>
          )}
          {r.apertura?.hay_dias_sin_cerrar && r.apertura.desde_cierre && avisoDescartado !== fecha && (
            <div className="aviso atencion caja-aviso-dias" role="status">
              <span>El último cierre fue el {formatearFecha(r.apertura.desde_cierre)}. Revise si faltan cierres de los días intermedios.</span>
              <button type="button" className="icono-boton" aria-label="Descartar el aviso" title="Descartar" onClick={() => setAvisoDescartado(fecha)}>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
              </button>
            </div>
          )}
          {d.ventas_abiertas.length > 0 && (
            <div className="aviso atencion" role="status">
              Hay ventas abiertas con ítems: {d.ventas_abiertas.map((v) => `#${v.id}${v.usuario ? ` (${v.usuario})` : ''}`).join(', ')}. Ciérrelas o anule sus ítems antes de cerrar el día.
            </div>
          )}

          <div className={sinApertura ? 'caja-trabajo caja-atenuada' : 'caja-trabajo'}>
            <section className="medidas caja-medidas">
              <Medida etiqueta="Total ingreso del día" valor={pesos(r.total_ingreso_dia)} pie={`Meta ${pesos(r.meta_venta_diaria)}`} tono="azul" icono="panel" />
              <Medida
                etiqueta={Number(r.faltante_meta) > 0 ? 'Faltante para la meta' : 'Sobre la meta'}
                valor={pesos(Math.abs(Number(r.faltante_meta)))}
                tono={Number(r.faltante_meta) > 0 ? 'amarillo' : 'verde'}
                icono="agotados"
              />
              <Medida
                etiqueta="Efectivo esperado"
                valor={r.efectivo_esperado !== null ? pesos(r.efectivo_esperado) : '—'}
                pie={r.apertura ? `Apertura ${pesos(r.apertura.valor)} + neto ${pesos(r.efectivo_neto_dia)}` : `Falta la apertura · neto ${pesos(r.efectivo_neto_dia)}`}
                tono="verde"
                icono="billetera"
              />
            </section>

            <div className="caja-zona">
              <PanelMovimientos
                resumen={r}
                movimientos={d.movimientos}
                recaudos={d.recaudos ?? []}
                movimientosEmpleado={d.movimientos_empleado ?? []}
                onAgregar={puedeEscribir ? () => setAgregando(true) : undefined}
                // S-76: recaudo de documentos solo con la caja abierta (apertura registrada) y el día sin cerrar.
                onRecaudo={puedeEscribir && !!r.apertura ? () => setRecaudando(true) : undefined}
                // RF-27: préstamos y abonos de empleados entran siempre a la caja de hoy (S-56): solo en el
                // día de hoy, con apertura y sin cerrar. Solo el mostrador (el admin ve la caja en consulta).
                onEmpleados={puedeEscribir && !!r.apertura && fecha === d.hoy ? () => setConEmpleados(true) : undefined}
                onAnular={puedeEscribir ? setAnulando : undefined}
                onAnularRecaudo={puedeEscribir ? setAnulandoRecaudo : undefined}
              />

              <section className="tarjeta-pagina caja-panel caja-panel-cierre" aria-labelledby="caja-titulo-cierre">
                <h2 id="caja-titulo-cierre">Efectivo esperado y cierre</h2>
                <div className="caja-panel-cuerpo">
                  <DesgloseEfectivo resumen={r} />
                  {cerrado && <CierreGuardado cierre={d.cierre!} />}
                  {cerrado && soloConsulta && (
                    <div className="acciones acciones-izquierda">
                      <button type="button" className="secundario boton-44" onClick={() => setReabriendo(d.cierre)}>Reabrir</button>
                    </div>
                  )}
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
                </div>
                {!cerrado && !soloConsulta && !r.apertura && (
                  <p className="ayuda caja-sin-apertura">Registre la apertura para poder cerrar el día.</p>
                )}
                {!cerrado && !soloConsulta && (
                  <FormularioCierre
                    key={fecha}
                    fecha={fecha}
                    esperado={r.efectivo_esperado ?? '0'}
                    base={r.base_caja}
                    bloqueado={d.ventas_abiertas.length > 0 || !r.apertura}
                    onCerrado={() => { refrescar(); avisos.mostrar('ok', `Caja del ${formatearFecha(fecha)} cerrada.`, undefined, 5000) }}
                  />
                )}
              </section>
            </div>
          </div>
        </>
      )}

      {cambiandoApertura && r && (
        <DialogoApertura fecha={fecha} apertura={r.apertura} base={r.base_caja} onCerrar={() => setCambiandoApertura(false)} onGuardada={alGuardarApertura} />
      )}
      {agregando && (
        <DialogoMovimiento
          fecha={fecha}
          esperado={r?.efectivo_esperado ?? null}
          base={r?.base_caja ?? '0'}
          onCerrar={() => setAgregando(false)}
          onGuardado={(avisoBase) => { setAgregando(false); refrescar(); if (avisoBase) avisos.mostrar('atencion', `El movimiento usó la base de caja (${pesos(r?.base_caja ?? '0')}).`) }}
        />
      )}
      {recaudando && <DialogoRecaudo fecha={fecha} onCerrar={() => setRecaudando(false)} onGuardado={() => { setRecaudando(false); refrescar() }} />}
      {conEmpleados && (
        <DialogoEmpleados
          esperado={fecha === d?.hoy ? (r?.efectivo_esperado ?? null) : null}
          base={r?.base_caja ?? '0'}
          onCerrar={() => setConEmpleados(false)}
          onGuardado={(aviso, avisoBase) => {
            setConEmpleados(false); refrescar(); qc.invalidateQueries({ queryKey: ['empleados'] })
            if (avisoBase) avisos.mostrar('atencion', `${aviso} Usó la base de caja.`)
            else avisos.mostrar('ok', aviso, undefined, 5000)
          }}
        />
      )}
      {anulandoRecaudo && (
        <DialogoAnular
          titulo="Anular recaudo"
          descripcion={`Recaudo de cartera · ${anulandoRecaudo.documento} · ${pesos(anulandoRecaudo.valor)}. Queda visible como anulado.`}
          ruta={`/caja/recaudos/${anulandoRecaudo.id}/anular`}
          onHecho={() => { setAnulandoRecaudo(null); refrescar() }}
          onCerrar={() => setAnulandoRecaudo(null)}
        />
      )}
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
    </div>
  )
}

/** Columna izquierda: pestañas "Movimientos" | "Por cuenta"; cada una desplaza su propio contenido. */
function PanelMovimientos({ resumen: r, movimientos, recaudos, movimientosEmpleado, onAgregar, onRecaudo, onEmpleados, onAnular, onAnularRecaudo }: {
  resumen: ResumenCaja
  movimientos: MovimientoCaja[]
  recaudos: RecaudoDocumento[]
  movimientosEmpleado: MovimientoEmpleadoDia[]
  onAgregar?: () => void
  onRecaudo?: () => void
  onEmpleados?: () => void
  onAnular?: (m: MovimientoCaja) => void
  onAnularRecaudo?: (r: RecaudoDocumento) => void
}) {
  const [pestana, setPestana] = useState<'movimientos' | 'cuentas'>('movimientos')
  const pestanas = [
    { clave: 'movimientos' as const, texto: 'Movimientos' },
    { clave: 'cuentas' as const, texto: 'Por cuenta' },
  ]
  const mover = (e: KeyboardEvent) => {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return
    e.preventDefault()
    const siguiente = pestana === 'movimientos' ? 'cuentas' : 'movimientos'
    setPestana(siguiente)
    document.getElementById(`caja-pestana-${siguiente}`)?.focus()
  }

  return (
    <section className="tarjeta-pagina caja-panel caja-panel-movimientos" aria-label="Movimientos del día">
      <div className="caja-panel-cabeza">
        <div className="pestanas caja-pestanas" role="tablist" aria-label="Movimientos del día" onKeyDown={mover}>
          {pestanas.map((p) => (
            <button
              key={p.clave}
              type="button"
              role="tab"
              id={`caja-pestana-${p.clave}`}
              aria-selected={pestana === p.clave}
              aria-controls={`caja-panel-${p.clave}`}
              tabIndex={pestana === p.clave ? 0 : -1}
              className={pestana === p.clave ? 'pestana activa' : 'pestana'}
              onClick={() => setPestana(p.clave)}
            >
              {p.texto}
              {p.clave === 'movimientos' && <span className="pestana-cuenta">{movimientos.length + recaudos.length + movimientosEmpleado.length}</span>}
            </button>
          ))}
        </div>
        {(onAgregar || onRecaudo || onEmpleados) && (
          <div className="recaudo-acciones">
            {onEmpleados && <button type="button" className="secundario boton-44" onClick={onEmpleados}>Empleados</button>}
            {onRecaudo && <button type="button" className="secundario boton-44" onClick={onRecaudo}>Recaudo de cartera</button>}
            {onAgregar && <button type="button" className="primario boton-44" onClick={onAgregar}>Agregar movimiento</button>}
          </div>
        )}
      </div>
      <div className="caja-panel-cuerpo" role="tabpanel" id={`caja-panel-${pestana}`} aria-labelledby={`caja-pestana-${pestana}`}>
        {pestana === 'movimientos'
          ? <ListaMovimientos movimientos={movimientos} recaudos={recaudos} movimientosEmpleado={movimientosEmpleado} totales={r.totales_movimientos} onAnular={onAnular} onAnularRecaudo={onAnularRecaudo} />
          : <ResumenPorCuenta resumen={r} />}
      </div>
    </section>
  )
}

const CAMPOS_CUENTA = ['ventas', 'recaudo', 'otros_ingresos', 'ingresos_externos', 'pagos_proveedores', 'egresos', 'devoluciones', 'neto'] as const

/**
 * Resumen por cuenta: por defecto solo las cuentas con algún valor distinto de 0. La aclaración de
 * qué cuenta para la meta va en un botón de información (popover accesible con teclado).
 */
function ResumenPorCuenta({ resumen: r }: { resumen: ResumenCaja }) {
  // S-88: el dueño de las billeteras es del admin y del contable; el mostrador no lo ve.
  const { usuario } = useAuth()
  const verDueno = usuario?.rol === 'admin' || usuario?.rol === 'contable'
  const [todas, setTodas] = useState(false)
  const conValor = r.cuentas.filter((c) => CAMPOS_CUENTA.some((k) => Number(c[k]) !== 0)
    || Number(c.abonos_empleados ?? 0) !== 0 || Number(c.prestamos_empleados ?? 0) !== 0)
  const filas = todas ? r.cuentas : conValor

  return (
    <div className="caja-por-cuenta">
      <div className="caja-por-cuenta-titulo">
        <h3>Resumen por cuenta</h3>
        <InfoCaja etiqueta="Qué cuenta para la meta">
          Ventas a crédito del día: <strong>{pesos(r.ventas_credito)}</strong> (cuentan para la meta, no entran a ninguna cuenta). El recaudo de cartera y los ingresos externos no cuentan para la meta.
        </InfoCaja>
        <span className="caja-credito">Crédito del día <strong>{pesos(r.ventas_credito)}</strong></span>
      </div>
      {filas.length === 0 ? <p className="vacio caja-vacio">Sin movimiento por cuenta</p> : (
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
            {filas.map((c) => (
              <tr key={c.cuenta_pago_id ?? 'sin'}>
                <td>{c.nombre}{verDueno && c.propietario && <small className="bloque">{c.propietario}</small>}{c.ventas_sin_detalle && Number(c.ventas_sin_detalle) > 0 && <small className="bloque">Incluye {pesos(c.ventas_sin_detalle)} de ventas sin detalle de pago</small>}</td>
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
      )}
      {conValor.length < r.cuentas.length && (
        <button type="button" className="secundario boton-44 caja-ver-cuentas" onClick={() => setTodas((t) => !t)}>{todas ? 'Ver solo las cuentas con movimiento' : 'Ver todas las cuentas'}</button>
      )}
      {verDueno && <TablaBilleteras grupos={r.billeteras ?? []} etiquetaNeto="Movimiento del día" />}
    </div>
  )
}

/** Botón "i" con un popover de texto: se abre con clic o teclado y se cierra con Esc o al salir. */
function InfoCaja({ etiqueta, children }: { etiqueta: string; children: ReactNode }) {
  const [abierto, setAbierto] = useState(false)
  const id = useId()
  return (
    <span className="caja-info" onBlur={(e) => { if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setAbierto(false) }}>
      <button
        type="button"
        className="caja-info-boton"
        aria-label={etiqueta}
        aria-expanded={abierto}
        aria-controls={id}
        onClick={() => setAbierto((a) => !a)}
        onKeyDown={(e) => { if (e.key === 'Escape' && abierto) { e.stopPropagation(); setAbierto(false) } }}
      >i</button>
      {abierto && <span id={id} role="note" className="caja-info-popover">{children}</span>}
    </span>
  )
}

/** Tres líneas visibles (neto, apertura, esperado) y el detalle del efectivo plegado. */
/** S-101: «Ver detalle» es un botón secundario con flecha que gira; el desglose se abre debajo. */
export function DesgloseEfectivo({ resumen: r }: { resumen: ResumenCaja }) {
  const [detalle, setDetalle] = useState(false)
  return (
    <>
      <dl className="desglose-efectivo caja-desglose">
        <div className="subtotal"><dt>Efectivo neto del día</dt><dd>{pesos(r.efectivo.neto)}</dd></div>
        <div><dt>+ Apertura de la caja</dt><dd>{r.apertura ? pesos(r.apertura.valor) : 'Sin registrar'}</dd></div>
        <div className="total"><dt>Efectivo esperado (cuadre)</dt><dd>{r.efectivo_esperado !== null ? pesos(r.efectivo_esperado) : '—'}</dd></div>
        {/* S-98: la base va aparte, en tono neutro: no cuenta en el cuadre pero queda en el cajón. */}
        <div className="caja-base"><dt>+ Base de caja (no cuenta en el cuadre)</dt><dd>{pesos(r.base_caja)}</dd></div>
        <div className="caja-cajon"><dt>En el cajón debe haber</dt><dd>{r.efectivo_en_cajon_esperado !== null ? pesos(r.efectivo_en_cajon_esperado) : '—'}</dd></div>
      </dl>
      <button type="button" className="secundario boton-44 caja-ver-detalle" aria-expanded={detalle} aria-controls="caja-detalle-efectivo" onClick={() => setDetalle((v) => !v)}>
        <Icono nombre="chevron" />
        {detalle ? 'Ocultar detalle' : 'Ver detalle'}
      </button>
      {detalle && (
        <dl className="desglose-efectivo caja-desglose-detalle" id="caja-detalle-efectivo">
          <div><dt>Ventas en efectivo</dt><dd>{pesos(r.efectivo.ventas)}</dd></div>
          <div><dt>+ Recaudo de cartera en efectivo</dt><dd>{pesos(r.efectivo.recaudo)}</dd></div>
          <div><dt>+ Otros ingresos en efectivo</dt><dd>{pesos(r.efectivo.otros_ingresos)}</dd></div>
          <div><dt>+ Ingresos externos</dt><dd>{pesos(r.efectivo.ingresos_externos)}</dd></div>
          {r.efectivo.abonos_empleados !== undefined && <div><dt>+ Abonos de empleados en efectivo</dt><dd>{pesos(r.efectivo.abonos_empleados)}</dd></div>}
          <div><dt>− Devoluciones de contado</dt><dd>{pesos(r.efectivo.devoluciones)}</dd></div>
          <div><dt>− Pagos a proveedores en efectivo</dt><dd>{pesos(r.efectivo.pagos_proveedores)}</dd></div>
          <div><dt>− Egresos (gastos, fijos y cuotas)</dt><dd>{pesos(r.efectivo.egresos)}</dd></div>
          {r.efectivo.prestamos_empleados !== undefined && <div><dt>− Préstamos a empleados</dt><dd>{pesos(r.efectivo.prestamos_empleados)}</dd></div>}
        </dl>
      )}
    </>
  )
}

/**
 * S-84: "Apertura $ 650.000 · del cierre del 25/11" (arrastre) o "· cambiada a mano" (con el motivo
 * y lo que dejó el cierre anterior en un desplegable accesible por teclado). Cerrado: solo el valor.
 */
function TextoApertura({ apertura: a, cerrado }: { apertura: AperturaCaja; cerrado: boolean }) {
  const manual = cerrado ? a.origen_usado === 'manual' : a.origen === 'manual'
  return (
    <span className="caja-apertura-texto">
      <span>Apertura <strong>{pesos(a.valor)}</strong></span>
      {!cerrado && a.origen === 'arrastre' && a.desde_cierre && <span className="caja-apertura-origen">· del cierre del {formatearFecha(a.desde_cierre).slice(0, 5)}</span>}
      {!cerrado && manual && (
        // S-100: una línea; el texto completo (y lo que dejó el cierre anterior) en el title.
        <small
          className="caja-apertura-cambio"
          title={[
            `Cambiada por ${a.fijado_por?.name ?? '—'}${a.fijado_at ? `, ${formatearFechaHora(a.fijado_at)}` : ''}: ${a.motivo ?? 'sin motivo'}`,
            a.cierre_anterior_valor !== null ? `El cierre anterior dejó ${pesos(a.cierre_anterior_valor)}.` : '',
          ].filter(Boolean).join(' ')}
        >
          Cambiada por {a.fijado_por?.name ?? '—'}: {a.motivo ?? 'sin motivo'}
        </small>
      )}
    </span>
  )
}

/**
 * S-84: "Cambiar apertura" (o "Abrir caja" el primer día). El valor se precarga con la apertura
 * actual; "Usar la del cierre anterior" pone lo que dejó el último cierre. El motivo es siempre
 * obligatorio, de 3 a 200 caracteres (lo valida el servidor; aquí solo se anticipa).
 */
export function DialogoApertura({ fecha, apertura, base = '0', onCerrar, onGuardada }: {
  fecha: string
  apertura: AperturaCaja | null
  /** S-98: la base fija no se incluye en la apertura. */
  base?: string
  onCerrar: () => void
  onGuardada: (a: AperturaCaja | null) => void
}) {
  const arrastre = apertura?.cierre_anterior_valor ?? null
  const inicial = apertura ? formatoNumero(apertura.valor) : ''
  const [valor, setValor] = useState(inicial)
  const [motivo, setMotivo] = useState(apertura?.origen === 'manual' ? apertura.motivo ?? '' : '')
  const n = leerNumero(valor)
  const valido = n !== null && !Number.isNaN(n) && n >= 0 && Math.round(n * 100) === n * 100
  const motivoFalta = motivo.trim().length < 3
  const primerDia = !apertura
  const campoMotivo = useRef<HTMLInputElement>(null)

  const guardar = useMutation({
    mutationFn: async () => (await api.put<{ apertura: AperturaCaja | null }>(`/caja/dias/${fecha}/apertura`, { valor: n, motivo: motivo.trim() })).data,
    onSuccess: (d) => onGuardada(d.apertura),
  })
  const errores: Record<string, string> = guardar.isError ? erroresCampo(guardar.error) : {}
  if (guardar.isError && guardar.error instanceof AxiosError) {
    const datos = guardar.error.response?.data as { campo?: string; message?: string } | undefined
    if (datos?.campo && datos.message) errores[datos.campo] = datos.message
  }
  const general = guardar.isError && Object.keys(errores).length === 0 ? mensajeError(guardar.error) : null
  const enviar = () => {
    if (!valido) return
    // S-84: el motivo es siempre obligatorio; vacío o corto → el foco va al motivo.
    if (motivoFalta) { campoMotivo.current?.focus(); return }
    if (!guardar.isPending) guardar.mutate()
  }
  const razon = valido && motivoFalta ? 'Falta el motivo' : null

  return (
    <Dialogo
      titulo={primerDia ? 'Abrir la caja' : 'Cambiar la apertura'}
      descripcion={`Caja del ${formatearFecha(fecha)}`}
      modificado={valor !== inicial || motivo.trim() !== (apertura?.origen === 'manual' ? apertura.motivo ?? '' : '')}
      pendiente={guardar.isPending}
      onCerrar={onCerrar}
      onEnviar={enviar}
      pie={(
        <>
          {razon && <small className="ayuda apertura-razon" role="status">{razon}</small>}
          <BotonCancelar />
          <button type="submit" className="primario" disabled={!valido || motivoFalta || guardar.isPending}>{guardar.isPending ? 'Guardando…' : 'Guardar apertura'}</button>
        </>
      )}
    >
      <DialogoSeccion>
        <label htmlFor="apertura-valor">
          <span className="etiqueta-campo">Efectivo de apertura (sin la base)<span className="obligatorio" aria-hidden="true">*</span></span>
        </label>
        <CampoMoneda
          id="apertura-valor"
          autoFocus
          placeholder="Ej: 200.000"
          value={valor}
          aria-invalid={(valor.trim() !== '' && !valido) || !!errores.valor}
          aria-describedby="apertura-ayuda"
          onChange={(e) => { setValor(e.target.value); guardar.reset() }}
        />
        <small id="apertura-ayuda" className={(valor.trim() !== '' && !valido) || errores.valor ? 'ayuda error' : 'ayuda'}>
          {errores.valor ?? (valor.trim() !== '' && !valido
            ? 'Escriba un valor en pesos, 0 o mayor, con máximo 2 decimales.'
            : `La base de ${pesos(base)} no se incluye. Solo el efectivo para el cuadre que ya está en la caja al empezar (puede ser 0). Lo que llegue durante el día desde la Caja General se registra como ingreso externo.`)}
        </small>
        {arrastre !== null && (
          <button type="button" className="secundario boton-44 apertura-usar-cierre" onClick={() => { setValor(formatoNumero(arrastre)); guardar.reset() }}>
            Usar la del cierre anterior ({pesos(arrastre)})
          </button>
        )}
        <label htmlFor="apertura-motivo">
          <span className="etiqueta-campo">Motivo<span className="obligatorio" aria-hidden="true">*</span></span>
        </label>
        <input
          ref={campoMotivo}
          id="apertura-motivo"
          required
          maxLength={200}
          value={motivo}
          aria-invalid={(motivoFalta && motivo !== '') || !!errores.motivo}
          aria-describedby="apertura-motivo-ayuda"
          onChange={(e) => { setMotivo(e.target.value); guardar.reset() }}
        />
        <small id="apertura-motivo-ayuda" className={errores.motivo ? 'ayuda error' : 'ayuda'}>
          {errores.motivo ?? `Obligatorio, de 3 a 200 caracteres (${motivo.trim().length}/200). Por ejemplo: se retiró dinero de la caja.`}
        </small>
        {(general || errores.fecha) && <AvisoPagina tono="error">{general ?? errores.fecha}</AvisoPagina>}
      </DialogoSeccion>
    </Dialogo>
  )
}

type FiltroMovimientos = 'todos' | 'gastos' | 'ingresos' | 'recaudos' | 'empleados'

/** S-76: una fila de la lista: movimiento de caja, recaudo de documento o (RF-27) préstamo o abono de empleado, en orden de registro. */
type FilaMovimiento = { clase: 'movimiento'; m: MovimientoCaja } | { clase: 'recaudo'; r: RecaudoDocumento } | { clase: 'empleado'; e: MovimientoEmpleadoDia }

const creadaEn = (f: FilaMovimiento) => (f.clase === 'movimiento' ? f.m.created_at : f.clase === 'recaudo' ? f.r.created_at : f.e.created_at)

const esIngreso = (m: MovimientoCaja) => m.grupo === 'otro_ingreso' || m.grupo === 'ingreso_externo'

/** "−$ 20.000" / "+$ 200.000"; en cero, sin signo. */
const conSigno = (signo: '−' | '+', valor: string) => (Number(valor) === 0 ? pesos(valor) : `${signo}${pesos(valor)}`)

const VACIO_POR_FILTRO: Record<FiltroMovimientos, string> = {
  todos: 'No hay movimientos este día.',
  gastos: 'No hay gastos este día.',
  ingresos: 'No hay ingresos este día.',
  recaudos: 'No hay recaudos este día.',
  empleados: 'No hay movimientos este día.',
}

/**
 * S-75: filtro Todos / Gastos / Ingresos con cantidad y total (del servidor, sin anulados) y pie de
 * totales siempre visible. El filtro es en el cliente: la lista ya viene en la misma respuesta.
 */
function ListaMovimientos({ movimientos, recaudos, movimientosEmpleado, totales, onAnular, onAnularRecaudo }: {
  movimientos: MovimientoCaja[]
  recaudos: RecaudoDocumento[]
  movimientosEmpleado: MovimientoEmpleadoDia[]
  totales?: ResumenCaja['totales_movimientos']
  onAnular?: (m: MovimientoCaja) => void
  onAnularRecaudo?: (r: RecaudoDocumento) => void
}) {
  const [filtro, setFiltro] = useState<FiltroMovimientos>('todos')
  const gastos = totales?.gastos ?? { cantidad: 0, total: '0' }
  const ingresos = totales?.ingresos ?? { cantidad: 0, total: '0' }
  const totalRecaudos = totales?.recaudos ?? { cantidad: 0, total: '0' }
  // RF-27: préstamos y abonos de empleados (sin anulados); ni gasto ni ingreso.
  const cero = { cantidad: 0, total: '0' }
  const emp = totales?.empleados ?? { prestamos: cero, abonos_caja: cero, abonos_nomina: cero }
  const cantidadEmpleados = emp.prestamos.cantidad + emp.abonos_caja.cantidad + emp.abonos_nomina.cantidad
  // S-76: movimientos, recaudos y movimientos de empleados en una sola lista, por hora de registro.
  const filas = useMemo<FilaMovimiento[]>(() => [
    ...movimientos.map((m) => ({ clase: 'movimiento' as const, m })),
    ...recaudos.map((r) => ({ clase: 'recaudo' as const, r })),
    ...movimientosEmpleado.map((e) => ({ clase: 'empleado' as const, e })),
  ].sort((a, b) => {
    const x = creadaEn(a)
    const y = creadaEn(b)
    return x < y ? -1 : x > y ? 1 : 0
  }), [movimientos, recaudos, movimientosEmpleado])
  const visibles = filtro === 'todos' ? filas : filas.filter((f) => (
    filtro === 'recaudos' ? f.clase === 'recaudo'
      : filtro === 'empleados' ? f.clase === 'empleado'
        : f.clase === 'movimiento' && esIngreso(f.m) === (filtro === 'ingresos')
  ))
  const columnas = onAnular || onAnularRecaudo ? 5 : 4
  const filaTotal = (tipo: 'gastos' | 'ingresos' | 'recaudos') => (
    <tr key={tipo} className={`caja-total caja-total-${tipo}`}>
      <th scope="row" colSpan={3}>{tipo === 'gastos' ? 'Total gastos' : tipo === 'ingresos' ? 'Total ingresos' : 'Total recaudos'}</th>
      <td className="col-numero">{tipo === 'gastos' ? conSigno('−', gastos.total) : conSigno('+', (tipo === 'ingresos' ? ingresos : totalRecaudos).total)}</td>
      {columnas === 5 && <td />}
    </tr>
  )

  return (
    <div className="caja-movimientos">
      <div className="caja-filtro">
        <ControlSegmentado<FiltroMovimientos>
          id="caja-filtro-movimientos"
          etiqueta="Mostrar"
          valor={filtro}
          onCambio={setFiltro}
          opciones={[
            { valor: 'todos', texto: `Todos (${gastos.cantidad + ingresos.cantidad + totalRecaudos.cantidad + cantidadEmpleados})` },
            { valor: 'gastos', texto: <>Gastos ({gastos.cantidad}) · <span className="caja-gasto">{conSigno('−', gastos.total)}</span></> },
            { valor: 'ingresos', texto: <>Ingresos ({ingresos.cantidad}) · <span className="caja-ingreso">{conSigno('+', ingresos.total)}</span></> },
            { valor: 'recaudos', texto: <>Recaudos ({totalRecaudos.cantidad}) · <span className="caja-ingreso">{conSigno('+', totalRecaudos.total)}</span></> },
            { valor: 'empleados', texto: `Empleados (${cantidadEmpleados})` },
          ]}
        />
      </div>
      {visibles.length === 0 ? <p className="vacio caja-vacio">{VACIO_POR_FILTRO[filtro]}</p> : (
    <table className="tabla-caja caja-tabla-movimientos">
      <caption className="solo-lector">Movimientos de caja del día</caption>
      <thead><tr><th>Tipo</th><th>Concepto</th><th>Cuenta</th><th className="col-numero">Valor</th>{columnas === 5 && <th><span className="solo-lector">Acción</span></th>}</tr></thead>
      <tbody>
        {visibles.map((f) => f.clase === 'recaudo' ? (
          <FilaRecaudo key={`r-${f.r.id}`} r={f.r} conAccion={columnas === 5} onAnular={onAnularRecaudo} />
        ) : f.clase === 'empleado' ? (
          <FilaEmpleado key={`e-${f.e.id}`} e={f.e} conAccion={columnas === 5} />
        ) : (
          <FilaMovimientoCaja key={`m-${f.m.id}`} m={f.m} conAccion={columnas === 5} onAnular={onAnular} />
        ))}
      </tbody>
      <tfoot>
        {(filtro === 'todos' || filtro === 'gastos') && filaTotal('gastos')}
        {(filtro === 'todos' || filtro === 'ingresos') && filaTotal('ingresos')}
        {(filtro === 'todos' || filtro === 'recaudos') && filaTotal('recaudos')}
        {filtro === 'empleados' && (
          <>
            <tr className="caja-total empleado-total-prestamos">
              <th scope="row" colSpan={3}>Total préstamos</th>
              <td className="col-numero">{conSigno('−', emp.prestamos.total)}</td>
              {columnas === 5 && <td />}
            </tr>
            <tr className="caja-total empleado-total-abonos">
              <th scope="row" colSpan={3}>
                Total abonos
                {emp.abonos_nomina.cantidad > 0 && <small className="bloque empleado-total-nomina"><span className="chip empleado-nomina">Nómina</span> {pesos(emp.abonos_nomina.total)}</small>}
              </th>
              <td className="col-numero">{conSigno('+', emp.abonos_caja.total)}</td>
              {columnas === 5 && <td />}
            </tr>
          </>
        )}
      </tfoot>
    </table>
      )}
    </div>
  )
}

function FilaMovimientoCaja({ m, conAccion, onAnular }: { m: MovimientoCaja; conAccion: boolean; onAnular?: (m: MovimientoCaja) => void }) {
  return (
    <tr className={m.anulacion ? 'reversado' : undefined}>
      <td>{m.grupo_nombre}<small className="bloque">{m.categoria.nombre}</small></td>
      <td>
        {m.concepto}{m.referencia && <small className="bloque">Ref. {m.referencia}</small>}
        <small className="bloque">{m.usuario.name} · {formatearFechaHora(m.created_at)}</small>
        {m.anulacion && <small className="bloque">Anulado por {m.anulacion.usuario?.name ?? '—'}: «{m.anulacion.motivo}»</small>}
      </td>
      <td>{m.cuenta.nombre}</td>
      <td className="col-numero">{esIngreso(m) ? '+' : '−'}{pesos(m.valor)}</td>
      {conAccion && <td>{onAnular && !m.anulacion && <BotonFila variante="anular" compacto descripcion={`Anular ${m.concepto}`} onClick={() => onAnular(m)} />}</td>}
    </tr>
  )
}

/** S-76: recaudo de documento en la lista del día: documento y observación debajo, en pequeño. */
function FilaRecaudo({ r, conAccion, onAnular }: { r: RecaudoDocumento; conAccion: boolean; onAnular?: (r: RecaudoDocumento) => void }) {
  return (
    <tr className={r.anulacion ? 'reversado recaudo-fila' : 'recaudo-fila'}>
      <td>Recaudo de cartera<small className="bloque">{r.documento}</small></td>
      <td>
        {r.observacion && <span className="recaudo-observacion">{r.observacion}</span>}
        <small className="bloque">{r.usuario.name} · {formatearFechaHora(r.created_at)}</small>
        {r.anulacion && <small className="bloque">Anulado por {r.anulacion.usuario?.name ?? '—'}: «{r.anulacion.motivo}»</small>}
      </td>
      <td>{r.cuenta.nombre}</td>
      <td className="col-numero">+{pesos(r.valor)}</td>
      {conAccion && <td>{onAnular && !r.anulacion && <BotonFila variante="anular" compacto descripcion={`Anular el recaudo ${r.documento}`} onClick={() => onAnular(r)} />}</td>}
    </tr>
  )
}

/**
 * RF-27: préstamo (−) o abono (+) de empleado en la lista del día. El abono por nómina lleva la marca
 * "Nómina": no suma al efectivo. Sin "Anular": solo el admin anula, desde la cuenta de empleados.
 */
function FilaEmpleado({ e, conAccion }: { e: MovimientoEmpleadoDia; conAccion: boolean }) {
  const prestamo = e.tipo === 'prestamo'
  const nomina = e.forma === 'nomina'
  return (
    <tr className={e.anulacion ? 'reversado empleado-fila' : 'empleado-fila'}>
      <td>
        {prestamo ? 'Préstamo a empleado' : 'Abono de empleado'}
        {nomina && <small className="bloque"><span className="chip empleado-nomina">Nómina</span></small>}
      </td>
      <td>
        {e.empleado.nombre}
        {e.nota && <small className="bloque">{e.nota}</small>}
        <small className="bloque">{e.usuario.name} · {formatearFechaHora(e.created_at)}</small>
        {e.anulacion && <small className="bloque">Anulado por {e.anulacion.usuario?.name ?? '—'}: «{e.anulacion.motivo}»</small>}
      </td>
      <td>{e.cuenta?.nombre ?? '—'}</td>
      <td className={nomina ? 'col-numero empleado-valor-nomina' : 'col-numero'}>{prestamo ? '−' : '+'}{pesos(e.valor)}</td>
      {conAccion && <td />}
    </tr>
  )
}

function CierreGuardado({ cierre }: { cierre: CierreCaja }) {
  const dif = Number(cierre.diferencia)
  return (
    <dl className="desglose-efectivo cierre-guardado">
      {cierre.apertura !== null && <div><dt>Apertura</dt><dd>{pesos(cierre.apertura)}</dd></div>}
      <div><dt>Efectivo contado (todo el cajón)</dt><dd>{pesos(cierre.efectivo_contado)}</dd></div>
      {/* S-98: en los cierres anteriores la base es 0 y se ven igual que antes. */}
      {Number(cierre.base) > 0 && (
        <>
          <div className="caja-base"><dt>− Base de caja</dt><dd>{pesos(cierre.base)}</dd></div>
          <div><dt>Efectivo para el cuadre</dt><dd>{pesos(cierre.efectivo_para_cuadre)}</dd></div>
        </>
      )}
      <div><dt>Efectivo esperado</dt><dd>{pesos(cierre.efectivo_esperado)}</dd></div>
      <div className={dif === 0 ? 'total' : 'total diferencia'}><dt>Diferencia</dt><dd>{dif > 0 ? '+' : ''}{pesos(cierre.diferencia)}</dd></div>
      {cierre.observacion && <div><dt>Observación</dt><dd>{cierre.observacion}</dd></div>}
    </dl>
  )
}

/**
 * Efectivo contado con diferencia en vivo, observación opcional (plegada tras "+ Agregar
 * observación") y "Cerrar día" con confirmación. Va al pie de la tarjeta: siempre visible.
 */
export function FormularioCierre({ fecha, esperado, base = '0', bloqueado, onCerrado }: { fecha: string; esperado: string; base?: string; bloqueado: boolean; onCerrado: () => void }) {
  const [contado, setContado] = useState('')
  const [observacion, setObservacion] = useState('')
  const [conObservacion, setConObservacion] = useState(false)
  const [confirmando, setConfirmando] = useState(false)
  const n = leerNumero(contado)
  const valido = n !== null && !Number.isNaN(n) && n >= 0
  // S-98: se cuenta todo el cajón; la base no cuenta para el cuadre.
  const nBase = Number(base)
  const enCajon = Number(esperado) + nBase
  const paraCuadre = valido ? Math.round((n - nBase) * 100) / 100 : null
  const diferencia = paraCuadre !== null ? Math.round((paraCuadre - Number(esperado)) * 100) / 100 : null
  // Supuesto S-98: faltar la base solo avisa, pero la observación se vuelve obligatoria.
  const faltaBase = valido && nBase > 0 && n < nBase
  const faltaObservacion = faltaBase && observacion.trim() === ''
  // S-101: la observación se ve si se abrió, si es obligatoria o si ya tiene texto (subir el contado no la esconde).
  const verObservacion = conObservacion || faltaBase || observacion !== ''
  const botonObservacion = useRef<HTMLButtonElement>(null)
  /** Quita la observación (vacía y cierra) y devuelve el foco a «Agregar observación». No aplica si es obligatoria. */
  const quitarObservacion = () => {
    setObservacion('')
    setConObservacion(false)
    setTimeout(() => botonObservacion.current?.focus(), 0)
  }

  const cerrar = useMutation({
    mutationFn: async () => (await api.post('/caja/cierres', { fecha, efectivo_contado: n, observacion: observacion.trim() || null })).data,
    onSuccess: () => {
      setConfirmando(false)
      onCerrado()
    },
  })

  // S-94: la confirmación va FUERA del <form> (hermana, no hija) y el submit solo cuenta si viene de
  // este mismo formulario; antes, el submit del diálogo subía hasta aquí y lo reabría.
  return (
    <>
    <form className="caja-form-cierre" onSubmit={(e) => { e.preventDefault(); if (e.target !== e.currentTarget) return; if (valido && !bloqueado && !faltaObservacion && !cerrar.isPending) setConfirmando(true) }}>
      <label htmlFor="caja-contado" className="etiqueta-campo">Efectivo contado (todo el cajón, base incluida)<span className="obligatorio" aria-hidden="true">*</span></label>
      <CampoMoneda
        id="caja-contado"
        placeholder={`Ej: ${formatoNumero(enCajon)}`}
        value={contado}
        aria-invalid={contado.trim() !== '' && !valido}
        aria-describedby="cierre-diferencia"
        onChange={(e) => { setContado(e.target.value); cerrar.reset() }}
      />
      <small id="cierre-diferencia" className={diferencia !== null && diferencia !== 0 ? 'ayuda error' : 'ayuda'} role="status">
        {contado.trim() !== '' && !valido ? 'Escriba un valor en pesos, 0 o mayor.'
          : diferencia === null ? `Esperado ${pesos(esperado)} · en el cajón ${pesos(enCajon)}`
            : `Base ${pesos(base)} → efectivo para el cuadre ${pesos(paraCuadre ?? 0)}. ${diferencia === 0 ? 'Cuadra con el efectivo esperado.' : `${diferencia > 0 ? 'Sobran' : 'Faltan'} ${pesos(Math.abs(diferencia))}.`}`}
      </small>
      {faltaBase && <div className="aviso error" role="alert">Falta la base: el cajón debe tener al menos {pesos(base)}. Escriba la observación.</div>}
      {verObservacion ? (
        <div className="caja-observacion">
          <label htmlFor="caja-observacion" className="etiqueta-campo">Observación {faltaBase ? <span className="obligatorio" aria-hidden="true">*</span> : '(opcional)'}</label>
          <div className="caja-observacion-fila">
            <input
              id="caja-observacion"
              autoFocus={conObservacion}
              required={faltaBase}
              aria-invalid={faltaObservacion}
              aria-describedby={faltaBase ? 'caja-observacion-obligatoria' : undefined}
              maxLength={255}
              value={observacion}
              onChange={(e) => setObservacion(e.target.value)}
              onKeyDown={(e) => {
                // S-101: Escape quita la observación (con o sin texto), salvo si es obligatoria.
                if (e.key === 'Escape' && !faltaBase) { e.preventDefault(); e.stopPropagation(); quitarObservacion() }
              }}
            />
            {!faltaBase && (
              <button type="button" className="secundario boton-44 caja-quitar-observacion" aria-label="Quitar la observación" title="Quitar la observación" onClick={quitarObservacion}>
                <Icono nombre="quitar" />
                <span>Quitar</span>
              </button>
            )}
          </div>
          {faltaBase && <small id="caja-observacion-obligatoria" className="ayuda">Obligatoria: falta la base de caja</small>}
        </div>
      ) : (
        <button ref={botonObservacion} type="button" className="secundario boton-44 caja-agregar-observacion" onClick={() => setConObservacion(true)}>
          <Icono nombre="mas" />
          Agregar observación
        </button>
      )}
      {cerrar.isError && !confirmando && <AvisoPagina tono="error">{mensajeError(cerrar.error)}</AvisoPagina>}
      <button type="submit" className="primario boton-44 caja-boton-cerrar" disabled={!valido || bloqueado || faltaObservacion || cerrar.isPending}>Cerrar día</button>
    </form>
      {confirmando && (
        <Confirmar
          titulo={`Cerrar la caja del ${formatearFecha(fecha)}`}
          texto={`Efectivo contado ${pesos(n ?? 0)}; base ${pesos(base)}; para el cuadre ${pesos(paraCuadre ?? 0)}; esperado ${pesos(esperado)}; diferencia ${pesos(diferencia ?? 0)}. La base queda en el cajón. Después de cerrar, el día queda en solo lectura (solo el administrador lo reabre).`}
          accion="Cerrar día"
          pendiente={cerrar.isPending}
          error={cerrar.isError ? mensajeError(cerrar.error) : null}
          onConfirmar={() => { if (!cerrar.isPending) cerrar.mutate() }}
          onCancelar={() => { setConfirmando(false); cerrar.reset() }}
        />
      )}
    </>
  )
}

/**
 * RF-26: agregar un movimiento. Pestañas por grupo (Gasto, Gasto fijo, Cuota bancaria, Ingreso
 * externo, Otro ingreso); categoría, concepto, valor, cuenta y referencia. S-52: solo "Otro ingreso"
 * admite cuentas distintas de efectivo.
 */
/**
 * S-76: recaudo de una factura o remisión a crédito de Contapyme que no está en las ventas a crédito
 * del sistema. Solo entra a la caja del día (no toca la cartera de clientes). El backend decide.
 */
function DialogoRecaudo({ fecha, onCerrar, onGuardado }: { fecha: string; onCerrar: () => void; onGuardado: () => void }) {
  const [tipo, setTipo] = useState<TipoDocumentoVenta | ''>('')
  const [numero, setNumero] = useState('')
  const [valor, setValor] = useState('')
  const [cuenta, setCuenta] = useState<number | ''>('')
  const [observacion, setObservacion] = useState('')
  const cuentas = useCuentasPago()
  const activas = cuentas.data ?? []
  const cuentaElegida = cuenta !== '' && activas.some((c) => c.id === cuenta) ? cuenta : (activas.find((c) => c.tipo === 'efectivo')?.id ?? '')
  const n = leerNumero(valor)
  const valorMalo = valor.trim() !== '' && (n === null || Number.isNaN(n) || n <= 0)
  const listo = tipo !== '' && numero.trim() !== '' && n !== null && !Number.isNaN(n) && n > 0 && cuentaElegida !== ''

  const guardar = useMutation({
    mutationFn: async () => (await api.post(`/caja/dias/${fecha}/recaudos`, {
      tipo_documento: tipo, numero_documento: numero.trim(), valor: n, cuenta_pago_id: cuentaElegida, observacion: observacion.trim() || null,
    })).data,
    onSuccess: onGuardado,
  })
  const errores = guardar.isError ? erroresCampo(guardar.error) : {}
  const general = guardar.isError && Object.keys(errores).length === 0 ? mensajeError(guardar.error) : null

  return (
    <Dialogo
      titulo="Recaudo de cartera"
      descripcion="Para facturas a crédito que no están en ventas a crédito del sistema."
      variante="ancha"
      onCerrar={onCerrar}
      pendiente={guardar.isPending}
      onEnviar={() => { if (listo && !guardar.isPending) guardar.mutate() }}
      pie={(
        <>
          <BotonCancelar />
          <button type="submit" className="primario" disabled={!listo || guardar.isPending}>{guardar.isPending ? 'Guardando…' : 'Registrar recaudo'}</button>
        </>
      )}
    >
      <DialogoSeccion>
        <div className="campos-dos">
          <label>
            <span className="etiqueta-campo">Tipo de documento<span className="obligatorio" aria-hidden="true">*</span></span>
            <select autoFocus required value={tipo} aria-invalid={!!errores.tipo_documento} onChange={(e) => { setTipo(e.target.value as TipoDocumentoVenta | ''); guardar.reset() }}>
              <option value="">Seleccione…</option>
              <option value="factura">Factura</option>
              <option value="remision">Remisión</option>
            </select>
            {errores.tipo_documento && <small className="ayuda error">{errores.tipo_documento}</small>}
          </label>
          <label>
            <span className="etiqueta-campo">N.º del documento<span className="obligatorio" aria-hidden="true">*</span></span>
            <input required maxLength={60} autoComplete="off" value={numero} aria-invalid={!!errores.numero_documento} onChange={(e) => { setNumero(e.target.value); guardar.reset() }} />
            {errores.numero_documento && <small className="ayuda error">{errores.numero_documento}</small>}
          </label>
        </div>
        <div className="campos-dos">
          <label>
            <span className="etiqueta-campo">Valor recaudado<span className="obligatorio" aria-hidden="true">*</span></span>
            <CampoMoneda required placeholder="Ej: 150.000" value={valor} aria-invalid={valorMalo || !!errores.valor} onChange={(e) => { setValor(e.target.value); guardar.reset() }} />
            {(valorMalo || errores.valor) && <small className="ayuda error">{errores.valor ?? 'Debe ser mayor que 0.'}</small>}
          </label>
          <label>
            <span className="etiqueta-campo">Cuenta de pago<span className="obligatorio" aria-hidden="true">*</span></span>
            <select value={cuentaElegida} aria-invalid={!!errores.cuenta_pago_id} onChange={(e) => { setCuenta(e.target.value === '' ? '' : Number(e.target.value)); guardar.reset() }}>
              {cuentaElegida === '' && <option value="">{cuentas.isLoading ? 'Cargando…' : 'Seleccione…'}</option>}
              {activas.map((c) => <option key={c.id} value={c.id}>{etiquetaCuenta(c)}</option>)}
            </select>
            {errores.cuenta_pago_id && <small className="ayuda error">{errores.cuenta_pago_id}</small>}
          </label>
        </div>
        <label>
          <span className="etiqueta-campo">Observación (opcional)</span>
          <textarea maxLength={500} rows={2} value={observacion} aria-invalid={!!errores.observacion} onChange={(e) => setObservacion(e.target.value)} />
          {errores.observacion && <small className="ayuda error">{errores.observacion}</small>}
        </label>
        {general && <AvisoPagina tono="error">{general}</AvisoPagina>}
      </DialogoSeccion>
    </Dialogo>
  )
}

function DialogoMovimiento({ fecha, esperado, base, onCerrar, onGuardado }: {
  fecha: string
  /** S-98: efectivo esperado del día, para avisar si el gasto usaría la base. */
  esperado: string | null
  base: string
  onCerrar: () => void
  onGuardado: (avisoBase: boolean) => void
}) {
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
  // S-98: un egreso en efectivo que deje el esperado por debajo de 0 usaría la base (solo avisa).
  const enEfectivo = (cuentas.data ?? []).find((c) => c.id === cuentaElegida)?.tipo === 'efectivo'
  const esSalida = grupo === 'vario' || grupo === 'fijo' || grupo === 'financiero'
  const usaBase = esperado !== null && enEfectivo && esSalida && n !== null && !Number.isNaN(n) && n > 0 && Number(esperado) - n < 0

  const guardar = useMutation({
    mutationFn: async () => (await api.post<{ aviso_base?: boolean }>('/caja/movimientos', {
      fecha, categoria_caja_id: categoria, concepto: concepto.trim(), valor: n, cuenta_pago_id: cuentaElegida, referencia: referencia.trim() || null,
    })).data,
    onSuccess: (r) => onGuardado(r.aviso_base === true),
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
          <BotonCancelar />
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
              {cuentasPermitidas.map((c) => <option key={c.id} value={c.id}>{etiquetaCuenta(c)}</option>)}
            </select>
            <small className={errores.cuenta_pago_id ? 'ayuda error' : 'ayuda'}>{errores.cuenta_pago_id ?? (soloEfectivo(grupo) ? 'Este tipo solo se registra en efectivo.' : 'Cualquier cuenta activa.')}</small>
          </label>
        </div>
        <label>
          <span className="etiqueta-campo">Referencia (opcional)</span>
          <input maxLength={60} placeholder="Ej: FVE-1234" value={referencia} onChange={(e) => setReferencia(e.target.value)} />
        </label>
        {usaBase && <div className="aviso atencion" role="status">Este movimiento usaría la base de caja ({pesos(base)}).</div>}
        {general && <AvisoPagina tono="error">{general}</AvisoPagina>}
      </DialogoSeccion>
    </Dialogo>
  )
}

type TipoEmpleado = 'prestamo' | 'abono'
/** Cuenta de pago del abono: una cuenta activa o el descuento de nómina (no entra a la caja). */
type CuentaAbono = number | 'nomina' | ''

const textoEmpleado = (e: EmpleadoOpcion) =>
  `${e.nombre}${e.documento ? ` · ${e.documento}` : ''} · ${Number(e.saldo) > 0 ? `debe ${pesos(e.saldo)}` : 'sin deuda'}`

/**
 * RF-27, S-81 (ajusta S-56 y S-57): préstamo o abono de un empleado desde la caja de hoy (solo
 * mostrador). El empleado se elige en un select con su saldo; el abono es parcial o total ("Pagar
 * todo") y sale por una cuenta de pago o por descuento de nómina (forma `nomina`: sin cuenta, no mueve
 * la caja). Lo de "Quedaría en" es informativo: el servidor valida el saldo con el empleado bloqueado.
 */
function DialogoEmpleados({ esperado = null, base = '0', onCerrar, onGuardado }: {
  /** S-98: efectivo esperado de hoy (null si la caja abierta no es la de hoy). */
  esperado?: string | null
  base?: string
  onCerrar: () => void
  onGuardado: (aviso: string, avisoBase: boolean) => void
}) {
  const [tipo, setTipo] = useState<TipoEmpleado>('prestamo')
  const [empleadoId, setEmpleadoId] = useState<number | ''>('')
  const [valor, setValor] = useState('')
  const [cuenta, setCuenta] = useState<CuentaAbono>('')
  const [nota, setNota] = useState('')
  const empleados = useQuery({
    queryKey: ['empleados', 'activos'],
    queryFn: async () => (await api.get<{ data: EmpleadoOpcion[] }>('/empleados')).data.data,
  })
  const cuentas = useCuentasPago()
  const activas = cuentas.data ?? []
  const todos = empleados.data ?? []
  const lista = tipo === 'abono' ? todos.filter((e) => Number(e.saldo) > 0) : todos
  // En abono, un empleado sin deuda deja de estar elegido (vuelve si se pasa a préstamo).
  const elegido = lista.find((e) => e.id === empleadoId)
  const saldo = elegido ? Number(elegido.saldo) : 0
  const cuentaElegida: CuentaAbono = cuenta === 'nomina' || (cuenta !== '' && activas.some((c) => c.id === cuenta))
    ? cuenta
    : (activas.find((c) => c.tipo === 'efectivo')?.id ?? '')
  const nomina = tipo === 'abono' && cuentaElegida === 'nomina'
  const n = leerNumero(valor)
  const valorValido = n !== null && !Number.isNaN(n) && n > 0 && Number.isInteger(n)
  const supera = tipo === 'abono' && !!elegido && valorValido && n > saldo
  const valorMalo = valor.trim() !== '' && !valorValido
  const quedaria = elegido && valorValido ? (tipo === 'prestamo' ? saldo + n : saldo - n) : null
  const listo = !!elegido && valorValido && !supera && (tipo === 'prestamo' || cuentaElegida !== '')

  // S-98: el préstamo sale de Efectivo; si deja el esperado de hoy por debajo de 0, usaría la base (solo avisa).
  const usaBase = tipo === 'prestamo' && esperado !== null && valorValido && Number(esperado) - (n ?? 0) < 0

  const guardar = useMutation({
    mutationFn: async () => (await api.post<{ aviso_base?: boolean }>(`/empleados/${empleadoId}/${tipo === 'prestamo' ? 'prestamos' : 'abonos'}`, {
      valor: n,
      nota: nota.trim() || null,
      ...(tipo === 'abono' ? (nomina ? { forma: 'nomina' } : { forma: 'caja', cuenta_pago_id: cuentaElegida }) : {}),
    })).data,
    onSuccess: (r) => {
      const nombre = elegido?.nombre ?? ''
      const monto = pesos(n ?? 0)
      const resto = quedaria ?? 0
      onGuardado(
        tipo === 'prestamo' ? `Préstamo de ${monto} registrado a ${nombre}. Debe ${pesos(resto)}.`
          : nomina ? `Descuento de nómina de ${monto} registrado a ${nombre}.`
            : `Abono de ${monto} registrado a ${nombre}. ${resto > 0 ? `Debe ${pesos(resto)}.` : 'Deuda cancelada.'}`,
        r.aviso_base === true,
      )
    },
  })
  const errores = guardar.isError ? erroresCampo(guardar.error) : {}
  const general = guardar.isError && Object.keys(errores).length === 0 ? mensajeError(guardar.error) : null
  const vacio = empleados.isSuccess && (todos.length === 0
    ? 'No hay empleados activos. El administrador debe crearlos.'
    : lista.length === 0 ? 'Ningún empleado tiene saldo pendiente.' : null)

  return (
    <Dialogo
      titulo="Cuenta de empleados"
      descripcion={tipo === 'prestamo' ? 'El préstamo sale del efectivo de la caja.' : undefined}
      variante="ancha"
      modificado={empleadoId !== '' || valor.trim() !== '' || nota.trim() !== ''}
      onCerrar={onCerrar}
      pendiente={guardar.isPending}
      onEnviar={() => { if (listo && !guardar.isPending) guardar.mutate() }}
      pie={(
        <>
          <BotonCancelar />
          <button type="submit" className="primario" disabled={!listo || guardar.isPending}>
            {guardar.isPending ? 'Guardando…' : tipo === 'prestamo' ? 'Registrar préstamo' : 'Registrar abono'}
          </button>
        </>
      )}
    >
      <DialogoSeccion>
        <ControlSegmentado<TipoEmpleado>
          id="empleado-tipo"
          etiqueta="Tipo"
          valor={tipo}
          onCambio={(t) => { setTipo(t); guardar.reset() }}
          opciones={[{ valor: 'prestamo', texto: 'Préstamo' }, { valor: 'abono', texto: 'Abono' }]}
        />
        <label>
          <span className="etiqueta-campo">Empleado<span className="obligatorio" aria-hidden="true">*</span></span>
          <select
            className="empleado-select"
            autoFocus
            required
            value={elegido ? elegido.id : ''}
            disabled={!!vacio}
            aria-describedby="empleado-saldo"
            onChange={(e) => { setEmpleadoId(e.target.value === '' ? '' : Number(e.target.value)); guardar.reset() }}
          >
            <option value="" disabled>{empleados.isLoading ? 'Cargando…' : 'Seleccione un empleado'}</option>
            {lista.map((e) => <option key={e.id} value={e.id}>{textoEmpleado(e)}</option>)}
          </select>
          <small id="empleado-saldo" className="ayuda empleado-saldo" role="status">
            {vacio ?? (elegido ? `Debe ${pesos(saldo)}${quedaria !== null && !supera ? ` · Quedaría en ${pesos(quedaria)}` : ''}` : '')}
          </small>
        </label>
        <div className="campos-dos">
          <label>
            <span className="etiqueta-campo">Valor<span className="obligatorio" aria-hidden="true">*</span></span>
            <span className="empleado-valor">
              <CampoMoneda
                required
                placeholder="Ej: 50.000"
                value={valor}
                aria-invalid={valorMalo || supera || !!errores.valor}
                aria-describedby="empleado-valor-ayuda"
                onChange={(e) => { setValor(e.target.value); guardar.reset() }}
              />
              {tipo === 'abono' && (
                <button
                  type="button"
                  className="secundario empleado-pagar-todo"
                  disabled={!elegido}
                  onClick={() => { setValor(formatoNumero(saldo)); guardar.reset() }}
                >Pagar todo</button>
              )}
            </span>
            <small id="empleado-valor-ayuda" className={valorMalo || supera || errores.valor ? 'ayuda error' : 'ayuda'}>
              {errores.valor
                ?? (valorMalo ? 'Debe ser mayor que 0.'
                  : supera ? `No puede superar lo que debe (${pesos(saldo)})`
                    : tipo === 'abono' && elegido && valorValido
                      ? (n < saldo ? `Abono parcial: el empleado seguirá debiendo ${pesos(saldo - n)}.` : 'Cancela la deuda.')
                      : '')}
            </small>
          </label>
          {tipo === 'abono' && (
            <label>
              <span className="etiqueta-campo">Cuenta de pago<span className="obligatorio" aria-hidden="true">*</span></span>
              <select
                className="empleado-select"
                value={cuentaElegida}
                aria-invalid={!!errores.cuenta_pago_id}
                aria-describedby={nomina ? 'empleado-nomina-nota' : undefined}
                onChange={(e) => { setCuenta(e.target.value === 'nomina' ? 'nomina' : e.target.value === '' ? '' : Number(e.target.value)); guardar.reset() }}
              >
                {cuentaElegida === '' && <option value="">{cuentas.isLoading ? 'Cargando…' : 'Seleccione…'}</option>}
                {activas.map((c) => <option key={c.id} value={c.id}>{etiquetaCuenta(c)}</option>)}
                <option disabled value="separador">──────────</option>
                <option value="nomina">Descuento de nómina (no entra a la caja)</option>
              </select>
              {errores.cuenta_pago_id
                ? <small className="ayuda error">{errores.cuenta_pago_id}</small>
                : nomina && <small id="empleado-nomina-nota" className="ayuda">Baja la deuda del empleado; no suma a la caja.</small>}
            </label>
          )}
        </div>
        <label>
          <span className="etiqueta-campo">Observación (opcional)</span>
          <input maxLength={255} autoComplete="off" value={nota} aria-invalid={!!errores.nota} onChange={(e) => setNota(e.target.value)} />
          {errores.nota && <small className="ayuda error">{errores.nota}</small>}
        </label>
        {usaBase && <div className="aviso atencion" role="status">Este movimiento usaría la base de caja ({pesos(base)}).</div>}
        {(general || errores.forma) && <AvisoPagina tono="error">{general ?? errores.forma}</AvisoPagina>}
      </DialogoSeccion>
    </Dialogo>
  )
}
