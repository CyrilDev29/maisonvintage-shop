<?php

namespace App\EntityListener;

use App\Entity\Article;
use Symfony\Component\String\Slugger\SluggerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;

class ArticleSlugListener
{
    public function __construct(private SluggerInterface $slugger) {}

    public function prePersist(Article $article, PrePersistEventArgs $event): void
    {
        $this->ensureSlug($article);
    }

    public function preUpdate(Article $article, PreUpdateEventArgs $event): void
    {
        $this->ensureSlug($article);
    }

    private function ensureSlug(Article $article): void
    {
        if (!$article->getTitre()) {
            return;
        }

        if (!$article->getSlug()) {
            $slug = $this->slugger->slug($article->getTitre())->lower();
            $article->setSlug((string) $slug);
        }
    }
}
