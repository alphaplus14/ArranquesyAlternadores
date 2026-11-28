import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import { etiquetaCuenta, NOMBRE_TIPO_DOCUMENTO, infoPagina, type PaginaInforme, type RecaudoDocumento, type TipoDocumentoVenta, type TotalesRecaudos } from '../../api/tipos'
import BarraFiltros, { CampoFiltro, TextoResultados, VacioFiltros } from '../../componentes/BarraFiltros'
import { rangoAtajo } from '../../utilidades/atajosPeriodo'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import Medida from '../../componentes/Medida'
import BotonFila from '../../componentes/BotonFila'
import BotonCancelar from '../../componentes/BotonCancelar'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import Paginacion from '../../componentes/Paginacion'
import { fechaCorta } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import { useCuentasPago } from '../../hooks/useCatalogos'
import { useFiltrosUrl } from '../../hooks/useFiltrosUrl'
import { formatearFechaHora } from '../../utilidades/fechas'
import '../../componentes/cartera.css'

type FiltroTipo = 'todos' | TipoDocumentoVenta

/**
 * S-76: recaudos de documentos de Contapyme registrados en la caja del mostrador (facturas o
 * remisiones a crédito que no son ventas a crédito del sistema). Solo lectura y solo en pantalla.
 * Los totales cubren todo el rango filtrado, sin los anulados. S-89: barra común (atajos de período,
 * filtros en la URL, "Limpiar filtros" y "Mostrando N de M").
 */
