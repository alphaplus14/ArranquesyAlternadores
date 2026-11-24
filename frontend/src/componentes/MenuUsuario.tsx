import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import { useLocation } from 'react-router-dom'
import type { Rol, Usuario } from '../api/tipos'
import Icono from './Icono'

const ROL_LEGIBLE: Record<Rol, string> = { admin: 'Administrador', logistica: 'Logística', mostrador: 'Mostrador', contable: 'Contable' }

/** Hasta 2 iniciales en mayúscula: primera y última palabra del nombre (o la primera letra si es una sola). */
function iniciales(nombre: string): string {
  const palabras = nombre.trim().split(/\s+/).filter(Boolean)
  if (palabras.length === 0) return '?'
  const primera = palabras[0][0]
  const ultima = palabras.length > 1 ? palabras[palabras.length - 1][0] : ''
  return (primera + ultima).toLocaleUpperCase('es')
}

/** El nombre y el usuario se muestran una sola vez si son iguales sin importar mayúsculas. */
function mismoTexto(a: string, b: string) {
  return a.trim().toLocaleLowerCase('es') === b.trim().toLocaleLowerCase('es')
}

/**
 * Menú de usuario del encabezado: avatar con iniciales (y en escritorio, nombre, rol y chevron).
 * Patrón de menú accesible: role="menu"/"menuitem", foco en el primer ítem al abrir, flechas,
 * Home/End y Tab circulan dentro; Escape, clic fuera, elegir un ítem o cambiar de ruta lo
 * cierran y el foco vuelve al botón (salvo al abrir un diálogo, que toma el foco).
 */
export default function MenuUsuario({ usuario, onEditarCuenta, onSalir }: {
  usuario: Usuario
  /** Solo se ofrece si llega (hoy, solo para el admin: PATCH /cuenta es del admin). */
  onEditarCuenta?: () => void
  onSalir: () => void
}) {
  const [abierto, setAbierto] = useState(false)
  const contenedor = useRef<HTMLDivElement>(null)
  const boton = useRef<HTMLButtonElement>(null)
  const panel = useRef<HTMLDivElement>(null)
  const idPanel = useId()
  const ubicacion = useLocation()

  const items = () => [...(panel.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])]

  const cerrar = (devolverFoco: boolean) => {
    setAbierto(false)
    if (devolverFoco) boton.current?.focus()
  }

  // Al abrir, el foco va al primer ítem.
  useEffect(() => {
    if (abierto) panel.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()
  }, [abierto])

  // Cambiar de ruta cierra el menú.
  useEffect(() => {
    setAbierto(false)
  }, [ubicacion.pathname])

  // Clic fuera: cierra sin robar el foco a lo que se tocó.
  useEffect(() => {
    if (!abierto) return
    const fuera = (e: MouseEvent | TouchEvent) => {
      if (!contenedor.current?.contains(e.target as Node)) setAbierto(false)
    }
    document.addEventListener('mousedown', fuera)
    document.addEventListener('touchstart', fuera)
    return () => {
      document.removeEventListener('mousedown', fuera)
      document.removeEventListener('touchstart', fuera)
    }
  }, [abierto])

  const alTeclearBoton = (e: KeyboardEvent<HTMLButtonElement>) => {
    // Enter y Espacio ya hacen clic en un botón; la flecha abajo también abre.
    if (e.key === 'ArrowDown' && !abierto) {
      e.preventDefault()
      setAbierto(true)
    }
  }

  const alTeclearPanel = (e: KeyboardEvent<HTMLDivElement>) => {
    const lista = items()
    if (lista.length === 0) return
    const actual = lista.indexOf(document.activeElement as HTMLElement)
    const ir = (i: number) => lista[(i + lista.length) % lista.length].focus()

    switch (e.key) {
      case 'Escape':
        e.preventDefault()
        cerrar(true)
        break
      case 'ArrowDown':
        e.preventDefault()
        ir(actual + 1)
        break
      case 'ArrowUp':
        e.preventDefault()
        ir(actual - 1)
        break
      case 'Home':
        e.preventDefault()
        ir(0)
        break
      case 'End':
        e.preventDefault()
        ir(lista.length - 1)
        break
      case 'Tab':
        e.preventDefault()
        ir(actual + (e.shiftKey ? -1 : 1))
        break
    }
  }

  const nombre = usuario.name
  const repetido = mismoTexto(nombre, usuario.usuario)
  const rol = ROL_LEGIBLE[usuario.rol]
  const letras = iniciales(nombre)

  return (
    <div className="menu-usuario" ref={contenedor}>
      <button
        ref={boton}
        type="button"
        className={abierto ? 'menu-usuario-boton abierto' : 'menu-usuario-boton'}
        aria-label="Menú de usuario"
        aria-haspopup="menu"
        aria-expanded={abierto}
        aria-controls={abierto ? idPanel : undefined}
        onClick={() => setAbierto((v) => !v)}
        onKeyDown={alTeclearBoton}
      >
        <span className="avatar" aria-hidden>{letras}</span>
        <span className="menu-usuario-texto" aria-hidden>
          <span className="menu-usuario-nombre">{nombre}</span>
          <span className="menu-usuario-rol">{rol}</span>
        </span>
        <span className="menu-usuario-chevron" aria-hidden><Icono nombre="chevron" /></span>
      </button>

      {abierto && (
        <div ref={panel} id={idPanel} className="menu-usuario-panel" role="menu" aria-label="Menú de usuario" onKeyDown={alTeclearPanel}>
          <div className="menu-usuario-cabecera" role="presentation">
            <span className="avatar grande" aria-hidden>{letras}</span>
            <div className="menu-usuario-datos">
              <strong>{nombre}</strong>
              {!repetido && <span className="menu-usuario-arroba">@{usuario.usuario}</span>}
              <span className="chip">{rol}</span>
            </div>
          </div>
          {onEditarCuenta && (
            <>
              <div className="menu-usuario-separador" role="separator" />
              <button
                type="button"
                role="menuitem"
                tabIndex={-1}
                className="menu-usuario-item"
                // El diálogo toma el foco; no se devuelve al botón.
                onClick={() => { cerrar(false); onEditarCuenta() }}
              >
                <Icono nombre="editar" />
                <span>Editar mi cuenta</span>
              </button>
            </>
          )}
          <div className="menu-usuario-separador" role="separator" />
          <button
            type="button"
            role="menuitem"
            tabIndex={-1}
            className="menu-usuario-item salir"
            onClick={() => { cerrar(true); onSalir() }}
          >
            <Icono nombre="salir" />
            <span>Salir</span>
          </button>
        </div>
      )}
    </div>
  )
}
