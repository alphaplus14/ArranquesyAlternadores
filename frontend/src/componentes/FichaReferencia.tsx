import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api, mensajeError } from '../api/cliente'
import type { Calidad, Opcion, Referencia } from '../api/tipos'
import SelectorUniversal from './SelectorUniversal'
import { useCategorias, useProveedores } from '../hooks/useCatalogos'
import { useAvisoTexto } from './Avisos'
import Dialogo, { DialogoSeccion } from './Dialogo'
import Icono from './Icono'

/**
 * RF-01, RF-14, RF-20, S-15, S-18, S-20, S-23.
 * Una sola ficha para crear o editar una referencia en el catálogo y para crearla
 * desde un ingreso (código y proveedor fijos). El cuerpo que se guarda vive aquí.
 */
type Valores = {
  codigo_referencia: string
  /** S-23: '' = sin calidad. */
  calidad: '' | Calidad
  proveedor_id: string
  categoria_id: string
  codigo_universal_id: string
  descripcion: string
  precio_venta: string
  /** RF-14 / S-15: el backend acepta 19 o 0 (sin IVA). */
  iva_venta_porcentaje: '19' | '0'
}

const vacio: Valores = {
  codigo_referencia: '', calidad: '', proveedor_id: '', categoria_id: '',
  codigo_universal_id: '', descripcion: '', precio_venta: '', iva_venta_porcentaje: '19',
}

type Base = { onCerrar: () => void; onGuardada: () => void }

export type FichaReferenciaProps =
  | (Base & { modo: 'catalogo'; referencia: Referencia | null })
  | (Base & { modo: 'ingreso'; codigo: string; proveedor: Opcion })

function valoresIniciales(props: FichaReferenciaProps): Valores {
  if (props.modo === 'ingreso') {
    return { ...vacio, codigo_referencia: props.codigo, proveedor_id: String(props.proveedor.id) }
  }
  const r = props.referencia
  if (!r) return vacio
  return {
    codigo_referencia: r.codigo_referencia,
    calidad: r.calidad ?? '',
    proveedor_id: String(r.proveedor_id),
    categoria_id: String(r.categoria_id),
    codigo_universal_id: r.codigo_universal_id ? String(r.codigo_universal_id) : '',
    descripcion: r.descripcion,
    precio_venta: r.precio_venta,
    iva_venta_porcentaje: Number(r.iva_venta_porcentaje) === 0 ? '0' : '19',
  }
}