export default function Recaudos() {
  const mes = rangoAtajo('este_mes')
  const { filtros: f, fijar, pagina, irA, activos, limpiar } = useFiltrosUrl({ desde: mes.desde, hasta: mes.hasta, tipo: 'todos', cuenta: '', q: '' })
  const cuentas = useCuentasPago()

  const params = {
    desde: f.desde,
    hasta: f.hasta,
    tipo: f.tipo === 'todos' ? undefined : f.tipo,
    cuenta_pago_id: f.cuenta || undefined,
    q: f.q || undefined,
  }
  const { data, isFetching } = useQuery({
    queryKey: ['recaudos-documento', params, pagina],
    queryFn: async () => (await api.get<PaginaInforme<RecaudoDocumento, TotalesRecaudos>>('/recaudos-documento', { params: { ...params, page: pagina, por_pagina: 50 } })).data,
    placeholderData: (previo) => previo,
  })
  const t = data?.totales
  const info = data ? infoPagina(data) : null
  // S-99: el motivo completo de una anulación se lee en un diálogo (Escape cierra; el foco vuelve al botón).
  const [viendo, setViendo] = useState<RecaudoDocumento | null>(null)

  return (
    <div className="pila pagina-tabla-fija">
      <div className="pagina-encabezado">
        <h1>Recaudos de cartera</h1>
      </div>

      <section className="tarjeta-pagina tarjeta-tabla">
        <BarraFiltros
          busqueda={{ valor: f.q, onCambio: (q) => fijar({ q }), placeholder: 'N.º del documento u observación' }}
          periodo={{ desde: f.desde, hasta: f.hasta, onCambio: (desde, hasta) => fijar({ desde, hasta }) }}
          activos={activos}
          onLimpiar={limpiar}
        >
          <ControlSegmentado<FiltroTipo>
            id="recaudo-tipo"
            etiqueta="Tipo de documento"
            valor={f.tipo as FiltroTipo}
            onCambio={(tipo) => fijar({ tipo })}
            opciones={[
              { valor: 'todos', texto: 'Todos' },
              { valor: 'factura', texto: 'Facturas' },
              { valor: 'remision', texto: 'Remisiones' },
            ]}
          />
          <CampoFiltro etiqueta="Cuenta de pago">
            <select value={f.cuenta} onChange={(e) => fijar({ cuenta: e.target.value })}>
              <option value="">Todas las cuentas</option>
              {(cuentas.data ?? []).map((c) => <option key={c.id} value={c.id}>{etiquetaCuenta(c)}</option>)}
            </select>
          </CampoFiltro>
        </BarraFiltros>

        {t && (
          <div className="medidas recaudo-medidas">
            <Medida etiqueta="Total recaudado vigente" valor={pesos(t.total_vigente)} tono="verde" icono="billetera" />
            <Medida etiqueta="Cantidad" valor={t.cantidad_vigente} tono="azul" icono="auditoria" />
            {t.por_cuenta.map((c) => (
              <Medida key={c.cuenta_pago_id} etiqueta={c.nombre} valor={pesos(c.total)} tono="azul" icono="billetera" />
            ))}
          </div>
        )}

        {info && <TextoResultados mostrando={data!.data.length} total={info.total} de="recaudos" />}
        <div className="tabla-desplazable tabla-viva llena" aria-busy={isFetching}>
          {!data && <div className="cargando">Cargando…</div>}
          {data && (
            <table className="recaudo-tabla">
              <colgroup>
                <col className="r-fecha" /><col className="r-tipo" /><col className="r-numero" /><col className="r-valor" />
                <col className="r-cuenta" /><col className="r-observacion" /><col className="r-usuario" /><col className="r-estado" />
              </colgroup>
              <thead>
                <tr>
                  <th>Fecha</th><th>Tipo</th><th>N.º</th><th className="cifra">Valor</th><th>Cuenta</th><th>Observación</th><th>Registrado por</th><th>Estado</th>
                </tr>
              </thead>
              <tbody>
                {data.data.length === 0 && <tr><td colSpan={8} className="vacio"><VacioFiltros hayFiltros={activos > 0} sinFiltros="No hay recaudos en el período." onLimpiar={limpiar} /></td></tr>}
                {data.data.map((r) => (
                  <tr key={r.id} className={r.anulacion ? 'reversado' : undefined}>
                    <td data-etiqueta="Fecha">{fechaCorta(r.fecha)}</td>
                    <td data-etiqueta="Tipo">{NOMBRE_TIPO_DOCUMENTO[r.tipo_documento]}</td>
                    <td data-etiqueta="N.º">{r.numero_documento}</td>
                    <td data-etiqueta="Valor" className="cifra">{pesos(r.valor)}</td>
                    <td data-etiqueta="Cuenta">{r.cuenta.nombre}</td>
                    {/* S-99: 2 líneas como máximo; el texto completo sigue en el DOM (lector de pantalla) y en el title. */}
                    <td data-etiqueta="Observación" className="sin-tachar">
                      {r.observacion ? <span className="recaudo-recorte" title={r.observacion}>{r.observacion}</span> : '—'}
                    </td>
                    <td data-etiqueta="Registrado por" className="sin-tachar">{r.usuario.name}<small className="bloque">{formatearFechaHora(r.created_at)}</small></td>
                    <td data-etiqueta="Estado" className="sin-tachar recaudo-estado">
                      {r.anulacion ? (
                        <>
                          <span className="recaudo-estado-fila">
                            <span className="chip anulado">Anulado</span>
                            <BotonFila variante="ver" etiqueta="Ver motivo" compacto descripcion={`Ver el motivo de la anulación del recaudo ${r.numero_documento}`} onClick={() => setViendo(r)} />
                          </span>
                          <small
                            className="recaudo-motivo"
                            title={`Anulado por ${r.anulacion.usuario?.name ?? '—'} el ${formatearFechaHora(r.anulacion.created_at)}: ${r.anulacion.motivo}`}
                          >
                            Anulado por {r.anulacion.usuario?.name ?? '—'} · «{r.anulacion.motivo}»
                          </small>
                        </>
                      ) : <span className="chip vigente">Vigente</span>}
                    </td>
                  </tr>
                ))}
                {data.data.length > 0 && t && (
                  <tr className="fila-total recaudo-total">
                    <td colSpan={3}>Total vigente del rango ({t.cantidad_vigente})</td>
                    <td className="cifra">{pesos(t.total_vigente)}</td>
                    <td colSpan={4} className="recaudo-total-relleno" />
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>
        <Paginacion pagina={data} onCambiar={irA} />
      </section>

      {viendo?.anulacion && (
        <Dialogo
          titulo="Motivo de la anulación"
          descripcion={`Recaudo ${NOMBRE_TIPO_DOCUMENTO[viendo.tipo_documento].toLowerCase()} ${viendo.numero_documento} · ${pesos(viendo.valor)}`}
          variante="confirmar"
          confirmarSalida={false}
          onCerrar={() => setViendo(null)}
          pie={<BotonCancelar autoFocus>Cerrar</BotonCancelar>}
        >
          <DialogoSeccion>
            <p className="recaudo-motivo-completo">«{viendo.anulacion.motivo}»</p>
            <p className="ayuda">Anulado por {viendo.anulacion.usuario?.name ?? '—'} el {formatearFechaHora(viendo.anulacion.created_at)}.</p>
          </DialogoSeccion>
        </Dialogo>
      )}
    </div>
  )
}
