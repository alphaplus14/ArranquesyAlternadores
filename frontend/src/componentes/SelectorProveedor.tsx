import { useCallback, useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type KeyboardEvent } from 'react'
import { createPortal } from 'react-dom'
import { useProveedores } from '../hooks/useCatalogos'
import { useAuth } from '../auth/AuthProvider'
import { filtrarProveedores, normalizarNit, normalizarTexto } from './filtroProveedores'
import DialogoProveedor from './DialogoProveedor'

/** Opciones visibles a la vez; con más, se pide seguir escribiendo. */
const MAXIMO = 50
const ALTO_LISTA = 300

interface Opcion { valor: string; nombre: string; nit?: string | null }
/** Valor de la opción «+ Crear proveedor "…"» (S-24). */
const CREAR = '__crear__'
type Via = 'enter' | 'clic' | 'tab'

/**
 * Combobox de proveedor con búsqueda por nombre o NIT (patrón ARIA combobox + listbox).
 * Filtra en el navegador la lista que ya trae useProveedores. El valor es el id como string
 * ('' = ninguno). Escribir reinicia la selección; salir sin elegir vuelve al valor anterior.
 * La lista va en un portal con posición fija: no la recorta el overflow de una modal y abre
 * hacia arriba si abajo no cabe (también con el teclado virtual del móvil).
 */
