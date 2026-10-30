<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Response;
use App\Http\Controller;
use App\Services\Search;

final class SearchController extends Controller
{
    public function index(): Response
    {
        $q = $this->request->str('q');
        $quick = $this->request->bool('rapida');
        $results = $this->app->make(Search::class)->run($this->user(), $q, $quick ? 5 : 30);
        if ($quick || $this->request->wantsJson()) {
            return $this->json($results);
        }
        return $this->render('search/index.twig', ['q' => $q, 'results' => $results]);
    }
}
