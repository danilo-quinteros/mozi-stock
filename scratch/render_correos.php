<?php

// Renderiza los correos a HTML para revisión visual. Sin base de datos: modelos sin guardar.
// Uso: php scratch/render_correos.php <directorio_salida>

use App\Enums\EventoAviso;
use App\Mail\AvisoVencimientoSuscripcion;
use App\Models\AjusteGlobal;
use App\Models\Empresa;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Markdown;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app()->setLocale('es');
config(['app.url' => 'http://127.0.0.1:8123']);
url()->forceRootUrl('http://127.0.0.1:8123');

$out = $argv[1];
$usuario = new User(['name' => 'Demo', 'email' => 'demo@example.test']);
$usuario->id = 1;

$markdown = app(Markdown::class);
$verifica = (new VerifyEmail)->toMail($usuario);
file_put_contents("$out/verificar.html", (string) $markdown->render($verifica->markdown, $verifica->data()));
$reset = (new ResetPassword('token-demo'))->toMail($usuario);
file_put_contents("$out/restablecer.html", (string) $markdown->render($reset->markdown, $reset->data()));

$empresa = new Empresa(['nombre' => 'Ferretería Demo']);
$suscripcion = new Suscripcion;
$suscripcion->vencimiento = now()->addDays(3)->toImmutable();
$suscripcion->precio_mensual = '149.00';
$ajustes = new AjusteGlobal(['receptor_nombre' => 'MoziStudios']);
$eventos = EventoAviso::cases();
foreach ([false, true] as $revision) {
    $mail = new AvisoVencimientoSuscripcion($empresa, $suscripcion, $eventos[0], $revision, $ajustes);
    file_put_contents("$out/aviso".($revision ? '-revision' : '').'.html', $mail->render());
}
echo "ok\n";
