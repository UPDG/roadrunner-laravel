# RoadRunner <-> Laravel/Lumen Bridge

In order to Laravel and Lumen work properly with RoadRunner you have to use this library. Without it Laravel not reseting
some components and can end up with bugs.

## Installation

### Require this library using composer

`composer req updg/roadrunner-laravel`

### Create psr-worker.php file and add this content to it

```php
<?php
require __DIR__ . "/vendor/autoload.php";

$integration = new \updg\roadrunner\laravel\LaravelIntegration();
$bridge = new \updg\roadrunner\easy\RoadRunner($integration);
$bridge->run();
```

That's it! Now just run RoadRunner server.
