<?php

    use Coco\torrent\Torrent;

    require '../vendor/autoload.php';

    $t = './torrent/5.torrent';

    $torrent = new Torrent($t);

    print_r($torrent->name());
    echo PHP_EOL;

    print_r($torrent->announce());
    echo PHP_EOL;

    print_r($torrent->comment());
    echo PHP_EOL;

    print_r($torrent->content());
    echo PHP_EOL;