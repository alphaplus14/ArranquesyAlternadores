import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import type { Ingreso } from '../../api/tipos'
import { AvisosProvider } from '../../componentes/Avisos'

// S-95 (RF-28): cambio de condición del descuento en la corrección, solo presentación.
vi.mock('../../auth/AuthProvider', () => ({ useAuth: () => ({ tieneRol: () => true, usuario: { rol: 'logistica' } }) }))

import { PanelCorreccion } from './PanelCorreccion'

/** Factura mixta como la 22512: descuento del documento 67.500 y 10 % en dos líneas. */
function ingresoMixto(): Ingreso {
  const linea = (id: number, codigo: string) => ({
    id, referencia_id: id, cantidad: 2, costo_unitario: '100000.00', descuento_porcentaje: '10.00', iva_porcentaje: '19.00',
    referencia: { codigo_referencia: codigo, descripcion: 'Pieza' },
  })
  return {
    id: 41, tipo: 'factura', numero_documento: '22512', fecha: '2026-11-26', forma_pago: 'credito', vence_el: '2026-12-26',
    descuento_documento: '67500.00', factura: null, proveedor: { id: 1, nombre: 'Molpartes SAS' }, items: [linea(1, 'A-1'), linea(2, 'B-2')],
  } as unknown as Ingreso
}

function montar() {
  const qc = new QueryClient()
  render(
    <MemoryRouter>
      <QueryClientProvider client={qc}>
        <AvisosProvider><PanelCorreccion ingreso={ingresoMixto()} onCancelar={() => {}} onCorregido={() => {}} /></AvisosProvider>
      </QueryClientProvider>
    </MemoryRouter>,
  )
  return { usuario: userEvent.setup(), revisar: screen.getByRole('button', { name: 'Revisar cambios' }) }
}

const confirmacion = () => document.querySelector<HTMLElement>('.confirmar-condicion')!
const descuentoDe = (codigo: string) => screen.getByRole('cell', { name: (_, el) => el.closest('tr')?.textContent?.includes(codigo) === true && el.getAttribute('data-label') === 'Desc. %' })

describe('S-95: condición del descuento en la corrección', () => {
  it('un documento mixto abre en «Mixto (anterior)» con resumen de solo lectura y sin opción activa', () => {
    montar()
    // S-96: sin título "Corregir factura…"; el aviso de auditoría es flotante (se va solo a los 5 s).
    expect(screen.queryByRole('heading', { name: /Corregir/ })).toBeNull()
    expect(document.querySelector('.aviso-flotante')?.textContent).toContain('La corrección queda auditada')
    const tarjeta = screen.getByRole('region', { name: 'Descuento del documento' })
    expect(within(tarjeta).getByText('Mixto (anterior)')).toBeTruthy()
    expect(within(tarjeta).getAllByRole('radio').some((r) => r.getAttribute('aria-checked') === 'true')).toBe(false)
    expect(within(tarjeta).getByText(/Documento: .*67\.500 · Ítems: 10 % en 2 ítems/)).toBeTruthy()
    // Desc. % en texto, sin campo.
    expect(within(descuentoDe('A-1')).queryByRole('textbox')).toBeNull()
    expect(descuentoDe('A-1').textContent).toBe('10 %')
  })

  it('«Mantener» y Escape cierran la confirmación sin cambios; mientras está abierta no se revisa', async () => {
    const { usuario, revisar } = montar()
    await usuario.click(screen.getByRole('radio', { name: 'Del documento' }))

    const caja = confirmacion()
    expect(caja.textContent).toBe('Cambiar a Del documento quitará los descuentos de 2 ítems (10 %).CambiarMantener')
    expect((revisar as HTMLButtonElement).disabled).toBe(true)
    expect(revisar.getAttribute('title')).toBe('Termine el cambio de condición')
    expect(screen.queryByText(/Confirme o cancele/)).toBeNull()

    await usuario.click(within(caja).getByRole('button', { name: 'Mantener' }))
    expect(document.querySelector('.confirmar-condicion')).toBeNull()
    expect(screen.getByText('Mixto (anterior)')).toBeTruthy()
    expect(descuentoDe('A-1').textContent).toBe('10 %')

    await usuario.click(screen.getByRole('radio', { name: 'Por producto' }))
    expect(confirmacion()).toBeTruthy()
    await usuario.keyboard('{Escape}')
    expect(document.querySelector('.confirmar-condicion')).toBeNull()
    expect(screen.getByText('Mixto (anterior)')).toBeTruthy()
  })

  it('«Cambiar» a Del documento pone en 0 las líneas y muestra el campo $ / %; volver a Por producto lo quita y devuelve los %', async () => {
    const { usuario } = montar()
    await usuario.click(screen.getByRole('radio', { name: 'Del documento' }))
    await usuario.click(screen.getByRole('button', { name: 'Cambiar' }))

    expect(screen.getByRole('radio', { name: 'Del documento' }).getAttribute('aria-checked')).toBe('true')
    expect(screen.getByLabelText('Unidad del descuento')).toBeTruthy()
    expect(descuentoDe('A-1').textContent).toBe('—')
    expect(descuentoDe('B-2').textContent).toBe('—')

    // De documento a producto: se avisa que se quita el del documento y, al aplicar, vuelven los % de las líneas.
    await usuario.click(screen.getByRole('radio', { name: 'Por producto' }))
    expect(confirmacion().textContent).toContain('quitará el descuento del documento')
    await usuario.click(screen.getByRole('button', { name: 'Cambiar' }))
    expect(screen.queryByLabelText('Unidad del descuento')).toBeNull()
    expect(within(descuentoDe('A-1')).getByRole('textbox')).toHaveProperty('value', '10')
  })
})
