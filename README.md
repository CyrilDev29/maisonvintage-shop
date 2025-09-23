# MaisonVintage Shop

Projet de **boutique e-commerce** développé en **PHP/Symfony** pour une brocante professionnelle.  
Ce projet est réalisé dans le cadre de mon stage de 2 mois à l’ENI École Informatique (22 septembre 2025 → 14 novembre 2025).

---

## 🎯 Objectifs
- Développer un site e-commerce mobile-first, simple et sécurisé.
- Permettre la mise en ligne et la gestion d’articles (produits vintage, mobilier, décoration, etc.).
- Intégrer un système de paiement en ligne (Stripe Checkout).
- Mettre en place un back-office minimaliste utilisable depuis un iPhone.
- Documenter et déployer le projet en production.

---

## 🛠️ Stack technique
- **Symfony 6+** (PHP 8.2)
- **Doctrine ORM** (MySQL / MariaDB)
- **Twig** (front-end minimaliste, mobile-first)
- **EasyAdmin** (back-office)
- **VichUploader + UX Dropzone** (upload images)
- **Stripe Checkout** (paiement carte)
- **Symfony Mailer** (emails transactionnels)
- **Bootstrap 5 / Tailwind** (CSS responsive)

---

## 📂 Fonctionnalités principales
- Gestion des utilisateurs avec vérification par email.
- Catalogue produits (catégories, photos multiples, stock, vente par lot).
- Pages publiques :
  - Accueil (derniers produits)
  - Boutique (par catégories)
  - Détail produit (photos + vidéo YouTube)
  - Victime de son succès (articles vendus)
  - Nouveau cocon (photos envoyées par les clients)
  - Contact (formulaire email)
- Panier + commande + paiement Stripe.
- Statuts commande (préparation, expédié, livré, annulé).
- Emails automatiques liés aux statuts.
- Gestion simplifiée de l’expédition (Mondial Relay, Colissimo, Cocolis).
- Back-office minimal adapté aux mobiles.

---

## 🚀 Installation locale
Pré-requis : PHP 8.2+, Composer, Symfony CLI, MySQL/MariaDB, WAMP/MAMP/LAMP

```bash
# Cloner le projet
git clone https://github.com/CyrilDev29/maisonvintage-shop.git
cd maisonvintage

# Installer les dépendances
composer install

# Configurer la base de données dans .env.local
cp .env .env.local
# puis éditer DATABASE_URL et MAILER_DSN

# Créer la base + migrations
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n

# Lancer le serveur
symfony server:start -d
