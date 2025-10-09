#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Kanopi\DrupalSdcValidator\Validator;

exit((new Validator())->run($argv));
