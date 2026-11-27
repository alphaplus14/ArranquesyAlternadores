import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import { etiquetaCuenta, NOMBRE_TIPO_DOCUMENTO, type PaginaInforme, type RecaudoDocumento, type TipoDocumentoVenta, type TotalesRecaudos } from '../../api/tipos'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import Medida from '../../componentes/Medida'
import Paginacion from '../../componentes/Paginacion'
import { fechaCorta, hoy } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import { useCuentasPago } from '../../hooks/useCatalogos'
import { useDebounce } from '../../hooks/useDebounce'
import { formatearFechaHora } from '../../utilidades/fechas'
import '../../componentes/cartera.css'

type FiltroTipo = 'todos' | TipoDocumentoVenta

const primeroDelMes = () => `${hoy().slice(0, 8)}01`

/**
 * S-76: recaudos de documentos de Contapyme registrados en la caja del mostrador (facturas o
 * remisiones a crédito que no son ventas a crédito del sistema). Solo lectura y solo en pantalla.
 * Los totales cubren todo el rango filtrado, sin los anulados.
 */
export default function Recaudos() {
  const [desde, setDesde] = useState(primeroDelMes())
  const [hasta, setHasta] = useState(hoy())
  const [tipo, setTipo] = useState<FiltroTipo>('todos')
  const [cuenta, setCuenta] = useState('')
  const [q, setQ] = useState('')
  const [pagina, setPagina] = useState(1)
  const busqueda = useDebounce(q.trim(), 300)
  const cuentas = useCuentasPago()

  const params = {
    desde, hasta,
    tipo: tipo === 'todos' ? undefined : tipo,
    cuenta_pago_id: cuenta || undefined,
    q: busqueda || undefined,
  }
  const { data, isFetching } = useQuery({
    queryKey: ['recaudos-documento', params, pagina],
    queryFn: async () => (await api.get<PaginaInforme<RecaudoDocumento, TotalesRecaudos>>('/recaudos-documento', { params: { ...params, page: pagina, por_pagina: 50 } })).data,
    placeholderData: (previo) => previo,
  })
  const reiniciar = <T,>(fijar: (v: T) => void) => (v: T) => { fijar(v); setPagina(1) }
  const t = data?.totales

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Recaudos de cartera</h1>
      </div>

      <section className="tarjeta-pagina">
        <ControlSegmentado<FiltroTipo>
          id="recaudo-tipo"
          etiqueta="Tipo de documento"
          valor={tipo}
          onCambio={reiniciar(setTipo)}
          opciones={[
            { valor: 'todos', texto: 'Todos' },
            { valor: 'factura', texto: 'Facturas' },
            { valor: 'remision', texto: 'Remisiones' },
          ]}
        />
        <div className="filtros-informe">
          <label>Desde<input type="date" max={hasta} value={desde} onChange={(e) => reiniciar(setDesde)(e.target.value)} /></label>
          <label>Hasta<input type="date" min={desde} max={hoy()} value={hasta} onChange={(e) => reiniciar(setHasta)(e.target.value)} /></label>
          <label>Cuenta de pago
            <select value={cuenta} onChange={(e) => reiniciar(setCuenta)(e.target.value)}>
              <option value="">Todas las cuentas</option>
              {(cuentas.data ?? []).map((c) => <option key={c.id} value={c.id}>{etiquetaCuenta(c)}</option>)}
            </select>
          </label>
          <label className="recaudo-buscar">Buscar
            <input type="search" placeholder="N.º del documento u observación" value={q} onChange={(e) => reiniciar(setQ)(e.target.value)} />
          </label>
        </div>

        {t && (
          <div className="medidas recaudo-medidas">
            <Medida etiqueta="Total recaudado vigente" valor={pesos(t.total_vigente)} tono="verde" icono="billetera" />
            <Medida etiqueta="Cantidad" valor={t.cantidad_vigente} tono="azul" icono="auditoria" />
            {t.por_cuenta.map((c) => (
              <Medida key={c.cuenta_pago_id} etiqueta={c.nombre} valor={pesos(c.total)} tono="azul" icono="billetera" />
            ))}
          </div>
        )}

        <div className="tabla-desplazable" aria-busy={isFetching}>
          {!data && <div className="cargando">Cargando…</div>}
          {data && (
            <table className="recaudo-tabla">
              <thead>
                <tr>
                  <th>Fecha</th><th>Tipo</th><th>N.º</th><th className="cifra">Valor</th><th>Cuenta</th><th>Observación</th><th>Registrado por</th><th>Estado</th>
                </tr>
              </thead>
              <tbody>
                {data.data.length === 0 && <tr><td colSpan={8} className="vacio">No hay recaudos en el período.</td></tr>}
                {data.data.map((r) => (
                  <tr key={r.id} className={r.anulacion ? 'reversado' : undefined}>
                    <td>{fechaCorta(r.fecha)}</td>
                    <td>{NOMBRE_TIPO_DOCUMENTO[r.tipo_documento]}</td>
                    <td>{r.numero_documento}</td>
                    <td className="cifra">{pesos(r.valor)}</td>
                    <td>{r.cuenta.nombre}</td>
                    <td className="recaudo-observacion">{r.observacion ?? '—'}</td>
                    <td>{r.usuario.name}<small className="bloque">{formatearFechaHora(r.created_at)}</small></td>
                    <td>
                      {r.anulacion
                        ? <><span className="chip">Anulado</span><small className="bloque">{r.anulacion.usuario?.name ?? '—'}: «{r.anulacion.motivo}»</small></>
                        : 'Vigente'}
                    </td>
                  </tr>
                ))}
                {data.data.length > 0 && t && (
                  <tr className="fila-total">
                    <td colSpan={3}>Total vigente del rango ({t.cantidad_vigente})</td>
                    <td className="cifra">{pesos(t.total_vigente)}</td>
                    <td colSpan={4} />
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>
        <Paginacion pagina={data} onCambiar={setPagina} />
      </section>
    </div>
  )
}
