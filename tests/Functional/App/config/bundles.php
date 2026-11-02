<?php

use Smbpunt\TimelineGraphBundle\TimelineGraphBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;

return [
    FrameworkBundle::class => ['all' => true],
    TwigBundle::class => ['all' => true],
    WebProfilerBundle::class => ['all' => true],
    TimelineGraphBundle::class => ['all' => true],
];
