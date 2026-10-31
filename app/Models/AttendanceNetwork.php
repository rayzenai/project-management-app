<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttendanceNetworkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address range of the organization's office network (`cidr`, IPv4 or
 * IPv6): a punch from an address in it is "office network". A VPN's range
 * does not belong here.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $label
 * @property string $cidr
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AttendanceNetwork extends Model
{
    /** @use HasFactory<AttendanceNetworkFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'label', 'cidr'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
