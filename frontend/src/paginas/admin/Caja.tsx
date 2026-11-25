import { type KeyboardEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import EmpleadosCaja from './caja/EmpleadosCaja'
import CajaMes from './CajaMes'
import CatalogosCaja from './CatalogosCaja'
import { VistaDia } from '../mostrador/CierreDia'

type Clave = 'dia' | 'mes' | 'catalogos' | 'empleados'

/**
 * Caja del administrador. RF-25 / RF-26 (S-54): el día de caja en modo consulta (solo "Reabrir" un
 * cierre con motivo), el cuadre mensual y la gestión de cuentas y categorías. RF-27: la cuenta de
 * empleados. Todo en pantalla (regla 16). La pestaña va en `?vista=` para poder enlazarla.
 */
const PESTANAS: { clave: Clave; titulo: string }[] = [
  { clave: 'dia', titulo: 'Día' },
  { clave: 'mes', titulo: 'Mes' },
  { clave: 'catalogos', titulo: 'Cuentas y categorías' },
  { clave: 'empleados', titulo: 'Empleados' },
]

export default function Caja() {
  const [params, setParams] = useSearchParams()
  const activa: Clave = PESTANAS.find((p) => p.clave === params.get('vista'))?.clave ?? 'dia'
  const setActiva = (clave: Clave, extra: Record<string, string> = {}) => setParams((p) => {
    const n = new URLSearchParams(p)
    if (clave === 'dia') n.delete('vista'); else n.set('vista', clave)
    Object.entries(extra).forEach(([k, v]) => n.set(k, v))
    return n
  }, { replace: true })

  function mover(e: KeyboardEvent) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return
    e.preventDefault()
    const i = PESTANAS.findIndex((p) => p.clave === activa)
    const siguiente = PESTANAS[(i + (e.key === 'ArrowRight' ? 1 : -1) + PESTANAS.length) % PESTANAS.length]
    setActiva(siguiente.clave)
    document.getElementById(`pestana-caja-${siguiente.clave}`)?.focus()
  }

  return (
    <div className="pila caja">
      <div className="pagina-encabezado">
        <h1>Caja</h1>
        <p>Caja diaria del mostrador (consulta), cuadre mensual, cuentas y categorías, y la cuenta de empleados. Solo en pantalla.</p>
      </div>
      <div className="pestanas" role="tablist" aria-label="Caja" onKeyDown={mover}>
        {PESTANAS.map((p) => (
          <button
            key={p.clave}
            type="button"
            role="tab"
            id={`pestana-caja-${p.clave}`}
            aria-selected={activa === p.clave}
            aria-controls={`panel-caja-${p.clave}`}
            tabIndex={activa === p.clave ? 0 : -1}
            className={activa === p.clave ? 'pestana activa' : 'pestana'}
            onClick={() => setActiva(p.clave)}
          >
            {p.titulo}
          </button>
        ))}
      </div>
      <div role="tabpanel" id={`panel-caja-${activa}`} aria-labelledby={`pestana-caja-${activa}`} className="pila">
        {activa === 'dia' && <VistaDia soloConsulta />}
        {activa === 'mes' && <CajaMes onVerDia={(fecha) => setActiva('dia', { fecha })} />}
        {activa === 'catalogos' && <CatalogosCaja />}
        {activa === 'empleados' && <section className="panel catalogo"><EmpleadosCaja /></section>}
      </div>
    </div>
  )
}
