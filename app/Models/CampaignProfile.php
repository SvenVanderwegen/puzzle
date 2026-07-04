<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per user: total XP earned across every campaign attempt and a
 * running solve count. The player's current level is never stored here —
 * it's always derived from total_xp via CampaignService::levelForXp(), the
 * same "derive, don't store redundant state" principle EndlessScore already
 * follows for its running-best record.
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
