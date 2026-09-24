<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dinners', function (Blueprint $table): void {
            $table->string('emoji', 32)->nullable();
            $table->string('image_path', 64)->nullable()->index();
            $table->string('image_thumbhash', 64)->nullable();
        });

        // Every stored image, so files nothing points at any more can be pruned.
        // Rows outlive their household (null) until the prune removes the files.
        Schema::create('dinner_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 64)->unique();
            $table->string('source', 16);
            $table->string('thumbhash', 64);
            $table->unsignedInteger('bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dinner_images');
        Schema::table('dinners', function (Blueprint $table): void {
            $table->dropIndex(['image_path']);
            $table->dropColumn(['emoji', 'image_path', 'image_thumbhash']);
        });
    }
};
