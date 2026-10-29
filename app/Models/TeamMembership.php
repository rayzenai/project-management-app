<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A person's place on a project's team (`member_team`): `leader` is the
 * project's manager. A pivot model so TeamMembershipObserver hears every
 * add, removal and change of manager made through the relations.
 *
 * @property int $id
 * @property int $member_id
 * @property int $team_id
 * @property string|null $role
 */
class TeamMembership extends Pivot
{
    protected $table = 'member_team';

    public $incrementing = true;

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
