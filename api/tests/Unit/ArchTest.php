<?php

declare(strict_types=1);

// WS-06 boundary rules (brief): controllers stay thin, logic lives in Domain
// services, and the module edges below hold mechanically.

arch('the domain layer never references Illuminate\Http')
    ->expect('App\Domain')
    ->not->toUse('Illuminate\Http');

arch('only the Content domain touches Storage')
    ->expect('App')
    ->not->toUse('Illuminate\Support\Facades\Storage')
    ->ignoring('App\Domain\Content');

arch('only the Ratings domain uses the ratings model')
    ->expect('App\Models\Rating')
    ->toOnlyBeUsedIn('App\Domain\Ratings');

arch('only the Ratings domain uses the board_ratings model')
    ->expect('App\Models\BoardRating')
    ->toOnlyBeUsedIn('App\Domain\Ratings');

arch('only the Ratings domain uses the rating_events model')
    ->expect('App\Models\RatingEvent')
    ->toOnlyBeUsedIn('App\Domain\Ratings');

// declare(strict_types=1) is enforced mechanically by Pint (declare_strict_types
// rule, pint --test gate); pest's toUseStrictTypes trips an analyzer defect here.
arch('no debug output ships')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();
