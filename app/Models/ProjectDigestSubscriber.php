<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProjectDigestSubscriber extends Model
{
    protected $table = 'project_digest_subscribers';

    protected $fillable = [
        'email',
        'categories',
        'frequency',
        'confirmation_token',
        'confirmed_at',
        'unsubscribe_token',
        'unsubscribed_at',
        'last_sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ProjectDigestSubscriber>  $query
     * @return Builder<ProjectDigestSubscriber>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_at')->whereNull('unsubscribed_at');
    }

    /**
     * @param  Builder<ProjectDigestSubscriber>  $query
     * @return Builder<ProjectDigestSubscriber>
     */
    public function scopeDueForWeekly(Builder $query): Builder
    {
        $threshold = now()->subDays(6)->subHours(12);

        return $query->active()->where(function (Builder $q) use ($threshold) {
            $q->whereNull('last_sent_at')->orWhere('last_sent_at', '<', $threshold);
        });
    }

    public function confirmUrl(): string
    {
        return url('/plan/digest/confirm/'.$this->confirmation_token);
    }

    public function unsubscribeUrl(): string
    {
        return url('/plan/digest/unsubscribe/'.$this->unsubscribe_token);
    }
}
