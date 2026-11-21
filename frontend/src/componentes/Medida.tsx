import Icono, { type IconoNombre } from './Icono'

/** Tarjeta de resumen: etiqueta apagada, número grande e icono en pastel. */
export default function Medida({ etiqueta, valor, pie, tono, icono, codigo = false }: {
  etiqueta: string
  valor: string | number
  pie?: string
  tono: 'azul' | 'amarillo' | 'rojo' | 'verde'
  icono: IconoNombre
  codigo?: boolean
}) {
  return (
    <article className="medida">
      <div>
        <span className="medida-etiqueta">{etiqueta}</span>
        <strong className={codigo ? 'medida-numero medida-codigo' : 'medida-numero'}>{valor}</strong>
        {pie && <span className="medida-pie">{pie}</span>}
      </div>
      <span className={`medida-icono ${tono}`}><Icono nombre={icono} /></span>
    </article>
  )
}
