<?php

declare(strict_types=1);

use SystemDesignLab\Experiment\Experiment;
use SystemDesignLab\Experiment\Options;
use SystemDesignLab\Experiment\Output;

return new Experiment(
    name: 'demo:smoke',
    description: 'show the experiment output shape and the runtime environment',
    supports: [],
    run: static function (Options $options, Output $output): void {
        $output->heading('System Design Lab smoke test', 'No chapter code: this only verifies the harness.');
        $output->line(sprintf('PHP %s, %s %s', PHP_VERSION, PHP_OS_FAMILY, php_uname('m')));
        $output->line(sprintf('memory_limit=%s, OPcache CLI=%s, Xdebug=%s', ini_get('memory_limit'), ini_get('opcache.enable_cli'), function_exists('xdebug_info') ? 'installed' : 'not installed'));
        $output->measure('php_version', PHP_VERSION);
        $output->measure('memory_limit_mb', 512);
        $output->measure('demo_value', 42);
    },
);
