<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per user: the player's points (total_xp, earned by every solved
 * fire in every mode, see PlayerProgress::award()) and how many campaign
 * fires they have solved. The level and rank are never stored here — they
 * are always derived from total_xp via Progression::standing().
 */
class CampaignProfile extends Model
{
    protected $table = 'burnfront_campaign_profiles';

    protected $fillable = ['user_id', 'total_xp', 'puzzles_solved'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
