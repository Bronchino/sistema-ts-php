<?php
declare(strict_types=1);

namespace SistemaTS;

use RuntimeException;

class SistemaTsClient
{
    // Endpoint medico da endpointServiziMedico.txt :contentReference[oaicite:8]{index=8}
    public const ENDPOINT_TEST = 'https://invioSS730pTest.sanita.finanze.it/InvioTelematicoSS730pMtomWeb/InvioTelematicoSS730pMtomPort';
    public const ENDPOINT_PROD = 'https://invioSS730p.sanita.finanze.it/InvioTelematicoSS730pMtomWeb/InvioTelematicoSS730pMtomPort';

    private string $environment;

    public function __construct(string $environment = 'TEST')
    {
        $this->environment = $environment === 'PROD' ? 'PROD' : 'TEST';
    }

    /**
     * Invia il file zip al Sistema TS via SOAP MTOM.
     *
     * @param string              $zipPath     percorso file ZIP (file01.zip)
     * @param string              $zipName     nome logico del file (es. file01.zip)
     * @param array<string,mixed> $requestData TsSpeseRequest completo
     *
     * @return array<string,mixed>
     */
    public function inviaFile(string $zipPath, string $zipName, array $requestData): array
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException("ZIP file not found: {$zipPath}");
        }

        $zipData = file_get_contents($zipPath);
        if ($zipData === false || $zipData === '') {
            throw new RuntimeException("Cannot read ZIP file or empty: {$zipPath}");
        }

        $endpoint = $this->environment === 'PROD'
            ? self::ENDPOINT_PROD
            : self::ENDPOINT_TEST;

        // --- Lettura dati inviante / proprietario --------------------------

        $inviante = $requestData['inviante'] ?? [];
        $prop     = $requestData['proprietario'] ?? [];

        $pincodeInviante = trim((string)($inviante['pincodeInviante'] ?? ''));
        if ($pincodeInviante === '') {
            throw new RuntimeException("inviante.pincodeInviante mancante nel payload TS");
        }

        $tipoProprietario = (string)($prop['tipo'] ?? 'PROFESSIONISTA');
        $cfProprietario   = trim((string)($prop['cfProprietario'] ?? ''));

        if ($tipoProprietario !== 'PROFESSIONISTA') {
            throw new RuntimeException("SistemaTsClient configurato per LIBERO PROFESSIONISTA; tipoProprietario diverso non gestito");
        }
        if ($cfProprietario === '') {
            throw new RuntimeException("cfProprietario mancante per proprietario.tipo=PROFESSIONISTA");
        }

        // --- Cifratura PIN con certificato Sanitel -------------------------

        $pincodeCifrato = $this->encryptWithSanitelCert($pincodeInviante);

        // cfProprietario: in CHIARO nel SOAP, cifrato nel file XML (lo gestiremo in TsXmlBuilder) :contentReference[oaicite:9]{index=9}

        // Costruisco il SOAP XML (body) con riferimenti MTOM/XOP
        $soapBody = $this->buildSoapBody(
            $zipName,
            $pincodeCifrato,
            $cfProprietario
        );

        // --- MTOM multipart/related ----------------------------------------

        $boundary = 'MIMEBoundary_' . bin2hex(random_bytes(8));
        $cidRoot  = 'root.message@ts';
        $cidFile  = 'file.zip@ts';

        $multipartBody =
            "--{$boundary}\r\n" .
            "Content-Type: application/xop+xml; charset=UTF-8; type=\"text/xml\"\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n" .
            "Content-ID: <{$cidRoot}>\r\n\r\n" .
            $soapBody . "\r\n" .
            "--{$boundary}\r\n" .
            "Content-Type: application/zip\r\n" .
            "Content-Transfer-Encoding: binary\r\n" .
            "Content-ID: <{$cidFile}>\r\n" .
            "Content-Disposition: attachment; name=\"{$zipName}\"\r\n\r\n" .
            $zipData . "\r\n" .
            "--{$boundary}--\r\n";

        // --- Chiamata HTTP via cURL ----------------------------------------

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException("Cannot init cURL");
        }

        $headers = [
            "Content-Type: multipart/related; type=\"application/xop+xml\"; start=\"<{$cidRoot}>\"; boundary=\"{$boundary}\"",
            'SOAPAction: ""',
        ];

        // BASIC AUTH come da IndicazioniTecniche/UtenzeTestMedico 
        $user = getenv('TS_BASIC_USER') ?: '';
        $pass = getenv('TS_BASIC_PASS') ?: '';
        if ($user !== '' && $pass !== '') {
            $authHeader = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
            $headers[] = $authHeader;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $multipartBody,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $errNo    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            return [
                'esito'         => 'KO',
                'httpCode'      => $httpCode,
                'erroreTecnico' => $errMsg,
            ];
        }

        if ($response === false || $response === '') {
            return [
                'esito'         => 'KO',
                'httpCode'      => $httpCode,
                'erroreTecnico' => 'Empty response',
            ];
        }

        $parsed = $this->parseSoapResponseBasic($response);

        return [
            'esito'    => $parsed['esito'] ?? 'OK',
            'httpCode' => $httpCode,
            'raw'      => $response,
            'parsed'   => $parsed,
        ];
    }

    /**
     * Cifra una stringa con il certificato Sanitel (Sanitel.cer) e ritorna Base64.
     * In futuro potremo adeguare padding/algoritmo se TS lo richiede.
     */
    private function encryptWithSanitelCert(string $plain): string
    {
        $certPath = getenv('SANITEL_CERT_PATH') ?: '';
        if ($certPath === '' || !is_file($certPath)) {
            throw new RuntimeException('SANITEL_CERT_PATH non configurato o file mancante');
        }

        $certPem = file_get_contents($certPath);
        if ($certPem === false || $certPem === '') {
            throw new RuntimeException("Impossibile leggere il certificato Sanitel: {$certPath}");
        }

        $pubKey = openssl_pkey_get_public($certPem);
        if ($pubKey === false) {
            throw new RuntimeException('Impossibile ottenere la chiave pubblica da Sanitel.cer');
        }

        $ok = openssl_public_encrypt($plain, $encrypted, $pubKey); // padding di default (PKCS1)
        openssl_free_key($pubKey);

        if (!$ok) {
            throw new RuntimeException('Errore nella cifratura OpenSSL del PIN');
        }

        return base64_encode($encrypted);
    }

    /**
     * Body SOAP per libero professionista:
     * - nomeFileAllegato
     * - pincodeInvianteCifrato (cifrato con Sanitel.cer)
     * - documento (xop:Include)
     * - datiProprietario con cfProprietario IN CHIARO :contentReference[oaicite:11]{index=11}
     */
    private function buildSoapBody(
        string $zipName,
        string $pincodeCifrato,
        string $cfProprietarioChiaro
    ): string {
        $zipNameEsc = $this->xmlEscape($zipName);
        $pinEsc     = $this->xmlEscape($pincodeCifrato);
        $cfEsc      = $this->xmlEscape($cfProprietarioChiaro);

        $xml = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:inv="http://sistemats.sanita.finanze.it/">
  <soapenv:Header/>
  <soapenv:Body>
    <inv:invioTelematicoSS730pMtom>
      <nomeFileAllegato>{$zipNameEsc}</nomeFileAllegato>
      <pincodeInvianteCifrato>{$pinEsc}</pincodeInvianteCifrato>
      <documento>
        <xop:Include xmlns:xop="http://www.w3.org/2004/08/xop/include" href="cid:file.zip@ts"/>
      </documento>
      <datiProprietario>
        <cfProprietario>{$cfEsc}</cfProprietario>
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

    /**
     * Parser semplice della risposta SOAP per estrarre:
     * - codiceEsito
     * - descrizioneEsito
     * - protocollo
     */
    private function parseSoapResponseBasic(string $responseXml): array
    {
        $result = [
            'esito'             => null,
            'codiceEsito'       => null,
            'descrizione'       => null,
            'protocollo'        => null,
            'dataAccoglienza'   => null,
            'nomeFileAllegato'  => null,
            'dimensioneFileAllegato' => null,
        ];

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($responseXml);
        if ($xml === false) {
            return $result;
        }

        $bodyStr = '';
        foreach ($xml->children() as $child) {
            $bodyStr .= $child->asXML() ?: '';
        }

        if (preg_match('/<codiceEsito>([^<]+)<\/codiceEsito>/', $bodyStr, $m)) {
            $result['codiceEsito'] = $m[1];
            $result['esito'] = $m[1] === '000' ? 'OK' : 'KO';
        }
        if (preg_match('/<descrizioneEsito>([^<]+)<\/descrizioneEsito>/', $bodyStr, $m)) {
            $result['descrizione'] = $m[1];
        }
        if (preg_match('/<protocollo>([^<]+)<\/protocollo>/', $bodyStr, $m)) {
            $result['protocollo'] = $m[1];
        }
        if (preg_match('/<dataAccoglienza>([^<]+)<\/dataAccoglienza>/', $bodyStr, $m)) {
            $result['dataAccoglienza'] = $m[1];
        }
        if (preg_match('/<nomeFileAllegato>([^<]+)<\/nomeFileAllegato>/', $bodyStr, $m)) {
            $result['nomeFileAllegato'] = $m[1];
        }
        if (preg_match('/<dimensioneFileAllegato>([^<]+)<\/dimensioneFileAllegato>/', $bodyStr, $m)) {
            $result['dimensioneFileAllegato'] = $m[1];
        }

        return $result;
    }
}
