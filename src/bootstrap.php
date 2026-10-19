<?php
// Minimal PSR-4 style autoloader: Sysmon\Foo -> src/Foo.php, Sysmon\Collector\Bar -> src/Collector/Bar.php
spl_autoload_register(function (string $class): void {
    if (strpos($class, 'Sysmon\\') !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
