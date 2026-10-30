import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { Unidad } from '../../api/tipos'

// Debe coincidir con config/etiquetas.php (supuesto S-05).
const TAMANOS = [
  { clave: 'grande', nombre: 'Grande (100×50 mm) · arranques' },
  { clave: 'pequeno', nombre: 'Pequeño (50×25 mm) · sensores' },
  { clave: 'mas_pequeno', nombre: 'Más pequeño (38×19 mm) · sensor de temperatura' },
]

/** RF-03: una etiqueta por unidad, en el tamaño que elige quien imprime y pega. */
export default function Etiquetas() {
  const [params, setParams] = useSearchParams()
  const ingresoId = params.get('ingreso') ?? ''
  const [seleccion, setSeleccion] = useState<Set<number>>(new Set())
  const [tamano, setTamano] = useState('pequeno')

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

  const alternar = (id: number) => {
    const s = new Set(seleccion)
    if (s.has(id)) s.delete(id)
    else s.add(id)
    setSeleccion(s)
  }

  return (
    <div className="panel">
      <h1>Etiquetas</h1>
      <form className="formulario en-linea" onSubmit={(e) => {
        e.preventDefault()
        setParams({ ingreso: String(new FormData(e.currentTarget).get('ingreso') ?? '') })
      }}>
        <label>Ingreso #<input name="ingreso" defaultValue={ingresoId} required /></label>
        <button>Cargar unidades</button>
      </form>

      {unidades.isError && <div className="aviso error">{mensajeError(unidades.error)}</div>}

      {unidades.data && (
        <>
          <fieldset className="tamanos">
            <legend>Tamaño de etiqueta</legend>
            {TAMANOS.map((t) => (
              <label key={t.clave}>
                <input type="radio" name="tamano" checked={tamano === t.clave} onChange={() => setTamano(t.clave)} /> {t.nombre}
              </label>
            ))}
          </fieldset>

          <table>
            <thead><tr><th></th><th>Código</th><th>Referencia</th><th>Marca</th><th>Estado</th></tr></thead>
            <tbody>
              {unidades.data.map((u) => (
                <tr key={u.id}>
                  <td><input type="checkbox" checked={seleccion.has(u.id)} onChange={() => alternar(u.id)} /></td>
                  <td className="mono">{u.codigo_barras}</td>
                  <td>{u.referencia?.codigo_referencia} <small>{u.referencia?.descripcion}</small></td>
                  <td>{u.referencia?.marca?.nombre}</td>
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
          {imprimir.isError && <div className="aviso error">{mensajeError(imprimir.error)}</div>}
        </>
      )}
    </div>
  )
}
