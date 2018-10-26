#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use \TheAentMachine\Aent\ServiceAent;
use \TheAentMachine\AentGeneric\Event\AddEvent;

$application = new ServiceAent("Generic", new AddEvent());
$application->run();
