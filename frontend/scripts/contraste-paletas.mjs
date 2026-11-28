// S-70: comprueba que las 6 paletas de acento cumplan WCAG AA (4,5:1) en cada uso de la app.
// Uso: npm run test:contraste (sale con código 1 si alguna combinación baja de 4,5).
import { readFileSync } from 'node:fs'

const paletas = JSON.parse(readFileSync(new URL('../src/preferencias/paletas.json', import.meta.url), 'utf8'))

/** Fondos claros fijos de index.css sobre los que van los enlaces y textos en acento. */
const BLANCO = '#FFFFFF'
const FONDO = '#F4F6FB' // --fondo
const SUAVE_FONDO = '#EEF2FA' // --suave-fondo (hover de botones secundarios)
const RESALTE = '#F8C800' // --resalte: iniciales del avatar y opción elegida, sobre el acento
const MINIMO = 4.5

function luminancia(hex) {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

export function contraste(a, b) {
  const [l1, l2] = [luminancia(a), luminancia(b)].sort((x, y) => y - x)
  return (l1 + 0.05) / (l2 + 0.05)
}

let fallas = 0
for (const [clave, p] of Object.entries(paletas)) {
  const pares = [
    ['texto del botón primario sobre el acento', p.texto, p.acento],
    ['texto del botón primario sobre el hover', p.texto, p.hover],
    ['enlace (acento) sobre blanco', p.acento, BLANCO],
    ['enlace (acento) sobre el fondo', p.acento, FONDO],
    ['acento sobre suave-fondo', p.acento, SUAVE_FONDO],
    ['acento sobre su tono suave (chips, pestañas)', p.acento, p.suave],
    ['enlace en hover sobre blanco', p.hover, BLANCO],
    ['amarillo de la marca sobre el acento (avatar)', RESALTE, p.acento],
    // S-96: botón primario deshabilitado (texto en el acento sobre su tono suave).
    ['texto del botón primario deshabilitado', p.acento, p.suave],
  ]
  for (const [uso, frente, fondo] of pares) {
    const c = contraste(frente, fondo)
    const ok = c >= MINIMO
    if (!ok) fallas++
    console.log(`${ok ? 'ok   ' : 'FALLA'} ${clave.padEnd(8)} ${c.toFixed(2).padStart(6)}:1  ${uso}`)
  }
}

// S-96: botón de peligro deshabilitado (colores fijos de index.css: #B00000 sobre --error-fondo).
for (const [uso, frente, fondo] of [
  ['texto del botón de peligro', '#FFFFFF', '#D80808'],
  ['texto del botón de peligro deshabilitado', '#B00000', '#FDE8E7'],
]) {
  const c = contraste(frente, fondo)
  const ok = c >= MINIMO
  if (!ok) fallas++
  console.log(`${ok ? 'ok   ' : 'FALLA'} fijo     ${c.toFixed(2).padStart(6)}:1  ${uso}`)
}

if (fallas > 0) {
  console.error(`\n${fallas} combinación(es) por debajo de ${MINIMO}:1.`)
  process.exit(1)
}
console.log(`\nLas ${Object.keys(paletas).length} paletas cumplen WCAG AA (≥ ${MINIMO}:1).`)
