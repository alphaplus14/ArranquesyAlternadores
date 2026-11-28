import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import TarjetaDescuento from './TarjetaDescuento'
import type { CondicionDescuento } from './condicionDescuento'

// S-95: la tarjeta "Descuento" (chip, control y zona C) y la confirmación en línea.
describe('S-95: TarjetaDescuento', () => {
  const casos: Array<[CondicionDescuento, string, string]> = [
    ['documento', 'Del documento', 'Valor del descuento'],
    ['producto', 'Por producto', 'Edite el % de cada ítem en la tabla.'],
    ['ninguno', 'Sin descuento', 'Sin descuento; elija una condición para darlo.'],
    ['mixto', 'Mixto (anterior)', 'Documento: $ 67.500 · Ítems: 10 % en 5 ítems'],
  ]

  it.each(casos)('condición %s: chip «%s» y su zona C', (condicion, chip, zonaC) => {
    render(
      <TarjetaDescuento
        id="t"
        condicion={condicion}
        onElegir={() => {}}
        resumenMixto="Documento: $ 67.500 · Ítems: 10 % en 5 ítems"
        campoDocumento={<label>Valor del descuento<input /></label>}
      />,
    )
    const tarjeta = screen.getByRole('region', { name: 'Descuento del documento' })
    expect(within(tarjeta).getAllByText(chip)[0].className).toContain('chip')
    expect(within(tarjeta).getByText(zonaC)).toBeTruthy()

    const opciones = within(tarjeta).getAllByRole('radio')
    expect(opciones.map((o) => o.textContent)).toEqual(['Sin descuento', 'Del documento', 'Por producto'])
    const elegidas = opciones.filter((o) => o.getAttribute('aria-checked') === 'true')
    if (condicion === 'mixto') {
      // Ninguna opción activa; el grupo se describe con la línea de ayuda.
      expect(elegidas).toHaveLength(0)
      expect(within(tarjeta).getByRole('radiogroup').getAttribute('aria-describedby')).toBe('t-mixto')
      expect(within(tarjeta).getAllByText(chip)[0].className).toContain('atencion')
    } else {
      expect(elegidas).toHaveLength(1)
    }
  })

  it('la confirmación en línea: foco en «Cambiar», Escape = «Mantener», la opción pulsada queda pendiente', async () => {
    const usuario = userEvent.setup()
    const onCambiar = vi.fn()
    const onMantener = vi.fn()
    render(
      <TarjetaDescuento
        id="t"
        condicion="mixto"
        onElegir={() => {}}
        cambio={{ nueva: 'documento', texto: 'Cambiar a Del documento quitará los descuentos de 5 ítems (10 %).' }}
        onCambiar={onCambiar}
        onMantener={onMantener}
        resumenMixto="Documento: $ 67.500 · Ítems: 10 % en 5 ítems"
      />,
    )
    const confirmacion = screen.getByRole('status')
    expect(confirmacion.textContent).toContain('quitará los descuentos de 5 ítems')
    expect(document.activeElement).toBe(within(confirmacion).getByRole('button', { name: 'Cambiar' }))
    expect(screen.getByRole('radio', { name: 'Del documento' }).className).toContain('pendiente')
    // La zona C no cambia mientras se confirma.
    expect(screen.getByText('Documento: $ 67.500 · Ítems: 10 % en 5 ítems')).toBeTruthy()
    // Un solo «Cancelar» en pantalla: la confirmación usa «Mantener».
    expect(within(confirmacion).queryByRole('button', { name: 'Cancelar' })).toBeNull()

    await usuario.keyboard('{Escape}')
    expect(onMantener).toHaveBeenCalledTimes(1)
    await usuario.click(within(confirmacion).getByRole('button', { name: 'Cambiar' }))
    expect(onCambiar).toHaveBeenCalledTimes(1)
  })

  it('en solo lectura no hay control de condición', () => {
    render(<TarjetaDescuento id="t" condicion="producto" soloLectura contenido={<p>texto propio</p>} />)
    expect(screen.queryByRole('radiogroup')).toBeNull()
    expect(screen.getByText('Por producto')).toBeTruthy()
    expect(screen.getByText('texto propio')).toBeTruthy()
  })
})
