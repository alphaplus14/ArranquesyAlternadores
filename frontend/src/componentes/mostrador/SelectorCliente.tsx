import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, mensajeError } from '../../api/cliente'
import type { Cliente, Pagina } from '../../api/tipos'
import { useAviso } from '../Avisos'
import { useDebounce } from '../../hooks/useDebounce'
import { ATRIBUTO_ESCAPE_LOCAL } from '../dialogoCierre'

const LIMITE_BUSQUEDA = 8
/** S-72: no se lista nada hasta escribir al menos esto (el backend responde 422 con menos). */
const MIN_CARACTERES = 2
const MENSAJE_DUPLICADO = 'Ya existe un cliente con ese documento.'
const SIN_FILAS: Cliente[] = []

/** Lo escrito es un documento: solo dígitos (se toleran espacios, puntos y guiones). */
function digitosDe(texto: string): string {
  return /^[\d\s.-]+$/.test(texto) ? texto.replace(/\D/g, '') : ''
}

function sinTildes(texto: string): string {
  return texto.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
}

/** Marca la parte del nombre que contiene lo escrito, sin distinguir mayúsculas ni tildes (como MySQL). */
function resaltarNombre(nombre: string, texto: string): ReactNode {
  const aguja = sinTildes(texto)
  if (aguja === '') return nombre
  let normal = ''
  const origen: number[] = []
  for (let i = 0; i < nombre.length; i++) {
    for (const letra of sinTildes(nombre[i])) {
      normal += letra
      origen.push(i)
    }
  }
  const pos = normal.indexOf(aguja)
  if (pos < 0) return nombre
  const inicio = origen[pos]
  const fin = origen[pos + aguja.length - 1] + 1
  return <>{nombre.slice(0, inicio)}<mark className="cliente-coincidencia">{nombre.slice(inicio, fin)}</mark>{nombre.slice(fin)}</>
}

function resaltarDocumento(documento: string, digitos: string): ReactNode {
  if (digitos === '' || !documento.startsWith(digitos)) return documento
  return <><mark className="cliente-coincidencia">{documento.slice(0, digitos.length)}</mark>{documento.slice(digitos.length)}</>
}

function Meta({ cliente, digitos = '' }: { cliente: Cliente; digitos?: string }) {
  return (
    <span className="eleccion-meta">
      {cliente.documento && <>{resaltarDocumento(cliente.documento, digitos)} · </>}
      plazo {cliente.plazo_dias} días
    </span>
  )
}

type Opcion = { tipo: 'cliente'; cliente: Cliente } | { tipo: 'crear'; destacada: boolean }

/**
 * RF-13 / RF-14 (S-72): selector de cliente de la venta a crédito. Al abrir no lista ningún cliente;
 * busca desde 2 caracteres, hasta 8 activos (250 ms de retardo; la petición anterior se cancela y un
 * resultado viejo no pisa al nuevo). Flechas recorren, Enter elige, Esc limpia. Si lo escrito no está,
 * ofrece crear el cliente con el texto ya cargado. Solo comodidad: el backend decide las reglas.
 */
