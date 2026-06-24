<?php


declare(strict_types=1);

namespace App\Controller;

use App\Service\Shipping\MondialRelayApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoint AJAX pour la recherche de points relais Mondial Relay.
 * Appelé depuis le checkout quand le client sélectionne Mondial Relay.
 */
final class MondialRelayController extends AbstractController
{
    // Seul un utilisateur connecté peut chercher des points relais (appelé depuis le checkout)
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/api/mondial-relay/points-relais', name: 'api_mondial_relay_points_relais', methods: ['GET'])]
    public function pointsRelais(Request $request, MondialRelayApi $api): JsonResponse
    {
        $cp = (string)($request->query->get('cp', ''));
        $pays = (string)($request->query->get('pays', 'FR'));

        // Validation du code postal (5 chiffres pour la France)
        if (!$cp || !preg_match('/^\d{5}$/', $cp)) {
            return $this->json(['error' => 'Code postal invalide'], 400);
        }

        // Pays en majuscules et limité aux caractères alpha
        $pays = strtoupper(preg_replace('/[^A-Za-z]/', '', $pays) ?: 'FR');

        $points = $api->searchPointsRelais($cp, $pays);

        return $this->json($points);
    }
}
