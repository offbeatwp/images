<?php

namespace PHPSTORM_META {
    override(\offbeat(), map([
        'images' => OffbeatWP\Images\Repositories\ImagesRepository::class
    ]));

    override(\container(), map([
        'images' => OffbeatWP\Images\Repositories\ImagesRepository::class
    ]));
}