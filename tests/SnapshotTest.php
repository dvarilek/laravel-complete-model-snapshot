<?php

declare(strict_types=1);

use Dvarilek\CompleteModelSnapshot\Exceptions\InvalidSnapshotException;
use Dvarilek\CompleteModelSnapshot\Tests\Models\TestRootModel;
use Dvarilek\CompleteModelSnapshot\DTO\AttributeTransferObject;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Dvarilek\CompleteModelSnapshot\Models\Snapshot;
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

    $snapshot = $model->takeSnapshot([
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

    $model->takeSnapshot();
    Carbon::setTestNow(now()->addMinute());

    $model->takeSnapshot();
    Carbon::setTestNow(now()->addMinutes(2));

    $latestSnapshot = $model->takeSnapshot();
    Carbon::setTestNow(now()->addMinutes(3));

    expect($model->snapshots)
        ->toHaveCount(3)
        ->and($model->latestSnapshot->getKey())->toBe($latestSnapshot->getKey());
});

test('oldestSnapshot method returns the oldest snapshot ', function () {
    Carbon::setTestNow(now());

    $model = TestRootModel::query()->create();

    $oldestSnapshot = $model->takeSnapshot();
    Carbon::setTestNow(now()->addMinute());

    $model->takeSnapshot();
    Carbon::setTestNow(now()->addMinutes(2));

    $model->takeSnapshot();
    Carbon::setTestNow(now()->addMinutes(3));

    expect($model->snapshots)
        ->toHaveCount(3)
        ->and($model->oldestSnapshot->getKey())->toBe($oldestSnapshot->getKey());
});


test('takeSnapshot method dispatches events during the snapshot creation process', function () {
    Event::fake();

    $model = TestRootModel::query()->create();

    $model->takeSnapshot();

    Event::assertDispatched('eloquent.snapshotting: ' . $model::class, function (string $eventName, TestRootModel $payloadModel) use ($model) {
        return $payloadModel->is($model);
    });

    Event::assertDispatched('eloquent.snapshot: ' . $model::class, function (string $eventName, TestRootModel $payloadModel) use ($model) {
        return $payloadModel->is($model);
    });
});

test('takeSnapshot operation is canceled when false is returned from snapshotting listener', function () {
    $class = new class extends TestRootModel {
        public static function booted(): void
        {
            static::snapshotting(fn () => false);
        }
    };

    $model = $class::query()->create();
    $result = $model->takeSnapshot();

    expect($result)
        ->toBeNull()
        ->and($model->snapshots)
        ->toHaveCount(0);
});

test('rewindTo method dispatches events during the restoration process', function () {
    Event::fake();

    $model = TestRootModel::query()->create();

    $snapshot = $model->takeSnapshot();
    $model = $model->rewindTo($snapshot);

    Event::assertDispatched('eloquent.rewinding: ' . $model::class, function (string $eventName, TestRootModel $payloadModel) use ($model) {
        return $payloadModel->is($model);
    });

    Event::assertDispatched('eloquent.rewound: ' . $model::class, function (string $eventName, TestRootModel $payloadModel) use ($model) {
        return $payloadModel->is($model);
    });
});

test('rewindTo method accepts only valid snapshots', function () {

    $model = TestRootModel::query()->create();
    $differentModel = TestRootModel::query()->create();

    $snapshot = $model->takeSnapshot();

    expect(fn () => $differentModel->rewindTo($snapshot))
        ->toThrow(InvalidSnapshotException::class);
});

test('rewindTo operation is canceled when false is returned from rewinding listener', function () {
    $class = new class extends TestRootModel {
        public static function booted(): void
        {
            static::rewinding(fn () => false);
        }
    };

    $model = $class::query()->create([
        'attribute1' => 'firstSnapshotValue',
    ]);
    $firstSnapshot = $model->takeSnapshot();

    $model->update([
        'attribute1' => 'secondSnapshotValue',
    ]);
    $model->takeSnapshot();

    $result = $model->rewindTo($firstSnapshot);

    expect($result)
        ->toBeNull()
        ->and($model)
        ->attribute1->toBe('secondSnapshotValue');
});

test('sync method synchronizes origin with the given snapshots state', function () {
    $snapshotValue1 = "snapshotValue1";
    $snapshotValue2 = "snapshotValue2";

    $model = TestRootModel::query()->create([
        'attribute1' => $snapshotValue1,
        'attribute2' => $snapshotValue2,
    ]);

    $snapshot = $model->takeSnapshot();

    $model->update([
        'attribute1' => Str::random(10),
        'attribute2' => Str::random(10),
    ]);

    $model = $snapshot->sync();

    expect($model)
        ->toBeInstanceOf(TestRootModel::class)
        ->attribute1->toBe($snapshotValue1)
        ->attribute2->toBe($snapshotValue2);
});
