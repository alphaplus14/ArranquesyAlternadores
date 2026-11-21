import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, mensajeError } from '../../api/cliente'
import type { Ingreso } from '../../api/tipos'
import Medida from '../../componentes/Medida'
import { useProveedores } from '../../hooks/useCatalogos'
import { useScanner } from '../../hooks/useScanner'

type Campo = 'proveedor_id' | 'numero_documento' | 'fecha'

/**
 * RF-04: logística escanea el QR de la factura de compra. El backend la ubica por CUFE:
 * si es nueva crea el borrador con el encabezado, si está en borrador lo reabre, y si ya se
 * confirmó la rechaza. Los ítems se digitan después, como en la remisión.
 * Si el QR no trae proveedor, número o fecha, se piden aquí y se reenvía el mismo contenido.
 */
export default function IngresoQr() {
  const navegar = useNavigate()
  const proveedores = useProveedores()
  const inputRef = useRef<HTMLInputElement>(null)
  const [pendiente, setPendiente] = useState<{ contenido: string; faltan: Campo[] } | null>(null)
  const [complemento, setComplemento] = useState({ proveedor_id: '', numero_documento: '', fecha: new Date().toISOString().slice(0, 10) })

  const leer = useMutation({
    mutationFn: async (datos: { contenido: string } & Partial<Record<Campo, string>>) =>
      (await api.post<Ingreso>('/ingresos/qr', datos)).data,
    onSuccess: (i) => navegar(`/logistica/ingresos/${i.id}`),
    onError: (e, datos) => {
      const errores = e instanceof AxiosError ? (e.response?.data as { errors?: Record<string, string[]> })?.errors : undefined
      const faltan = (['proveedor_id', 'numero_documento', 'fecha'] as Campo[]).filter((c) => errores?.[c])
      if (faltan.length > 0) setPendiente({ contenido: datos.contenido, faltan })
    },
  })

  const onCodigo = useCallback(
    (contenido: string) => {
      if (inputRef.current) inputRef.current.value = ''
      setPendiente(null)
      leer.mutate({ contenido })
    },
    [leer],
  )
  const scanner = useScanner(onCodigo, { multilinea: true })

  useEffect(() => {
    if (!pendiente) inputRef.current?.focus()
  }, [pendiente, leer.isPending])

  const pide = (c: Campo) => pendiente?.faltan.includes(c)

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Factura con QR</h1>
        <p>El QR llena el encabezado. Los ítems se digitan en el siguiente paso.</p>
      </div>
      <section className="medidas">
        <Medida etiqueta="Lectura" valor={leer.isPending ? 'Buscando' : pendiente ? 'Incompleta' : 'Lista'} tono="azul" icono="qr" />
        <Medida etiqueta="Datos que faltan" valor={pendiente?.faltan.length ?? 0} tono={pendiente ? 'rojo' : 'verde'} icono="agotados" />
        <Medida etiqueta="Proveedores" valor={proveedores.data?.length ?? '—'} tono="amarillo" icono="catalogo" />
        <Medida etiqueta="Siguiente paso" valor="Ítems" pie="Igual que la remisión" tono="verde" icono="ingreso" />
      </section>
    <div className="panel">
      <h2>Escanear la factura</h2>

      <input
        ref={inputRef}
        className="escaner"
        placeholder="Escanee el QR de la factura"
        onKeyDown={scanner.onKeyDown}
        onPaste={scanner.onPaste}
        disabled={leer.isPending || pendiente !== null}
        autoComplete="off"
      />
      {leer.isPending && <div className="aviso" role="status">Buscando la factura…</div>}
      {leer.isError && !pendiente && <div className="aviso error" role="alert">{mensajeError(leer.error)}</div>}

      {pendiente && (
        <form
          className="formulario en-linea"
          onSubmit={(e) => {
            e.preventDefault()
            leer.mutate({ contenido: pendiente.contenido, ...complemento })
          }}
        >
          <div className="aviso error" role="alert">El QR no trae todos los datos de la factura. Complételos:</div>
          {pide('proveedor_id') && (
            <label>Proveedor
              <select required value={complemento.proveedor_id} onChange={(e) => setComplemento({ ...complemento, proveedor_id: e.target.value })}>
                <option value="">—</option>
                {proveedores.data?.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
              </select>
            </label>
          )}
          {pide('numero_documento') && (
            <label>N.º de factura<input required value={complemento.numero_documento} onChange={(e) => setComplemento({ ...complemento, numero_documento: e.target.value })} /></label>
          )}
          {pide('fecha') && (
            <label>Fecha<input type="date" required value={complemento.fecha} onChange={(e) => setComplemento({ ...complemento, fecha: e.target.value })} /></label>
          )}
          <button className="primario" disabled={leer.isPending}>Continuar</button>
          <button type="button" className="secundario" onClick={() => setPendiente(null)}>Escanear otra</button>
        </form>
      )}
    </div>
    </div>
  )
}
