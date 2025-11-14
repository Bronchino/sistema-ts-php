<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SistemaTS\TsXmlBuilder;
use SistemaTS\SistemaTsClient;
use function SistemaTS\json_response;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'POST' && $path === '/invia') {
    handle_invia();
} else {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found";
}

function handle_invia(): void
{
    try {
        $raw = file_get_contents('php://input');
        if ($raw === false) {
            throw new RuntimeException('Cannot read request body');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON');
        }

        $builder = new TsXmlBuilder();
        $xml = $builder->buildXml($data);

        // Salva XML temporaneo e crea ZIP
        $tmpDir = sys_get_temp_dir() . '/ts_' . bin2hex(random_bytes(4));
        if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            throw new RuntimeException("Failed to create temp dir");
        }

        $xmlPath = $tmpDir . '/file01.xml';
        $zipPath = $tmpDir . '/file01.zip';

        file_put_contents($xmlPath, $xml);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Cannot create ZIP file");
        }
        $zip->addFile($xmlPath, 'file01.xml');
        $zip->close();

        $environment = ($data['environment'] ?? 'TEST') === 'PROD' ? 'PROD' : 'TEST';

        $client = new SistemaTsClient($environment);
        $result = $client->inviaFile($zipPath, 'file01.zip', $data);

        json_response([
            'status'   => 'OK',
            'result'   => $result,
        ]);
    } catch (Throwable $e) {
        json_response([
            'status'  => 'ERROR',
            'message' => $e->getMessage(),
        ], 400);
    }
}
