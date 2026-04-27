<?php declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WaitlistSignupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $email
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class WaitlistSignup extends Model
{
    /** @use HasFactory<WaitlistSignupFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];
}
