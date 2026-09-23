<?php

namespace Goldnead\Courses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A learner a buyer let into what they bought.
 *
 * @property int $id
 * @property string $owner_id
 * @property string $product
 * @property string $email
 * @property int $slot
 * @property Carbon|null $created_at
 */
class TeamMember extends Model
{
    protected $table = 'courses_team_members';

    protected $fillable = [
        'owner_id',
        'product',
        'email',
        'slot',
    ];

    protected function casts(): array
    {
        return ['slot' => 'integer'];
    }
}
