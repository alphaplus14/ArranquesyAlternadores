import { NOMBRE_CALIDAD, type Calidad } from '../api/tipos'

/** S-23: etiqueta pequeña con la calidad de la referencia (fino / genérico); nada si no la tiene. */
export default function ChipCalidad({ calidad }: { calidad: Calidad | null | undefined }) {
  if (!calidad) return null
  return <span className="chip calidad">{NOMBRE_CALIDAD[calidad]}</span>
}
