<?php

namespace App\Controller;

use App\Form\ContactType;
use App\Model\ContactMessage;
use App\Repository\ArticleRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Annotation\Route;

class PagesController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(ArticleRepository $articleRepository): Response
    {
        // On récupère plus large (ex: 30 derniers) pour pouvoir filtrer quantity>0
        // puis on coupera à 9 dans le template.
        $lastArticles = $articleRepository->findBy([], ['createdAt' => 'DESC'], 30);

        return $this->render('home/index.html.twig', [
            'articles' => $lastArticles,
        ]);
    }

    #[Route('/catalogue', name: 'catalogue')]
    public function catalogue(ArticleRepository $articleRepository): Response
    {
        $articles = $articleRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('pages/catalogue.html.twig', [
            'articles' => $articles,
        ]);
    }

    #[Route('/nouveau-cocon', name: 'nouveau_cocon')]
    public function nouveauCocon(): Response
    {
        return $this->render('pages/nouveau_cocon.html.twig');
    }

    #[Route('/victime-de-son-succes', name: 'victime_succes')]
    public function victimeSucces(ArticleRepository $articleRepository): Response
    {
        $articlesVendus = $articleRepository->findVendus();

        return $this->render('pages/victime_succes.html.twig', [
            'articles' => $articlesVendus,
        ]);
    }

    #[Route('/contact', name: 'contact')]
    public function contact(Request $request, MailerInterface $mailer): Response
    {
        $data = new ContactMessage();
        $form = $this->createForm(ContactType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Honeypot
            if ($form->has('website') && $form->get('website')->getData()) {
                return $this->redirectToRoute('contact');
            }

            $from = $_ENV['CONTACT_FROM'] ?? 'no-reply@localhost';
            $to   = $_ENV['CONTACT_TO']   ?? 'dest@example.com';

            $email = (new TemplatedEmail())
                ->from(new Address($from, 'Maison Vintage'))
                ->to($to)
                ->subject('Contact — ' . $data->getSubject())
                ->replyTo($data->getEmail() ?: $from)
                ->htmlTemplate('emails/contact.html.twig')
                ->context([
                    'name'       => $data->getName(),
                    'from_email' => $data->getEmail(),
                    'subject'    => $data->getSubject(),
                    'message'    => $data->getMessage(),
                ]);

            $mailer->send($email);

            $this->addFlash('success', 'Merci, votre message a bien été envoyé.');
            return $this->redirectToRoute('contact');
        }

        return $this->render('pages/contact.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