export default function SelectorProveedor({
  value, onChange, id, required, placeholder, opcionTodos, limpiable, autoFocus, error, alElegir, className, permitirCrear,
  'aria-invalid': ariaInvalid, 'aria-label': ariaLabel,
}: {
  value: string
  onChange: (id: string) => void
  id?: string
  required?: boolean
  placeholder?: string
  /** Modo filtro: primera opción que equivale a '' (sin filtro). Incluye el botón para limpiar. */
  opcionTodos?: string
  /** Muestra el botón para limpiar aunque no haya opcionTodos. */
  limpiable?: boolean
  autoFocus?: boolean
  /** Error del backend para este campo. */
  error?: string
  /** Se llama tras elegir con Enter o clic (no con Tab ni mientras se escribe). */
  alElegir?: () => void
  /** S-24: ofrece «+ Crear proveedor "…"» (solo admin y logística; nunca en los filtros). */
  permitirCrear?: boolean
  className?: string
  'aria-invalid'?: boolean
  'aria-label'?: string
}) {
  const proveedores = useProveedores()
  const { tieneRol } = useAuth()
  const puedeCrear = !!permitirCrear && tieneRol('logistica') // el admin pasa siempre
  /** Texto con que se abrió "Nuevo proveedor"; null = cerrado. */
  const [creando, setCreando] = useState<string | null>(null)
  const base = useId()
  const listaId = `${base}-lista`
  const errorId = `${base}-error`
  const input = useRef<HTMLInputElement>(null)

  /** null = muestra el proveedor elegido; texto = el usuario está escribiendo. */
  const [texto, setTexto] = useState<string | null>(null)
  const [abierta, setAbierta] = useState(false)
  const [activa, setActiva] = useState(-1)
  const [posicion, setPosicion] = useState<CSSProperties>({})
  const editando = useRef(false)
  const anterior = useRef(value)

  const seleccionado = proveedores.data?.find((p) => String(p.id) === value)
  const filtrados = useMemo(() => filtrarProveedores(proveedores.data ?? [], texto ?? ''), [proveedores.data, texto])
  // Crear solo si lo escrito no coincide exacto con un nombre o un NIT existente.
  const escrito = (texto ?? '').trim()
  const coincideExacto = escrito !== '' && (proveedores.data ?? []).some((p) =>
    normalizarTexto(p.nombre) === normalizarTexto(escrito) || (!!p.nit && normalizarNit(p.nit) === normalizarNit(escrito)))
  const ofrecerCrear = puedeCrear && escrito !== '' && !coincideExacto
  const encontrados: Opcion[] = useMemo(() => [
    ...(opcionTodos && !texto ? [{ valor: '', nombre: opcionTodos }] : []),
    ...filtrados.slice(0, MAXIMO).map((p) => ({ valor: String(p.id), nombre: p.nombre, nit: p.nit })),
  ], [filtrados, opcionTodos, texto])
  const opciones: Opcion[] = ofrecerCrear ? [...encontrados, { valor: CREAR, nombre: escrito }] : encontrados
  const hayMas = filtrados.length > MAXIMO
  const conLimpiar = (opcionTodos !== undefined || limpiable) && value !== '' && texto === null

  // required: sin una selección válida el formulario no se envía.
  useEffect(() => {
    input.current?.setCustomValidity(required && value === '' ? 'Elija un proveedor de la lista.' : '')
  }, [required, value])

  const abrir = () => {
    setActiva(Math.max(0, opciones.findIndex((o) => o.valor === value)))
    setAbierta(true)
  }

  const cerrar = () => { setAbierta(false); setActiva(-1) }

  const elegir = (o: Opcion, via: Via) => {
    if (o.valor === CREAR) {
      // Abre "Nuevo proveedor" con lo escrito; la selección anterior vuelve al salir del campo.
      cerrar()
      setCreando(o.nombre)
      return
    }
    editando.current = false
    setTexto(null)
    cerrar()
    onChange(o.valor)
    if (via !== 'tab') alElegir?.()
  }

  const alEscribir = (v: string) => {
    if (!editando.current) {
      editando.current = true
      anterior.current = value
      if (value !== '') onChange('') // escribir reinicia la selección
    }
    setTexto(v)
    setAbierta(true)
    setActiva(0)
  }

  const alPulsar = (e: KeyboardEvent<HTMLInputElement>) => {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault()
        if (!abierta) abrir()
        else setActiva((i) => Math.min(i + 1, opciones.length - 1))
        break
      case 'ArrowUp':
        e.preventDefault()
        if (abierta) setActiva((i) => Math.max(i - 1, 0))
        break
      case 'Enter':
        if (abierta) {
          e.preventDefault() // con la lista abierta, Enter elige; no envía el formulario
          if (opciones[activa]) elegir(opciones[activa], 'enter')
        }
        break
      case 'Escape':
        if (abierta) {
          // Cierra solo la lista: que no llegue al Esc de la modal.
          e.preventDefault()
          e.stopPropagation()
          cerrar()
        }
        break
      case 'Tab':
        if (abierta && opciones[activa]) elegir(opciones[activa], 'tab')
        break
    }
  }

  const alSalir = () => {
    cerrar()
    if (editando.current) {
      // Escribió y salió sin elegir: vuelve al valor anterior (o vacío).
      editando.current = false
      setTexto(null)
      onChange(anterior.current)
    }
  }

  const limpiar = () => {
    editando.current = false
    setTexto(null)
    onChange('')
    input.current?.focus()
  }

  const ubicar = useCallback(() => {
    const el = input.current
    if (!el) return
    const r = el.getBoundingClientRect()
    const vista = window.visualViewport
    const arribaVisible = vista?.offsetTop ?? 0
    const abajoVisible = vista ? vista.offsetTop + vista.height : window.innerHeight
    const espacioAbajo = abajoVisible - r.bottom - 8
    const espacioArriba = r.top - arribaVisible - 8
    const ancho = Math.min(Math.max(r.width, 240), window.innerWidth - 16)
    const left = Math.max(8, Math.min(r.left, window.innerWidth - ancho - 8))
    const haciaAbajo = espacioAbajo >= Math.min(ALTO_LISTA, 180) || espacioAbajo >= espacioArriba
    setPosicion(haciaAbajo
      ? { left, width: ancho, top: r.bottom + 4, maxHeight: Math.min(ALTO_LISTA, espacioAbajo) }
      : { left, width: ancho, bottom: window.innerHeight - r.top + 4, maxHeight: Math.min(ALTO_LISTA, espacioArriba) })
  }, [])

  useLayoutEffect(() => {
    if (!abierta) return
    ubicar()
    const vista = window.visualViewport
    window.addEventListener('resize', ubicar)
    window.addEventListener('scroll', ubicar, true)
    vista?.addEventListener('resize', ubicar)
    vista?.addEventListener('scroll', ubicar)
    return () => {
      window.removeEventListener('resize', ubicar)
      window.removeEventListener('scroll', ubicar, true)
      vista?.removeEventListener('resize', ubicar)
      vista?.removeEventListener('scroll', ubicar)
    }
  }, [abierta, ubicar])

  useEffect(() => {
    if (abierta && activa >= 0) document.getElementById(`${listaId}-${activa}`)?.scrollIntoView({ block: 'nearest' })
  }, [abierta, activa, listaId])

  const invalido = ariaInvalid ?? !!error

  return (
    <>
      <div className={`selector${conLimpiar ? ' con-limpiar' : ''}${className ? ` ${className}` : ''}`}>
        <input
          ref={input}
          id={id}
          type="text"
          role="combobox"
          aria-expanded={abierta}
          aria-controls={listaId}
          aria-autocomplete="list"
          aria-activedescendant={abierta && activa >= 0 && opciones[activa] ? `${listaId}-${activa}` : undefined}
          aria-invalid={invalido || undefined}
          aria-describedby={error ? errorId : undefined}
          aria-label={ariaLabel}
          autoComplete="off"
          autoFocus={autoFocus}
          required={required}
          placeholder={placeholder ?? opcionTodos ?? 'Nombre o NIT'}
          value={texto ?? seleccionado?.nombre ?? ''}
          onChange={(e) => alEscribir(e.target.value)}
          onKeyDown={alPulsar}
          onClick={() => { if (!abierta) abrir() }}
          onBlur={alSalir}
        />
        {conLimpiar && (
          <button type="button" className="selector-limpiar" aria-label="Quitar el proveedor elegido" onClick={limpiar}>
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        )}
        <svg className="selector-flecha" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden><path d="M6 9l6 6 6-6" /></svg>
      </div>
      {error && <small id={errorId} className="ayuda error">{error}</small>}

      {abierta && createPortal(
        // mousedown no quita el foco del input: el clic elige sin disparar el blur.
        <div className="selector-lista" style={{ position: 'fixed', ...posicion }} onMouseDown={(e) => e.preventDefault()}>
          {encontrados.length === 0 && <div className="selector-nota" role="status">Sin resultados</div>}
          <ul role="listbox" id={listaId} aria-label="Proveedores">
            {opciones.map((o, i) => (
              <li
                key={o.valor || 'todos'}
                id={`${listaId}-${i}`}
                role="option"
                aria-selected={i === activa}
                className={`selector-opcion${i === activa ? ' activa' : ''}${o.valor === value ? ' elegida' : ''}${o.valor === CREAR ? ' crear' : ''}`}
                onMouseMove={() => { if (i !== activa) setActiva(i) }}
                onClick={() => elegir(o, 'clic')}
              >
                {o.valor === CREAR
                  ? <span>+ Crear proveedor «{o.nombre}»</span>
                  : <><span>{o.nombre}</span>{o.nit && <small>NIT {o.nit}</small>}</>}
              </li>
            ))}
          </ul>
          {hayMas && <div className="selector-nota" role="status">Siga escribiendo para acotar</div>}
        </div>,
        document.body,
      )}

      {creando !== null && (
        <DialogoProveedor
          texto={creando}
          onCreado={(nuevo) => {
            editando.current = false
            setTexto(null)
            setCreando(null)
            onChange(String(nuevo.id))
            // Al siguiente campo (N.º del documento en la modal de ingreso), o de vuelta al selector.
            setTimeout(() => (alElegir ? alElegir() : input.current?.focus()), 0)
          }}
          onUsar={(idExistente) => {
            editando.current = false
            setTexto(null)
            setCreando(null)
            onChange(String(idExistente))
            setTimeout(() => (alElegir ? alElegir() : input.current?.focus()), 0)
          }}
          onCerrar={() => { setCreando(null); setTimeout(() => input.current?.focus(), 0) }}
        />
      )}
    </>
  )
}
