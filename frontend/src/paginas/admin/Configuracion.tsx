import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import CampoStockMinimo from '../../componentes/CampoStockMinimo'
import { aValorMinimo, errorStockMinimo } from '../../utilidades/stockMinimo'

/** RF-22 (S-28): parámetros del almacén que fija el administrador. Cada cambio queda auditado. */
export default function Configuracion() {
  const qc = useQueryClient()
  const general = useQuery({
    queryKey: ['configuracion', 'stock-minimo'],
    queryFn: async () => (await api.get<{ valor: number | null }>('/configuracion/stock-minimo')).data,
  })
  const [valor, setValor] = useState('')
  const [guardado, setGuardado] = useState(false)

  useEffect(() => {
    if (general.data) setValor(general.data.valor == null ? '' : String(general.data.valor))
  }, [general.data])

  const guardar = useMutation({
    mutationFn: async () => (await api.put<{ valor: number | null }>('/configuracion/stock-minimo', { valor: aValorMinimo(valor) })).data,
    onSuccess: (d) => {
      qc.setQueryData(['configuracion', 'stock-minimo'], d)
      // Cambia el mínimo efectivo de muchos productos.
      qc.invalidateQueries({ queryKey: ['referencias'] })
      qc.invalidateQueries({ queryKey: ['bajo-minimo'] })
      setGuardado(true)
    },
  })

  const actual = general.data?.valor
  const sinCambios = (actual == null ? '' : String(actual)) === valor.trim()

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Configuración</h1>
        <p>Parámetros del almacén. Solo el administrador los cambia; cada cambio queda en la auditoría.</p>
      </div>
      <section className="tarjeta-pagina">
        <h2>Stock mínimo general</h2>
        <form
          className="formulario campo-configuracion"
          onSubmit={(e) => { e.preventDefault(); setGuardado(false); guardar.mutate() }}
        >
          <CampoStockMinimo
            id="stock-minimo-general"
            etiqueta="Stock mínimo general"
            ayuda="Se aplica a los productos que no tengan uno propio. 0 o vacío = sin mínimo general."
            valor={valor}
            onCambio={(v) => { setValor(v); setGuardado(false) }}
          />
          {general.isError && <div className="aviso error">{mensajeError(general.error)}</div>}
          {guardar.isError && <div className="aviso error">{mensajeError(guardar.error)}</div>}
          {guardado && <div className="aviso ok" role="status">Guardado. El informe Bajo mínimo ya usa el nuevo valor.</div>}
          <div className="acciones acciones-izquierda">
            <button className="primario boton-44" disabled={general.isLoading || guardar.isPending || sinCambios || errorStockMinimo(valor) !== null}>Guardar</button>
            <Link to="/admin/auditoria?tabla=configuraciones">Ver cambios anteriores</Link>
          </div>
        </form>
      </section>
    </div>
  )
}
