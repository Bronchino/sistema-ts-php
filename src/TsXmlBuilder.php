<?php
declare(strict_types=1);

namespace SistemaTS;

use DOMDocument;
use DOMElement;

class TsXmlBuilder
{
    /**
     * @param array<string, mixed> $payload
     */
    public function buildXml(array $payload): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        // Root element (nome scelto in base al PDF; può adeguarlo allo XSD ufficiale)
        $root = $doc->createElement('precompilata');
        $doc->appendChild($root);

        // Opzionale1-3 vuoti per ora
        $root->appendChild($doc->createElement('opzionale1', ''));
        $root->appendChild($doc->createElement('opzionale2', ''));
        $root->appendChild($doc->createElement('opzionale3', ''));

        $this->appendProprietario($doc, $root, $payload['proprietario'] ?? []);
        $this->appendDocumenti($doc, $root, $payload['documenti'] ?? []);

        return $doc->saveXML() ?: '';
    }

    /**
     * @param DOMDocument $doc
     * @param DOMElement  $root
     * @param array<string, mixed> $proprietario
     */
    private function appendProprietario(DOMDocument $doc, DOMElement $root, array $proprietario): void
    {
        $prop = $doc->createElement('Proprietario');

        $tipo = $proprietario['tipo'] ?? 'PROFESSIONISTA';

        if ($tipo === 'PROFESSIONISTA') {
            // Caso PROFESSIONISTA (TS: cfProprietario cifrato, qui in chiaro da cifrare a valle)
            $cfProprietario = $proprietario['cfProprietario'] ?? '';
            $prop->appendChild($doc->createElement('cfProprietario', $cfProprietario));
        } else {
            // Caso STRUTTURA (non usato per il suo scenario, ma previsto)
            $prop->appendChild($doc->createElement('codiceRegione', $proprietario['codiceRegione'] ?? ''));
            $prop->appendChild($doc->createElement('codiceAsl', $proprietario['codiceAsl'] ?? ''));
            $prop->appendChild($doc->createElement('codiceSSA', $proprietario['codiceSSA'] ?? ''));
            $prop->appendChild($doc->createElement('cfProprietario', $proprietario['cfProprietario'] ?? ''));
        }

        $root->appendChild($prop);
    }

    /**
     * @param DOMDocument $doc
     * @param DOMElement  $root
     * @param array<int, array<string, mixed>> $documenti
     */
    private function appendDocumenti(DOMDocument $doc, DOMElement $root, array $documenti): void
    {
        foreach ($documenti as $docSpesa) {
            $elDoc = $doc->createElement('documentoSpesa');

            // idSpesa (idDocumentoFiscale)
            $elIdSpesa = $doc->createElement('idSpesa');
            $idDocumentoFiscale = $docSpesa['idDocumentoFiscale'] ?? [];

            $idDocEl = $doc->createElement('idDocumentoFiscale');
            $idDocEl->appendChild($doc->createElement('pIva', $idDocumentoFiscale['pIva'] ?? ''));
            $idDocEl->appendChild($doc->createElement('dataEmissione', $idDocumentoFiscale['dataEmissione'] ?? ''));

            $numDoc = $idDocumentoFiscale['numDocumentoFiscale'] ?? [];
            $numDocEl = $doc->createElement('numDocumentoFiscale');
            $numDocEl->appendChild($doc->createElement('dispositivo', (string)($numDoc['dispositivo'] ?? 1)));
            $numDocEl->appendChild($doc->createElement('NumDocumento', $numDoc['numDocumento'] ?? ''));

            $idDocEl->appendChild($numDocEl);
            $elIdSpesa->appendChild($idDocEl);
            $elDoc->appendChild($elIdSpesa);

            // dataPagamento
            $elDoc->appendChild($doc->createElement('dataPagamento', $docSpesa['dataPagamento'] ?? ''));

            // flagPagamentoAnticipato
            $flagAnt = !empty($docSpesa['flagPagamentoAnticipato']) ? '1' : '0';
            $elDoc->appendChild($doc->createElement('flagPagamentoAnticipato', $flagAnt));

            // flagOperazione
            $elDoc->appendChild($doc->createElement('flagOperazione', $docSpesa['flagOperazione'] ?? 'I'));

            // cfCittadino (da cifrare più avanti – qui in chiaro)
            if (!empty($docSpesa['cfCittadino']) && (int)($docSpesa['flagOpposizione'] ?? 0) === 0) {
                $elDoc->appendChild($doc->createElement('cfCittadino', $docSpesa['cfCittadino']));
            }

            // pagamentoTracciato: SI/NO
            $elDoc->appendChild($doc->createElement('pagamentoTracciato', $docSpesa['pagamentoTracciato'] ?? 'NO'));

            // tipoDocumento: D/F
            $elDoc->appendChild($doc->createElement('tipoDocumento', $docSpesa['tipoDocumento'] ?? 'F'));

            // flagOpposizione: 0/1
            $elDoc->appendChild($doc->createElement('flagOpposizione', (string)($docSpesa['flagOpposizione'] ?? 0)));

            // Voci di spesa
            $voci = $docSpesa['vociSpesa'] ?? [];
            foreach ($voci as $voce) {
                $voceEl = $doc->createElement('voceSpesa');

                $voceEl->appendChild($doc->createElement('tipoSpesa', $voce['tipoSpesa'] ?? 'SR'));
                if (!empty($voce['flagTipoSpesa'])) {
                    $voceEl->appendChild($doc->createElement('flagTipoSpesa', $voce['flagTipoSpesa']));
                }

                $importo = number_format((float)($voce['importo'] ?? 0), 2, '.', '');
                $voceEl->appendChild($doc->createElement('importo', $importo));

                // Aliquota IVA o natura IVA
                if (isset($voce['aliquotaIvaPercent'])) {
                    $aliquota = number_format((float)$voce['aliquotaIvaPercent'], 2, '.', '');
                    $voceEl->appendChild($doc->createElement('AliquotaIva', $aliquota));
                } elseif (!empty($voce['naturaIva'])) {
                    $voceEl->appendChild($doc->createElement('naturaIVA', $voce['naturaIva']));
                }

                $elDoc->appendChild($voceEl);
            }

            // Rimborso (idRimborso) – semplificato
            if (($docSpesa['flagOperazione'] ?? '') === 'R' && !empty($docSpesa['rimborsoDi'])) {
                $idRimborso = $doc->createElement('idRimborso');
                $orig = $docSpesa['rimborsoDi']['idDocumentoRimborsato'] ?? [];

                $origEl = $doc->createElement('idDocumentoFiscale');
                $origEl->appendChild($doc->createElement('pIva', $orig['pIva'] ?? ''));
                $origEl->appendChild($doc->createElement('dataEmissione', $orig['dataEmissione'] ?? ''));

                $origNum = $orig['numDocumentoFiscale'] ?? [];
                $origNumEl = $doc->createElement('numDocumentoFiscale');
                $origNumEl->appendChild($doc->createElement('dispositivo', (string)($origNum['dispositivo'] ?? 1)));
                $origNumEl->appendChild($doc->createElement('NumDocumento', $origNum['numDocumento'] ?? ''));

                $origEl->appendChild($origNumEl);
                $idRimborso->appendChild($origEl);
                $elDoc->appendChild($idRimborso);
            }

            $root->appendChild($elDoc);
        }
    }
}
