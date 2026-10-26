<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shared lists, votes, pledge tallies, and click savings. No payment tables. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained('users');
            $table->string('title', 80);
            $table->string('token', 16)->unique();
            $table->char('currency', 3);
            $table->timestampTz('created_at');
        });

        Schema::create('shared_list_members', function (Blueprint $table): void {
            $table->foreignUuid('list_id')->constrained('shared_lists')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestampTz('created_at');
            $table->primary(['list_id', 'user_id']);
        });

        Schema::create('shared_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('list_id')->constrained('shared_lists')->cascadeOnDelete();
            $table->string('query', 120);
            $table->string('brand', 80)->nullable();
            $table->string('size', 40)->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('shared_list_votes', function (Blueprint $table): void {
            $table->foreignUuid('item_id')->constrained('shared_list_items')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('vote', 8);
            $table->timestampTz('created_at');
            $table->primary(['item_id', 'user_id']);
        });

        Schema::create('shared_list_pledges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('list_id')->constrained('shared_lists')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('note', 120)->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('savings_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('offer_key', 24);
            $table->string('store', 120);
            $table->char('currency', 3);
            $table->unsignedInteger('paid_minor');
            $table->unsignedInteger('highest_minor');
            $table->unsignedInteger('saved_minor');
            $table->timestampTz('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_events');
        Schema::dropIfExists('shared_list_pledges');
        Schema::dropIfExists('shared_list_votes');
        Schema::dropIfExists('shared_list_items');
        Schema::dropIfExists('shared_list_members');
        Schema::dropIfExists('shared_lists');
    }
};
