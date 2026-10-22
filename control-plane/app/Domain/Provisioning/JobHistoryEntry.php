<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Provisioning;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One operation and its result (spec section 6, decision 0014). Written
 * through App\Application\Services\JobHistory only.
 *
 * @property string $id
 * @property JobType $type
 * @property string $resource_type
 * @property string $resource_id
 * @property string $dispatch_id the token of the job's latest dispatch; only the copy that carries it may run
 * @property int|null $generation
 * @property string|null $node_id
 * @property JobStatus $status
 * @property FailureClass|null $failure_class
 * @property string|null $message
 * @property int $attempts
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable('type', 'resource_type', 'resource_id', 'dispatch_id', 'generation', 'node_id', 'status', 'attempts')]
final class JobHistoryEntry extends Model
{
    use HasUuids;

    protected $table = 'job_history';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => JobType::class,
            'status' => JobStatus::class,
            'failure_class' => FailureClass::class,
            'generation' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
