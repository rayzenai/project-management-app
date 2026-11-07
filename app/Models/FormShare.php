<?php

namespace App\Models;

use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A form given to one person of its project's organization: Can edit
 * (`edit`) or Can see responses (`responses`). Counts only while the person
 * is active and in that organization ({@see WorkspaceAccess::isFormShareEffective()}).
 *
 * @property int $id
 * @property int $form_id
 * @property int $member_id
 * @property string $permission
 * @property int|null $shared_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Form $form
 * @property-read Member $member
 */
class FormShare extends Model
{
    protected $fillable = ['form_id', 'member_id', 'permission', 'shared_by'];

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sharer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }
}
