<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends AbstractController
{
    public function index(): Response
    {
        return $this->render('default/default.html.twig', [
            // this array defines the variables passed to the template,
            // where the key is the variable name and the value is the variable value
            // (Twig recommends using snake_case variable names: 'foo_bar' instead of 'fooBar')
            'users' => [
                ['username' => 'pcmoreno'],
                ['username' => 'that'],
                ['username' => 'this'],
                ['username' => 'nope'],

            ],
            'notifications' => 'no',
        ]);
    }
}
