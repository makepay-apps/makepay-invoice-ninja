<?php
// Run from a trusted checkout: php scripts/install.php /absolute/path/to/invoiceninja
$target = realpath($argv[1] ?? '');
if (!$target || !is_file($target.'/artisan') || !is_file($target.'/app/Models/Gateway.php')) throw new RuntimeException('Pass an Invoice Ninja v5 application path.');
$root = dirname(__DIR__);
$modelPath = $target.'/app/Models/Gateway.php';
$model = file_get_contents($modelPath);
foreach (['getHelp', 'getMethods'] as $method) {
    if (str_contains($model, "// MakePay $method")) continue;
    $pattern = '/(public function '.$method.'\(\)(?:\s*:\s*array)?\s*\{)/';
    $guard = $method === 'getHelp'
        ? "\n        // MakePay getHelp\n        if (\$this->provider === 'MakePay') return 'https://github.com/makepay-apps/makepay-invoice-ninja';\n"
        : "\n        // MakePay getMethods\n        if (\$this->provider === 'MakePay') return [GatewayType::CRYPTO => ['refund' => false, 'token_billing' => false, 'webhooks' => ['makepay.payment.status_changed']]];\n";
    $model = preg_replace_callback($pattern, fn($m) => $m[1].$guard, $model, 1, $count);
    if ($count !== 1) throw new RuntimeException('Unsupported Gateway model; no files changed.');
}
if (!is_file($modelPath.'.makepay-backup')) copy($modelPath, $modelPath.'.makepay-backup');
file_put_contents($modelPath, $model);
foreach (['app/PaymentDrivers', 'resources/views'] as $folder) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $relative = substr($file->getPathname(), strlen($root)+1);
        if (!is_dir(dirname($target.'/'.$relative))) mkdir(dirname($target.'/'.$relative), 0755, true);
        copy($file->getPathname(), $target.'/'.$relative);
    }
}
require $target.'/vendor/autoload.php';
$app = require $target.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); });
$gateway = App\Models\Gateway::where('provider', 'MakePay')->first() ?? new App\Models\Gateway();
$gateway->provider = 'MakePay';
$gateway->name = 'MakePay';
$gateway->key = $gateway->key ?: substr(hash('sha256', 'makepay-invoice-ninja-v1'), 0, 32);
$gateway->is_offsite = true; $gateway->visible = true;
$gateway->site_url = 'https://www.makepay.io'; $gateway->default_gateway_type_id = App\Models\GatewayType::CRYPTO;
$gateway->fields = (object) ['companyId' => '', 'apiKeyId' => '', 'apiKeySecret' => '', 'webhookSecret' => ''];
$gateway->save();
Illuminate\Support\Facades\Artisan::call('optimize:clear');
echo "Installed MakePay. Open Settings > Payment Settings > New Gateway > MakePay.\n";
