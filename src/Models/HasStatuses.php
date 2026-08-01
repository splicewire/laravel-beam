<?php

namespace Splicewire\Beam\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Spatie\ModelStatus\HasStatuses as BaseHasStatuses;

trait HasStatuses
{
    use BaseHasStatuses {
        BaseHasStatuses::scopeCurrentStatus as baseScopeCurrentStatus;
        BaseHasStatuses::scopeOtherCurrentStatus as baseScopeOtherCurrentStatus;
    }

    public function scopeCurrentStatus(Builder $builder, ...$names)
    {
        $names = is_array($names) ? Arr::flatten($names) : func_get_args();
        $builder
            ->whereHas(
                'statuses',
                function (Builder $query) use ($names) {
                    $query
                        ->whereIn('name', $names)
                        ->where(
                            'id',
                            function (QueryBuilder $subQuery) {
                                $subQuery
                                    // ->selectRaw('max(id)') // This is the original line
                                    ->select('id')->limit(1)->orderByDesc('created_at') // This is the new line for uuids
                                    ->from($this->getStatusTableName())
                                    ->where('model_type', $this->getStatusModelType())
                                    ->whereColumn($this->getModelKeyColumnName(), $this->getQualifiedKeyName());
                            }
                        );
                }
            );
    }

    /**
     * @param  string|array  $names
     * @return void
     **/
    public function scopeOtherCurrentStatus(Builder $builder, ...$names)
    {
        $names = is_array($names) ? Arr::flatten($names) : func_get_args();
        $builder
            ->whereHas(
                'statuses',
                function (Builder $query) use ($names) {
                    $query
                        ->whereNotIn('name', $names)
                        ->where(
                            'id',
                            function (QueryBuilder $subQuery) {
                                $subQuery
                                    // ->selectRaw('max(id)') // This is the original line
                                    ->select('id')->limit(1)->orderByDesc('created_at') // This is the new line for uuids
                                    ->from($this->getStatusTableName())
                                    ->where('model_type', $this->getStatusModelType())
                                    ->whereColumn($this->getModelKeyColumnName(), $this->getQualifiedKeyName());
                            }
                        );
                }
            )
            ->orWhereDoesntHave('statuses');
    }

    public function deleteEnumStatuses(string|array $enumOrCases)
    {
        if (is_string($enumOrCases)) {
            $cases = $enumOrCases::cases();
        } else {
            $cases = $enumOrCases;
        }

        return $this->deleteStatus(array_keys($cases));
    }
}
