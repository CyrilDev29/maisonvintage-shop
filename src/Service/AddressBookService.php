<?php

namespace App\Service;

use App\Entity\Address;
use App\Entity\User;
use App\Repository\AddressRepository;
use Doctrine\ORM\EntityManagerInterface;

class AddressBookService
{
    public function __construct(
        private AddressRepository $addressRepo,
        private EntityManagerInterface $em
    ) {}


    public function ensurePrimaryAddress(User $user): ?Address
    {
        if ($this->addressRepo->count(['user' => $user]) > 0) {
            return null;
        }

        // Nom complet
        $fullName = null;
        if (method_exists($user, 'getFullName') && $user->getFullName()) {
            $fullName = (string) $user->getFullName();
        } else {
            $first = method_exists($user, 'getFirstname') ? (string) $user->getFirstname() : '';
            $last  = method_exists($user, 'getLastname')  ? (string) $user->getLastname()  : '';
            $uid   = method_exists($user, 'getUserIdentifier') ? (string) $user->getUserIdentifier() : 'Client';
            $fullName = trim($first . ' ' . $last) ?: $uid;
        }

        // ---- Ligne 1 (adresse / rue) ----
        $line1 = null;

        // variantes communes pour une adresse complète
        $candidatesLine1 = [
            'getAdresse', 'getAddress', 'getAddressLine1', 'getAdresse1', 'getLine1', 'getStreet', 'getRue',
        ];
        foreach ($candidatesLine1 as $m) {
            if (method_exists($user, $m) && $user->$m()) {
                $line1 = (string) $user->$m();
                break;
            }
        }

        // fallback possible via numero + rue
        if (!$line1) {
            $numero = null;
            $rue    = null;

            foreach (['getNumero', 'getNumber'] as $m) {
                if (method_exists($user, $m) && $user->$m()) { $numero = (string) $user->$m(); break; }
            }
            foreach (['getRue', 'getStreet'] as $m) {
                if (method_exists($user, $m) && $user->$m()) { $rue = (string) $user->$m(); break; }
            }

            if ($rue) {
                $line1 = trim(($numero ? $numero . ' ' : '') . $rue);
            }
        }

        // ---- Complément éventuel ----
        $line2 = null;
        foreach (['getAddressLine2', 'getAdresse2', 'getLine2', 'getComplement', 'getComplementAdresse'] as $m) {
            if (method_exists($user, $m) && $user->$m()) { $line2 = (string) $user->$m(); break; }
        }

        // ---- Code postal ----
        $postal = null;
        foreach (['getCodePostal', 'getPostalCode', 'getCp', 'getZip'] as $m) {
            if (method_exists($user, $m) && $user->$m()) { $postal = (string) $user->$m(); break; }
        }

        // ---- Ville ----
        $city = null;
        foreach (['getVille', 'getCity'] as $m) {
            if (method_exists($user, $m) && $user->$m()) { $city = (string) $user->$m(); break; }
        }

        // ---- Pays ----
        $country = 'FR';
        foreach (['getCountryCode', 'getCountry', 'getPays'] as $m) {
            if (method_exists($user, $m) && $user->$m()) {
                $val = (string) $user->$m();
                // garde 2 lettres si on te donne "France"
                $country = (strlen($val) === 2) ? strtoupper($val) : 'FR';
                break;
            }
        }

        // ---- Téléphone (optionnel) ----
        $phone = null;
        foreach (['getTelephone', 'getPhone', 'getTel'] as $m) {
            if (method_exists($user, $m) && $user->$m()) { $phone = (string) $user->$m(); break; }
        }

        // Minimum requis : line1 + postal + city
        if (!$line1 || !$postal || !$city) {
            return null;
        }

        $addr = (new Address())
            ->setUser($user)
            ->setFullName($fullName)
            ->setLine1($line1)
            ->setLine2($line2)
            ->setPostalCode($postal)
            ->setCity($city)
            ->setCountry($country ?: 'FR')
            ->setPhone($phone);

        $this->em->persist($addr);
        $this->em->flush();

        return $addr;
    }
}
