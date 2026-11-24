import { useCallback, useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api } from '../../api/cliente'
import type { Auditoria as TAuditoria, Pagina } from '../../api/tipos'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import Icono from '../../componentes/Icono'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import Paginacion from '../../componentes/Paginacion'

const TABLAS_AUDITORIA = [
  { valor: 'referencias', etiqueta: 'Referencias', detalle: 'Catálogo de productos' },
  { valor: 'codigos_universales', etiqueta: 'Códigos universales', detalle: 'Códigos de unificación' },
  { valor: 'proveedores', etiqueta: 'Proveedores', detalle: 'Catálogo de proveedores' },
  { valor: 'categorias', etiqueta: 'Categorías', detalle: 'Tipos de repuesto' },
  { valor: 'clientes', etiqueta: 'Clientes', detalle: 'Datos de clientes' },
  { valor: 'ingresos', etiqueta: 'Ingresos', detalle: 'Facturas y remisiones' },
  { valor: 'ingreso_items', etiqueta: 'Líneas de ingreso', detalle: 'Ítems de compras' },
  { valor: 'users', etiqueta: 'Usuarios', detalle: 'Accesos del sistema' },
  { valor: 'venta_items', etiqueta: 'Ítems de venta', detalle: 'Precios en ventas a crédito' },
  { valor: 'configuraciones', etiqueta: 'Configuración', detalle: 'Stock mínimo general (RF-22)' },
] as const

/** RF-11: valor anterior, valor nuevo, usuario y fecha de cada edición. */
export default function Auditoria() {
  const [params, setParams] = useSearchParams()
  const tabla = params.get('tabla') ?? ''
  const registroId = params.get('registro_id') ?? ''

  const { filas, resultado, info, irA } = useListaPaginada<TAuditoria>({
    clave: ['auditoria', tabla, registroId],
    filtros: [tabla, registroId],
    traer: async (pagina) => (await api.get<Pagina<TAuditoria>>('/auditoria', {
      params: { tabla: tabla || undefined, registro_id: registroId || undefined, page: pagina },
    })).data,
  })

  const filtrar = (cambios: Record<string, string>) => {
    const p = { tabla, registro_id: registroId, ...cambios }
    setParams(Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '')))
  }

  const etiquetaTabla = TABLAS_AUDITORIA.find((t) => t.valor === tabla)?.etiqueta ?? 'Todas las tablas'

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Auditoría</h1>
        <p>Cada edición guarda el valor anterior, el nuevo, quién lo cambió y cuándo.</p>
      </div>
      <Resumen
        clave="auditoria-resumen"
        compacto={(
          <>
            <span><strong>{info ? info.total.toLocaleString('es-CO') : '—'}</strong> ediciones</span>
            <span>Pág. <strong>{info ? `${info.actual}/${info.ultima}` : '—'}</strong></span>
            <span>Tabla <strong>{tabla ? etiquetaTabla : 'Todas'}</strong></span>
            <span>Registro <strong>{registroId || '—'}</strong></span>
          </>
        )}
      >
        <Medida etiqueta="Ediciones" valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="azul" icono="auditoria" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="catalogo" />
        <Medida etiqueta="Tabla" valor={tabla ? etiquetaTabla : 'Todas'} tono="verde" icono="caja" codigo={!!tabla} />
        <Medida etiqueta="Registro" valor={registroId || '—'} tono="rojo" icono="buscar" />
      </Resumen>
      <section className="tarjeta-pagina">
        <div className="fila-titulo">
          <h2>Historial</h2>
          <div className="acciones">
            <FiltroTabla
              valor={tabla}
              onCambio={(t) => filtrar({ tabla: t, registro_id: '' })}
            />
            <label className="busca">
              <Icono nombre="buscar" />
              <input
                aria-label="Id del registro"
                placeholder="Id del registro"
                disabled={!tabla}
                value={registroId}
                onChange={(e) => filtrar({ registro_id: e.target.value })}
              />
            </label>
          </div>
        </div>
      {/* Scroll horizontal propio: la página nunca se desborda. En < 768 px, cada fila es una tarjeta. */}
      <div className="tabla-auditoria-scroll">
        <table className="tabla-auditoria">
          <colgroup>
            <col className="col-fecha" /><col className="col-usuario" /><col className="col-tabla" />
            <col className="col-registro" /><col className="col-campo" /><col /><col />
          </colgroup>
          <thead><tr><th>Fecha</th><th>Usuario</th><th>Tabla</th><th>Registro</th><th>Campo</th><th>Antes</th><th>Después</th></tr></thead>
          <tbody>
            {filas.map((a) => (
              <tr key={a.id}>
                <td data-etiqueta="Fecha">{new Date(a.created_at).toLocaleString()}</td>
                <td data-etiqueta="Usuario">{a.usuario?.name ?? 'sistema'}</td>
                <td data-etiqueta="Tabla">{a.tabla}</td>
                <td data-etiqueta="Registro">{a.registro_id}</td>
                <td data-etiqueta="Campo">{a.campo}</td>
                <td data-etiqueta="Antes" className="antes valor-auditoria">{a.valor_anterior}</td>
                <td data-etiqueta="Después" className="despues valor-auditoria">{a.valor_nuevo}</td>
              </tr>
            ))}
            {resultado && filas.length === 0 && <tr><td colSpan={7} className="vacio">Sin ediciones registradas.</td></tr>}
          </tbody>
        </table>
      </div>
      <Paginacion pagina={resultado} onCambiar={irA} />
    </section>
    </div>
  )
}

