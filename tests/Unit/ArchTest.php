<?php

declare(strict_types=1);

arch('every source file declares strict types')
    ->expect('Beziworld\Allegro')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('data objects are immutable')
    ->expect('Beziworld\Allegro\Data')
    ->toBeReadonly();

arch('exceptions share the package base exception')
    ->expect('Beziworld\Allegro\Exceptions')
    ->toExtend('Beziworld\Allegro\Exceptions\AllegroException')
    ->ignoring('Beziworld\Allegro\Exceptions\AllegroException');
