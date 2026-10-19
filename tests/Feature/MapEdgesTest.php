<?php

use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\Courses\Integrations\Map\CourseMapObjects;

/*
 * The funnels map's edges from a course to the product that opens it
 * (statamic-brand-context 1.18): product -> unlocks -> course.
 */

beforeEach(function () {
    $this->makeCourse('stimme-basics', ['title' => 'Stimme Basics', 'product' => 'stimme-paket']);
    $this->makeCourse('cvt-im-chor-minikurs', ['title' => 'CVT im Chor Minikurs']);
    $this->makeCourse('arbeitsblatt', ['title' => 'Arbeitsblatt', 'kind' => 'material']);
    $this->map = app(CourseMapObjects::class);
});

it('draws the edge from the product field when it is set', function () {
    $edge = collect($this->map->edges())->firstWhere('toId', 'stimme-basics');

    expect($edge)->toBeInstanceOf(MapEdge::class)
        ->and($edge->fromType)->toBe('product')
        ->and($edge->fromId)->toBe('stimme-paket')
        ->and($edge->toType)->toBe('course')
        ->and($edge->toId)->toBe('stimme-basics')
        ->and($edge->kind)->toBe('unlocks')
        ->and($edge->label)->toBe('unlocks')
        ->and($edge->derivedFrom)->toBe('course field `product`')
        ->and($edge->dashed)->toBeFalse()
        ->and($edge->toLabel)->toBeNull();
});

it('falls back to the course slug as product when the field is empty', function () {
    $edge = collect($this->map->edges())->firstWhere('toId', 'cvt-im-chor-minikurs');

    expect($edge->fromType)->toBe('product')
        ->and($edge->fromId)->toBe('cvt-im-chor-minikurs')
        ->and($edge->toType)->toBe('course')
        ->and($edge->toId)->toBe('cvt-im-chor-minikurs')
        ->and($edge->kind)->toBe('unlocks')
        ->and($edge->derivedFrom)->toBe('course slug (field `product` is empty)');
});

it('yields one edge per course and none for a material', function () {
    $edges = $this->map->edges();

    expect($edges)->toHaveCount(2)
        ->and(array_map(fn (MapEdge $e) => $e->toId, $edges))->not->toContain('arbeitsblatt');
});

it('answers through the registry', function () {
    $edges = app(MapObjectRegistry::class)->edges();

    expect($edges)->toHaveKey('product:stimme-paket>unlocks>course:stimme-basics')
        ->and($edges)->toHaveKey('product:cvt-im-chor-minikurs>unlocks>course:cvt-im-chor-minikurs')
        ->and($edges['product:stimme-paket>unlocks>course:stimme-basics'])->toBeInstanceOf(MapEdge::class);
});
