<?php

declare(strict_types=1);

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = "Fnlla\\Php\\";
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = $root . "/src/" . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
    if (is_file($path)) {
        require $path;
    }
});

require_once $root . "/src/Support/helpers.php";

$classes = [
    Fnlla\Php\Container\Container::class,
    Fnlla\Php\Console\Application::class,
    Fnlla\Php\Http\Request::class,
    Fnlla\Php\Http\Response::class,
    Fnlla\Php\Routing\Router::class,
    Fnlla\Php\Validation\Validator::class,
    Fnlla\Php\View\View::class,
    Fnlla\Php\Support\FrameworkIdentity::class,
];

foreach ($classes as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "Missing class: " . $class . PHP_EOL);
        exit(1);
    }
}

$container = new Fnlla\Php\Container\Container();
$container->singleton(stdClass::class, static fn (): stdClass => (object) ["ok" => true]);
if ($container->make(stdClass::class) !== $container->make(stdClass::class)) {
    fwrite(STDERR, "Container singleton contract failed." . PHP_EOL);
    exit(1);
}

$validated = Fnlla\Php\Validation\Validator::make(
    ["email" => "developer@example.test"],
    ["email" => "required|email"]
)->validate();

if (($validated["email"] ?? null) !== "developer@example.test") {
    fwrite(STDERR, "Validator contract failed." . PHP_EOL);
    exit(1);
}

if (Fnlla\Php\Support\FrameworkIdentity::REPOSITORY !== "techayoDEV/fnlla-core") {
    fwrite(STDERR, "Core repository identity was not rewritten." . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "FNLLA Core package smoke test passed." . PHP_EOL);