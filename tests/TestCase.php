<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        // La conexión resuelta también contempla DB_URL, no solo DB_DATABASE.
        $connection = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        // Las pruebas usan el mismo motor que producción (bloqueos de filas, CHECK y FK compuestas).
        if (! app()->environment('testing') || $connection !== 'mysql' || $database !== 'mozistock_test') {
            throw new RuntimeException('Pest solo puede usar la base MySQL mozistock_test en entorno testing.');
        }

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
