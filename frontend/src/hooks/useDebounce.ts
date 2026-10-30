import { useEffect, useState } from 'react'

export function useDebounce<T>(valor: T, ms = 250): T {
  const [demorado, setDemorado] = useState(valor)

  useEffect(() => {
    const t = setTimeout(() => setDemorado(valor), ms)
    return () => clearTimeout(t)
  }, [valor, ms])

  return demorado
}
