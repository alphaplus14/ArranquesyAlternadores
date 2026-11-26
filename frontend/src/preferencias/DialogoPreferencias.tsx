import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { useMutation } from '@tanstack/react-query'
import { mensajeError } from '../api/cliente'
import { useAuth } from '../auth/AuthProvider'
import BotonCancelar from '../componentes/BotonCancelar'
import ControlSegmentado from '../componentes/ControlSegmentado'
import Dialogo, { DialogoSeccion } from '../componentes/Dialogo'
import {
  ACENTOS, CLAVES, FILAS, PALETAS, efectivas, guardarPreferencias, porDefecto, preferenciasGuardadas,
  revertirVistaPrevia, vistaPrevia,
  type Acento, type ClavePreferencia, type Densidad, type Preferencias, type TamanoLetra,
} from './preferencias'

type Borrador = ReturnType<typeof efectivas>

const TAMANOS: { valor: TamanoLetra; nombre: string; escala: number }[] = [
  { valor: 'normal', nombre: 'Normal', escala: 1 },
  { valor: 'grande', nombre: 'Grande', escala: 1.125 },
  { valor: 'muy_grande', nombre: 'Muy grande', escala: 1.25 },
]

/**
 * S-70: preferencias de pantalla (admin, logística y contable). Cada cambio se ve al instante: solo
 * cambian variables CSS, sin re-render de la app. Cerrar o "Cancelar" revierte; "Guardar" hace UN
 * PATCH con solo lo que cambió; "Restablecer" borra lo guardado y vuelve a los valores del rol.
 */
export default function DialogoPreferencias({ onCerrar }: { onCerrar: () => void }) {
  const { usuario } = useAuth()
  const [inicial] = useState<Borrador>(() => efectivas())
  const [b, setB] = useState<Borrador>(inicial)
  const [error, setError] = useState<string | null>(null)

  // Al cerrar por cualquier camino (guardado o no), las variables vuelven a lo guardado.
  useEffect(() => () => revertirVistaPrevia(), [])

  const cambiar = <K extends ClavePreferencia>(clave: K, valor: Borrador[K]) => {
    const nuevo = { ...b, [clave]: valor }
    setB(nuevo)
    setError(null)
    vistaPrevia(nuevo)
  }

  /** Solo lo que cambió. Volver al valor por defecto del rol borra la clave (null). */
  const cambios = () => {
    const defecto = porDefecto(usuario?.rol ?? null) as Preferencias
    const r: Partial<Record<ClavePreferencia, Preferencias[ClavePreferencia] | null>> = {}
    for (const k of CLAVES) {
      if (b[k] === inicial[k]) continue
      r[k] = k !== 'filas_por_pagina' && b[k] === defecto[k] ? null : b[k]
    }
    return r
  }

  const guardar = useMutation({
    mutationFn: (datos: Parameters<typeof guardarPreferencias>[0]) => guardarPreferencias(datos),
    onSuccess: onCerrar,
    // Las variables quedan como antes de abrir; la modal sigue abierta con el mensaje.
    onError: (e) => { revertirVistaPrevia(); setError(mensajeError(e)) },
  })

  const enviar = () => {
    const datos = cambios()
    if (Object.keys(datos).length === 0) { onCerrar(); return }
    guardar.mutate(datos)
  }

  const restablecer = () => {
    const guardadas = Object.keys(preferenciasGuardadas()) as ClavePreferencia[]
    if (guardadas.length === 0) { onCerrar(); return }
    guardar.mutate(Object.fromEntries(guardadas.map((k) => [k, null])))
  }

  const modificado = CLAVES.some((k) => b[k] !== inicial[k])

  return (
    <Dialogo
      titulo="Preferencias"
      pendiente={guardar.isPending}
      modificado={modificado}
      onCerrar={onCerrar}
      onEnviar={enviar}
      pie={(
        <>
          <button type="button" className="secundario preferencias-restablecer" disabled={guardar.isPending} onClick={restablecer}>Restablecer</button>
          <BotonCancelar />
          <button type="submit" className="primario" disabled={guardar.isPending}>{guardar.isPending ? 'Guardando…' : 'Guardar'}</button>
        </>
      )}
    >
      <DialogoSeccion>
        <ControlSegmentado
          id="pref-letra"
          etiqueta="Tamaño de letra"
          opciones={TAMANOS.map((t) => ({
            valor: t.valor,
            nombre: t.nombre,
            texto: <span className="preferencias-muestra-letra" style={{ fontSize: `${t.escala}rem` }} aria-hidden>Aa</span>,
          }))}
          valor={b.tamano_letra}
          onCambio={(v) => cambiar('tamano_letra', v)}
        />
        <ControlSegmentado<Densidad>
          id="pref-densidad"
          etiqueta="Densidad"
          opciones={[{ valor: 'comoda', texto: 'Cómoda' }, { valor: 'compacta', texto: 'Compacta' }]}
          valor={b.densidad}
          onCambio={(v) => cambiar('densidad', v)}
        />
        <SelectorColor valor={b.acento} onCambio={(v) => cambiar('acento', v)} />
        <ControlSegmentado
          id="pref-filas"
          etiqueta="Filas por página"
          opciones={FILAS.map((n) => ({ valor: String(n), texto: String(n) }))}
          valor={b.filas_por_pagina ? String(b.filas_por_pagina) : ''}
          onCambio={(v) => cambiar('filas_por_pagina', Number(v) as Borrador['filas_por_pagina'])}
        />
        <div className="preferencias-interruptor">
          <span id="pref-menu-etiqueta">Menú lateral plegado por defecto</span>
          <button
            type="button"
            role="switch"
            aria-checked={b.menu_plegado}
            aria-labelledby="pref-menu-etiqueta"
            className="preferencias-switch"
            onClick={() => cambiar('menu_plegado', !b.menu_plegado)}
          >
            <span aria-hidden />
          </button>
        </div>
        {error && <div className="aviso error" role="alert">{error}</div>}
      </DialogoSeccion>
    </Dialogo>
  )
}

