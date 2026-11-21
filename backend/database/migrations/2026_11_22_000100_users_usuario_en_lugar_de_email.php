<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Autenticación (no es un RF): se inicia sesión con nombre de usuario, no con correo.
 *
 * - Convierte cada correo en usuario: parte anterior a "@", sin tildes, en minúsculas y solo
 *   con [a-z0-9._-], máximo 30. Si queda con menos de 3 caracteres se completa con el id.
 *   Si dos quedan iguales, el segundo lleva sufijo numérico (admin, admin2, admin3…).
 * - Renombra users.email a users.usuario (string 40, único) y quita email_verified_at.
 * - down(): email = usuario || '@la8va.local'.
 *
 * password_reset_tokens.email no se toca: el restablecimiento por correo no se usa
 * (las claves se restablecen con `usuarios:clave`).
 */
return new class extends Migration
{
    private const MIN = 3;

    private const MAX = 30;

    public function up(): void
    {
        $usados = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'email']) as $fila) {
            $base = $this->base((string) $fila->email, (int) $fila->id);
            $usuario = $base;

            for ($n = 2; isset($usados[$usuario]); $n++) {
                $sufijo = (string) $n;
                $usuario = substr($base, 0, self::MAX - strlen($sufijo)).$sufijo;
            }

            $usados[$usuario] = true;
            DB::table('users')->where('id', $fila->id)->update(['email' => $usuario]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn('email_verified_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('email', 'usuario');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('usuario', 40)->change();
            $table->unique('usuario');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['usuario']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('usuario', 'email');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 255)->change();
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });

        DB::table('users')->update(['email' => DB::raw("CONCAT(email, '@la8va.local')")]);

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }

    private function base(string $correo, int $id): string
    {
        $local = Str::before($correo, '@');
        $limpio = preg_replace('/[^a-z0-9._-]/', '', strtolower(Str::ascii($local)));
        $limpio = substr($limpio, 0, self::MAX);

        if (strlen($limpio) < self::MIN) {
            $limpio = substr(($limpio === '' ? 'usuario' : $limpio).'_'.$id, 0, self::MAX);
        }

        return $limpio;
    }
};
