import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import { GRUPOS_CAJA, NOMBRE_GRUPO_CAJA, NOMBRE_TIPO_CUENTA, type CategoriaCaja, type CuentaPago, type GrupoCaja, type TipoCuentaPago } from '../../api/tipos'
import { useAvisoTexto } from '../../componentes/Avisos'
import { useCategoriasCaja, useCuentasPago } from '../../hooks/useCatalogos'

const TIPOS: TipoCuentaPago[] = ['efectivo', 'banco', 'datafono', 'billetera']

/**
 * RF-24 / RF-26 (S-48, S-51): el admin crea, renombra y desactiva cuentas de pago y categorías de
 * caja. Nada se borra (tienen historia); cada cambio queda en la auditoría.
 */
export default function CatalogosCaja() {
  return (
    <div className="caja-columnas">
      <Cuentas />
      <Categorias />
    </div>
  )
}

function Cuentas() {
  const qc = useQueryClient()
  const cuentas = useCuentasPago(true)
  const [nombre, setNombre] = useState('')
  const [tipo, setTipo] = useState<TipoCuentaPago>('banco')
  const refrescar = () => qc.invalidateQueries({ queryKey: ['cuentas-pago'] })

  const crear = useMutation({
    mutationFn: () => api.post('/cuentas-pago', { nombre: nombre.trim(), tipo }),
    onSuccess: () => { setNombre(''); refrescar() },
  })
  const editar = useMutation({
    mutationFn: ({ id, cambios }: { id: number; cambios: Partial<CuentaPago> }) => api.put(`/cuentas-pago/${id}`, cambios),
    onSuccess: refrescar,
  })
  useAvisoTexto(editar.isError ? mensajeError(editar.error) : null)

  return (
    <section className="tarjeta-pagina">
      <h2>Cuentas de pago</h2>
      <form className="alta-en-linea" onSubmit={(e) => { e.preventDefault(); if (nombre.trim().length >= 2) crear.mutate() }}>
        <label><span className="solo-lector">Nombre de la cuenta</span><input maxLength={60} placeholder="Nueva cuenta" value={nombre} onChange={(e) => { setNombre(e.target.value); crear.reset() }} /></label>
        <label><span className="solo-lector">Tipo</span>
          <select value={tipo} onChange={(e) => setTipo(e.target.value as TipoCuentaPago)}>
            {TIPOS.map((t) => <option key={t} value={t}>{NOMBRE_TIPO_CUENTA[t]}</option>)}
          </select>
        </label>
        <button type="submit" className="primario" disabled={nombre.trim().length < 2 || crear.isPending}>Agregar</button>
      </form>
      {crear.isError && <div className="aviso error" role="alert">{mensajeError(crear.error)}</div>}
      <table className="tabla-caja">
        <thead><tr><th>Cuenta</th><th>Tipo</th><th>Estado</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
        <tbody>
          {(cuentas.data ?? []).map((c) => (
            <FilaEditable
              key={c.id}
              nombre={c.nombre}
              detalle={NOMBRE_TIPO_CUENTA[c.tipo]}
              activa={c.activa}
              pendiente={editar.isPending}
              onRenombrar={(n) => editar.mutate({ id: c.id, cambios: { nombre: n } })}
              onActivar={(a) => editar.mutate({ id: c.id, cambios: { activa: a } })}
            />
          ))}
        </tbody>
      </table>
    </section>
  )
}

