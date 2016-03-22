<?php
/**
 * @author artyfarty
 */
include "vendor/autoload.php";

use Symfony\Component\Console\Application;
$console = new Application();

$console->add(new \Arty\VKDownloadFavs\Commands\Download("download"));
$console->add(new \Arty\VKDownloadFavs\Commands\GetToken("get_token"));

$console->run();