export default function FichaReferencia(props: FichaReferenciaProps) {
  const ingreso = props.modo === 'ingreso'
  const referencia = props.modo === 'catalogo' ? props.referencia : null
  const id = referencia?.id ?? null
  const [f, setF] = useState(() => valoresIniciales(props))
  const proveedores = useProveedores()
  const categorias = useCategorias()
  const categoria = categorias.data?.find((c) => String(c.id) === f.categoria_id)
  const admiteUniversal = categoria?.admite_codigo_universal ?? false

  const guardar = useMutation({
    mutationFn: async () => {
      const cuerpo = {
        codigo_referencia: f.codigo_referencia.trim(),
        proveedor_id: Number(f.proveedor_id),
        categoria_id: Number(f.categoria_id),
        calidad: f.calidad || null,
        descripcion: f.descripcion.trim(),
        precio_venta: Number(f.precio_venta),
        iva_venta_porcentaje: Number(f.iva_venta_porcentaje),
        codigo_universal_id: admiteUniversal && f.codigo_universal_id ? Number(f.codigo_universal_id) : null,
      }
      return id ? api.put(`/referencias/${id}`, cuerpo) : api.post('/referencias', cuerpo)
    },
    onSuccess: props.onGuardada,
  })

  const setCampo = (k: keyof Valores, valor: string) => {
    setF((actual) => {
      const siguiente = { ...actual, [k]: valor }
      if (k === 'categoria_id') siguiente.codigo_universal_id = ''
      return siguiente
    })
  }

  const errorPrecio = f.precio_venta === '' ? null : Number(f.precio_venta) > 0 ? null : 'El precio de venta debe ser mayor que 0.'
  const sinCategorias = categorias.data?.length === 0
  const completo = f.codigo_referencia.trim() !== ''
    && f.proveedor_id !== ''
    && f.categoria_id !== ''
    && f.descripcion.trim() !== ''
    && f.precio_venta !== ''
    && errorPrecio === null

  const titulo = ingreso ? 'Crear referencia' : id ? 'Editar referencia' : 'Nueva referencia'
  const subtitulo = ingreso
    ? `${props.codigo} · ${props.proveedor.nombre}`
    : referencia
      ? [referencia.codigo_referencia, referencia.proveedor?.nombre].filter(Boolean).join(' · ')
      : 'Código, origen y precio de venta'
  const ayudaPrecio = errorPrecio ?? (ingreso
    ? 'Mayor que 0. Si no lo conoce, consúltelo con el administrador antes de crearla.'
    : 'Mayor que 0. Una referencia sin precio no se puede vender.')
  const etiquetaBoton = guardar.isPending ? 'Guardando…' : ingreso ? 'Crear y agregar' : 'Guardar'
  useAvisoTexto(sinCategorias ? 'No hay categorías en el catálogo. Pídale al administrador que las cree.' : null, 'atencion')
  useAvisoTexto(guardar.isError ? mensajeError(guardar.error) : null)

  return (
    <Dialogo
      titulo={titulo}
      descripcion={subtitulo}
      marca={<Icono nombre="etiqueta" />}
      variante="ficha"
      onCerrar={props.onCerrar}
      pendiente={guardar.isPending}
      onEnviar={() => { if (completo && !guardar.isPending) guardar.mutate() }}
      pie={(
        <>
          <button type="button" className="secundario" onClick={props.onCerrar}>Cancelar</button>
          <button type="submit" className="primario" disabled={!completo || guardar.isPending}>{etiquetaBoton}</button>
        </>
      )}
    >
        <DialogoSeccion>
          <h3>Identidad</h3>
          {ingreso && <p className="ayuda">Este código no existe para este proveedor. Al guardarla se agrega la línea al ingreso.</p>}
          <div className="campos-dos">
            <label>Código de referencia
              <input
                required
                maxLength={80}
                autoFocus={!ingreso}
                readOnly={ingreso}
                value={f.codigo_referencia}
                onChange={(e) => setCampo('codigo_referencia', e.target.value)}
              />
            </label>
            <label>Calidad
              <select value={f.calidad} onChange={(e) => setCampo('calidad', e.target.value)}>
                <option value="">—</option>
                <option value="fino">Fino</option>
                <option value="generico">Genérico</option>
              </select>
            </label>
          </div>
          <label>Descripción
            <input required maxLength={255} value={f.descripcion} onChange={(e) => setCampo('descripcion', e.target.value)} />
          </label>
        </DialogoSeccion>

        <DialogoSeccion titulo="Origen">
          <div className="campos-dos">
            <label>Proveedor
              {ingreso ? (
                <input value={props.proveedor.nombre} readOnly aria-readonly="true" />
              ) : (
                <select required value={f.proveedor_id} onChange={(e) => setCampo('proveedor_id', e.target.value)}>
                  <option value="">—</option>
                  {proveedores.data?.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
                </select>
              )}
            </label>
            <label>Categoría
              <select required autoFocus={ingreso} value={f.categoria_id} onChange={(e) => setCampo('categoria_id', e.target.value)}>
                <option value="">—</option>
                {categorias.data?.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
              </select>
            </label>
          </div>
          {ingreso && <p className="ayuda">Si la categoría que necesita no aparece, pídale al administrador que la cree; aquí no se crean.</p>}
          {admiteUniversal && (
            // S-59: elegir o crear el universal en línea, con la categoría de la referencia.
            <SelectorUniversal categoriaId={f.categoria_id} value={f.codigo_universal_id} onChange={(v) => setCampo('codigo_universal_id', v)} />
          )}
        </DialogoSeccion>

        <DialogoSeccion titulo="Venta">
          <div className="campos-dos">
            <label>Precio de venta
              <input
                type="number"
                min="0.01"
                step="0.01"
                required
                aria-invalid={errorPrecio !== null}
                aria-describedby="ficha-precio-ayuda"
                value={f.precio_venta}
                onChange={(e) => setCampo('precio_venta', e.target.value)}
              />
            </label>
            <label>IVA de venta
              <select value={f.iva_venta_porcentaje} onChange={(e) => setCampo('iva_venta_porcentaje', e.target.value)}>
                <option value="19">19 %</option>
                <option value="0">0 % · sin IVA</option>
              </select>
            </label>
          </div>
          <p id="ficha-precio-ayuda" className={errorPrecio ? 'ayuda error' : 'ayuda'}>{ayudaPrecio}</p>
          <p className="ayuda">0 % significa que la referencia se vende sin IVA. Es el IVA que queda guardado en cada venta.</p>
        </DialogoSeccion>
    </Dialogo>
  )
}