function Categorias() {
  const qc = useQueryClient()
  const categorias = useCategoriasCaja(true)
  const [nombre, setNombre] = useState('')
  const [grupo, setGrupo] = useState<GrupoCaja>('vario')
  const refrescar = () => qc.invalidateQueries({ queryKey: ['caja-categorias'] })

  const crear = useMutation({
    mutationFn: () => api.post('/caja/categorias', { nombre: nombre.trim(), grupo }),
    onSuccess: () => { setNombre(''); refrescar() },
  })
  const editar = useMutation({
    mutationFn: ({ id, cambios }: { id: number; cambios: Partial<CategoriaCaja> }) => api.put(`/caja/categorias/${id}`, cambios),
    onSuccess: refrescar,
  })
  useAvisoTexto(editar.isError ? mensajeError(editar.error) : null)

  return (
    <section className="tarjeta-pagina">
      <h2>Categorías de caja</h2>
      <form className="alta-en-linea" onSubmit={(e) => { e.preventDefault(); if (nombre.trim().length >= 2) crear.mutate() }}>
        <label><span className="solo-lector">Nombre de la categoría</span><input maxLength={100} placeholder="Nueva categoría" value={nombre} onChange={(e) => { setNombre(e.target.value); crear.reset() }} /></label>
        <label><span className="solo-lector">Grupo</span>
          <select value={grupo} onChange={(e) => setGrupo(e.target.value as GrupoCaja)}>
            {GRUPOS_CAJA.map((g) => <option key={g} value={g}>{NOMBRE_GRUPO_CAJA[g]}</option>)}
          </select>
        </label>
        <button type="submit" className="primario" disabled={nombre.trim().length < 2 || crear.isPending}>Agregar</button>
      </form>
      {crear.isError && <div className="aviso error" role="alert">{mensajeError(crear.error)}</div>}
      {GRUPOS_CAJA.map((g) => {
        const filas = (categorias.data ?? []).filter((c) => c.grupo === g)
        return (
          <details key={g} className="grupo-categorias" open={g === 'vario'}>
            <summary>{NOMBRE_GRUPO_CAJA[g]} <span className="pestana-cuenta">{filas.length}</span></summary>
            <table className="tabla-caja">
              <tbody>
                {filas.map((c) => (
                  <FilaEditable
                    key={c.id}
                    nombre={c.nombre}
                    activa={c.activa}
                    pendiente={editar.isPending}
                    onRenombrar={(n) => editar.mutate({ id: c.id, cambios: { nombre: n } })}
                    onActivar={(a) => editar.mutate({ id: c.id, cambios: { activa: a } })}
                  />
                ))}
              </tbody>
            </table>
          </details>
        )
      })}
    </section>
  )
}

function FilaEditable({ nombre, detalle, activa, pendiente, onRenombrar, onActivar }: {
  nombre: string
  detalle?: string
  activa: boolean
  pendiente: boolean
  onRenombrar: (nombre: string) => void
  onActivar: (activa: boolean) => void
}) {
  const [editando, setEditando] = useState(false)
  const [texto, setTexto] = useState(nombre)

  return (
    <tr className={activa ? undefined : 'inactiva'}>
      <td>
        {editando ? (
          <form className="alta-en-linea" onSubmit={(e) => { e.preventDefault(); if (texto.trim().length >= 2) { onRenombrar(texto.trim()); setEditando(false) } }}>
            <label><span className="solo-lector">Nuevo nombre</span><input autoFocus maxLength={100} value={texto} onChange={(e) => setTexto(e.target.value)} /></label>
            <button type="submit" className="primario" disabled={pendiente || texto.trim().length < 2}>Guardar</button>
            <button type="button" className="secundario" onClick={() => { setTexto(nombre); setEditando(false) }}>Cancelar</button>
          </form>
        ) : nombre}
      </td>
      {detalle !== undefined && <td>{detalle}</td>}
      <td><span className={activa ? 'chip cerrada' : 'chip'}>{activa ? 'Activa' : 'Inactiva'}</span></td>
      <td className="acciones-fila">
        {!editando && <button type="button" className="enlace" onClick={() => setEditando(true)}>Renombrar</button>}
        <button type="button" className="enlace" disabled={pendiente} onClick={() => onActivar(!activa)}>{activa ? 'Desactivar' : 'Activar'}</button>
      </td>
    </tr>
  )
}
