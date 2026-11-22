<?php

use App\Enums\Rol;
use App\Models\Referencia;

/*
 * RF-12: búsqueda de respaldo por descripción. Corre con DatabaseTruncation (ver Pest.php):
 * el índice FULLTEXT de InnoDB no ve las filas que no se han confirmado.
 */

function refCon(string $codigo, string $descripcion, int $existencia = 0): Referencia
{
    $referencia = Referencia::factory()->create(['codigo_referencia' => $codigo, 'descripcion' => $descripcion]);
    if ($existencia > 0) {
        ingresar($referencia, $existencia);
    }

    return $referencia->refresh();
}

function codigosDe(string $q): array
{
    return collect(test()->getJson('/api/buscar?q='.urlencode($q))->assertOk()->json('referencias'))
        ->pluck('codigo_referencia')->all();
}

beforeEach(fn () => actuarComo(Rol::Mostrador));

it('RF-12: buscar por una palabra de la descripción encuentra la referencia', function () {
    refCon('1J0073', 'ALTERNADOR ISUZU NPR 24V', 1);
    refCon('IVR-606', 'REGULADOR KOMATSU 24V', 1);

    expect(codigosDe('ISUZU'))->toBe(['1J0073'])
        ->and(codigosDe('isuzu'))->toBe(['1J0073']);
});

it('RF-12: con varias palabras exige todas y acepta el comienzo de cada una', function () {
    refCon('13492', 'ALTERNADOR TOYOTA HILUX 12V', 1);
    refCon('26203', 'ALTERNADOR HINO 300 24V', 1);
    refCon('231-037', 'SWITCH PARQUEO HILUX', 1);

    expect(codigosDe('alternador hilux'))->toBe(['13492'])
        ->and(codigosDe('altern hil'))->toBe(['13492']);
});

it('RF-12: la respuesta trae la descripción y las unidades disponibles para elegir', function () {
    refCon('13492', 'ALTERNADOR TOYOTA HILUX 12V', 2);

    $this->getJson('/api/buscar?q=hilux')->assertOk()
        ->assertJsonPath('referencias.0.descripcion', 'ALTERNADOR TOYOTA HILUX 12V')
        ->assertJsonPath('referencias.0.existencia', 2)
        ->assertJsonCount(2, 'referencias.0.unidades_disponibles');
});

it('RF-12: las coincidencias de código van antes que las de descripción, aunque no tengan existencia', function () {
    refCon('OTRA-1', 'ARRANQUE ISUZU 12V', 3);
    refCon('ISUZU-77', 'REGULADOR GENERICO', 0);

    expect(codigosDe('ISUZU'))->toBe(['ISUZU-77', 'OTRA-1']);
});

it('RF-12: entre las coincidencias por descripción van primero las que tienen existencia', function () {
    // La agotada es la más relevante (repite la palabra), pero se prefiere la que se puede vender.
    refCon('AGOTADA', 'ALTERNADOR ISUZU ISUZU ISUZU', 0);
    refCon('CON-STOCK', 'ALTERNADOR ISUZU', 1);

    expect(codigosDe('ISUZU'))->toBe(['CON-STOCK', 'AGOTADA']);
});

it('RF-12: entre las coincidencias por código van primero las que tienen existencia', function () {
    refCon('HX-1', 'PIEZA UNO', 0);
    refCon('HX-2', 'PIEZA DOS', 1);

    expect(codigosDe('HX-'))->toBe(['HX-2', 'HX-1']);
});

it('RF-12: una referencia que coincide por código y por descripción sale una sola vez', function () {
    refCon('ISUZU-77', 'ALTERNADOR ISUZU', 1);

    expect(codigosDe('ISUZU'))->toBe(['ISUZU-77']);
});

it('RF-12: el total de referencias sigue limitado a 20 sumando código y descripción', function () {
    foreach (range(1, 15) as $i) {
        refCon("ISUZU-{$i}", 'PIEZA');
    }
    foreach (range(1, 15) as $i) {
        refCon("D-{$i}", 'ALTERNADOR ISUZU');
    }

    $codigos = codigosDe('ISUZU');

    expect($codigos)->toHaveCount(20)
        ->and(array_slice($codigos, 0, 15))->each->toStartWith('ISUZU-')
        ->and(array_slice($codigos, 15))->each->toStartWith('D-');
});

it('RF-12: un código de barras exacto devuelve la unidad sin mezclar descripciones', function () {
    $referencia = refCon('13492', 'ALTERNADOR TOYOTA HILUX', 1);
    $codigo = $referencia->unidades()->value('codigo_barras');
    // Otra referencia que menciona ese código en la descripción no debe aparecer.
    refCon('OTRA', "ALTERNADOR REEMPLAZA {$codigo}", 1);

    $this->getJson("/api/buscar?q={$codigo}")->assertOk()
        ->assertJsonPath('unidad.codigo_barras', $codigo)
        ->assertJsonCount(0, 'referencias');
});

it('RF-12: un término sin palabras buscables no falla', function () {
    refCon('13492', 'ALTERNADOR TOYOTA HILUX', 1);

    expect(codigosDe('--'))->toBe([]);
});
