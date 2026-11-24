<?php

use App\Enums\Rol;
use App\Enums\Calidad;
use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\Referencia;

/*
 * RF-12 / búsqueda por texto del catálogo (GET /api/referencias?q=, scope Referencia::buscarTexto).
 * Corre con DatabaseTruncation (tests/Pest.php): el índice FULLTEXT de InnoDB no ve filas sin
 * confirmar, así que dentro de la transacción de RefreshDatabase la búsqueda no encontraría nada.
 */

beforeEach(function () {
    actuarComo(Rol::Mostrador);
    $this->proveedor = Proveedor::factory()->create();
    $this->otroProveedor = Proveedor::factory()->create();
    $this->categoria = Categoria::factory()->create();

    $catalogo = [
        'A-001' => 'ALTERNADOR TOYOTA HILUX 12V 80A',
        'A-002' => 'ALTERNADOR TOYOTA COROLLA 12V 70A',
        'A-003' => 'ALTERNADOR ISUZU NQR REWARD 24V 80A',
        'A-004' => 'ALTERNADOR ISUZU NPR 24V',
        'B-001' => 'BENDIX TOYOTA HILUX',
        'B-002' => 'BENDIX DE ARRANQUE NQR CCW',
        'B-003' => 'BENDIX MAZDA B 2600',
        'M-001' => 'MOTOR DE ARRANQUE MOTOCARRO 175',
        'M-002' => 'MOTOR ARRANQUE RENAULT 9 12V',
        'P-001' => 'POLEA MAZDA 6 MAZDA 3',
        'P-002' => 'POLEA MAZDA 323',
        'S-001' => 'BOBINA IGNICIÓN CHEVROLET',
        'S-002' => 'SENSOR TEMPERATURA HAFEI',
        'S-003' => 'REGULADOR VOLTAJE UNIVERSAL',
        '12718N' => 'ALTERNADOR NQR POLEA W',
        'Z-900' => 'ARRANQUE COMPATIBLE CON 12718N',
    ];
    foreach ($catalogo as $codigo => $descripcion) {
        crearReferenciaBuscable($codigo, $descripcion);
    }
});

function crearReferenciaBuscable(string $codigo, string $descripcion): Referencia
{
    return Referencia::factory()->create([
        'codigo_referencia' => $codigo,
        'descripcion' => $descripcion,
        'proveedor_id' => test()->proveedor->id,
        'categoria_id' => test()->categoria->id,
    ]);
}

/** Códigos que devuelve el listado, en el orden de la respuesta. */
function codigosBuscados(string $q, array $extra = []): array
{
    $consulta = http_build_query(['q' => $q, 'por_pagina' => 100] + $extra);

    return collect(test()->getJson("/api/referencias?{$consulta}")->assertOk()->json('data'))
        ->pluck('codigo_referencia')->all();
}

it('RF-12 búsqueda: exige todas las palabras, no basta con una', function () {
    expect(codigosBuscados('alternador hilux'))->toBe(['A-001'])
        ->and(codigosBuscados('ALTERNADOR ISUZU NQR'))->toBe(['A-003']);
});

it('RF-12 búsqueda: acepta el comienzo de cada palabra', function () {
    expect(codigosBuscados('ALTERN'))->toEqualCanonicalizing(['A-001', 'A-002', 'A-003', 'A-004', '12718N'])
        ->and(codigosBuscados('altern hil'))->toBe(['A-001'])
        ->and(codigosBuscados('isu nqr'))->toBe(['A-003']);
});

it('RF-12 búsqueda: ordena por relevancia y no por código', function () {
    // Por código "S-003" iría antes que "Z-999"; con más apariciones de la palabra, "Z-999" va primero.
    crearReferenciaBuscable('Z-999', 'REGULADOR REGULADOR REGULADOR KIT');

    expect(codigosBuscados('regulador'))->toBe(['Z-999', 'S-003']);
});

