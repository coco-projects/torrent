<?php

    use Coco\torrent\Torrent;

    require '../vendor/autoload.php';

    $t = './torrent/5.torrent';

    $torrent = new Torrent($t);

    print_r($torrent->getDetailedInfo());