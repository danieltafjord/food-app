<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dinner_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->json('content_authors')->nullable();
            $table->unsignedBigInteger('erasure_version')->default(0);
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamp('synced_at', 6)->nullable();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->index(['household_id', 'sync_version']);
        });
        Schema::table('dinners', fn (Blueprint $table) => $table->string('category', 36)->nullable()->change());
    }

    public function down(): void
    {
        DB::table('dinners')->whereIn('category', DB::table('dinner_categories')->select('uuid'))->update(['category' => null]);
        Schema::dropIfExists('dinner_categories');
        Schema::table('dinners', fn (Blueprint $table) => $table->string('category', 32)->nullable()->change());
    }
};
