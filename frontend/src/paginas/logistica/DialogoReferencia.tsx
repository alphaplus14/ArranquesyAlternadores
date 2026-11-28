import { useEffect, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { AvisoPagina } from '../../componentes/AvisoPagina'
import { api, mensajeError } from '../../api/cliente'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import type { Opcion, Referencia } from '../../api/tipos'
import SelectorUniversal from '../../componentes/SelectorUniversal'
import CampoMoneda from '../../componentes/CampoMoneda'
import { leerNumero } from '../../componentes/numeros'
import { useCategorias } from '../../hooks/useCatalogos'
import SelectorCategoria from '../../componentes/SelectorCategoria'
import ControlSegmentado, { type OpcionSegmento } from '../../componentes/ControlSegmentado'
import AvisoCoincidencias from '../../componentes/AvisoCoincidencias'
import BotonCancelar from '../../componentes/BotonCancelar'

function Candado() {
  return (
    <svg className="candado" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
      <rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" />
    </svg>
  )
}

type TipoCodigo = 'barras' | 'referencia'
const TIPOS_CODIGO: OpcionSegmento<TipoCodigo>[] = [
  { valor: 'barras', texto: 'Código de barras o QR' },
  { valor: 'referencia', texto: 'Referencia del proveedor' },
]

/**
 * RF-20: crea la referencia que no existe con el código leído y el proveedor del ingreso (ambos
 * fijos). Precio de venta > 0 (S-20). Calidad opcional (S-23). El IVA de venta no se pide: el
 * backend lo deja en 19 % (S-15). S-24: logística también puede crear la categoría (sin código universal).
 */
export default function DialogoReferencia({ codigo, proveedor, onCreada, onCerrar }: {
  codigo: string
  proveedor: Opcion
  onCreada: (referencia: Referencia) => void
  onCerrar: () => void
}) {
  const categorias = useCategorias()
  const campoCategoria = useRef<HTMLSelectElement>(null)
  const campoCalidad = useRef<HTMLSelectElement>(null)
  const [f, setF] = useState({ calidad: '', categoria_id: '', descripcion: '', precio_venta: '', codigo_universal_id: '' })
  // S-25: el código leído puede ser el de barras del producto o la referencia del proveedor.
  // De 8 a 14 dígitos se toma como código de barras; si no, como referencia.
  const [tipo, setTipo] = useState<TipoCodigo>(/^\d{8,14}$/.test(codigo) ? 'barras' : 'referencia')
  /** El otro código (el que no se leyó), opcional; y el que se consulta al salir del campo. */
  const [otro, setOtro] = useState('')
  const [otroConsultado, setOtroConsultado] = useState('')
  // El foco empieza en Categoría también si algo lo movió durante el montaje.
  useEffect(() => {
    const cuadro = requestAnimationFrame(() => campoCategoria.current?.focus())
    return () => cancelAnimationFrame(cuadro)
  }, [])
  const categoria = categorias.data?.find((c) => String(c.id) === f.categoria_id)
  const admiteUniversal = categoria?.admite_codigo_universal ?? false

  const crear = useMutation({
    mutationFn: async () => (await api.post<Referencia>('/referencias', {
      // Leído como código de barras: la referencia es opcional (vacía = el mismo código, S-25).
      codigo_referencia: tipo === 'referencia' ? codigo : otro.trim(),
      codigo_fabricante: tipo === 'barras' ? codigo : otro.trim() || null,
      proveedor_id: proveedor.id,
      calidad: f.calidad || null,
      categoria_id: Number(f.categoria_id),
      descripcion: f.descripcion.trim(),
      precio_venta: leerNumero(f.precio_venta), // S-67: sin el formato
      codigo_universal_id: admiteUniversal && f.codigo_universal_id ? Number(f.codigo_universal_id) : null,
    })).data,
    onSuccess: onCreada,
  })

  const errorPrecio = f.precio_venta === '' ? null : (leerNumero(f.precio_venta) ?? 0) > 0 ? null : 'El precio de venta debe ser mayor que 0.'
  const completo = f.categoria_id !== '' && f.descripcion.trim() !== '' && f.precio_venta !== '' && errorPrecio === null
  const campo = (k: keyof typeof f) => ({ value: f[k], onChange: (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value }) })

  return (
    // Marco compartido: X de cerrar, Escape, foco atrapado y bloqueo del scroll.
    <Dialogo
      titulo="Crear referencia"
      pendiente={crear.isPending}
      onCerrar={onCerrar}
      onEnviar={() => crear.mutate()}
      pie={(
        <>
          <BotonCancelar />
          <button type="submit" className="primario" disabled={!completo || crear.isPending}>{crear.isPending ? 'Guardando…' : 'Crear y agregar'}</button>
        </>
      )}
    >
      <DialogoSeccion>
        {/* Datos fijos: no son campos (no se enfocan ni se editan). */}
        <dl className="datos-fijos">
          <div>
            <dt>Código leído</dt>
            <dd><Candado />{codigo}<span className="solo-lector">, fijado por el escaneo</span></dd>
          </div>
          <div>
            <dt>Proveedor</dt>
            <dd><Candado />{proveedor.nombre}<span className="solo-lector">, fijado por el ingreso</span></dd>
          </div>
        </dl>
        <p className="ayuda">El código no existe para este proveedor. Se crea para el proveedor de este ingreso, con el código escaneado, y al guardarla se agrega el ítem.</p>
        <ControlSegmentado
          id="tipo-codigo-leido"
          etiqueta="¿Qué es el código leído?"
          opciones={TIPOS_CODIGO}
          valor={tipo}
          onCambio={(nuevo) => { setTipo(nuevo); setOtro(''); setOtroConsultado('') }}
        />
        <div className="codigos-referencia">
          {/* El campo del código leído es un dato fijo (no se enfoca); el otro, opcional y editable. */}
          {tipo === 'referencia' ? (
            <div className="codigo-fijo">
              <span className="etiqueta-campo">Referencia del proveedor</span>
              <span className="valor-fijo"><Candado />{codigo}<span className="solo-lector">, el código leído</span></span>
            </div>
          ) : (
            <>
              <label>Referencia del proveedor (opcional)
                <input maxLength={80} value={otro} autoComplete="off" aria-describedby="referencia-vacia-ayuda" onChange={(e) => setOtro(e.target.value)} onBlur={() => setOtroConsultado(otro)} />
              </label>
              <p id="referencia-vacia-ayuda" className="ayuda">Si el producto no trae otra referencia, déjela vacía y se usará el código de barras.</p>
            </>
          )}
          {tipo === 'barras' ? (
            <div className="codigo-fijo">
              <span className="etiqueta-campo">Código de barras o QR del producto</span>
              <span className="valor-fijo"><Candado />{codigo}<span className="solo-lector">, el código leído</span></span>
            </div>
          ) : (
            <label>Código de barras o QR del producto (si lo trae)
              <input maxLength={120} value={otro} autoComplete="off" onChange={(e) => setOtro(e.target.value)} onBlur={() => setOtroConsultado(otro)} />
            </label>
          )}
        </div>
        <AvisoCoincidencias codigos={[codigo, otroConsultado]} proveedorId={proveedor.id} />
        <SelectorCategoria
          selectRef={campoCategoria}
          autoFocus
          value={f.categoria_id}
          onChange={(categoria_id) => setF((v) => ({ ...v, categoria_id, codigo_universal_id: '' }))}
          alElegir={() => campoCalidad.current?.focus()}
        />
        <label>Calidad (opcional)
          <select ref={campoCalidad} {...campo('calidad')}>
            <option value="">—</option>
            <option value="fino">Fino</option>
            <option value="generico">Genérico</option>
          </select>
        </label>
        {admiteUniversal ? (
          // S-59: logística elige o crea el universal en línea, sin salir de la factura.
          <SelectorUniversal
            etiqueta="Código universal (opcional)"
            categoriaId={f.categoria_id}
            value={f.codigo_universal_id}
            onChange={(codigo_universal_id) => setF((v) => ({ ...v, codigo_universal_id }))}
          />
        ) : f.categoria_id !== '' && (
          <p className="ayuda">Esta categoría no usa código universal. Si este repuesto debería unificarse (regulador, portadiodo u otro que el administrador haya marcado), pídale que active esa opción en Proveedores y categorías.</p>
        )}
        <label>Descripción<input required maxLength={255} {...campo('descripcion')} /></label>
        <label>Precio de venta
          <CampoMoneda required aria-invalid={errorPrecio !== null} {...campo('precio_venta')} />
        </label>
        <p className={errorPrecio ? 'ayuda error' : 'ayuda'}>{errorPrecio ?? 'Con IVA incluido. Mayor que 0. Si no lo conoce, consúltelo con el administrador antes de crearla.'}</p>
        {crear.isError && <AvisoPagina tono="error">{mensajeError(crear.error)}</AvisoPagina>}
      </DialogoSeccion>
    </Dialogo>
  )
}
