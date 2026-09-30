<?php
declare(strict_types=1);
namespace App\Controllers;
use Fnlla\Php\Http\Response;
final class EchoController {
    public function show(string $id): Response { return Response::json(["id" => $id]); }
}