/** Seis muestras circulares: radiogroup con nombre accesible y ✓ en la elegida (no solo color). */
function SelectorColor({ valor, onCambio }: { valor: Acento; onCambio: (v: Acento) => void }) {
  const botones = useRef<Array<HTMLButtonElement | null>>([])

  const alPulsar = (e: KeyboardEvent<HTMLButtonElement>, i: number) => {
    const ultimo = ACENTOS.length - 1
    const destino = { ArrowRight: i === ultimo ? 0 : i + 1, ArrowDown: i === ultimo ? 0 : i + 1, ArrowLeft: i === 0 ? ultimo : i - 1, ArrowUp: i === 0 ? ultimo : i - 1, Home: 0, End: ultimo }[e.key]
    if (destino === undefined) return
    e.preventDefault()
    onCambio(ACENTOS[destino])
    botones.current[destino]?.focus()
  }

  return (
    <div className="campo-segmentado">
      <span id="pref-color-etiqueta" className="etiqueta-campo">Color</span>
      <div role="radiogroup" aria-labelledby="pref-color-etiqueta" className="preferencias-colores">
        {ACENTOS.map((a, i) => {
          const elegido = a === valor
          return (
            <button
              key={a}
              ref={(el) => { botones.current[i] = el }}
              type="button"
              role="radio"
              aria-checked={elegido}
              aria-label={PALETAS[a].nombre}
              title={PALETAS[a].nombre}
              tabIndex={elegido ? 0 : -1}
              className={elegido ? 'preferencias-color elegido' : 'preferencias-color'}
              style={{ background: PALETAS[a].acento, color: PALETAS[a].texto }}
              onClick={() => onCambio(a)}
              onKeyDown={(e) => alPulsar(e, i)}
            >
              {elegido && (
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                  <path d="M5 12.5l4.5 4.5L19 7.5" />
                </svg>
              )}
            </button>
          )
        })}
      </div>
    </div>
  )
}
