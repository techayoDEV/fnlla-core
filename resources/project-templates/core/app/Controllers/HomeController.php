<?php

declare(strict_types=1);

namespace App\Controllers;

use Fnlla\Php\Controllers\Controller;
use Fnlla\Php\Http\Response;

final class HomeController extends Controller
{
    public function index(): Response
    {
        return $this->view("pages/home", ["pageTitle" => config("app.name")]);
    }

    public function health(): Response
    {
        return Response::json(["status" => "ok"]);
    }
}
