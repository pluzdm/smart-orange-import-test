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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 64);
            $table->dateTime('created_at');
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('phone', 64);
            $table->string('email', 255)->nullable();
            $table->string('city', 100);
            $table->string('source', 100);
            $table->string('utm_campaign', 255)->nullable();
            $table->string('product', 255);
            $table->decimal('budget_uah', 12, 2)->nullable();
            $table->string('status', 100);
            $table->string('manager', 100)->nullable();
            $table->text('comment')->nullable();
            $table->dateTime('next_contact_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
