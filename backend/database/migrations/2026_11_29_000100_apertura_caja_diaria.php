<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RF-25 (S-49 reescrito): el saldo inicial de caja varía cada día. Se reemplaza el saldo inicial
 * único (configuraciones `saldo_caja_inicial` y `saldo_caja_inicial_fecha`) por una APERTURA DIARIA
 * que registra el mostrador.
 *
 * - aperturas_caja: una por fecha (UNIQUE, que también es el índice por fecha), valor >= 0,
 *   usuario que la fijó o la corrigió por última vez. Auditable (la corrección deja valor anterior
 *   y nuevo). No hay DELETE.
 * - cierres_caja + apertura (nullable: los cierres anteriores no la tienen y no se reescriben).
 *   En los cierres nuevos: efectivo_esperado = apertura + efectivo neto del día, y para no romper
 *   la lectura de las columnas viejas, saldo_anterior = apertura y saldo_acumulado = efectivo_esperado.
 *   En los cierres viejos esas dos columnas conservan el saldo encadenado de antes.
 * - Se borran las claves del saldo inicial único de configuraciones (dato muerto: nadie las lee).
 */
return new class extends Migration
{
    private const CLAVES_SALDO_INICIAL = ['saldo_caja_inicial', 'saldo_caja_inicial_fecha'];

    public function up(): void
    {
        Schema::create('aperturas_caja', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->decimal('valor', 14, 2);
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE aperturas_caja ADD CONSTRAINT aperturas_caja_valor_no_negativo CHECK (valor >= 0)');

        Schema::table('cierres_caja', function (Blueprint $table) {
            $table->decimal('apertura', 14, 2)->nullable()->after('fecha');
        });

        DB::table('configuraciones')->whereIn('clave', self::CLAVES_SALDO_INICIAL)->delete();
    }

    public function down(): void
    {
        Schema::table('cierres_caja', function (Blueprint $table) {
            $table->dropColumn('apertura');
        });

        Schema::dropIfExists('aperturas_caja');
        // Las claves del saldo inicial único no se restauran: su valor ya no se conoce.
    }
};
