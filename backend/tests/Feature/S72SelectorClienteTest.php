<?php

use App\Enums\Rol;
use App\Models\Cliente;

/** @return list<string> */
function nombres(\Illuminate\Testing\TestResponse $respuesta): array
{
    // Las colecciones sin paginar salen sin envoltura `data` (JsonResource::withoutWrapping).
    return collect($respuesta->assertOk()->json())->pluck('nombre')->all();
}

it('S-72: un cliente inactivo no sale en la búsqueda', function () {
    actuarComo(Rol::Mostrador);
    Cliente::factory()->create(['nombre' => 'Faber Inactivo', 'documento' => '555111', 'activo' => false]);

    expect($this->getJson('/api/clientes?q=faber&limite=8')->json())->toBe([]);
    expect($this->getJson('/api/clientes?q=555&limite=8')->json())->toBe([]);
});

it('S-72: la búsqueda por nombre contiene el texto, sin distinguir mayúsculas ni tildes, con máximo 8', function () {
    actuarComo(Rol::Mostrador);
    Cliente::factory()->create(['nombre' => 'Taller Fáber']);
    Cliente::factory()->create(['nombre' => 'FABER repuestos']);
    Cliente::factory()->create(['nombre' => 'Otro']);
    foreach (range(1, 10) as $i) {
        Cliente::factory()->create(['nombre' => "Motos {$i}"]);
    }

    expect(nombres($this->getJson('/api/clientes?q=fab&limite=8')))->toBe(['FABER repuestos', 'Taller Fáber']); // primero el que empieza por el texto

    expect($this->getJson('/api/clientes?q=MOTOS&limite=8')->json())->toHaveCount(8);
    expect($this->getJson('/api/clientes?q=%25%25&limite=8')->json())->toBe([]); // % es literal
});

it('S-72: la búsqueda por documento es por prefijo y solo con dígitos; el exacto va primero', function () {
    actuarComo(Rol::Mostrador);
    $faber = Cliente::factory()->create(['nombre' => 'Zeta Faber', 'documento' => '11982790291']);
    Cliente::factory()->create(['nombre' => 'Alfa', 'documento' => '119827902915']);
    Cliente::factory()->create(['nombre' => 'Contiene 1198 en el nombre no', 'documento' => '900']);

    expect(nombres($this->getJson('/api/clientes?q=1198279&limite=8')))->toBe(['Alfa', 'Zeta Faber']);

    $this->getJson('/api/clientes?q=11982790291&limite=8')->assertOk()
        ->assertJsonPath('0.id', $faber->id);

    // Un documento que no existe no devuelve nada (el frontend ofrece crearlo).
    expect($this->getJson('/api/clientes?q=2122122112&limite=8')->json())->toBe([]);
    // El documento no se busca "contiene": 279 no es prefijo de ninguno.
    expect($this->getJson('/api/clientes?q=279&limite=8')->json())->toBe([]);
});

it('S-72: el listado paginado no cambia y filtra por documento exacto, activo o no', function () {
    actuarComo(Rol::Mostrador);
    Cliente::factory()->create(['nombre' => 'Taller Fáber']);
    $inactivo = Cliente::factory()->create(['nombre' => 'Viejo', 'documento' => '777', 'activo' => false]);

    // Sin `limite` sigue siendo el listado paginado por prefijo de nombre.
    $this->getJson('/api/clientes?q=fab')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/clientes?q=Taller')->assertOk()->assertJsonPath('meta.total', 1);

    $this->getJson('/api/clientes?documento=777')->assertOk()
        ->assertJsonPath('data.0.id', $inactivo->id)
        ->assertJsonPath('data.0.activo', false);
    $this->getJson('/api/clientes?documento=77')->assertOk()->assertJsonPath('meta.total', 0);
});

it('S-72: un documento repetido se rechaza con el mensaje que reconoce el selector', function () {
    actuarComo(Rol::Mostrador);
    Cliente::factory()->create(['nombre' => 'Faber', 'documento' => '11982790291']);

    $this->postJson('/api/clientes', ['nombre' => 'Duplicado', 'documento' => '11982790291'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.documento.0', 'Ya existe un cliente con ese documento.');

    expect(Cliente::where('documento', '11982790291')->count())->toBe(1);
});

it('S-72: los permisos de crear no cambian: mostrador y admin crean; logística y contable no', function () {
    actuarComo(Rol::Mostrador);
    $this->postJson('/api/clientes', ['nombre' => 'Nuevo de mostrador', 'documento' => '2122122112'])->assertCreated();

    actuarComo(Rol::Admin);
    $this->postJson('/api/clientes', ['nombre' => 'Nuevo de admin'])->assertCreated();

    actuarComo(Rol::Logistica);
    $this->postJson('/api/clientes', ['nombre' => 'X'])->assertForbidden();

    actuarComo(Rol::Contable);
    $this->postJson('/api/clientes', ['nombre' => 'X'])->assertForbidden();
});

it('S-72: la búsqueda rápida exige al menos 2 caracteres (422) y nunca trae más de 8', function () {
    actuarComo(Rol::Mostrador);
    foreach (range(1, 12) as $i) {
        Cliente::factory()->create(['nombre' => "Faber {$i}"]);
    }

    // Sin texto, con 1 carácter o con solo espacios alrededor de 1: 422, nunca el listado completo.
    $this->getJson('/api/clientes?limite=8')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/clientes?q=f&limite=8')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/clientes?q=%20%20f%20&limite=8')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/clientes?q=1&limite=8')->assertUnprocessable()->assertJsonValidationErrors('q');

    // Con 2 caracteres (letras o números) ya busca, hasta 8.
    expect($this->getJson('/api/clientes?q=fa&limite=8')->assertOk()->json())->toHaveCount(8);

    // El tope es 8: pedir más es 422.
    $this->getJson('/api/clientes?q=fa&limite=9')->assertUnprocessable()->assertJsonValidationErrors('limite');
    $this->getJson('/api/clientes?q=fa&limite=100')->assertUnprocessable()->assertJsonValidationErrors('limite');
});

it('S-72: el endpoint de clientes recientes ya no existe', function () {
    actuarComo(Rol::Mostrador);

    // /clientes/{cliente} solo acepta PUT y PATCH: un GET a "recientes" ya no trae datos.
    expect($this->getJson('/api/clientes/recientes')->status())->toBeIn([404, 405]);
});

it('S-72: el listado paginado de admin sigue aceptando 1 carácter (no es la búsqueda rápida)', function () {
    actuarComo(Rol::Admin);
    Cliente::factory()->create(['nombre' => 'Faber']);

    $this->getJson('/api/clientes?q=f')->assertOk()->assertJsonPath('meta.total', 1);
});
