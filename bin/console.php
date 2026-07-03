<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/random_image.php';

exit(ri_cli_main(dirname(__DIR__), $argv));
