import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { infoPagina, type FilaVencimiento, type TipoVencimiento } from '../../api/tipos'
import { useVencimientos, type FiltrosVencimientos } from '../../hooks/useVencimientos'
import BarraFiltros, { CampoFiltro, TextoResultados, VacioFiltros } from '../../componentes/BarraFiltros'
import { useFiltrosUrl } from '../../hooks/useFiltrosUrl'
import { DialogoMovimiento } from '../../componentes/Cartera'
import BotonFila from '../../componentes/BotonFila'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import Medida from '../../componentes/Medida'
import Paginacion from '../../componentes/Paginacion'
import { fechaCorta } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import { invalidarCartera } from './cartera'
import '../../componentes/cartera.css'

type Filtro = 'todas' | TipoVencimiento
type Estado = 'todos' | 'vencidas' | 'por_vencer'

/**
 * RF-30: lo vencido y lo que vence dentro del aviso, por pagar (proveedores) y por cobrar
 * (clientes). Desde una factura por pagar se registra su pago (RF-17, ajuste). Solo en el programa.
 * S-88: filtros en el servidor (en la URL): tipo, estado, texto, rango de vencimiento y orden; las
 * tarjetas son de lo filtrado.
 */
export default function Vencimientos() {
  const qc = useQueryClient()
  const { filtros: f, fijar, pagina, irA, activos, limpiar } = useFiltrosUrl({
    tipo: 'todas', estado: 'todos', q: '', vence_desde: '', vence_hasta: '', orden: 'vence_asc',
  })
  const [pagando, setPagando] = useState<FilaVencimiento | null>(null)
  const filtro = f.tipo as Filtro
  const tipo = filtro === 'todas' ? undefined : filtro
  const filtros: FiltrosVencimientos = {
    estado: f.estado === 'todos' ? undefined : f.estado as FiltrosVencimientos['estado'],
    q: f.q || undefined,
    vence_desde: f.vence_desde || undefined,
    vence_hasta: f.vence_hasta || undefined,
    orden: f.orden === 'vence_asc' ? undefined : f.orden as FiltrosVencimientos['orden'],
  }
  const { data, isFetching } = useVencimientos({ tipo, pagina, filtros })
  const totales = data?.totales
  const info = data ? infoPagina(data) : null

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Vencimientos</h1>
        <p>
          Facturas de proveedores y ventas a crédito con saldo que ya vencieron o vencen
          {data ? (data.aviso_dias === 1 ? ' mañana' : ` en los próximos ${data.aviso_dias} días`) : ' pronto'}.
          Una factura sale de la lista cuando queda cancelada.
        </p>
      </div>

      <div className="medidas">
        <Medida etiqueta="Vencidas" valor={totales ? totales.vencidas.cantidad : '—'} pie={totales ? pesos(totales.vencidas.pendiente) : undefined} tono="rojo" icono="agotados" />
        <Medida etiqueta="Por vencer" valor={totales ? totales.por_vencer.cantidad : '—'} pie={totales ? pesos(totales.por_vencer.pendiente) : undefined} tono="amarillo" icono="auditoria" />
        <Medida etiqueta="Pendiente total" valor={totales ? pesos(totales.todas.pendiente) : '—'} tono="azul" icono="caja" />
      </div>

      <section className="tarjeta-pagina">
        <div className="fila-titulo">
          <h2>Documentos</h2>
        </div>
        <BarraFiltros busqueda={{ valor: f.q, onCambio: (q) => fijar({ q }), placeholder: 'Proveedor, cliente o n.º de documento' }} activos={activos} onLimpiar={limpiar}>
          <ControlSegmentado<Filtro>
            id="filtro-vencimientos"
            etiqueta="Tipo"
            valor={filtro}
            onCambio={(v) => fijar({ tipo: v })}
            opciones={[
              { valor: 'todas', texto: 'Todo' },
              { valor: 'por_pagar', texto: 'Por pagar' },
              { valor: 'por_cobrar', texto: 'Por cobrar' },
            ]}
          />
          <ControlSegmentado<Estado>
            id="estado-vencimientos"
            etiqueta="Estado"
            valor={f.estado as Estado}
            onCambio={(v) => fijar({ estado: v })}
            opciones={[{ valor: 'todos', texto: 'Todas' }, { valor: 'vencidas', texto: 'Vencidas' }, { valor: 'por_vencer', texto: 'Por vencer' }]}
          />
          <CampoFiltro etiqueta="Vence desde">
            <input type="date" max={f.vence_hasta || undefined} value={f.vence_desde} onChange={(e) => fijar({ vence_desde: e.target.value })} />
          </CampoFiltro>
          <CampoFiltro etiqueta="Vence hasta">
            <input type="date" min={f.vence_desde || undefined} value={f.vence_hasta} onChange={(e) => fijar({ vence_hasta: e.target.value })} />
          </CampoFiltro>
          <CampoFiltro etiqueta="Orden">
            <select value={f.orden} onChange={(e) => fijar({ orden: e.target.value })}>
              <option value="vence_asc">Lo más vencido primero</option>
              <option value="pendiente_desc">Mayor pendiente</option>
              <option value="titular">Proveedor o cliente</option>
            </select>
          </CampoFiltro>
        </BarraFiltros>
        {info && <TextoResultados mostrando={data!.data.length} total={info.total} de="documentos" />}

        <div className="tabla-desplazable">
          <table aria-busy={isFetching}>
            <thead>
              <tr>
                <th>Estado</th><th>Tipo</th><th>Proveedor o cliente</th><th>Documento</th><th>Vence</th>
                <th className="cifra">Valor</th><th className="cifra">Pendiente</th><th className="boton-fila-columna boton-fila-doble">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {data?.data.map((f) => (
                <tr key={`${f.tipo}-${f.documento_id}`}>
                  <td>
                    {f.estado === 'vencida'
                      ? <span className="chip vencida">Vencida · {f.dias_vencido} {f.dias_vencido === 1 ? 'día' : 'días'}</span>
                      : <span className="chip por-vencer">{f.dias_para_vencer === 0 ? 'Vence hoy' : f.dias_para_vencer === 1 ? 'Vence mañana' : `En ${f.dias_para_vencer} días`}</span>}
                  </td>
                  <td>{f.tipo === 'por_pagar' ? 'Por pagar' : 'Por cobrar'}</td>
                  <td>{f.titular.nombre}</td>
                  <td>{f.documento}</td>
                  <td>{fechaCorta(f.vence_el)}</td>
                  <td className="cifra">{pesos(f.valor)}</td>
                  <td className="cifra"><strong>{pesos(f.pendiente)}</strong></td>
                  <td className="boton-fila-columna boton-fila-doble">
                    <span className="boton-fila-acciones">
                      <BotonFila
                        variante="ver"
                        to={`${f.tipo === 'por_pagar' ? '/contable/proveedores' : '/contable/clientes'}/${f.titular.id}`}
                        descripcion={`Ver cuenta de ${f.titular.nombre}`}
                      />
                      {f.tipo === 'por_pagar' && (
                        <BotonFila variante="pagar" descripcion={`Pagar ${f.documento} de ${f.titular.nombre}`} onClick={() => setPagando(f)} />
                      )}
                    </span>
                  </td>
                </tr>
              ))}
              {data && data.data.length === 0 && (
                <tr><td colSpan={8} className="vacio"><VacioFiltros hayFiltros={activos > 0} sinFiltros="No hay nada vencido ni por vencer. 🎉" onLimpiar={limpiar} /></td></tr>
              )}
            </tbody>
          </table>
        </div>
        <Paginacion pagina={data} onCambiar={irA} />
      </section>

      {pagando && (
        <DialogoMovimiento
          titulo="Registrar pago"
          titular={pagando.titular.nombre}
          ruta={`/proveedores/${pagando.titular.id}/pagos`}
          documento={{ id: pagando.documento_id, nombre: pagando.documento, valor: pagando.valor, abonado: pagando.abonado, pendiente: pagando.pendiente }}
          onCerrar={() => setPagando(null)}
          onGuardado={() => {
            setPagando(null)
            invalidarCartera(qc)
          }}
        />
      )}
    </div>
  )
}
