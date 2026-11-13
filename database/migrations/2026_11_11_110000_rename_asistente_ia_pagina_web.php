<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * La página «Asistente de IA» pasó a «Agente de IA» (clave y URL agente-ia). El SEO que el
     * superadmin ya hubiera editado se conserva con la clave nueva.
     */
    public function up(): void
    {
        if (! DB::table('paginas_web')->where('clave', 'agente-ia')->exists()) {
            DB::table('paginas_web')->where('clave', 'asistente-ia')->update(['clave' => 'agente-ia']);
        }
    }

    public function down(): void
    {
        if (! DB::table('paginas_web')->where('clave', 'asistente-ia')->exists()) {
            DB::table('paginas_web')->where('clave', 'agente-ia')->update(['clave' => 'asistente-ia']);
        }
    }
};
