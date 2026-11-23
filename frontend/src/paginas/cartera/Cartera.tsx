import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import type { ListaCartera, ResumenCartera } from '../../api/tipos'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { useDebounce } from '../../hooks/useDebounce'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import Paginacion from '../../componentes/Paginacion'
import Icono from '../../componentes/Icono'
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
  const [q, setQ] = useState('')
  const [soloConSaldo, setSoloConSaldo] = useState(true)
  const [soloVencidos, setSoloVencidos] = useState(false)
  const [corte, setCorte] = useState('')
  const [abonando, setAbonando] = useState<ResumenCartera | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)
  const disparador = useRef<HTMLButtonElement | null>(null)
  const termino = useDebounce(q.trim())

  const filtros = { termino, soloConSaldo, soloVencidos, corte }
  const { filas, resultado, info, irA, cargando } = useListaPaginada<ResumenCartera>({
    clave: ['cartera', filtros],
    filtros: { q, soloConSaldo, soloVencidos, corte },
    traer: async (page) => (await api.get<ListaCartera>('/cartera', {
      params: {
        busqueda: termino || undefined,
        solo_con_saldo: soloConSaldo ? 1 : 0,
        solo_vencidos: soloVencidos ? 1 : 0,
        corte: corte || undefined,
        page,
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
        <Medida etiqueta="Corte" valor={fechaCorta(lista?.corte ?? hoyEnBogota())} pie={corte ? 'fecha elegida' : 'hoy'} tono="verde" icono="auditoria" />
      </Resumen>

      <section className="tarjeta-pagina">
        <div className="formulario en-linea filtros-cartera">
          <label className="busca">
            <Icono nombre="buscar" />
            <input placeholder="Nombre o documento" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Buscar cliente por nombre o documento" />
          </label>
          <label className="check"><input type="checkbox" checked={soloConSaldo} onChange={(e) => setSoloConSaldo(e.target.checked)} /> Solo con saldo</label>
          <label className="check"><input type="checkbox" checked={soloVencidos} onChange={(e) => setSoloVencidos(e.target.checked)} /> Solo vencidos</label>
          <label className="campo-corte">Corte
            <input type="date" value={corte} max={hoyEnBogota()} onChange={(e) => setCorte(e.target.value)} />
          </label>
        </div>

        <BarraAntiguedad valores={t?.antiguedad ?? CERO} />
        {aviso && <div className="aviso ok" role="status">{aviso}</div>}

        {filas.length === 0 ? (
          <p className="vacio">{cargando ? 'Cargando…' : 'Ningún cliente con esos filtros.'}</p>
        ) : (
          <table className={cargando ? 'tabla-apilable tabla-cartera actualizando' : 'tabla-apilable tabla-cartera'}>
            <caption className="solo-lector">Clientes con su saldo, mayor saldo primero</caption>
            <thead><tr><th>Cliente</th><th className="col-numero">Saldo</th><th className="col-numero">Vencido</th><th>Próximo vencimiento</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
            <tbody>
              {filas.map((f) => f.cliente && (
                <tr key={f.cliente.id}>
                  <td data-etiqueta="Cliente">
                    <span><strong>{f.cliente.nombre}</strong>{f.cliente.documento && <small className="bloque">{f.cliente.documento}</small>}</span>
                  </td>
                  <td data-etiqueta="Saldo" className="col-numero">
                    {f.saldo_a_favor ? <span className="chip saldo-favor">{textoSaldo(f.saldo)}</span> : <strong>{pesos(f.saldo)}</strong>}
                  </td>
                  <td data-etiqueta="Vencido" className={Number(f.vencido) > 0 ? 'col-numero agotado' : 'col-numero'}>{pesos(f.vencido)}</td>
                  <td data-etiqueta="Próximo vencimiento">{f.proximo_vencimiento ? fechaCorta(f.proximo_vencimiento) : '—'}</td>
                  <td className="celda-accion">
                    <div className="acciones-fila">
                      <Link className="boton secundario boton-44" to={`/cartera/${f.cliente.id}`} aria-label={`Ver la cartera de ${f.cliente.nombre}`}>Ver</Link>
                      <button
                        type="button"
                        className="primario boton-44"
                        disabled={!f.cliente.activo}
                        title={f.cliente.activo ? undefined : 'Cliente inactivo'}
                        aria-label={`Abonar a ${f.cliente.nombre}`}
                        onClick={(e) => { disparador.current = e.currentTarget; setAviso(null); setAbonando(f) }}
                      >Abonar</button>
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
            setAviso(`Abono de ${pesos(abono.valor)} registrado a ${resumen.cliente?.nombre}. Saldo: ${textoSaldo(resumen.saldo)}.`)
            cerrarAbono()
          }}
        />
      )}
    </div>
  )
}