it('RF-12 búsqueda: mantiene el prefijo del código y lo pone primero', function () {
    expect(codigosBuscados('12718'))->toBe(['12718N', 'Z-900'])
        ->and(codigosBuscados('A-00'))->toBe(['A-001', 'A-002', 'A-003', 'A-004'])
        ->and(codigosBuscados('p-001'))->toBe(['P-001']);
});

it('RF-12 búsqueda: palabras de menos de 3 letras se exigen igual', function () {
    expect(codigosBuscados('mazda 6'))->toBe(['P-001'])
        ->and(codigosBuscados('bendix B 2600'))->toBe(['B-003'])
        ->and(codigosBuscados('MOTOR ARRANQUE 9'))->toBe(['M-002']);
});

it('RF-12 búsqueda: las stopwords de InnoDB no rompen ni filtran de más', function () {
    // «de» es stopword: "motor de arranque" también trae "MOTOR ARRANQUE …".
    expect(codigosBuscados('motor de arranque'))->toEqualCanonicalizing(['M-001', 'M-002'])
        ->and(codigosBuscados('bendix de arranque'))->toBe(['B-002'])
        ->and(codigosBuscados('bendix arranque'))->toBe(['B-002'])
        // Solo stopwords: se buscan como texto en vez de devolver todo o nada.
        ->and(codigosBuscados('de'))->toEqualCanonicalizing(['B-002', 'M-001'])
        ->and(codigosBuscados('+de*'))->toEqualCanonicalizing(['B-002', 'M-001']);
});

it('RF-12 búsqueda: los caracteres del modo booleano no rompen la consulta', function (string $q) {
    $this->getJson('/api/referencias?'.http_build_query(['q' => $q]))->assertOk();
})->with(['+', '-', '*', '"', '(', ')', '<', '>', '~', '@', '+-*"()<>~@', '"alternador', 'alternador)',
    '@hilux', 'hilux @3', "o'reilly", '100%_', '\\', '%', 'ALTERNADOR*', '-hilux', '+de*', '+a* +b*']);

it('RF-12 búsqueda: ignora los operadores y busca las palabras que quedan', function () {
    expect(codigosBuscados('"(alternador)" <hilux> ~@'))->toBe(['A-001'])
        ->and(codigosBuscados('-hilux +alternador'))->toBe(['A-001'])
        ->and(codigosBuscados('100%'))->toBe([]);
});

it('RF-12 búsqueda: no distingue mayúsculas ni tildes', function () {
    expect(codigosBuscados('ALTERNADÓR HÍLUX'))->toBe(['A-001'])
        ->and(codigosBuscados('Alternador Hilux'))->toBe(['A-001'])
        ->and(codigosBuscados('ignicion'))->toBe(['S-001'])
        ->and(codigosBuscados('IGNICIÓN'))->toBe(['S-001']);
});

it('RF-12 búsqueda: la paginación y los filtros siguen funcionando con q', function () {
    Referencia::where('codigo_referencia', 'A-002')->update([
        'proveedor_id' => $this->otroProveedor->id,
        'calidad' => Calidad::Generico,
    ]);

    $this->getJson('/api/referencias?q=alternador&por_pagina=2')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.last_page', 3);

    expect(codigosBuscados('alternador', ['proveedor_id' => $this->otroProveedor->id]))->toBe(['A-002'])
        ->and(codigosBuscados('alternador', ['categoria_id' => $this->categoria->id]))->toHaveCount(5);
});

it('RF-12 búsqueda: sin q el listado sigue ordenado por código', function () {
    $codigos = codigosBuscados('');

    expect($codigos)->toHaveCount(16)
        ->and($codigos)->toBe(collect($codigos)->sort(SORT_STRING)->values()->all());
});

it('RF-12 búsqueda: el scope buscarTexto se puede reutilizar fuera del controlador', function () {
    expect(Referencia::query()->buscarTexto('alternador isuzu')->pluck('codigo_referencia')->all())
        ->toEqualCanonicalizing(['A-003', 'A-004'])
        ->and(Referencia::query()->buscarTexto('   ')->count())->toBe(16);
});
