<?php
declare(strict_types=1);

namespace SistemaTS;

class SistemaTsClient
{
    public const ENDPOINT_TEST  = 'https://invioSS730pTest.sanita.finanze.it/InvioTelematicoSS730pMtomWeb/InvioTelematicoSS730pMtomPort';
    public const ENDPOINT_PROD  = 'https://invioSS730p.sanita.finanze.it/InvioTelematicoSS730pMtomWeb/InvioTelematicoSS730pMtomPort';

    public function __construct(
        private readonly string $environment = 'TEST'
    ) {}

    /**
     * @param string $zipPath           Path al file ZIP da inviare
     * @param string $nomeFileAllegato  Es. "file01.zip"
     * @param array<string, mixed> $payload TsSpeseRequest completo (per pincode, proprietario, ecc.)
     * @return array<string, mixed>    Risposta normalizzata
     */
    public function inviaFile(string $zipPath, string $nomeFileAllegato, array $payload): array
    {
        if (!is_readable($zipPath)) {
            throw new \RuntimeException("ZIP file not readable: {$zipPath}");
        }

        $endpoint = $this->environment === 'PROD'
            ? self::ENDPOINT_PROD
            : self::ENDPOINT_TEST;

        $pincode = $payload['inviante']['pincodeInviante'] ?? '';
        $proprietario = $payload['proprietario'] ?? [];

        // NB: qui il pincode è ancora "non cifrato". Dovrà applicare le regole TS.
        $bodyXml = $this->buildSoapBody($nomeFileAllegato, $pincode, $proprietario);

        $zipData = file_get_contents($zipPath);
        if ($zipData === false) {
            throw new \RuntimeException("Cannot read ZIP data");
        }

        $boundary = 'MIMEBoundary_' . bin2hex(random_bytes(8));
        $cidRoot  = 'root.message@ts';
        $cidFile  = 'file.zip@ts';

        $multipartBody =
            "--{$boundary}\r\n" .
            "Content-Type: application/xop+xml; charset=UTF-8; type=\"text/xml\"\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n" .
            "Content-ID: <{$cidRoot}>\r\n\r\n" .
            $bodyXml . "\r\n" .
            "--{$boundary}\r\n" .
            "Content-Type: application/zip\r\n" .
            "Content-Transfer-Encoding: binary\r\n" .
            "Content-ID: <{$cidFile}>\r\n" .
            "Content-Disposition: attachment; name=\"{$nomeFileAllegato}\"\r\n\r\n" .
            $zipData . "\r\n" .
            "--{$boundary}--\r\n";

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: multipart/related; type=\"application/xop+xml\"; start=\"<{$cidRoot}>\"; boundary=\"{$boundary}\"",
                'SOAPAction: ""',
            ],
            CURLOPT_POSTFIELDS     => $multipartBody,
            CURLOPT_TIMEOUT        => 60,
        ]);

        // TODO: se usa certificato client, aggiungere:
        // curl_setopt($ch, CURLOPT_SSLCERT, getenv('TS_CLIENT_CERT_PATH') ?: '');
        // curl_setopt($ch, CURLOPT_SSLKEY, getenv('TS_CLIENT_KEY_PATH') ?: '');
        // curl_setopt($ch, CURLOPT_SSLCERTPASSWD, getenv('TS_CLIENT_CERT_PASS') ?: '');

        $response = curl_exec($ch);
        $errNo    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            return [
                'esito'          => 'KO',
                'httpCode'       => $httpCode,
                'erroreTecnico'  => $errMsg,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'esito'    => 'KO',
                'httpCode' => $httpCode,
                'body'     => $response,
            ];
        }

        // TODO: parse effettivo SOAP XML per estrarre protocollo, codiceEsito, ecc.
        // Per ora ritorniamo il raw.
        return [
            'esito'    => 'OK',
            'httpCode' => $httpCode,
            'raw'      => $response,
        ];
    }

    /**
     * Costruisce il SOAP body, senza il pezzo binario (che è nel multipart).
     *
     * NB: adattare nomi tag e namespace al WSDL ufficiale
     *
     * @param string $nomeFileAllegato
     * @param string $pincodeInviante
     * @param array<string, mixed> $proprietario
     */
    private function buildSoapBody(string $nomeFileAllegato, string $pincodeInviante, array $proprietario): string
    {
        $cfProprietario = $proprietario['cfProprietario'] ?? '';

        $xml = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:inv="http://sistemats.sanita.finanze.it/">
  <soapenv:Header/>
  <soapenv:Body>
    <inv:invioTelematicoSS730pMtom>
      <nomeFileAllegato>{$this->xmlEscape($nomeFileAllegato)}</nomeFileAllegato>
      <pincodeInvianteCifrato>{$this->xmlEscape($pincodeInviante)}</pincodeInvianteCifrato>
      <documento>
        <xop:Include xmlns:xop="http://www.w3.org/2004/08/xop/include" href="cid:file.zip@ts"/>
      </documento>
      <datiProprietario>
        <cfProprietario>{$this->xmlEscape($cfProprietario)}</cfProprietario>
      </datiProprietario>
    </inv:invioTelematicoSS730pMtom>
  </soapenv:Body>
</soapenv:Envelope>
XML;

        return $xml;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
