<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Service\Shipping\Dto\ShippingOption;
use App\Service\Shipping\Dto\ShippingQuote;
use App\Service\Shipping\Model\Carrier;

/**
 * Client WebService SOAP Mondial Relay.
 * Gère la recherche de points relais et le calcul de tarifs.
 */
final class MondialRelayApi
{
    // WSDL officiel du WebService Mondial Relay v5
    private const WSDL = 'https://api.mondialrelay.com/Web_Services.asmx?WSDL';

    public function __construct(
        // Code enseigne (ex: CC201VCA) — injecté via MONDIALRELAY_API_KEY
        private readonly string $enseigne,
        // Clé privée (ex: sKSjuFPM) — injecté via MONDIALRELAY_API_SECRET
        private readonly string $clePrivee,
        // Code marque (ex: 41) — injecté via MONDIALRELAY_CODE_MARQUE
        private readonly string $codeMarque,
        // Flag LIVE : 1 = appels réels, 0 = fallback hardcodé
        private readonly bool $enabled = false,
    ) {}

    /**
     * Recherche les points relais les plus proches d'un code postal.
     * Utilisé par l'endpoint AJAX du checkout.
     *
     * @return array<int, array{id: string, nom: string, adresse: string, cp: string, ville: string, horaires: array}>
     */
    public function searchPointsRelais(string $codePostal, string $pays = 'FR', int $rayonKm = 10): array
    {
        if (!$this->enabled || !class_exists(\SoapClient::class)) {
            return [];
        }

        try {
            $soap = new \SoapClient(self::WSDL, [
                'trace'      => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ]);

            $paysStr  = strtoupper($pays);
            $cpStr    = $codePostal;
            $rayonStr = (string) $rayonKm;

            $security = $this->computeSecurity([
                $this->enseigne,
                $paysStr,
                '', '', $cpStr, '', '', '', '', '', '', $rayonStr, '',
            ]);

            $result = $soap->WSI3_PointRelais_Recherche([
                'Enseigne'       => $this->enseigne,
                'Pays'           => $paysStr,
                'NumPointRelais' => '',
                'Ville'          => '',
                'CP'             => $cpStr,
                'Latitude'       => '',
                'Longitude'      => '',
                'Taille'         => '',
                'Poids'          => '',
                'Action'         => '',
                'DelaiEnvoi'     => '',
                'RayonRecherche' => $rayonStr,
                'TypeActivite'   => '',
                'Security'       => $security,
            ]);

            $stat = (string) ($result->WSI3_PointRelais_RechercheResult->STAT ?? 'null');

            if ($stat !== '0') {
                return [];
            }

            $raw = $result->WSI3_PointRelais_RechercheResult->PointsRelais->PointRelais_Details ?? [];

            // L'API retourne un objet si 1 seul résultat, un tableau si plusieurs
            if (!is_array($raw)) {
                $raw = [$raw];
            }

            return array_map(fn(object $p) => [
                'id'       => (string) ($p->Num ?? ''),
                'nom'      => (string) ($p->LgAdr1 ?? ''),
                'adresse'  => trim((string) ($p->LgAdr3 ?? '') . ' ' . (string) ($p->LgAdr4 ?? '')),
                'cp'       => (string) ($p->CP ?? ''),
                'ville'    => (string) ($p->Ville ?? ''),
                'horaires' => $this->parseHoraires($p),
            ], $raw);

        } catch (\Throwable) {
            // En cas d'erreur réseau ou SOAP, on retourne tableau vide silencieusement
            return [];
        }
    }

    /**
     * Parse les horaires d'ouverture d'un point relais.
     * L'API retourne les horaires par jour : Horaires_Lundi, Horaires_Mardi, etc.
     */
    private function parseHoraires(object $p): array
    {
        $jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        $horaires = [];

        foreach ($jours as $jour) {
            $key = 'Horaires_' . $jour;
            $h = $p->$key ?? null;
            if (!$h) {
                continue;
            }

            $strings = is_array($h->string ?? null) ? $h->string : [];
            $matin = $this->formatHeure((string) ($strings[0] ?? '')) . '-' . $this->formatHeure((string) ($strings[1] ?? ''));
            $aprem = $this->formatHeure((string) ($strings[2] ?? '')) . '-' . $this->formatHeure((string) ($strings[3] ?? ''));

            $matinClean = str_replace('-', '', $matin);
            if ($matinClean !== '') {
                $apremClean = str_replace('-', '', $aprem);
                $horaires[$jour] = $apremClean !== '' ? "$matin / $aprem" : $matin;
            }
        }

        return $horaires;
    }

    /**
     * Formate une heure "0900" en "09h00". Retourne '' si vide ou "0000".
     */
    private function formatHeure(string $h): string
    {
        if (!$h || $h === '0000' || strlen($h) < 4) {
            return '';
        }
        return substr($h, 0, 2) . 'h' . substr($h, 2, 2);
    }

    /**
     * Calcule la clé de sécurité MD5 selon la doc Mondial Relay.
     * Concatène tous les paramètres + clé privée → MD5 en majuscules.
     *
     * @param array<string> $params
     */
    private function computeSecurity(array $params): string
    {
        $concat = implode('', $params) . $this->clePrivee;
        return strtoupper(md5($concat));
    }

    /**
     * Retourne une quote tarifaire Mondial Relay Point Relais.
     * Utilise un barème fallback (les vrais tarifs nécessitent un contrat spécifique).
     */
    public function quotePointRelais(int $totalWeightGr, string $countryCode = 'FR'): ShippingQuote
    {
        return $this->fallbackQuote($totalWeightGr, $countryCode);
    }

    /**
     * Barème tarifaire fallback (tarifs publics indicatifs).
     */
    private function fallbackQuote(int $totalWeightGr, string $countryCode): ShippingQuote
    {
        $isFR = strtoupper($countryCode) === 'FR';
        $w    = max(1, $totalWeightGr);

        if ($isFR) {
            $cents = ($w <= 500) ? 490 : (($w <= 1000) ? 590 : (($w <= 2000) ? 690 : 890));
        } else {
            $cents = ($w <= 500) ? 690 : (($w <= 1000) ? 890 : (($w <= 2000) ? 990 : 1290));
        }

        $opt = new ShippingOption(
            Carrier::MONDIAL_RELAY,
            'POINT_RELAIS',
            'Mondial Relay — Point Relais',
            $cents,
            [
                'source'             => 'fallback',
                'needRelaySelection' => true,
            ]
        );

        return new ShippingQuote([$opt]);
    }
}
