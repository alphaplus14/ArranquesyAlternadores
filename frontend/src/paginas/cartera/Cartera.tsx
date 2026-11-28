import { useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import BotonFila from '../../componentes/BotonFila'
import { useQueryClient } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import type { ListaCartera, ResumenCartera } from '../../api/tipos'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { useFiltrosUrl } from '../../hooks/useFiltrosUrl'
import BarraFiltros, { CampoFiltro } from '../../componentes/BarraFiltros'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import Paginacion from '../../componentes/Paginacion'
import BarraAntiguedad from '../../componentes/cartera/BarraAntiguedad'
import DialogoAbono from '../../componentes/cartera/DialogoAbono'
import { pesos } from '../../componentes/numeros'
import { fechaCorta } from '../../componentes/fechas'
import { textoSaldo } from '../../utilidades/cartera'
import { hoyEnBogota } from '../../utilidades/periodos'

const CERO = { al_dia: '0.00', d1_30: '0.00', d31_60: '0.00', d61_90: '0.00', mas_90: '0.00' }

/**
 * RF-15 / RF-18 (S-30): cartera de clientes. Saldo, vencido y próximo vencimiento por cliente,
 * mayor saldo primero, con la antigüedad del conjunto. Mostrador y admin. Solo en pantalla.
 */
export default function Cartera() {
  const qc = useQueryClient()
  const { search: filtrosLista } = useLocation() // la miga de la ficha vuelve con estos filtros
  // S-89: filtros en la URL (se conservan al entrar a un cliente y volver).
  const { filtros: f, fijar, activos, limpiar } = useFiltrosUrl({ q: '', saldo: '1', vencidos: '0', corte: '', desde: '', hasta: '' })
  const q = f.q
  const soloConSaldo = f.saldo === '1'
  const soloVencidos = f.vencidos === '1'
  const corte = f.corte
  const { desde, hasta } = f // RF-15: ventas a crédito cerradas dentro del período
  const [abonando, setAbonando] = useState<ResumenCartera | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)
  const disparador = useRef<HTMLButtonElement | null>(null)
  const enPeriodo = Boolean(desde || hasta)
  const termino = q.trim() // el buscador de BarraFiltros ya espera 300 ms

  const filtros = { termino, soloConSaldo, soloVencidos, corte, desde, hasta }
  const { filas, resultado, info, irA, cargando } = useListaPaginada<ResumenCartera>({
    clave: ['cartera', filtros],
    filtros: { q, soloConSaldo, soloVencidos, corte, desde, hasta },
    porPagina: { defecto: 25 }, // S-70
    traer: async (page, porPagina) => (await api.get<ListaCartera>('/cartera', {
      params: {
        busqueda: termino || undefined,
        solo_con_saldo: soloConSaldo ? 1 : 0,
        solo_vencidos: soloVencidos ? 1 : 0,
        corte: corte || undefined,
        desde: desde || undefined,
        hasta: hasta || undefined,
        page,
        por_pagina: porPagina,
      },
    })).data,
  })
  const lista = resultado as ListaCartera | undefined
  const t = lista?.totales

  const cerrarAbono = () => {
    setAbonando(null)
    setTimeout(() => disparador.current?.focus(), 0)
  }

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Cartera</h1>
        <p>Saldo de los clientes a crédito: ventas cerradas, netas de reversos, menos abonos. No factura ni toca Contapyme.</p>
      </div>
      <Resumen
        clave="cartera-resumen"
        compacto={(
          <>
            <span>Cartera <strong>{t ? pesos(t.cartera_total) : '—'}</strong></span>
            <span className={t && Number(t.vencido_total) > 0 ? 'resumen-agotados' : ''}>Vencido <strong>{t ? pesos(t.vencido_total) : '—'}</strong></span>
            <span>Clientes con saldo <strong>{t ? t.clientes_con_saldo : '—'}</strong></span>
          </>
        )}
      >
        <Medida etiqueta="Cartera total" valor={t ? pesos(t.cartera_total) : '—'} pie={t && Number(t.saldo_a_favor) > 0 ? `${pesos(t.saldo_a_favor)} a favor de clientes` : undefined} tono="azul" icono="billetera" />
        <Medida etiqueta="Vencido" valor={t ? pesos(t.vencido_total) : '—'} pie={t ? `${t.clientes_vencidos} cliente(s)` : undefined} tono="rojo" icono="agotados" />
        <Medida etiqueta="Clientes con saldo" valor={t ? t.clientes_con_saldo : '—'} tono="amarillo" icono="persona" />
        {enPeriodo && <Medida etiqueta="Ventas del período" valor={t?.ventas_periodo_total ? pesos(t.ventas_periodo_total) : '—'} pie="a crédito, cerradas en el rango" tono="azul" icono="billetera" />}
        <Medida etiqueta="Corte" valor={fechaCorta(lista?.corte ?? hoyEnBogota())} pie={corte ? 'fecha elegida' : 'hoy'} tono="verde" icono="auditoria" />
      </Resumen>

      <section className="tarjeta-pagina">
        <BarraFiltros
          busqueda={{ valor: q, onCambio: (v) => fijar({ q: v }), placeholder: 'Cliente, documento, venta o factura', etiqueta: 'Buscar por cliente, documento, número de venta o factura de Contapyme' }}
          periodo={{ desde, hasta, onCambio: (d, h) => fijar({ desde: d, hasta: h }) }}
          activos={activos}
          onLimpiar={limpiar}
        >
          <CampoFiltro etiqueta="Corte al día">
            <input type="date" value={corte} max={hoyEnBogota()} onChange={(e) => fijar({ corte: e.target.value })} />
          </CampoFiltro>
          <label className="check"><input type="checkbox" checked={soloConSaldo} onChange={(e) => fijar({ saldo: e.target.checked ? '1' : '0' })} /> Solo con saldo</label>
          <label className="check"><input type="checkbox" checked={soloVencidos} onChange={(e) => fijar({ vencidos: e.target.checked ? '1' : '0' })} /> Solo vencidos</label>
        </BarraFiltros>

        <BarraAntiguedad valores={t?.antiguedad ?? CERO} />
        {aviso && <div className="aviso ok" role="status">{aviso}</div>}

        {filas.length === 0 ? (
          <p className="vacio">{cargando ? 'Cargando…' : 'Ningún cliente con esos filtros.'}</p>
        ) : (
          <table className={cargando ? 'tabla-apilable tabla-cartera actualizando' : 'tabla-apilable tabla-cartera'}>
            <caption className="solo-lector">Clientes con su saldo, mayor saldo primero</caption>
            <thead><tr><th>Cliente</th>{enPeriodo && <th className="col-numero">Ventas del período</th>}<th className="col-numero">Saldo</th><th className="col-numero">Vencido</th><th>Próximo vencimiento</th><th className="boton-fila-columna boton-fila-doble">Acciones</th></tr></thead>
            <tbody>
              {filas.map((f) => f.cliente && (
                <tr key={f.cliente.id}>
                  <td data-etiqueta="Cliente">
                    <span>
                      <strong>{f.cliente.nombre}</strong>{f.cliente.documento && <small className="bloque">{f.cliente.documento}</small>}
                      {f.coincidencia && (
                        <small className="bloque coincidencia-venta">
                          Venta #{f.coincidencia.venta_id}{f.coincidencia.factura_contapyme ? ` · factura ${f.coincidencia.factura_contapyme}` : ''}
                        </small>
                      )}
                    </span>
                  </td>
                  {enPeriodo && <td data-etiqueta="Ventas del período" className="col-numero">{pesos(f.ventas_periodo ?? '0')}</td>}
                  <td data-etiqueta="Saldo" className="col-numero">
                    {f.saldo_a_favor ? <span className="chip saldo-favor">{textoSaldo(f.saldo)}</span> : <strong>{pesos(f.saldo)}</strong>}
                  </td>
                  <td data-etiqueta="Vencido" className={Number(f.vencido) > 0 ? 'col-numero agotado' : 'col-numero'}>{pesos(f.vencido)}</td>
                  <td data-etiqueta="Próximo vencimiento">{f.proximo_vencimiento ? fechaCorta(f.proximo_vencimiento) : '—'}</td>
                  <td className="celda-accion boton-fila-columna boton-fila-doble">
                    <div className="acciones-fila boton-fila-acciones">
                      <BotonFila
                        variante="ver"
                        state={{ lista: filtrosLista }}
                        to={f.coincidencia ? `/cartera/${f.cliente.id}?q=${encodeURIComponent(`#${f.coincidencia.venta_id}`)}` : `/cartera/${f.cliente.id}`}
                        descripcion={f.coincidencia ? `Ver la venta #${f.coincidencia.venta_id} de ${f.cliente.nombre}` : `Ver la cartera de ${f.cliente.nombre}`}
                      />
                      <BotonFila
                        variante="pagar"
                        etiqueta="Abonar"
                        // S-79: el abono va a una venta con saldo; sin saldo no hay a cuál.
                        disabled={!f.cliente.activo || Number(f.saldo) <= 0}
                        title={!f.cliente.activo ? 'Cliente inactivo' : Number(f.saldo) <= 0 ? 'Este cliente no tiene facturas con saldo.' : undefined}
                        descripcion={`Abonar a ${f.cliente.nombre}`}
                        onClick={(e) => { disparador.current = e.currentTarget; setAviso(null); setAbonando(f) }}
                      />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Paginacion pagina={resultado} onCambiar={irA} />
        {info && info.total > 0 && <p className="ayuda">Mostrando {filas.length} de {info.total} clientes.</p>}
      </section>

      {abonando?.cliente && (
        <DialogoAbono
          cliente={abonando.cliente}
          saldo={abonando.saldo}
          onCerrar={cerrarAbono}
          onGuardado={(abono, resumen) => {
            qc.invalidateQueries({ queryKey: ['cartera'] })
            qc.invalidateQueries({ queryKey: ['cartera-cliente', abono.cliente_id] })
            setAviso(`Abono de ${pesos(abono.valor)} registrado a ${resumen.cliente?.nombre} (a la venta ${abono.aplicado_a.replace(/^Venta /, '')}). Saldo: ${textoSaldo(resumen.saldo)}.`)
            cerrarAbono()
          }}
        />
      )}
    </div>
  )
}
