import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import { useAvisoTexto } from '../../componentes/Avisos'
import type { Unidad } from '../../api/tipos'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import ChipCalidad from '../../componentes/ChipCalidad'

// Tamaños definidos en el backend (config/etiquetas.php, supuesto S-05).
interface Tamano { clave: string; nombre: string; ancho: number; alto: number }

/** RF-03: una etiqueta por unidad, en el tamaño que elige quien imprime y pega. */
export default function Etiquetas() {
  const [params, setParams] = useSearchParams()
  const ingresoId = params.get('ingreso') ?? ''
  const [seleccion, setSeleccion] = useState<Set<number>>(new Set())
  const [tamano, setTamano] = useState('pequeno')

  const tamanos = useQuery({
    queryKey: ['etiquetas', 'tamanos'],
    queryFn: async () => (await api.get<Tamano[]>('/etiquetas/tamanos')).data,
    staleTime: Infinity,
  })

  const unidades = useQuery({
    queryKey: ['ingreso', ingresoId, 'unidades'],
    queryFn: async () => (await api.get<Unidad[]>(`/ingresos/${ingresoId}/unidades`)).data,
    enabled: ingresoId !== '',
  })

  useEffect(() => {
    if (unidades.data) setSeleccion(new Set(unidades.data.map((u) => u.id)))
  }, [unidades.data])

  const imprimir = useMutation({
    mutationFn: async () => {
      const r = await api.post('/etiquetas', { unidad_ids: [...seleccion], tamano }, { responseType: 'blob' })
      return URL.createObjectURL(r.data as Blob)
    },
    onSuccess: (url) => window.open(url, '_blank'),
  })
  useAvisoTexto(unidades.isError ? mensajeError(unidades.error) : null)
  useAvisoTexto(imprimir.isError ? mensajeError(imprimir.error) : null)

  const alternar = (id: number) => {
    const s = new Set(seleccion)
    if (s.has(id)) s.delete(id)
    else s.add(id)
    setSeleccion(s)
  }

  const tamanoActual = tamanos.data?.find((t) => t.clave === tamano)

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Etiquetas</h1>
        <p>Una etiqueta por unidad física. Quien imprime elige el tamaño.</p>
      </div>
      <Resumen
        clave="etiquetas-resumen"
        compacto={(
          <>
            <span>Ingreso <strong>{ingresoId ? `#${ingresoId}` : '—'}</strong></span>
            <span>Unidades <strong>{unidades.data?.length ?? '—'}</strong></span>
            <span>Seleccionadas <strong>{seleccion.size}</strong></span>
            <span>Tamaño <strong>{tamanoActual ? `${tamanoActual.nombre} ${tamanoActual.ancho}×${tamanoActual.alto}` : tamano}</strong></span>
          </>
        )}
      >
        <Medida etiqueta="Ingreso" valor={ingresoId ? `#${ingresoId}` : '—'} tono="azul" icono="ingreso" />
        <Medida etiqueta="Unidades" valor={unidades.data?.length ?? '—'} tono="amarillo" icono="caja" />
        <Medida etiqueta="Seleccionadas" valor={seleccion.size} tono="verde" icono="etiqueta" />
        <Medida etiqueta="Tamaño" valor={tamanoActual?.nombre ?? tamano} pie={tamanoActual ? `${tamanoActual.ancho}×${tamanoActual.alto} mm` : undefined} tono="rojo" icono="etiqueta" />
      </Resumen>
    <div className="panel">
      <div className="fila-titulo"><h2>Unidades del ingreso</h2></div>
      <form className="formulario en-linea" onSubmit={(e) => {
        e.preventDefault()
        setParams({ ingreso: String(new FormData(e.currentTarget).get('ingreso') ?? '') })
      }}>
        <label>Ingreso #<input name="ingreso" defaultValue={ingresoId} required /></label>
        <button className="primario">Cargar unidades</button>
      </form>

      {unidades.data && (
        <>
          <fieldset className="tamanos">
            <legend>Tamaño de etiqueta</legend>
            {tamanos.data?.map((t) => (
              <label key={t.clave}>
                <input type="radio" name="tamano" checked={tamano === t.clave} onChange={() => setTamano(t.clave)} /> {t.nombre} ({t.ancho}×{t.alto} mm)
              </label>
            ))}
          </fieldset>

          <table>
            <thead><tr><th></th><th>Código</th><th>Referencia</th><th>Proveedor</th><th>Estado</th></tr></thead>
            <tbody>
              {unidades.data.map((u) => (
                <tr key={u.id}>
                  <td><input type="checkbox" checked={seleccion.has(u.id)} onChange={() => alternar(u.id)} /></td>
                  <td className="mono">{u.codigo_barras}</td>
                  <td>{u.referencia?.codigo_referencia} <small>{u.referencia?.descripcion}</small></td>
                  <td>{u.referencia?.proveedor?.nombre} <ChipCalidad calidad={u.referencia?.calidad} /></td>
                  <td>{u.estado}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <div className="acciones">
            <button className="primario" disabled={seleccion.size === 0 || imprimir.isPending} onClick={() => imprimir.mutate()}>
              Generar PDF ({seleccion.size} etiquetas)
            </button>
          </div>
        </>
      )}
    </div>
    </div>
  )
}
