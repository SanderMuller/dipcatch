<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Addresses are now stored lower-case, because Fortify lower-cases what a
 * person types to sign in and Postgres compares byte for byte. An address
 * stored with capitals could not sign in with a password or get a reset link.
 *
 * A user row whose lower-case form another row already holds is left alone:
 * lower-casing it would merge two accounts. Those pairs need a manual merge.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::update(<<<'SQL'
            UPDATE users
            SET email = lower(email)
            WHERE email <> lower(email)
              AND NOT EXISTS (
                  SELECT 1 FROM users other
                  WHERE other.id <> users.id
                    AND lower(other.email) = lower(users.email)
              )
            SQL);

        DB::update('UPDATE invitations SET email = lower(email) WHERE email <> lower(email)');
    }
};