export default function SelectorCliente({ elegido, onElegir, onSucio }: {
  elegido: Cliente | null
  onElegir: (cliente: Cliente | null) => void
  /** Hay texto en el buscador o un alta a medias (para el aviso de salida de la modal). */
  onSucio: (sucio: boolean) => void
}) {
  const qc = useQueryClient()
  const avisos = useAviso()
  const listaId = useId()
  const campoRef = useRef<HTMLInputElement>(null)
  const [q, setQ] = useState('')
  const [activo, setActivo] = useState(0)
  const [creando, setCreando] = useState(false)
  const [nuevo, setNuevo] = useState({ nombre: '', documento: '', telefono: '' })
  const [duplicado, setDuplicado] = useState<{ documento: string; cliente: Cliente | null } | null>(null)

  const texto = q.trim()
  const termino = useDebounce(texto, 250)
  const conTexto = texto !== ''
  // Al abrir no se lista ningún cliente: la búsqueda empieza con 2 caracteres.
  const buscable = [...texto].length >= MIN_CARACTERES

  const busqueda = useQuery({
    queryKey: ['clientes', 'buscar', termino],
    queryFn: async ({ signal }) => (await api.get<Cliente[]>('/clientes', { params: { q: termino, limite: LIMITE_BUSQUEDA }, signal })).data,
    enabled: [...termino].length >= MIN_CARACTERES && !elegido && !creando,
    // Mientras llega la nueva, se sostiene la anterior solo si es la misma búsqueda afinada ("fa" → "fab").
    placeholderData: (previa, consulta) => {
      const anterior = String(consulta?.queryKey[2] ?? '')
      return anterior !== '' && (termino.startsWith(anterior) || anterior.startsWith(termino)) ? previa : undefined
    },
  })

  // Los resultados corresponden a lo que hay escrito (no a una búsqueda anterior ni en espera).
  const vigente = buscable && termino === texto && busqueda.isSuccess && !busqueda.isPlaceholderData
  const filas = buscable ? (busqueda.data ?? SIN_FILAS) : SIN_FILAS
  const digitos = digitosDe(texto)

  const opciones = useMemo<Opcion[]>(() => {
    const lista: Opcion[] = filas.map((cliente) => ({ tipo: 'cliente', cliente }))
    if (!vigente) return lista
    if (filas.length === 0) return [{ tipo: 'crear', destacada: true }]
    const exacto = filas.some((c) => sinTildes(c.nombre.trim()) === sinTildes(texto) || (digitos !== '' && c.documento?.replace(/\D/g, '') === digitos))
    return exacto ? lista : [...lista, { tipo: 'crear', destacada: false }]
  }, [filas, vigente, texto, digitos])

  // Cada lista nueva empieza con la primera opción resaltada (se compara por contenido, no por referencia).
  const claveOpciones = opciones.map((o) => (o.tipo === 'cliente' ? o.cliente.id : `crear-${o.destacada}`)).join(',')
  const [claveVista, setClaveVista] = useState(claveOpciones)
  if (claveVista !== claveOpciones) {
    setClaveVista(claveOpciones)
    setActivo(0)
  }

  useEffect(() => {
    onSucio(conTexto || (creando && (nuevo.nombre.trim() !== '' || nuevo.documento.trim() !== '' || nuevo.telefono.trim() !== '')))
  }, [conTexto, creando, nuevo, onSucio])

  useEffect(() => {
    document.getElementById(`${listaId}-${activo}`)?.scrollIntoView({ block: 'nearest' })
  }, [activo, listaId])

  const elegir = (cliente: Cliente) => {
    onElegir(cliente)
    setQ('')
  }

  const abrirCreacion = () => {
    const documento = digitosDe(texto)
    setNuevo({ nombre: documento ? '' : texto, documento, telefono: '' })
    setDuplicado(null)
    setCreando(true)
  }

  const volverABuscar = () => {
    setCreando(false)
    setDuplicado(null)
    crear.reset()
    requestAnimationFrame(() => campoRef.current?.focus())
  }

  const crear = useMutation({
    mutationFn: async () => (await api.post<Cliente>('/clientes', {
      nombre: nuevo.nombre.trim(),
      documento: nuevo.documento.trim() || null,
      telefono: nuevo.telefono.trim() || null,
    })).data,
    onSuccess: (c) => {
      setCreando(false)
      setNuevo({ nombre: '', documento: '', telefono: '' })
      elegir(c)
      qc.invalidateQueries({ queryKey: ['clientes'] })
    },
    onError: async (e) => {
      const errores = e instanceof AxiosError ? (e.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors : undefined
      if (errores?.documento?.[0] !== MENSAJE_DUPLICADO) {
        avisos.mostrar('error', mensajeError(e))
        return
      }
      // El documento ya existe: se busca ese cliente (activo o no) para ofrecerlo en vez de duplicarlo.
      const documento = nuevo.documento.trim()
      setDuplicado({ documento, cliente: null })
      try {
        const pagina = (await api.get<Pagina<Cliente>>('/clientes', { params: { documento, por_pagina: 1 } })).data
        setDuplicado({ documento, cliente: pagina.data[0] ?? null })
      } catch {
        // Sin el cliente a mano queda solo el aviso.
      }
    },
  })

  const alPulsar = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (opciones.length === 0) return
      e.preventDefault()
      const paso = e.key === 'ArrowDown' ? 1 : -1
      setActivo((i) => (i + paso + opciones.length) % opciones.length)
    } else if (e.key === 'Enter') {
      e.preventDefault()
      // Solo con resultados de lo escrito: Enter no elige uno de una búsqueda anterior aún en pantalla.
      const opcion = vigente ? opciones[activo] : undefined
      if (!opcion) return
      if (opcion.tipo === 'cliente') elegir(opcion.cliente)
      else abrirCreacion()
    } else if (e.key === 'Escape' && q !== '') {
      e.preventDefault()
      setQ('')
    }
  }

  if (elegido) {
    return (
      <div className="cliente-ficha">
        <div>
          <strong>{elegido.nombre}</strong>
          <Meta cliente={elegido} />
        </div>
        <button
          type="button"
          className="secundario"
          onClick={() => { onElegir(null); requestAnimationFrame(() => campoRef.current?.focus()) }}
        >
          Cambiar
        </button>
      </div>
    )
  }

  if (creando) {
    const cliente = duplicado?.cliente
    return (
      <form className="formulario" onSubmit={(e) => { e.preventDefault(); setDuplicado(null); crear.mutate() }}>
        <label>Nombre<input autoFocus={nuevo.nombre === ''} required maxLength={150} value={nuevo.nombre} onChange={(e) => setNuevo({ ...nuevo, nombre: e.target.value })} /></label>
        <label>Documento (opcional)
          <input
            autoFocus={nuevo.nombre !== ''}
            maxLength={30}
            value={nuevo.documento}
            aria-invalid={duplicado !== null}
            onChange={(e) => { setNuevo({ ...nuevo, documento: e.target.value }); setDuplicado(null) }}
          />
        </label>
        {duplicado && (
          <div className="aviso error cliente-duplicado" role="alert">
            <p>Ya existe un cliente con ese documento.</p>
            {cliente && cliente.activo && (
              <button type="button" className="secundario" onClick={() => { setCreando(false); setDuplicado(null); elegir(cliente) }}>
                Seleccionar a {cliente.nombre}
              </button>
            )}
            {cliente && !cliente.activo && (
              <p>{cliente.nombre} está desactivado. Pida al administrador que lo reactive.</p>
            )}
          </div>
        )}
        <label>Teléfono (opcional)<input maxLength={30} inputMode="tel" value={nuevo.telefono} onChange={(e) => setNuevo({ ...nuevo, telefono: e.target.value })} /></label>
        <p className="ayuda">El sistema le pone el plazo por defecto; el administrador lo cambia en Clientes.</p>
        <div className="acciones">
          <button type="button" className="secundario" onClick={volverABuscar}>Volver a buscar</button>
          <button className="primario" disabled={crear.isPending || nuevo.nombre.trim() === ''}>Crear y elegir</button>
        </div>
      </form>
    )
  }

  const cargando = buscable && !vigente && filas.length === 0
  const idActivo = opciones[activo] ? `${listaId}-${activo}` : undefined

  return (
    <div className="cliente-selector">
      <input
        ref={campoRef}
        autoFocus
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={opciones.length > 0}
        aria-controls={listaId}
        aria-activedescendant={idActivo}
        aria-label="Buscar cliente"
        placeholder="Buscar cliente por nombre o documento"
        value={q}
        {...{ [ATRIBUTO_ESCAPE_LOCAL]: q !== '' ? '' : undefined }}
        onChange={(e) => setQ(e.target.value)}
        onKeyDown={alPulsar}
      />
      {!buscable && <small className="ayuda cliente-ayuda">Escriba al menos 2 letras o números para buscar</small>}
      <div className="eleccion cliente-lista" id={listaId} role="listbox" aria-label="Clientes encontrados">
        {cargando && <p className="eleccion-estado">Buscando…</p>}
        {opciones.map((opcion, i) => {
          const propias = {
            id: `${listaId}-${i}`,
            role: 'option' as const,
            'aria-selected': i === activo,
            // El foco se queda en el buscador: el clic no lo roba.
            onMouseDown: (e: { preventDefault: () => void }) => e.preventDefault(),
            onMouseEnter: () => setActivo(i),
          }
          if (opcion.tipo === 'cliente') {
            const c = opcion.cliente
            return (
              <div key={c.id} {...propias} className={i === activo ? 'eleccion-fila cliente-opcion cliente-opcion-activa' : 'eleccion-fila cliente-opcion'} onClick={() => elegir(c)}>
                <strong>{resaltarNombre(c.nombre, texto)}</strong>
                <Meta cliente={c} digitos={digitos} />
              </div>
            )
          }
          return (
            <div
              key="crear"
              {...propias}
              className={[opcion.destacada ? 'eleccion-alta cliente-crear' : 'cliente-crear-discreta', i === activo ? 'cliente-opcion-activa' : ''].join(' ').trim()}
              onClick={abrirCreacion}
            >
              {opcion.destacada ? `Crear cliente "${texto}"` : `¿No está? Crear cliente "${texto}"`}
            </div>
          )
        })}
      </div>
    </div>
  )
}