function normalizar(texto: string) {
  return texto.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase('es')
}

function inicialDe(texto: string) {
  const letra = texto.trim().charAt(0)
  return letra ? letra.toLocaleUpperCase('es') : '?'
}

function FiltroTabla({ valor, onCambio }: { valor: string; onCambio: (tabla: string) => void }) {
  const [abierto, setAbierto] = useState(false)
  const botonRef = useRef<HTMLButtonElement>(null)
  const yaAbrio = useRef(false)
  const elegida = TABLAS_AUDITORIA.find((t) => t.valor === valor)
  const cerrar = useCallback(() => setAbierto(false), [])
  const elegir = useCallback((tabla: string) => {
    onCambio(tabla)
    setAbierto(false)
  }, [onCambio])

  useEffect(() => {
    if (abierto) yaAbrio.current = true
    else if (yaAbrio.current) botonRef.current?.focus()
  }, [abierto])

  const etiqueta = elegida?.etiqueta ?? 'Todas las tablas'

  return (
    <>
      <button
        ref={botonRef}
        type="button"
        className={valor ? 'filtro-proveedor activo' : 'filtro-proveedor'}
        aria-haspopup="dialog"
        aria-expanded={abierto}
        aria-label={elegida ? `Tabla: ${elegida.etiqueta}. Cambiar filtro` : 'Filtrar por tabla'}
        title={etiqueta}
        onClick={() => setAbierto(true)}
      >
        <span className="filtro-proveedor-texto">{etiqueta}</span>
        <span className="filtro-proveedor-chevron"><Icono nombre="chevron" /></span>
      </button>
      {abierto && (
        <DialogoTablas
          valor={valor}
          onElegir={elegir}
          onCerrar={cerrar}
        />
      )}
    </>
  )
}

function DialogoTablas({
  valor,
  onElegir,
  onCerrar,
}: {
  valor: string
  onElegir: (tabla: string) => void
  onCerrar: () => void
}) {
  const [q, setQ] = useState('')
  const partes = normalizar(q.trim()).split(/\s+/).filter(Boolean)
  const visibles = TABLAS_AUDITORIA.filter((t) => {
    const texto = normalizar(`${t.etiqueta} ${t.valor} ${t.detalle}`)
    return partes.every((parte) => texto.includes(parte))
  })
  const elegidoRef = useRef<HTMLButtonElement>(null)
  const listaRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const fila = elegidoRef.current
    const lista = listaRef.current
    if (fila && lista) {
      const delta = fila.getBoundingClientRect().top - lista.getBoundingClientRect().top
      lista.scrollTop += delta - 64
    }
  }, [])

  const subtitulo = partes.length > 0
    ? `${visibles.length} ${visibles.length === 1 ? 'coincidencia' : 'coincidencias'}`
    : `${TABLAS_AUDITORIA.length} tablas con auditoría`

  return (
    <Dialogo
      titulo="Filtrar por tabla"
      descripcion={subtitulo}
      marca={<Icono nombre="auditoria" />}
      variante="proveedor"
      onCerrar={onCerrar}
    >
        <DialogoSeccion modo="selector">
          <label className="busca selector-busca">
            <Icono nombre="buscar" />
            <input
              autoFocus
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Buscar tabla"
              aria-label="Buscar tabla"
              autoComplete="off"
            />
          </label>

          <div className="selector-lista" ref={listaRef}>
            <button
              type="button"
              className={valor === '' ? 'selector-opcion fijo elegido' : 'selector-opcion fijo'}
              aria-current={valor === '' ? 'true' : undefined}
              onClick={() => onElegir('')}
            >
              <span className="selector-avatar" aria-hidden><Icono nombre="catalogo" /></span>
              <span className="selector-texto">
                <strong>Todas las tablas</strong>
                <small>Sin filtrar el historial</small>
              </span>
              {valor === '' && <MarcaElegido />}
            </button>
            <ul>
              {visibles.map((t) => {
                const activo = t.valor === valor
                return (
                  <li key={t.valor}>
                    <button
                      ref={activo ? elegidoRef : undefined}
                      type="button"
                      className={activo ? 'selector-opcion elegido' : 'selector-opcion'}
                      aria-current={activo ? 'true' : undefined}
                      onClick={() => onElegir(t.valor)}
                    >
                      <span className="selector-avatar" aria-hidden>{inicialDe(t.etiqueta)}</span>
                      <span className="selector-texto">
                        <strong>{t.etiqueta}</strong>
                        <small>{t.detalle} · {t.valor}</small>
                      </span>
                      {activo && <MarcaElegido />}
                    </button>
                  </li>
                )
              })}
              {visibles.length === 0 && (
                <li className="selector-estado">Ninguna tabla coincide con «{q.trim()}».</li>
              )}
            </ul>
          </div>
        </DialogoSeccion>
    </Dialogo>
  )
}

function MarcaElegido() {
  return (
    <svg className="selector-marca" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
      <path d="M5 12.5l4.2 4.2L19 7.5" />
    </svg>
  )
}