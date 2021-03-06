<?php

namespace Directus\Application\Http\Middlewares;

use Directus\Application\Container;
use Psr\Container\ContainerInterface;

abstract class AbstractMiddleware
{
    /**
     * @var Container
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }
}
