<?php

use Dvarilek\LaravelSnapshotTree\Tests\Models\TestRootModel;
use Dvarilek\LaravelSnapshotTree\DTO\AttributeTransferObject;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Dvarilek\LaravelSnapshotTree\Models\Snapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Stringable;
use Illuminate\Support\Str;
use Carbon\Carbon;

it('can make snapshot', function () {
    $value1 = Str::random(10);
    $value2 = Str::random(11);

    $extraValue1 = Str::random(12);
    $extraValue2 = Str::random(13);

    $model = TestRootModel::query()->create([
        'attribute1' => $value1,
        'attribute2' => $value2,
    ]);

    $snapshot = $model->makeSnapshot([
        'extraAttribute1' => $extraValue1,
        'extraAttribute2' => new AttributeTransferObject('extraAttribute2', $extraValue2, AsStringable::class),
    ]);

    $rawRecordData = DB::table($snapshot->getTable())->first();

    expect($snapshot)->toBeInstanceOf(Snapshot::class)
        ->attribute1->toBe($value1)
        ->attribute2->toBe($value2)
        ->extraAttribute1->toBe($extraValue1)
        ->extraAttribute2->toBeInstanceOf(Stringable::class)
        ->extraAttribute2->value->toBe($extraValue2)
        ->and($rawRecordData->storage)->toBeJson()
        ->and($rawRecordData)->not->toHaveProperty('attribute1')
        ->and($rawRecordData)->not->toHaveProperty('attribute2')
        ->and($rawRecordData)->not->toHaveProperty('extraAttribute1')
        ->and($rawRecordData)->not->toHaveProperty('extraAttribute2')
        ->and(json_decode($rawRecordData->storage, true))
        ->toHaveKeys([
            'attribute1',
            'attribute2',
            'extraAttribute1',
            'extraAttribute2',
        ])
        ->and(json_decode($rawRecordData->storage, true)['attribute1'])
        ->toBe([
            'attribute' => 'attribute1',
            'value' => $value1,
            'cast' => null,
        ])
        ->and(json_decode($rawRecordData->storage, true)['attribute2'])
        ->toBe([
            'attribute' => 'attribute2',
            'value' => $value2,
            'cast' => null,
        ])
        ->and(json_decode($rawRecordData->storage, true)['extraAttribute1'])
        ->toBe([
            'attribute' => 'extraAttribute1',
            'value' => $extraValue1,
            'cast' => null,
        ])
        ->and(json_decode($rawRecordData->storage, true)['extraAttribute2'])
        ->toBe([
            'attribute' => 'extraAttribute2',
            'value' => $extraValue2,
            'cast' => AsStringable::class,
        ]);
});

test('latestSnapshot method returns the latest snapshot ', function () {
    Carbon::setTestNow(now());

    $model = TestRootModel::query()->create();

    $model->makeSnapshot();
    Carbon::setTestNow(now()->addMinute());

    $model->makeSnapshot();
    Carbon::setTestNow(now()->addMinutes(2));

    $latestSnapshot = $model->makeSnapshot();
    Carbon::setTestNow(now()->addMinutes(3));

    expect($model->snapshot)
        ->toHaveCount(3)
        ->and($model->latestSnapshot->getKey())->toBe($latestSnapshot->getKey());
});

test('oldestSnapshot method returns the oldest snapshot ', function () {
    Carbon::setTestNow(now());

    $model = TestRootModel::query()->create();

    $oldestSnapshot = $model->makeSnapshot();
    Carbon::setTestNow(now()->addMinute());

    $model->makeSnapshot();
    Carbon::setTestNow(now()->addMinutes(2));

    $model->makeSnapshot();
    Carbon::setTestNow(now()->addMinutes(3));

    expect($model->snapshot)
        ->toHaveCount(3)
        ->and($model->oldestSnapshot->getKey())->toBe($oldestSnapshot->getKey());
});
