<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Functional\App\Controller;

use Smbpunt\TimelineGraphBundle\Tests\Functional\App\Greeting\Finder\GreetingFinder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GreetingController
{
    public function __construct(
        private GreetingFinder $finder,
    ) {
    }

    #[Route('/greeting', name: 'greeting')]
    public function __invoke(): Response
    {
        return new Response('<html><body>'.$this->finder->find('Ada').'</body></html>');
    }
}
