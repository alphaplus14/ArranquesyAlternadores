{{-- RF-03: una etiqueta por página, tamaño {{ $tamano }} ({{ $medidas['ancho'] }}x{{ $medidas['alto'] }} mm) --}}
@php
    $alto = $medidas['alto'];
    $grande = $tamano === 'grande';
    $minima = $tamano === 'mas_pequeno';
    $fuente = $grande ? 9 : ($minima ? 5 : 6.5);
    $altoBarras = $grande ? $alto * 0.45 : $alto * 0.5;
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    * { margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: {{ $fuente }}pt; }
    .etiqueta { width: {{ $medidas['ancho'] }}mm; height: {{ $alto }}mm; padding: 1.2mm 1.5mm; box-sizing: border-box; overflow: hidden; text-align: center; page-break-after: always; }
    .etiqueta:last-child { page-break-after: auto; }
    .linea { white-space: nowrap; overflow: hidden; }
    .ref { font-weight: bold; }
    .barras { width: 100%; height: {{ $altoBarras }}mm; margin: 0.6mm 0; }
    .codigo { font-weight: bold; letter-spacing: 0.4pt; }
</style>
</head>
<body>
@foreach ($etiquetas as $e)
    <div class="etiqueta">
        <div class="linea ref">{{ $e['universal'] ? $e['universal'].' · ' : '' }}{{ $e['referencia'] }}@unless($minima) · {{ $e['marca'] }}@endunless</div>
        <img class="barras" src="data:image/png;base64,{{ $e['barras'] }}" alt="">
        <div class="linea codigo">{{ $e['codigo'] }}</div>
        @if ($grande)
            <div class="linea">{{ \Illuminate\Support\Str::limit($e['descripcion'], 60) }}</div>
        @endif
    </div>
@endforeach
</body>
</html>
