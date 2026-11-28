import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AvisoPagina } from './AvisoPagina'
import { api, erroresCampo, mensajeError } from '../api/cliente'
import type { UniversalInventario } from '../api/tipos'
import { useCategorias } from '../hooks/useCatalogos'
import { aValorMinimo, errorStockMinimo } from '../utilidades/stockMinimo'
import BotonCancelar from './BotonCancelar'
import CampoStockMinimo from './CampoStockMinimo'
import Dialogo, { DialogoSeccion } from './Dialogo'

/**
 * S-82 (RF-01, RF-22; S-59): crear o editar un código universal desde Inventario. Código (único),
 * categoría (debe admitir universal) y descripción: logística y admin. El stock mínimo (sobre la
 * suma de sus referencias activas) y eliminar son solo del admin. Las reglas las decide el backend
 * (código repetido, categoría que no admite, referencias de otra categoría, borrar con referencias).
 */
export default function DialogoUniversal({ universal, esAdmin, onGuardado, onEliminado, onCerrar }: {
  /** null = nuevo. */
  universal: UniversalInventario | null
  esAdmin: boolean
  onGuardado: (id: number) => void
  onEliminado: () => void
  onCerrar: () => void
}) {
  const qc = useQueryClient()
  const categorias = useCategorias()
  const inicial = {
    codigo: universal?.codigo ?? '',
    categoria_id: universal ? String(universal.categoria_id) : '',
    descripcion: universal?.descripcion ?? '',
    stock_minimo: universal?.stock_minimo == null ? '' : String(universal.stock_minimo),
  }
  const [f, setF] = useState(inicial)
  const [confirmarBorrado, setConfirmarBorrado] = useState(false)
  const opciones = (categorias.data ?? []).filter((c) => c.admite_codigo_universal || String(c.id) === inicial.categoria_id)

  const refrescar = () => {
    qc.invalidateQueries({ queryKey: ['inventario'] })
    qc.invalidateQueries({ queryKey: ['universales'] })
    qc.invalidateQueries({ queryKey: ['referencias'] })
    qc.invalidateQueries({ queryKey: ['bajo-minimo'] })
  }

  const guardar = useMutation({
    mutationFn: async () => {
      const datos = {
        codigo: f.codigo.trim().toUpperCase(),
        categoria_id: Number(f.categoria_id),
        descripcion: f.descripcion.trim() || null,
        // S-59 / S-28: el backend rechaza el mínimo si no es el admin; solo se envía si lo es.
        ...(esAdmin ? { stock_minimo: aValorMinimo(f.stock_minimo) } : {}),
      }
      return (universal
        ? await api.put<{ id: number }>(`/codigos-universales/${universal.id}`, datos)
        : await api.post<{ id: number }>('/codigos-universales', datos)).data
    },
    onSuccess: (u) => { refrescar(); onGuardado(u.id) },
  })
  const eliminar = useMutation({
    mutationFn: () => api.delete(`/codigos-universales/${universal?.id}`),
    onSuccess: () => { refrescar(); onEliminado() },
  })

  const errores = guardar.isError ? erroresCampo(guardar.error) : {}
  const general = guardar.isError && Object.keys(errores).length === 0 ? mensajeError(guardar.error) : null
  const errorMinimo = esAdmin ? errorStockMinimo(f.stock_minimo) : null
  const modificado = JSON.stringify(f) !== JSON.stringify(inicial)
  const campo = (k: 'codigo' | 'categoria_id' | 'descripcion') => ({
    value: f[k],
    onChange: (e: { target: { value: string } }) => { setF({ ...f, [k]: e.target.value }); guardar.reset() },
  })

  return (
    <Dialogo
      titulo={universal ? `Editar universal ${universal.codigo}` : 'Nuevo universal'}
      descripcion="Agrupa referencias del mismo repuesto; su existencia es la suma de ellas."
      modificado={modificado}
      pendiente={guardar.isPending || eliminar.isPending}
      onCerrar={onCerrar}
      onEnviar={() => { if (!guardar.isPending && f.codigo.trim() && f.categoria_id && !errorMinimo) guardar.mutate() }}
      pie={(
        <>
          {universal && esAdmin && (
            confirmarBorrado ? (
              <span className="confirmar-en-linea" role="group" aria-label={`Eliminar ${universal.codigo}`}>
                <span>¿Eliminar {universal.codigo}?</span>
                <button type="button" className="peligro" disabled={eliminar.isPending} onClick={() => eliminar.mutate()}>Sí, eliminar</button>
                <button type="button" className="secundario" onClick={() => setConfirmarBorrado(false)}>No</button>
              </span>
            ) : (
              <button type="button" className="secundario texto-peligro" onClick={() => { eliminar.reset(); setConfirmarBorrado(true) }}>Eliminar</button>
            )
          )}
          <BotonCancelar />
          <button type="submit" className="primario" disabled={guardar.isPending || !f.codigo.trim() || !f.categoria_id || errorMinimo !== null}>
            {guardar.isPending ? 'Guardando…' : 'Guardar'}
          </button>
        </>
      )}
    >
      <DialogoSeccion>
        <label>Código
          <input required autoFocus maxLength={60} placeholder="Ej: IM204" aria-invalid={!!errores.codigo} aria-describedby="universal-codigo-ayuda" {...campo('codigo')} />
          <small id="universal-codigo-ayuda" className={errores.codigo ? 'ayuda error' : 'ayuda'}>{errores.codigo ?? 'En reguladores, el código de Transpo (ej. IM204).'}</small>
        </label>
        <label>Categoría
          <select required aria-invalid={!!errores.categoria_id} {...campo('categoria_id')}>
            <option value="">{categorias.isLoading ? 'Cargando…' : 'Elija la categoría…'}</option>
            {opciones.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
          </select>
          {errores.categoria_id
            ? <small className="ayuda error">{errores.categoria_id}</small>
            : <small className="ayuda">Solo las categorías que admiten código universal (RF-01).</small>}
        </label>
        <label>Descripción (opcional)
          <input maxLength={255} {...campo('descripcion')} />
        </label>
        {esAdmin ? (
          <CampoStockMinimo
            id="universal-stock-minimo"
            ayuda={`Vacío = usa el general; 0 = no maneja stock mínimo. Se compara con la suma de sus referencias activas${universal ? ` (hoy ${universal.existencia_total})` : ''}.`}
            valor={f.stock_minimo}
            onCambio={(v) => { setF({ ...f, stock_minimo: v }); guardar.reset() }}
          />
        ) : (
          <p className="ayuda">El stock mínimo del universal lo fija el administrador.</p>
        )}
        {general && <AvisoPagina tono="error">{general}</AvisoPagina>}
        {eliminar.isError && <div className="aviso error" role="alert">{mensajeError(eliminar.error)}</div>}
      </DialogoSeccion>
    </Dialogo>
  )
}
