<?php
declare(strict_types=1);

use Ctw\Qa\Rector\Config\RectorConfig\DefaultFileExtensions;
use Ctw\Qa\Rector\Config\RectorConfig\DefaultSets;
use Ctw\Qa\Rector\Config\RectorConfig\DefaultSkip;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    $fileExtensions = new DefaultFileExtensions();
    $sets           = new DefaultSets();
    $skip           = new DefaultSkip();

    $rectorConfig->fileExtensions($fileExtensions());

    // This project requires "php": "^8.3", so cap the level set at PHP 8.3
    // instead of the default's newest supported version.
    $rectorConfig->sets(
        array_map(
            static fn (string $set): string => LevelSetList::UP_TO_PHP_85 === $set
                ? LevelSetList::UP_TO_PHP_83
                : $set,
            $sets()
        )
    );

    $rectorConfig->paths(
        [
            sprintf('%s/src', __DIR__),
            sprintf('%s/test', __DIR__),
            sprintf('%s/ecs.php', __DIR__),
            sprintf('%s/rector.php', __DIR__),
        ]
    );

    $rectorConfig->skip($skip());
};
