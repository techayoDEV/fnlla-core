<?php

declare(strict_types=1);

namespace Fnlla\Php\Tests;

use Fnlla\Php\Http\Request;
use PHPUnit\Framework\TestCase;

final class CoreProjectTest extends TestCase
{
    public function testCoreApplicationBootsWithoutPanelRoutes(): void
    {
        $container = $GLOBALS["fnlla_container"];
        $router = require base_path("bootstrap/router.php");
        $application = new \Fnlla\Php\Application($router, $container, $container->make(\Fnlla\Php\Exceptions\ExceptionHandler::class));
        $response = $application->handle(new Request("GET", "/"));
        self::assertSame(200, $response->status());
        self::assertStringNotContainsString("developer-workspace", $response->body());
        self::assertSame(404, $application->handle(new Request("GET", "/developer/panel"))->status());
        self::assertSame(200, $application->handle(new Request("GET", "/api/health"))->status());
    }
}
