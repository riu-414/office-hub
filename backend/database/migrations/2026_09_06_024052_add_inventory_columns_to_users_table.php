<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1回目：既存UNIQUEを外して、列を追加する
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');

            $table->enum('role', ['admin', 'staff', 'member'])->default('member')->after('password');
            $table->foreignId('department_id')->nullable()->after('role')
                  ->constrained('departments')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('department_id');
            $table->softDeletes();
        });

        // 2回目：deleted_at が存在してから生成列を張る（DB設計書 §1.4）
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_unique_key', 255)->nullable()
                  ->storedAs('if(deleted_at is null, email, null)');
            $table->unique('email_unique_key', 'uq_users_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('uq_users_email');
            $table->dropColumn('email_unique_key');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
            $table->unique('email');
        });
    }
};
