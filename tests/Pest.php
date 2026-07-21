<?php

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Feature tests boot the framework. Unit tests deliberately do NOT: the rules
| engine in app/Domain is pure PHP with no Eloquent or framework coupling, so
| its tests must be able to run without a database or an application instance.
|
*/

pest()->extend(Tests\TestCase::class)->in('Feature');
