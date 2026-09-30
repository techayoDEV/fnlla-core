<?php
declare(strict_types=1);
namespace App\Controllers;
use Fnlla\Php\Http\Response;
final class CatalogController {
    public function index(): Response { return Response::json(["items" => [["id" => "item-1", "name" => "Example"]]]); }
}
