<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowed_login_emails', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (!Schema::hasTable('users')) {
            return;
        }

        $existingEmails = [];

        foreach (DB::table('users')->select(['email', 'created_at', 'updated_at'])->get() as $user) {
            $email = Str::lower(trim((string) $user->email));

            if ($email === '' || isset($existingEmails[$email])) {
                continue;
            }

            $existingEmails[$email] = [
                'email' => $email,
                'added_by_user_id' => null,
                'created_at' => $user->created_at ?? now(),
                'updated_at' => $user->updated_at ?? now(),
            ];
        }

        if ($existingEmails !== []) {
            DB::table('allowed_login_emails')->insert(array_values($existingEmails));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('allowed_login_emails');
    }
};