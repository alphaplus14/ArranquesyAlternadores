import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { FilaVencimiento, TipoVencimiento } from '../../api/tipos'
import { useVencimientos } from '../../hooks/useVencimientos'
import { DialogoMovimiento } from '../../componentes/Cartera'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import BotonExcel from '../../componentes/BotonExcel'
import Medida from '../../componentes/Medida'
import Paginacion from '../../componentes/Paginacion'
import { fechaCorta } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import { invalidarCartera } from './cartera'
import '../../componentes/cartera.css'

type Filtro = 'todas' | TipoVencimiento

/**
 * RF-22: lo vencido y lo que vence dentro del aviso, por pagar (proveedores) y por cobrar
 * (clientes). Desde una factura por pagar se registra su pago (RF-17, ajuste). Solo en el programa.
 */
export default function Vencimientos() {
  const qc = useQueryClient()
  const [filtro, setFiltro] = useState<Filtro>('todas')
  const [pagina, setPagina] = useState(1)
  const [pagando, setPagando] = useState<FilaVencimiento | null>(null)
  const tipo = filtro === 'todas' ? undefined : filtro
  const { data, isFetching } = useVencimientos({ tipo, pagina })
  const totales = data?.totales

  const cambiarFiltro = (f: Filtro) => {
    setFiltro(f)
    setPagina(1)
  }

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
          <ControlSegmentado<Filtro>
            id="filtro-vencimientos"
            etiqueta="Mostrar"
            valor={filtro}
            onCambio={cambiarFiltro}
            opciones={[
              { valor: 'todas', texto: 'Todo' },
              { valor: 'por_pagar', texto: 'Por pagar' },
              { valor: 'por_cobrar', texto: 'Por cobrar' },
            ]}
          />
          <BotonExcel ruta="/vencimientos" params={{ tipo }} />
        </div>

        <div className="tabla-desplazable">
          <table aria-busy={isFetching}>
            <thead>
              <tr>
                <th>Estado</th><th>Tipo</th><th>Proveedor o cliente</th><th>Documento</th><th>Vence</th>
                <th className="cifra">Valor</th><th className="cifra">Pendiente</th><th><span className="solo-lector">Acciones</span></th>
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
                  <td className="col-accion">
                    {f.tipo === 'por_pagar' && <button type="button" className="enlace" onClick={() => setPagando(f)}>Registrar pago</button>}
                  </td>
                </tr>
              ))}
              {data && data.data.length === 0 && <tr><td colSpan={8} className="vacio">No hay nada vencido ni por vencer. 🎉</td></tr>}
            </tbody>
          </table>
        </div>
        <Paginacion pagina={data} onCambiar={setPagina} />
      </section>

      {pagando && (
        <DialogoMovimiento
          titulo="Registrar pago"
          titular={pagando.titular.nombre}
          saldo={pagando.pendiente}
          ruta={`/proveedores/${pagando.titular.id}/pagos`}
          documento={{ id: pagando.documento_id, nombre: pagando.documento, pendiente: pagando.pendiente }}
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
